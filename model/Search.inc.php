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
	
	private $loaded;
	private $changed;
	
	private $conditions = array();
	private $maxSearchConditionID;
	//private $sql;
	//private $sqlParams;
	
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
	 * If there are gaps in the searchConditionIDs, |fixGaps| must be true
	 * and the caller must dispose of the search or reload the condition ids,
	 * which may change after the save.
	 *
	 * For new searches, setName() must be called before saving
	 */
	public function save($fixGaps=false) {
		if (!$this->libraryID) {
			throw new Exception("Library ID must be set before saving");
		}
		
		Zotero_Searches::editCheck($this);
		
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
			
			$key = $this->key ? $this->key : $this->generateKey();
			
			$fields = "searchName=?, libraryID=?, `key`=?, dateAdded=?, dateModified=?,
						serverDateModified=?";
			$timestamp = Zotero_DB::getTransactionTimestamp();
			$params = array(
				$this->name,
				$this->libraryID,
				$key,
				$this->dateAdded ? $this->dateAdded : $timestamp,
				$this->dateModified ? $this->dateModified : $timestamp,
				$timestamp
			);
			$shardID = Zotero_Shards::getByLibraryID($this->libraryID);
			
			if ($isNew) {
				$sql = "INSERT INTO savedSearches SET searchID=?, $fields";
				$stmt = Zotero_DB::getStatement($sql, true, $shardID);
				Zotero_DB::queryFromStatement($stmt, array_merge(array($searchID), $params));
				Zotero_Searches::cacheLibraryKeyID($this->libraryID, $key, $searchID);
				
				// Remove from delete log if it's there
				$sql = "DELETE FROM syncDeleteLogKeys WHERE libraryID=? AND objectType='search' AND `key`=?";
				Zotero_DB::query($sql, array($this->libraryID, $key), $shardID);
			}
			else {
				$sql = "UPDATE savedSearches SET $fields WHERE searchID=?";
				$stmt = Zotero_DB::getStatement($sql, true, $shardID);
				Zotero_DB::queryFromStatement($stmt, array_merge($params, array($searchID)));
			}
			
			// Close gaps in savedSearchIDs
			$saveConditions = array();
			$i = 1;
			
			foreach ($this->conditions as $id=>$condition) {
				if (!$fixGaps && $id != $i) {
					trigger_error('searchConditionIDs not contiguous and |fixGaps| not set in save() of saved search ' . $this->id, E_USER_ERROR);
				}
				$saveConditions[$i] = $condition;
				$i++;
			}
			
			$this->conditions = $saveConditions;
			
			// TODO: use proper bound parameters once DB class is updated
			foreach ($this->conditions as $searchConditionID => $condition) {
				$sql = "INSERT INTO savedSearchConditions (searchID,
						searchConditionID, `condition`, mode, operator,
						value, required) VALUES (?,?,?,?,?,?,?)";
				
				$sqlParams = array(
					$searchID, $searchConditionID,
					$condition['condition'],
					$condition['mode'] ? $condition['mode'] : '',
					$condition['operator'] ? $condition['operator'] : '',
					$condition['value'] ? $condition['value'] : '',
					$condition['required'] ? 1 : 0
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
		
		// If successful, set values in object
		if (!$this->id) {
			$this->id = $searchID;
		}
		if (!$this->key) {
			$this->key = $key;
		}
		
		return $this->id;
	}
	
	
	public function addCondition($condition, $mode, $operator, $value, $required) {
		if ($this->id && !$this->loaded) {
			$this->load(false);
		}
		
		/*
		if (!Zotero_SearchConditions.hasOperator(condition, operator)){
			throw ("Invalid operator '" . operator . "' for condition " . condition);
		}
		*/
		
		$searchConditionID = ++$this->maxSearchConditionID;
		
		$this->conditions[$searchConditionID] = array(
			'id' => $searchConditionID,
			'condition' => $condition,
			'mode' => $mode,
			'operator' => $operator,
			'value' => $value,
			'required' => $required
		);
		
		$this->changed = true;
		
		//$this->sql = null;
		//$this->sqlParams = null;
		return $searchConditionID;
	}
	
	
	public function updateCondition($searchConditionID, $condition, $mode, $operator, $value, $required) {
		if ($this->id && !$this->loaded) {
			$this->load(false);
		}
		
		if (!isset($this->conditions[$searchConditionID])) {
			trigger_error("Invalid searchConditionID $searchConditionID", E_USER_ERROR);
		}
		
		/*
		if (!Zotero_SearchConditions::hasOperator($condition, $operator)) {
			trigger_error("Invalid operator $operator", E_USER_ERROR);
		}
		*/
		
		$existingCondition = $this->conditions[$searchConditionID];
		
		if ($existingCondition['condition'] == $condition
				&& $existingCondition['mode'] == $mode
				&& $existingCondition['operator'] == $operator
				&& $existingCondition['value'] == $value
				&& $existingCondition['required'] == $required) {
			Z_Core::debug("Condition $searchConditionID for search
				$this->id has not changed");
			return;
		}
		
		$this->conditions[$searchConditionID] = array(
			'id' => $searchConditionID,
			'condition' => $condition,
			'mode' => $mode,
			'operator' => $operator,
			'value' => $value,
			'required' => $required
		);
		
		$this->changed = true;
		
		//$this->sql = null;
		//$this->sqlParams = null;
	}
	
	
	public function removeCondition($searchConditionID) {
		if (!isset($this->conditions[$searchConditionID])) {
			trigger_error("Invalid searchConditionID $searchConditionID", E_USER_ERROR);
		}
		unset($this->conditions[$searchConditionID]);
		$this->changed = true;
	}
	
	
	/**
	  * Returns an array with 'condition', 'mode', 'operator', 'value', 'required'
	  * for the given searchConditionID
	  */
	public function getSearchCondition($searchConditionID) {
		if ($this->id && !$this->loaded) {
			$this->load(false);
		}
		
		return isset($this->conditions[$searchConditionID]) ? $this->conditions[$searchConditionID] : false;
	}
	
	
	/**
	  * Returns a multidimensional array of conditions/mode/operator/value sets
	  * used in the search, indexed by searchConditionID
	  */
	public function getSearchConditions() {
		if ($this->id && !$this->loaded) {
			$this->load(false);
		}
		
		return $this->conditions;
	}
	
	
	private function load() {
		//Z_Core::debug("Loading data for search $this->id");
		
		if (!$this->libraryID) {
			throw new Exception("Library ID not set");
		}
		
		if (!$this->id && !$this->key) {
			throw new Exception("ID or key not set");
		}
		
		$shardID = Zotero_Shards::getByLibraryID($this->libraryID);
		
		$sql = "SELECT searchID AS id, searchName AS name, dateAdded, dateModified, libraryID, `key`,
				MAX(searchConditionID) AS maxSearchConditionID FROM savedSearches
				LEFT JOIN savedSearchConditions USING (searchID) WHERE ";
		if ($this->id) {
			$sql .= "searchID=?";
			$params = $this->id;
		}
		else {
			$sql .= "libraryID=? AND `key`=?";
			$params = array($this->libraryID, $this->key);
		}
		$sql .= " GROUP BY searchID";
		$data = Zotero_DB::rowQuery($sql, $params, $shardID);
		
		$this->loaded = true;
		
		if (!$data) {
			return;
		}
		
		foreach ($data as $key=>$val) {
			$this->$key = $val;
		}
		
		$sql = "SELECT * FROM savedSearchConditions
				WHERE searchID=? ORDER BY searchConditionID";
		$conditions = Zotero_DB::query($sql, $this->id, $shardID);
		
		foreach ($conditions as $condition) {
			/*
			if (!Zotero.SearchConditions.get(condition)){
				Zotero.debug("Invalid saved search condition '"
					+ condition + "' -- skipping", 2);
				continue;
			}
			*/
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
				if (!preg_match('/^[23456789ABCDEFGHIJKMNPQRSTUVWXTZ]{8}$/', $value)) {
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
