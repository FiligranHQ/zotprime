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

class Zotero_Setting {
	private $libraryID;
	private $name;
	private $value;
	private $version = 0;
	
	private $loaded;
	private $changed;
	
	
	public function __get($prop) {
		if ($this->name && !$this->loaded) {
			$this->load();
		}
		
		if (!property_exists('Zotero_Setting', $prop)) {
			throw new Exception("Zotero_Setting property '$prop' doesn't exist");
		}
		
		return $this->$prop;
	}
	
	
	public function __set($prop, $value) {
		switch ($prop) {
			case 'version':
				throw new Exception("Cannot modify version");
			
			case 'libraryID':
			case 'name':
				if ($this->loaded) {
					throw new Exception("Cannot set $prop after setting is already loaded");
				}
				$this->checkProperty($prop, $value);
				$this->$prop = $value;
				return;
		}
		
		if ($this->name) {
			if (!$this->loaded) {
				$this->load();
			}
		}
		else {
			$this->loaded = true;
		}
		
		$this->checkProperty($prop, $value);
		
		if ($this->$prop != $value) {
			//Z_Core::debug("Setting property '$prop' has changed from '{$this->$prop}' to '$value'");
			$this->changed = true;
			$this->$prop = $value;
		}
	}
	
	
	/**
	 * Check if setting exists in the database
	 *
	 * @return bool TRUE if the setting exists, FALSE if not
	 */
	public function exists() {
		$sql = "SELECT COUNT(*) FROM settings WHERE libraryID=? AND name=?";
		return !!Zotero_DB::valueQuery(
			$sql,
			array($this->libraryID, $this->name),
			Zotero_Shards::getByLibraryID($this->libraryID)
		);
	}
	
	
	/**
	 * Save the setting to the DB
	 */
	public function save($userID=false) {
		if (!$this->libraryID) {
			throw new Exception("libraryID not set");
		}
		if (!isset($this->name) || $this->name === '') {
			throw new Exception("Setting name not provided");
		}
		
		try {
			Zotero_Settings::editCheck($this, $userID);
		}
		// TEMP: Ignore this for now, since there seems to be a client bug as of 4.0.17 that can
		// cause settings from deleted libraries to remain
		catch (Exception $e) {
			error_log("WARNING: " . $e);
			return false;
		}
		
		if (!$this->changed) {
			Z_Core::debug("Setting $this->libraryID/$this->name has not changed");
			return false;
		}
		
		$shardID = Zotero_Shards::getByLibraryID($this->libraryID);
		
		Zotero_DB::beginTransaction();
		
		$isNew = !$this->exists();
		
		try {
			Z_Core::debug("Saving setting $this->libraryID/$this->name");
			
			$params = array(
				json_encode($this->value),
				Zotero_Libraries::getUpdatedVersion($this->libraryID),
				Zotero_DB::getTransactionTimestamp()
			);
			$params = array_merge(array($this->libraryID, $this->name), $params, $params);
			
			$shardID = Zotero_Shards::getByLibraryID($this->libraryID);
			
			$sql = "INSERT INTO settings (libraryID, name, value, version, lastUpdated) "
				. "VALUES (?, ?, ?, ?, ?) "
				. "ON DUPLICATE KEY UPDATE value=?, version=?, lastUpdated=?";
			Zotero_DB::query($sql, $params, $shardID);
			
			// Remove from delete log if it's there
			$sql = "DELETE FROM syncDeleteLogKeys WHERE libraryID=? AND objectType='setting' AND `key`=?";
			Zotero_DB::query($sql, array($this->libraryID, $this->name), $shardID);
			
			Zotero_DB::commit();
		}
		catch (Exception $e) {
			Zotero_DB::rollback();
			throw ($e);
		}
		
		return true;
	}
	
	
	public function toJSON($asArray=false, $requestParams=array()) {
		if (!$this->loaded) {
			$this->load();
		}
		
		$arr = array(
			'value' => $this->value,
			'version' => $this->version
		);
		
		if ($asArray) {
			return $arr;
		}
		
		return Zotero_Utilities::formatJSON($arr);
	}
	
	
	private function load() {
		$libraryID = $this->libraryID;
		$name = $this->name;
		
		Z_Core::debug("Loading data for setting $libraryID/$name");
		
		if (!$libraryID) {
			throw new Exception("Library ID not set");
		}
		
		if (!$name) {
			throw new Exception("Name not set");
		}
		
		$row = Zotero_Settings::getPrimaryDataByKey($libraryID, $name);
		
		$this->loaded = true;
		
		if (!$row) {
			return;
		}
		
		foreach ($row as $key => $val) {
			if ($key == 'value') {
				$val = json_decode($val);
			}
			$this->$key = $val;
		}
	}
	
	
	private function checkProperty($prop, $val) {
		if (!property_exists($this, $prop)) {
			throw new Exception("Invalid property '$prop'");
		}
		
		// Data validation
		switch ($prop) {
			case 'libraryID':
				if (!Zotero_Utilities::isPosInt($val)) {
					throw new Exception("Invalid '$prop' value '$value'");
				}
				break;
			
			case 'name':
				switch ($val) {
				case 'tagColors':
					break;
					
				default:
					throw new Exception("Invalid setting '$val'", Z_ERROR_INVALID_INPUT);
				}
				break;
			
			case 'value':
				Zotero_Settings::checkSettingValue($this->name, $val);
				break;
		}
	}
}
?>
