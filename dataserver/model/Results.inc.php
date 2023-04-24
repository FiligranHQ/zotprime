<?php
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2013 Center for History and New Media
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
class Zotero_Results {
	private $requestParams;
	private $successful = [];
	private $success = []; // Deprecated
	private $unchanged = [];
	private $failed = [];
	
	public function __construct(array $requestParams) {
		$this->requestParams = $requestParams;
	}
	
	
	public function addSuccessful($index, $obj) {
		if ($this->requestParams['v'] >= 3) {
			$this->successful[$index] = $obj;
		}
		// Deprecated
		$this->success[$index] = $obj['key'];
	}
	
	
	public function addUnchanged($index, $key) {
		$this->unchanged[$index] = $key;
	}
	
	
	public function addFailure($index, $key, Exception $e) {
		if (isset($this->failed[$index])) {
			throw new Exception("Duplicate index '$index' for failure with key '$key'");
		}
		$this->failed[$index] = Zotero_Errors::parseException($e);
		$this->failed[$index]['key'] = $key;
		return $this->failed[$index];
	}
	
	
	public function generateReport() {
		if ($this->requestParams['v'] >= 3) {
			$report = [
				'successful' => new stdClass(),
				'success' => new stdClass(),
				'unchanged' => new stdClass(),
				'failed' => new stdClass()
			];
		}
		else {
			$report = [
				'success' => new stdClass(),
				'unchanged' => new stdClass(),
				'failed' => new stdClass()
			];
		}
		foreach ($this->successful as $index => $key) {
			$report['successful']->$index = $key;
		}
		// Deprecated
		foreach ($this->success as $index => $key) {
			$report['success']->$index = $key;
		}
		foreach ($this->unchanged as $index => $key) {
			$report['unchanged']->$index = $key;
		}
		foreach ($this->failed as $index => $error) {
			$obj = [
				'key' => $error['key'],
				'code' => $error['code'],
				'message' => htmlspecialchars($error['message'])
			];
			if (isset($error['data'])) {
				$obj['data'] = $error['data'];
			}
			// If key is blank, don't include it
			if ($obj['key'] === '') {
				unset($obj['key']);
			}
			
			$report['failed']->$index = $obj;
		}
		return $report;
	}
	
	
	public function generateLogMessage() {
		if (!$this->failed) {
			return "";
		}
		
		$str = "";
		foreach ($this->failed as $error) {
			if (!$error['log']) {
				continue;
			}
			$str .= "Code: " . $error['code'] . "\n";
			$str .= "Key: " . $error['key'] . "\n";
			$str .= $error['exception'] . "\n\n";
		}
		return $str;
	}
}
