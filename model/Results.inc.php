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
	private $success = array();
	private $unchanged = array();
	private $failed = array();
	
	public function addSuccess($index, $key) {
		$this->success[$index] = $key;
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
	}
	
	
	public function generateReport() {
		$report = array(
			'success' => new stdClass(),
			'unchanged' => new stdClass(),
			'failed' => new stdClass()
		);
		foreach ($this->success as $index => $key) {
			$report['success']->$index = $key;
		}
		foreach ($this->unchanged as $index => $key) {
			$report['unchanged']->$index = $key;
		}
		foreach ($this->failed as $index => $error) {
			$report['failed']->$index = array(
				'key' => $error['key'],
				'code' => $error['code'],
				'message' => htmlspecialchars($error['message'])
			);
			// If key is blank, don't include it
			if ($report['failed']->{$index}['key'] === '') {
				unset($report['failed']->{$index}['key']);
			}
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
