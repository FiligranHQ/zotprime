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
	
	//
	// Abstract methods
	//
	abstract protected function log($msg);
	abstract protected function processFromQueue();
	abstract protected function notifyProcessor($signal);
}

class Zotero_Download_Processor extends Zotero_Processor {
	protected function log($msg) {
		Z_Log::log(Z_CONFIG::$SYNC_PROCESSOR_LOG_TARGET_DOWNLOAD, "[" . $this->id . "] $msg");
	}
	
	protected function processFromQueue() {
		return Zotero_Sync::processDownloadFromQueue($this->id);
	}
	
	protected function notifyProcessor($signal) {
		Zotero_Sync::notifyDownloadProcessor($signal);
	}
}

class Zotero_Upload_Processor extends Zotero_Processor {
	protected function log($msg) {
		Z_Log::log(Z_CONFIG::$SYNC_PROCESSOR_LOG_TARGET_UPLOAD, "[" . $this->id . "] $msg");
	}
	
	protected function processFromQueue() {
		return Zotero_Sync::processUploadFromQueue($this->id);
	}
	
	protected function notifyProcessor($signal) {
		Zotero_Sync::notifyUploadProcessor($signal);
	}
}

class Zotero_Error_Processor extends Zotero_Processor {
	protected function log($msg) {
		Z_Log::log(Z_CONFIG::$SYNC_PROCESSOR_LOG_TARGET_ERROR, "[" . $this->id . "] $msg");
	}
	
	protected function processFromQueue() {
		return Zotero_Sync::checkUploadForErrors($this->id);
	}
	
	protected function notifyProcessor($signal) {
		// Tell the upload processor a process is available
		Zotero_Sync::notifyUploadProcessor('NEXT');
		Zotero_Sync::notifyErrorProcessor($signal);
	}
}

class Zotero_Index_Processor extends Zotero_Processor {
	protected function log($msg) {
		Z_Log::log(Z_CONFIG::$PROCESSOR_LOG_TARGET_INDEX, "[" . $this->id . "] $msg");
	}
	
	protected function processFromQueue() {
		return Zotero_Solr::processFromQueue($this->id);
	}
	
	protected function notifyProcessor($signal) {
		Zotero_Solr::notifyProcessor($signal);
	}
}
?>
