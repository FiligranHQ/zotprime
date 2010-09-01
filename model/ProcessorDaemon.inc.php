<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2010 Center for History and New Media
                     George Mason University, Fairfax, Virginia, USA
                     http://zotero.org
    
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.
    
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.
    
    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
    
    ***** END LICENSE BLOCK *****
*/

abstract class Zotero_Processor_Daemon {
	// Default is no concurrency
	private $minProcessors = 1;
	private $maxProcessors = 1;
	private $minQueuedProcesses = 1; // minimum queued processes before we do anything
	private $maxQueuedPerProcessor = 3;
	private $minPurgeInterval = 20; // minimum time between purging orphaned processors
	private $minCheckInterval = 15; // minimum time between checking queued processes on NEXT
	private $lockWait = 2; // Delay when a processor returns a LOCK signal
	
	private $hostname;
	private $processors = array();
	private $totalProcesses = 0;
	
	// Set by implementors
	protected $name;
	protected $port;
	
	public function __construct($config=array()) {
		error_reporting(E_ALL | E_STRICT);
		set_time_limit(0);
		
		$this->hostname = gethostname();
		
		// Set configuration parameters
		foreach ($config as $key => $val) {
			switch ($key) {
				case 'minProcessors':
				case 'maxProcessors':
				case 'minQueuedProcesses':
				case 'maxQueuedPerProcessor':
				case 'minPurgeInterval':
				case 'minCheckInterval':
				case 'lockWait':
					$this->$key = $val;
					break;
				
				default:
					throw new Exception("Invalid configuration key '$key'");
			}
		}
	}
	
	
	public function run() {
		$this->log("Starting sync " . $this->name . " processor daemon");
		
		$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		$success = socket_bind($socket, Z_CONFIG::$SYNC_PROCESSOR_BIND_ADDRESS, $this->port);
		if (!$success) {
			$code = socket_last_error($socket);
			die(socket_strerror($code));
		}
		
		$buffer = 'GO';
		$name = null;
		
		$first = true;
		$lastPurge = 0;
		$this->totalProcesses = $this->countQueuedProcesses();
		$lastCheck = microtime(true);
		
		do {
			if ($first) {
				$first = false;
			}
			else {
				//$this->log("Waiting for command");
				$from = '';
				$port = 0;
				socket_recvfrom($socket, $buffer, 32, MSG_WAITALL, $from, $port);
			}
			
			// Processor return value
			if (preg_match('/^(DONE|NONE|LOCK|ERROR) ([0-9]+)/', $buffer, $return)) {
				$signal = $return[1];
				$id = $return[2];
				
				$this->removeProcessor($id);
				
				if ($signal == "DONE" || $signal == "ERROR") {
					$this->totalProcesses--;
				}
				else if ($signal == "NONE") {
					$this->totalProcesses = 0;
					continue;
				}
				else if ($signal == "LOCK") {
					$this->log("LOCK received — waiting " . $this->lockWait . " second" . $this->pluralize($this->lockWait));
					sleep($this->lockWait);
				}
				
				$buffer = "GO";
			}
			
			if ($buffer == "NEXT" || $buffer == "GO") {
				if ($buffer == "NEXT") {
					//$this->log("NEXT received");
					$this->totalProcesses++;
				}
				
				if ($lastPurge == 0) {
					$lastPurge = microtime(true);
				}
				// Only purge processors if enough time has passed since last check
				else if ((microtime(true) - $lastPurge) >= $this->minPurgeInterval) {
					$purged = $this->purgeProcessors();
					$this->log("Purged $purged lost processor" . $this->pluralize($purged));
					$purged = $this->purgeOldProcesses();
					$this->log("Purged $purged old process" . $this->pluralize($purged, "es"));
					$lastPurge = microtime(true);
				}
				
				$numProcessors = $this->countProcessors();
				
				if ($numProcessors >= $this->maxProcessors) {
					//$this->log("Already at max " . $this->maxProcessors . " processors");
					continue;
				}
				
				try {
					// Under some conditions, do a DB query to determine number of queued processes
					if ($buffer == "NEXT" && ($numProcessors <= 0 || (microtime(true) - $lastCheck) >= $this->minCheckInterval)) {
						//$this->log("Checking queued processes");
						$queuedProcesses = $this->countQueuedProcesses();
						$this->totalProcesses = $numProcessors + $queuedProcesses;
						$lastCheck = microtime(true);
					}
					// Otherwise just use our latest figures
					else {
						$queuedProcesses = max($this->totalProcesses - $numProcessors, 0);
					}
					
					$this->log($numProcessors . " processor" . $this->pluralize($numProcessors) . ", "
						. $queuedProcesses . " queued process" . $this->pluralize($queuedProcesses, "es"));
					
					
					if ($queuedProcesses == 0) {
						continue;
					}
					
					if ($queuedProcesses < $this->minQueuedProcesses) {
						$this->log("Under minimum queued processes");
						continue;
					}
					
					// See if we're above minimum and below the queued-per-processor limit
					$threshold = $numProcessors * $this->maxQueuedPerProcessor;
					if ($numProcessors >= $this->minProcessors && $queuedProcesses < $threshold) {
						$this->log("Under queued-per-processor limit");
						continue;
					}
					
					// Wanna be startin' somethin'
					$toStart = min(
						$queuedProcesses,
						max(
							$this->minProcessors - $numProcessors,
							
							ceil(($queuedProcesses - $threshold) / $this->maxQueuedPerProcessor)
						),
						$this->maxProcessors - $numProcessors
					);
					
					if ($toStart <= 0) {
						$this->log("No processors to start");
						continue;
					}
					
					$this->log("Starting $toStart new processor" . $this->pluralize($toStart));
					
					// Start new processors
					for ($i=0; $i<$toStart; $i++) {
						$id = Zotero_ID::getBigInt();
						$pid = shell_exec(
							Z_CONFIG::$CLI_PHP_PATH . " " . Z_ENV_BASE_PATH . "processor/"
							. $this->name . "/processor.php $id > /dev/null & echo $!"
						);
						$this->processors[$id] = $pid;
					}
				}
				catch (Exception $e) {
					// If lost connection to MySQL, exit so we can be restarted
					if (strpos($e->getMessage(), "MySQL error: MySQL server has gone away") === 0) {
						$this->log($e);
						$this->log("Lost connection to DB — exiting");
						exit;
					}
					
					$this->log($e);
				}
			}
		}
		while ($buffer != 'QUIT');
		
		$this->log("QUIT received — exiting");
	}
	
	
	private function countProcessors() {
		return sizeOf($this->processors);
	}
	
	
	private function removeProcessor($id) {
		if (!isset($this->processors[$id])) {
			//$this->log("Process $id not found for removal");
		}
		else {
			unset($this->processors[$id]);
		}
	}
	
	
	private function purgeProcessors() {
		$ids = array_keys($this->processors);
		$purged = 0;
		foreach ($ids as $id) {
			if (!$this->isRunning($this->processors[$id])) {
				$this->log("Purging lost processor $id");
				unset($this->processors[$id]);
				$this->removeProcess($id);
				$purged++;
			}
		}
		return $purged;
	}
	
	
	/**
	 * Remove process id from any processes in DB that we have no record of
	 * (e.g., started in a previous daemon session) and that are no longer running
	 */
	private function purgeOldProcesses() {
		$processes = $this->getOldProcesses($this->hostname);
		if (!$processes) {
			return 0;
		}
		
		$removed = 0;
		foreach ($processes as $id) {
			if (isset($this->processors[$id])) {
				continue;
			}
			// Check if process is running
			if (!$this->isRunningByID($id)) {
				$this->log("Purging lost process $id");
				$this->removeProcess($id);
				$removed++;
			}
		}
		return $removed;
	}
	
	
	private function isRunning($pid) {
		exec("ps $pid", $state);
		return sizeOf($state) >= 2;
	}
	
	
	private function isRunningByID($id) {
		exec("ps | grep $id | grep -v grep", $state);
		return (bool) sizeOf($state);
	}
	
	
	private function pluralize($num, $plural="s") {
		return $num == 1 ? "" : $plural;
	}
	
	
	//
	// Abstract methods
	//
	abstract public function log($msg);
	abstract protected function countQueuedProcesses();
	
	/**
	 * Get from the DB any processes that have been running
	 * longer than a given period of time
	 */
	abstract protected function getOldProcesses($host=null, $seconds=null);
	
	/**
	 * Remove process id from DB
	 */
	abstract protected function removeProcess($id);
}


class Zotero_Download_Processor_Daemon extends Zotero_Processor_Daemon {
	protected $name = 'download';
	
	public function __construct($config=array()) {
		$this->port = Z_CONFIG::$SYNC_PROCESSOR_PORT_DOWNLOAD;
		if (!$config || !isset($config['minProcessors'])) {
			$config['minProcessors'] = 2;
		}
		if (!$config || !isset($config['maxProcessors'])) {
			$config['maxProcessors'] = 3;
		}
		parent::__construct($config);
	}
	
	public function log($msg) {
		Z_Log::log(Z_CONFIG::$SYNC_PROCESSOR_LOG_TARGET_DOWNLOAD, $msg);
	}
	
	protected function countQueuedProcesses() {
		return Zotero_Sync::countQueuedDownloadProcesses();
	}
	
	protected function getOldProcesses($host=null, $seconds=null) {
		return Zotero_Sync::getOldDownloadProcesses($host, $seconds);
	}
	
	protected function removeProcess($id) {
		Zotero_Sync::removeDownloadProcess($id);
	}
}


class Zotero_Upload_Processor_Daemon extends Zotero_Processor_Daemon {
	protected $name = 'upload';
	
	public function __construct($config=array()) {
		$this->port = Z_CONFIG::$SYNC_PROCESSOR_PORT_UPLOAD;
		parent::__construct($config);
	}
	
	public function log($msg) {
		Z_Log::log(Z_CONFIG::$SYNC_PROCESSOR_LOG_TARGET_UPLOAD, $msg);
	}
	
	protected function countQueuedProcesses() {
		return Zotero_Sync::countQueuedUploadProcesses();
	}
	
	protected function getOldProcesses($host=null, $seconds=null) {
		return Zotero_Sync::getOldUploadProcesses($host, $seconds);
	}
	
	protected function removeProcess($id) {
		Zotero_Sync::removeUploadProcess($id);
	}
}


class Zotero_Error_Processor_Daemon extends Zotero_Processor_Daemon {
	protected $name = 'error';
	
	public function __construct($config=array()) {
		$this->port = Z_CONFIG::$SYNC_PROCESSOR_PORT_ERROR;
		if (!$config || !isset($config['minQueuedProcesses'])) {
			$config['minQueuedProcesses'] = 1; // TODO: increase
		}
		parent::__construct($config);
	}
	
	public function log($msg) {
		Z_Log::log(Z_CONFIG::$SYNC_PROCESSOR_LOG_TARGET_ERROR, $msg);
	}
	
	protected function countQueuedProcesses() {
		return Zotero_Sync::countQueuedUploadProcesses(true);
	}
	
			protected function getOldProcesses($host=null, $seconds=null) {
		return Zotero_Sync::getOldErrorProcesses($host, $seconds);
	}
	
	protected function removeProcess($id) {
		Zotero_Sync::removeErrorProcess($id);
	}
}
?>
