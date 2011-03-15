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

abstract class Zotero_Processor {
	protected $id;
	
	public function run($id=null) {
		$this->id = $id;
		$this->addr = gethostbyname(gethostname());
		
		if (!Z_CONFIG::$PROCESSORS_ENABLED) {
			$sleep = 20;
			$this->log("Processors disabled — exiting in $sleep seconds");
			sleep($sleep);
			try {
				$this->notifyProcessor("LOCK" . " " . $id);
			}
			catch (Exception $e) {
				$this->log($e);
			}
			return;
		}
		
		$this->log("Starting sync processor");
		
		$startTime = microtime(true);
		try {
			$processed = $this->processFromQueue();
		}
		catch (Exception $e) {
			$this->log($e);
			throw ($e);
		}
		
		$duration = microtime(true) - $startTime;
		
		$error = false;
		
		// Success
		if ($processed == 1) {
			$this->log("Process completed in " . round($duration, 2) . " seconds");
			$signal = "DONE";
		}
		else if ($processed == 0) {
			$this->log("Exiting with no processes found");
			$signal = "NONE";
		}
		else if ($processed == -1) {
			$this->log("Exiting on lock error");
			$signal = "LOCK";
		}
		else {
			$this->log("Exiting on error");
			$signal = "ERROR";
		}
		
		if ($id) {
			try {
				$this->notifyProcessor($signal . " " . $id);
			}
			catch (Exception $e) {
				$this->log($e);
			}
		}
	}
	
	
	protected function notifyProcessor($signal) {
		Zotero_Processors::notifyProcessor($this->mode, $signal, $this->addr, $this->port);
	}
	
	
	private function log($msg) {
		$targetVariable = "PROCESSOR_LOG_TARGET_" . strtoupper($this->mode);
		Z_Log::log(Z_CONFIG::$$targetVariable, "[" . $this->id . "] $msg");
	}
	
	
	//
	// Abstract methods
	//
	abstract protected function processFromQueue();
}

class Zotero_Download_Processor extends Zotero_Processor {
	protected $mode = 'download';
	
	public function __construct() {
		$this->port = Z_CONFIG::$PROCESSOR_PORT_DOWNLOAD;
	}
	
	protected function processFromQueue() {
		return Zotero_Sync::processDownloadFromQueue($this->id);
	}
}

class Zotero_Upload_Processor extends Zotero_Processor {
	protected $mode = 'upload';
	
	public function __construct() {
		$this->port = Z_CONFIG::$PROCESSOR_PORT_UPLOAD;
	}
	
	protected function processFromQueue() {
		return Zotero_Sync::processUploadFromQueue($this->id);
	}
}

class Zotero_Error_Processor extends Zotero_Processor {
	protected $mode = 'error';
	
	public function __construct() {
		$this->port = Z_CONFIG::$PROCESSOR_PORT_ERROR;
	}
	
	protected function processFromQueue() {
		return Zotero_Sync::checkUploadForErrors($this->id);
	}
	
	protected function notifyProcessor($signal) {
		// Tell the upload processor a process is available
		Zotero_Processors::notifyProcessors('upload', $signal);
		
		Zotero_Processors::notifyProcessor($this->mode, $signal, $this->addr, $this->port);
	}
}

class Zotero_Index_Processor extends Zotero_Processor {
	protected $mode = 'index';
	
	public function __construct() {
		$this->port = Z_CONFIG::$PROCESSOR_PORT_INDEX;
	}
	
	protected function processFromQueue() {
		return Zotero_Index::processFromQueue($this->id);
	}
}
?>
