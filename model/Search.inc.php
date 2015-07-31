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

class Zotero_Search extends Zotero_DataObject {
	protected $objectType = 'search';
	protected $dataTypesExtended = ['conditions'];
	
	protected $_name;
	protected $_dateAdded;
	protected $_dateModified;
	
	private $conditions = array();
	
	
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
		if (!$this->_libraryID) {
			throw new Exception("Library ID must be set before saving");
		}
		
		Zotero_Searches::editCheck($this, $userID);
		
		if (!$this->hasChanged()) {
			Z_Core::debug("Search $this->_id has not changed");
			return false;
		}
		
		if (!isset($this->_name) || $this->_name === '') {
			throw new Exception("Name not provided for saved search");
		}
		
		$shardID = Zotero_Shards::getByLibraryID($this->_libraryID);
		
		Zotero_DB::beginTransaction();
		
		$env = [];
		$isNew = $env['isNew'] = !$this->_id || !$this->exists();
		
		try {
			$searchID = $env['id'] = $this->_id ? $this->_id : Zotero_ID::get('savedSearches');
			
			Z_Core::debug("Saving search $this->_id");
			$key = $env['key'] = $this->_key ? $this->_key : Zotero_ID::getKey();
			
			$fields = "searchName=?, libraryID=?, `key`=?, dateAdded=?, dateModified=?,
						serverDateModified=?, version=?";
			$timestamp = Zotero_DB::getTransactionTimestamp();
			$params = array(
				$this->_name,
				$this->_libraryID,
				$key,
				$this->_dateAdded ? $this->_dateAdded : $timestamp,
				$this->_dateModified ? $this->_dateModified : $timestamp,
				$timestamp,
				Zotero_Libraries::getUpdatedVersion($this->_libraryID)
			);
			$shardID = Zotero_Shards::getByLibraryID($this->_libraryID);
			
			if ($isNew) {
				$sql = "INSERT INTO savedSearches SET searchID=?, $fields";
				$stmt = Zotero_DB::getStatement($sql, true, $shardID);
				Zotero_DB::queryFromStatement($stmt, array_merge(array($searchID), $params));
				
				// Remove from delete log if it's there
				$sql = "DELETE FROM syncDeleteLogKeys WHERE libraryID=? AND objectType='search' AND `key`=?";
				Zotero_DB::query($sql, array($this->_libraryID, $key), $shardID);
			}
			else {
				$sql = "UPDATE savedSearches SET $fields WHERE searchID=?";
				$stmt = Zotero_DB::getStatement($sql, true, $shardID);
				Zotero_DB::queryFromStatement($stmt, array_merge($params, array($searchID)));
			}
			
			if (!empty($this->changed['conditions'])) {
				if (!$isNew) {
					$sql = "DELETE FROM savedSearchConditions WHERE searchID=?";
					Zotero_DB::query($sql, $searchID, $shardID);
				}
				
				foreach ($this->conditions as $searchConditionID => $condition) {
					$sql = "INSERT INTO savedSearchConditions (searchID,
							searchConditionID, `condition`, mode, operator,
							value, required) VALUES (?,?,?,?,?,?,?)";
					$sqlParams = [
						$searchID,
						// Index search conditions from 1
						$searchConditionID + 1,
						$condition['condition'],
						$condition['mode'] ? $condition['mode'] : '',
						$condition['operator'] ? $condition['operator'] : '',
						$condition['value'] ? $condition['value'] : '',
						!empty($condition['required']) ? 1 : 0
					];
					try {
						Zotero_DB::query($sql, $sqlParams, $shardID);
					}
					catch (Exception $e) {
						$msg = $e->getMessage();
						if (strpos($msg, "Data too long for column 'value'") !== false) {
							throw new Exception("=Value '" . mb_substr($condition['value'], 0, 75) . "…' too long in saved search '" . $this->_name . "'");
						}
						throw ($e);
					}
				}
			}
			
			Zotero_DB::commit();
		}
		catch (Exception $e) {
			Zotero_DB::rollback();
			throw ($e);
		}
		
		$this->finalizeSave($env);
		
		return $isNew ? $this->_id : true;
	}
	
	
	public function updateConditions($conditions) {
		$this->loadPrimaryData();
		$this->loadConditions();
		
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
			$this->changed['conditions'] = true;
		}
		if (!empty($this->changed['conditions']) || sizeOf($this->conditions) > $conditions) {
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
		$this->loadPrimaryData();
		$this->loadConditions();
		
		return isset($this->conditions[$searchConditionID])
			? $this->conditions[$searchConditionID] : false;
	}
	
	
	/**
	  * Returns a multidimensional array of conditions/mode/operator/value sets
	  * used in the search, indexed by searchConditionID
	  */
	public function getSearchConditions() {
		$this->loadPrimaryData();
		$this->loadConditions();
		
		return $this->conditions;
	}
	
	
	public function toResponseJSON($requestParams=[], Zotero_Permissions $permissions) {
		$t = microtime(true);
		
		$this->loadPrimaryData();
		
		$json = [
			'key' => $this->key,
			'version' => $this->version,
			'library' => Zotero_Libraries::toJSON($this->libraryID)
		];
		
		// 'links'
		$json['links'] = [
			'self' => [
				'href' => Zotero_API::getSearchURI($this),
				'type' => 'application/json'
			]/*,
			'alternate' => [
				'href' => Zotero_URI::getSearchURI($this, true),
				'type' => 'text/html'
			]*/
		];

		
		// 'include'
		$include = $requestParams['include'];
		foreach ($include as $type) {
			if ($type == 'data') {
				$json[$type] = $this->toJSON($requestParams);
			}
		}
		
		return $json;
	}
	
	
	public function toJSON(array $requestParams=[]) {
		$this->loadPrimaryData();
		$this->loadConditions();
		
		if ($requestParams['v'] >= 3) {
			$arr['key'] = $this->key;
			$arr['version'] = $this->version;
		}
		else {
			$arr['searchKey'] = $this->key;
			$arr['searchVersion'] = $this->version;
		}
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
		
		return $arr;
	}
	
	
	/**
	 * Generate a SimpleXMLElement Atom object for the search
	 *
	 * @param array $queryParams
	 * @return SimpleXMLElement
	 */
	public function toAtom($queryParams) {
		$this->loadPrimaryData();
		$this->loadConditions();
		
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
		$author->uri = Zotero_URI::getLibraryURI($this->libraryID, true);
		
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
			$xml->content = Zotero_Utilities::formatJSON($this->toJSON($queryParams));
		}
		
		return $xml;
	}
	
	
	protected function loadConditions($reload = false) {
		if (!$this->identified) return;
		if ($this->loaded['conditions'] && !$reload) return;
		
		$sql = "SELECT * FROM savedSearchConditions
				WHERE searchID=? ORDER BY searchConditionID";
		$conditions = Zotero_DB::query(
			$sql, $this->_id, Zotero_Shards::getByLibraryID($this->_libraryID)
		);
		
		$this->conditions = [];
		foreach ($conditions as $condition) {
			$searchConditionID = $condition['searchConditionID'];
			$this->conditions[$searchConditionID] = [
				'id' => $searchConditionID,
				'condition' => $condition['condition'],
				'mode' => $condition['mode'],
				'operator' => $condition['operator'],
				'value' => $condition['value'],
				'required' => $condition['required']
			];
		}
		
		$this->loaded['conditions'] = true;
		$this->clearChanged('conditions');
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
