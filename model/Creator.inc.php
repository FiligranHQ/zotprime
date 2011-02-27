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

class Zotero_Creator {
	private $id;
	private $libraryID;
	private $key;
	private $creatorDataHash;
	private $firstName = '';
	private $lastName = '';
	private $shortName = '';
	private $fieldMode = 0;
	private $birthYear;
	private $dateAdded;
	private $dateModified;
	
	private $loaded = false;
	private $changed = array();
	
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
		
		if (!property_exists('Zotero_Creator', $field)) {
			throw new Exception("Zotero_Creator property '$field' doesn't exist");
		}
		
		return $this->$field;
	}
	
	
	public function __set($field, $value) {
		switch ($field) {
			case 'id':
			case 'libraryID':
			case 'key':
				if ($this->loaded) {
					trigger_error("Cannot set $field after creator is already loaded", E_USER_ERROR);
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
			$this->changed[$field] = true;
			$this->$field = $value;
		}
	}
	
	
	/**
	 * Check if creator exists in the database
	 *
	 * @return	bool			TRUE if the item exists, FALSE if not
	 */
	public function exists() {
		if (!$this->id) {
			trigger_error('$this->id not set');
		}
		
		$sql = "SELECT COUNT(*) FROM creators WHERE creatorID=?";
		return !!Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getShardIDByLibraryID($this->libraryID));
	}
	
	
	public function hasChanged() {
		return $this->changed;
	}
	
	
	public function save() {
		if (!$this->libraryID) {
			trigger_error("Library ID must be set before saving", E_USER_ERROR);
		}
		
		Zotero_Creators::editCheck($this);
		
		// If empty, move on
		if ($this->firstName === '' && $this->lastName === '') {
			throw new Exception('First and last name are empty');
		}
		
		if ($this->fieldMode == 1 && $this->firstName !== '') {
			throw new Exception('First name must be empty in single-field mode');
		}
		
		if (!$this->changed) {
			Z_Core::debug("Creator $this->id has not changed");
			return false;
		}
		
		Zotero_DB::beginTransaction();
		
		try {
			$creatorID = $this->id ? $this->id : Zotero_ID::get('creators');
			$isNew = !$this->id;
			
			Z_Core::debug("Saving creator $this->id");
			
			$key = $this->key ? $this->key : $this->generateKey();
			$creatorDataHash = Zotero_Creators::getDataHash($this, true);
			
			$timestamp = Zotero_DB::getTransactionTimestamp();
			$timestampMS = Zotero_DB::getTransactionTimestampMS();
			
			$dateAdded = $this->dateAdded ? $this->dateAdded : $timestamp;
			$dateModified = isset($this->changed['dateModified']) ? $this->dateModified : $timestamp;
			
			$fields = "creatorDataHash=?, libraryID=?, `key`=?, dateAdded=?,
						dateModified=?, serverDateModified=?, serverDateModifiedMS=?";
			$params = array(
				$creatorDataHash,
				$this->libraryID,
				$key,
				$dateAdded,
				$dateModified,
				$timestamp,
				$timestampMS
			);
			$shardID = Zotero_Shards::getByLibraryID($this->libraryID);
			
			if ($isNew) {
				$sql = "INSERT INTO creators SET creatorID=?, $fields";
				$stmt = Zotero_DB::getStatement($sql, true, $shardID);
				Zotero_DB::queryFromStatement($stmt, array_merge(array($creatorID), $params));
				Zotero_Creators::cacheLibraryKeyID($this->libraryID, $key, $creatorID);
				
				// Remove from delete log if it's there
				$sql = "DELETE FROM syncDeleteLogKeys WHERE libraryID=? AND objectType='creator' AND `key`=?";
				Zotero_DB::query($sql, array($this->libraryID, $key), $shardID);
			}
			else {
				$sql = "UPDATE creators SET $fields WHERE creatorID=?";
				$stmt = Zotero_DB::getStatement($sql, true, $shardID);
				Zotero_DB::queryFromStatement($stmt, array_merge($params, array($creatorID)));
			}
			
			// The client updates the mod time of associated items here, but
			// we don't, because either A) this is from syncing, where appropriate
			// mod times come from the client or B) the change is made through
			// $item->setCreator(), which updates the mod time.
			//
			// If the server started to make other independent creator changes,
			// linked items would need to be updated.
			
			Zotero_DB::commit();
			
			Zotero_Creators::cachePrimaryData(
				array(
					'id' => $creatorID,
					'libraryID' => $this->libraryID,
					'key' => $key,
					'dateAdded' => $dateAdded,
					'dateModified' => $dateModified,
					'creatorDataHash' => $creatorDataHash
				)
			);
		}
		catch (Exception $e) {
			Zotero_DB::rollback();
			throw ($e);
		}
		
		// If successful, set values in object
		if (!$this->id) {
			$this->id = $creatorID;
		}
		if (!$this->key) {
			$this->key = $key;
		}
		if (!$this->creatorDataHash) {
			$this->creatorDataHash = $creatorDataHash;
		}
		
		if ($isNew) {
			Zotero_Creators::cache($this);
		}
		
		// TODO: reload
		
		return $this->id;
	}
	
	
	public function equals($creator) {
		if (!$this->loaded) {
			$this->load();
		}
		
		return
			($creator->firstName === $this->firstName) &&
			($creator->lastName === $this->lastName) &&
			($creator->fieldMode == $this->fieldMode);
	}
	
	
	private function load() {
		if (!$this->libraryID) {
			throw new Exception("Library ID not set");
		}
		
		if (!$this->id && !$this->key) {
			throw new Exception("ID or key not set");
		}
		
		if ($this->id) {
			//Z_Core::debug("Loading data for creator $this->libraryID/$this->id");
			$row = Zotero_Creators::getPrimaryDataByID($this->libraryID, $this->id);
		}
		else {
			//Z_Core::debug("Loading data for creator $this->libraryID/$this->key");
			$row = Zotero_Creators::getPrimaryDataByKey($this->libraryID, $this->key);
		}
		
		$this->loaded = true;
		$this->changed = array();
		
		if (!$row) {
			return;
		}
		
		if ($row['libraryID'] != $this->libraryID) {
			throw new Exception("libraryID {$row['libraryID']} != $this->libraryID");
		}
		
		foreach ($row as $key=>$val) {
			$this->$key = $val;
		}
		
		$data = Zotero_Creators::getData($row['creatorDataHash']);
		foreach ($data as $key=>$val) {
			$this->$key = $val;
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
			
			case 'fieldMode':
				if ($value !== 0 && $value !== 1) {
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
				if ($value !== '' && !preg_match("/^[0-9]{4}\-[0-9]{2}\-[0-9]{2} ([0-1][0-9]|[2][0-3]):([0-5][0-9]):([0-5][0-9])$/", $value)) {
					$this->invalidValueError($field, $value);
				}
				break;
		}
	}
	
	
	private function generateKey() {
		return Zotero_ID::getKey();
	}
	
	
	private function invalidValueError($field, $value) {
		trigger_error("Invalid '$field' value '$value'", E_USER_ERROR);
	}
}
?>
