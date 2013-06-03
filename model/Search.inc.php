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

class Zotero_Search {
	private $id;
	private $libraryID;
	private $key;
	private $name;
	private $dateAdded;
	private $dateModified;
	private $version;
	
	private $conditions = array();
	
	private $loaded;
	private $changed;
	
	
	public function __construct() {
		$numArgs = func_num_args();
		if ($numArgs) {
			throw new Exception("Constructor doesn't take any parameters");
		}
	}
	
	
	public function __get($field) {
		if (($this->id || $this->key) && !$this->loaded) {
			$this->load(true);
		}
		
		if (!property_exists('Zotero_Search', $field)) {
			throw new Exception("Zotero_Search property '$field' doesn't exist");
		}
		
		return $this->$field;
	}
	
	
	public function __set($field, $value) {
		switch ($field) {
			case 'id':
			case 'libraryID':
			case 'key':
				if ($this->loaded) {
					throw new Exception("Cannot set $field after search is already loaded");
				}
				$this->checkValue($field, $value);
				$this->$field = $value;
				return;
		}
		
		if ($this->id || $this->key) {
			if (!$this->loaded) {
				$this->load(true);
			}
		}
		else {
			$this->loaded = true;
		}
		
		$this->checkValue($field, $value);
		
		if ($this->$field != $value) {
			//Z_Core::debug("Search field '$field' has changed from '{$this->$field}' to '$value'");
			$this->changed = true;
			$this->$field = $value;
		}
	}
	
	
	/**
	 * Check if search exists in the database
	 *
	 * @return	bool			TRUE if the item exists, FALSE if not
	 */
	public function exists() {
		if (!$this->id) {
			trigger_error('$this->id not set');
		}
		
		$sql = "SELECT COUNT(*) FROM savedSearches WHERE searchID=?";
		return !!Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
	}
	
	
	/*
	 * Save the search to the DB and return a savedSearchID
	 *
	 * For new searches, setName() must be called before saving
	 */
	public function save($userID=false) {
		if (!$this->libraryID) {
			throw new Exception("Library ID must be set before saving");
		}
		
		Zotero_Searches::editCheck($this, $userID);
		
		if (!$this->changed) {
			Z_Core::debug("Search $this->id has not changed");
			return false;
		}
		
		if (!isset($this->name) || $this->name === '') {
			throw new Exception("Name not provided for saved search");
		}
		
		$shardID = Zotero_Shards::getByLibraryID($this->libraryID);
		
		Zotero_DB::beginTransaction();
		
		$isNew = !$this->id || !$this->exists();
		
		try {
			$searchID = $this->id ? $this->id : Zotero_ID::get('savedSearches');
			
			Z_Core::debug("Saving search $this->id");
			
			if (!$isNew) {
				$sql = "DELETE FROM savedSearchConditions WHERE searchID=?";
				Zotero_DB::query($sql, $searchID, $shardID);
			}
			
			$key = $this->key ? $this->key : Zotero_ID::getKey();
			
			$fields = "searchName=?, libraryID=?, `key`=?, dateAdded=?, dateModified=?,
						serverDateModified=?, version=?";
			$timestamp = Zotero_DB::getTransactionTimestamp();
			$params = array(
				$this->name,
				$this->libraryID,
				$key,
				$this->dateAdded ? $this->dateAdded : $timestamp,
				$this->dateModified ? $this->dateModified : $timestamp,
				$timestamp,
				Zotero_Libraries::getUpdatedVersion($this->libraryID)
			);
			$shardID = Zotero_Shards::getByLibraryID($this->libraryID);
			
			if ($isNew) {
				$sql = "INSERT INTO savedSearches SET searchID=?, $fields";
				$stmt = Zotero_DB::getStatement($sql, true, $shardID);
				Zotero_DB::queryFromStatement($stmt, array_merge(array($searchID), $params));
				
				// Remove from delete log if it's there
				$sql = "DELETE FROM syncDeleteLogKeys WHERE libraryID=? AND objectType='search' AND `key`=?";
				Zotero_DB::query($sql, array($this->libraryID, $key), $shardID);
			}
			else {
				$sql = "UPDATE savedSearches SET $fields WHERE searchID=?";
				$stmt = Zotero_DB::getStatement($sql, true, $shardID);
				Zotero_DB::queryFromStatement($stmt, array_merge($params, array($searchID)));
			}
			
			foreach ($this->conditions as $searchConditionID => $condition) {
				$sql = "INSERT INTO savedSearchConditions (searchID,
						searchConditionID, `condition`, mode, operator,
						value, required) VALUES (?,?,?,?,?,?,?)";
				$sqlParams = array(
					$searchID,
					// Index search conditions from 1
					$searchConditionID + 1,
					$condition['condition'],
					$condition['mode'] ? $condition['mode'] : '',
					$condition['operator'] ? $condition['operator'] : '',
					$condition['value'] ? $condition['value'] : '',
					!empty($condition['required']) ? 1 : 0
				);
				try {
					Zotero_DB::query($sql, $sqlParams, $shardID);
				}
				catch (Exception $e) {
					$msg = $e->getMessage();
					if (strpos($msg, "Data too long for column 'value'") !== false) {
						throw new Exception("=Value '" . mb_substr($condition['value'], 0, 75) . "…' too long in saved search '" . $this->name . "'");
					}
					throw ($e);
				}
			}
			
			Zotero_DB::commit();
		}
		catch (Exception $e) {
			Zotero_DB::rollback();
			throw ($e);
		}
		
		if (!$this->id) {
			$this->id = $searchID;
		}
		if (!$this->key) {
			$this->key = $key;
		}
		
		return $this->id;
	}
	
	
	public function updateConditions($conditions) {
		if ($this->id && !$this->loaded) {
			$this->load();
		}
		for ($i = 1, $len = sizeOf($conditions); $i <= $len; $i++) {
			// Compare existing values to new values
			if (isset($this->conditions[$i])) {
				if ($this->conditions[$i]['condition'] == $conditions[$i - 1]['condition']
						&& $this->conditions[$i]['mode'] == $conditions[$i - 1]['mode']
						&& $this->conditions[$i]['operator'] == $conditions[$i - 1]['operator']
						&& $this->conditions[$i]['value'] == $conditions[$i - 1]['value']) {
					continue;
				}
			}
			$this->changed = true;
		}
		if ($this->changed || sizeOf($this->conditions) > $conditions) {
			$this->conditions = $conditions;
		}
		else {
			Z_Core::debug("Conditions have not changed for search $this->id");
		}
	}
	
	
	/**
	  * Returns an array with 'condition', 'mode', 'operator', and 'value'
	  * for the given searchConditionID
	  */
	public function getSearchCondition($searchConditionID) {
		if ($this->id && !$this->loaded) {
			$this->load();
		}
		
		return isset($this->conditions[$searchConditionID])
			? $this->conditions[$searchConditionID] : false;
	}
	
	
	/**
	  * Returns a multidimensional array of conditions/mode/operator/value sets
	  * used in the search, indexed by searchConditionID
	  */
	public function getSearchConditions() {
		if ($this->id && !$this->loaded) {
			$this->load();
		}
		
		return $this->conditions;
	}
	
	
	public function toJSON($asArray=false, $prettyPrint=false) {
		if (!$this->loaded) {
			$this->load();
		}
		
		$arr['searchKey'] = $this->key;
		$arr['searchVersion'] = $this->version;
		$arr['name'] = $this->name;
		$arr['conditions'] = array();
		
		foreach ($this->conditions as $condition) {
			$arr['conditions'][] = array(
				'condition' => $condition['condition']
					. ($condition['mode'] ? "/{$condition['mode']}" : ""),
				'operator' => $condition['operator'],
				'value' => $condition['value']
			);
		}
		
		if ($asArray) {
			return $arr;
		}
		
		return Zotero_Utilities::formatJSON($arr, $prettyPrint);
	}
	
	
	/**
	 * Generate a SimpleXMLElement Atom object for the search
	 *
	 * @param array $queryParams
	 * @return SimpleXMLElement
	 */
	public function toAtom($queryParams) {
		if (!$this->loaded) {
			$this->load();
		}
		
		// TEMP: multi-format support
		if (!empty($queryParams['content'])) {
			$content = $queryParams['content'];
		}
		else {
			$content = array('none');
		}
		$content = $content[0];
		
		$xml = new SimpleXMLElement(
			'<?xml version="1.0" encoding="UTF-8"?>'
			. '<entry xmlns="' . Zotero_Atom::$nsAtom
			. '" xmlns:zapi="' . Zotero_Atom::$nsZoteroAPI . '"/>'
		);
		
		$xml->title = $this->name ? $this->name : '[Untitled]';
		
		$author = $xml->addChild('author');
		// TODO: group item creator
		$author->name = Zotero_Libraries::getName($this->libraryID);
		$author->uri = Zotero_URI::getLibraryURI($this->libraryID);
		
		$xml->id = Zotero_URI::getSearchURI($this);
		
		$xml->published = Zotero_Date::sqlToISO8601($this->dateAdded);
		$xml->updated = Zotero_Date::sqlToISO8601($this->dateModified);
		
		$link = $xml->addChild("link");
		$link['rel'] = "self";
		$link['type'] = "application/atom+xml";
		$link['href'] = Zotero_API::getSearchURI($this);
		
		$xml->addChild('zapi:key', $this->key, Zotero_Atom::$nsZoteroAPI);
		$xml->addChild('zapi:version', $this->version, Zotero_Atom::$nsZoteroAPI);
		
		if ($content == 'json') {
			$xml->content['type'] = 'application/json';
			$xml->content = $this->toJSON();
		}
		
		return $xml;
	}
	
	
	private function load() {
		$libraryID = $this->libraryID;
		$id = $this->id;
		$key = $this->key;
		
		Z_Core::debug("Loading data for search " . ($id ? $id : $key));
		
		if (!$libraryID) {
			throw new Exception("Library ID not set");
		}
		
		if (!$id && !$key) {
			throw new Exception("ID or key not set");
		}
		
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		
		$sql = "SELECT searchID AS id, searchName AS name, dateAdded,
				dateModified, libraryID, `key`, version
				FROM savedSearches WHERE ";
		if ($id) {
			$sql .= "searchID=?";
			$params = $id;
		}
		else {
			$sql .= "libraryID=? AND `key`=?";
			$params = array($libraryID, $key);
		}
		$sql .= " GROUP BY searchID";
		$data = Zotero_DB::rowQuery($sql, $params, $shardID);
		
		$this->loaded = true;
		
		if (!$data) {
			return;
		}
		
		foreach ($data as $key => $val) {
			$this->$key = $val;
		}
		
		$sql = "SELECT * FROM savedSearchConditions
				WHERE searchID=? ORDER BY searchConditionID";
		$conditions = Zotero_DB::query($sql, $this->id, $shardID);
		
		foreach ($conditions as $condition) {
			
			$searchConditionID = $condition['searchConditionID'];
			$this->conditions[$searchConditionID] = array(
				'id' => $searchConditionID,
				'condition' => $condition['condition'],
				'mode' => $condition['mode'],
				'operator' => $condition['operator'],
				'value' => $condition['value'],
				'required' => $condition['required']
			);
		}
	}
	
	
	private function checkValue($field, $value) {
		if (!property_exists($this, $field)) {
			trigger_error("Invalid property '$field'", E_USER_ERROR);
		}
		
		// Data validation
		switch ($field) {
			case 'id':
			case 'libraryID':
				if (!Zotero_Utilities::isPosInt($value)) {
					$this->invalidValueError($field, $value);
				}
				break;
			
			case 'key':
				if (!Zotero_ID::isValidKey($value)) {
					$this->invalidValueError($field, $value);
				}
				break;
			
			case 'dateAdded':
			case 'dateModified':
				if (!preg_match("/^[0-9]{4}\-[0-9]{2}\-[0-9]{2} ([0-1][0-9]|[2][0-3]):([0-5][0-9]):([0-5][0-9])$/", $value)) {
					$this->invalidValueError($field, $value);
				}
				break;
		}
	}

}
?>
