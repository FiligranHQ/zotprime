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
	private $successes = array();
	private $failures = array();
	
	public function addSuccess($index, $key) {
		$this->successes[$index] = $key;
	}
	
	
	public function addFailure($index, $key, Exception $e) {
		if (isset($this->failures[$index])) {
			throw new Exception("Duplicate index '$index' for failure with key '$key'");
		}
		$this->failures[$index] = Zotero_Errors::parseException($e);
		$this->failures[$index]['key'] = $key;
	}
	
	
	public function generateReport() {
		$report = array(
			'success' => new stdClass(),
			'failed' => new stdClass()
		);
		foreach ($this->successes as $index => $key) {
			$report['success']->$index = $key;
		}
		foreach ($this->failures as $index => $error) {
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
		if (!$this->failures) {
			return "";
		}
		
		$str = "";
		foreach ($this->failures as $error) {
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
