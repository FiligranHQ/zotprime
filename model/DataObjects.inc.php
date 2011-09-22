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

class Zotero_DataObjects {
	public static $objectTypes = array(
		'creator' => array('singular'=>'Creator', 'plural'=>'Creators'),
		'item' => array('singular'=>'Item', 'plural'=>'Items'),
		'collection' => array('singular'=>'Collection', 'plural'=>'Collections'),
		'search' => array('singular'=>'Search', 'plural'=>'Searches'),
		'tag' => array('singular'=>'Tag', 'plural'=>'Tags'),
		'relation' => array('singular'=>'Relation', 'plural'=>'Relations')
	);
	
	protected static $ZDO_object = '';
	protected static $ZDO_objects = '';
	protected static $ZDO_Object = '';
	protected static $ZDO_Objects = '';
	protected static $ZDO_id = '';
	protected static $ZDO_table = '';
	
	private static $cacheVersion = 1;
	
	private static $idCache = array();
	private static $idCacheIsFromMemcached = array();
	private static $primaryDataByID = array();
	private static $primaryDataByKey = array();
	
	public static function field($field) {
		if (empty(static::$ZDO_object)) {
			trigger_error("Object name not provided", E_USER_ERROR);
		}
		
		switch ($field) {
			case 'object':
				return static::$ZDO_object;
			
			case 'objects':
				return static::$ZDO_objects
					? static::$ZDO_objects : static::$ZDO_object . 's';
			
			case 'Object':
				return ucwords(static::field('object'));
			
			case 'Objects':
				return ucwords(static::field('objects'));
			
			case 'id':
				return static::$ZDO_id ? static::$ZDO_id : static::$ZDO_object . 'ID';
			
			case 'table':
				return static::$ZDO_table ? static::$ZDO_table : static::field('objects');
		}
	}
	
	
	public static function getByLibraryAndKey($libraryID, $key) {
		$type = static::field('object');
		$types = static::field('objects');
		
		$exists = self::existsByLibraryAndKey($libraryID, $key);
		if (!$exists) {
			return false;
		}
		
		switch ($type) {
			case 'item':
			case 'relation':
				$className = "Zotero_" . ucwords($types);
				return call_user_func(array($className, 'get'), $libraryID, self::$idCache[$type][$libraryID][$key]);
			
			// Pass skipCheck, since we've already checked for existence
			case 'collection':
			case 'creator':
			case 'tag':
				$className = "Zotero_" . ucwords($types);
				return call_user_func(array($className, 'get'), $libraryID, self::$idCache[$type][$libraryID][$key], true);
			
			default:
				$className = "Zotero_" . ucwords($type);
				$obj = new $className;
				$obj->libraryID = $libraryID;
				$obj->id = self::$idCache[$type][$libraryID][$key];
				return $obj;
		}
	}
	
	
	public static function existsByLibraryAndKey($libraryID, $key) {
		if (!$libraryID || !is_numeric($libraryID)) {
			throw new Exception("libraryID '$libraryID' must be a positive integer");
		}
		
		$table = static::field('table');
		$id = static::field('id');
		$type = static::field('object');
		
		if ($type == 'relation') {
			if (!preg_match('/[a-f0-9]{32}/', $key)) {
				throw new Exception("Invalid key '$key'");
			}
		}
		else if (!preg_match('/[A-Z0-9]{8}/', $key)) {
			throw new Exception("Invalid key '$key'");
		}
		
		if (!isset(self::$idCache[$type])) {
			self::$idCache[$type] = array();
		}
		
		// Cache object ids in library if not done yet
		if (!isset(self::$idCache[$type][$libraryID])) {
			self::$idCache[$type][$libraryID] = array();
			
			$cacheKey = $type . 'IDsByKey_' . $libraryID . "_" . str_replace(" ", "_", Zotero_Libraries::getTimestamp($libraryID, true));
			$ids = Z_Core::$MC->get($cacheKey);
			if ($ids === false) {
				if ($type == 'relation') {
					$sql = "SELECT $id AS id, MD5(CONCAT(subject, '_', predicate, '_', object)) AS `key` FROM $table WHERE libraryID=?";
				}
				else {
					$sql = "SELECT $id AS id, `key` FROM $table WHERE libraryID=?";
				}
				$rows = Zotero_DB::query($sql, $libraryID, Zotero_Shards::getByLibraryID($libraryID));
				
				if (!$rows) {
					return false;
				}
				
				foreach ($rows as $row) {
					self::$idCache[$type][$libraryID][$row['key']] = $row['id'];
				}
				
				Z_Core::debug("Caching $cacheKey");
				Z_Core::$MC->set($cacheKey, self::$idCache[$type][$libraryID]);
			}
			else {
				self::$idCache[$type][$libraryID] = $ids;
			}
		}
		
		return isset(self::$idCache[$type][$libraryID][$key]);
	}
	
	
	/**
	 * Cache a new libraryID/key/id combination
	 *
		 * Newly created ids must be registered here or getByLibraryAndKey() won't work
	 */
	public static function cacheLibraryKeyID($libraryID, $key, $id) {
		$type = static::field('object');
		
		// Trigger caching of library object ids
		$existingObj = static::getByLibraryAndKey($libraryID, $key);
		
		// The first-inserted object in a new library will get cached by the above,
		// so only protest if the id is different
		if ($existingObj && $existingObj->id != $id) {
			throw new Exception($type . " with id " . $existingObj->id . " is already cached for library and key");
		}
		
		if (!$id || !is_numeric($id)) {
			throw new Exception("id '$id' must be a positive integer");
		}
		
		self::$idCache[$type][$libraryID][$key] = $id;
	}
	
	
	public static function clearLibraryKeyCache($libraryID) {
		$type = static::field('object');
		unset(self::$idCache[$type][$libraryID]);
	}
	
	
	public static function getPrimaryDataByID($libraryID, $id) {
		$type = static::field('object');
		
		if (!is_numeric($id)) {
			throw new Exception("Invalid id '$id'");
		}
		
		// If primary data isn't cached for library, do so now
		if (!isset(self::$primaryDataByID[$type][$libraryID])) {
			self::cachePrimaryDataByLibrary($libraryID);
		}
		
		if (!isset(self::$primaryDataByID[$type][$libraryID][$id])) {
			return false;
		}
		
		return self::$primaryDataByID[$type][$libraryID][$id];
	}
	
	
	public static function getPrimaryDataByKey($libraryID, $key) {
		$type = static::field('object');
		
		if (!is_numeric($libraryID)) {
			throw new Exception("Invalid libraryID '$libraryID'");
		}
		if (!preg_match('/[A-Z0-9]{8}/', $key)) {
			throw new Exception("Invalid key '$key'");
		}
		
		// If primary data isn't cached for library, do so now
		if (!isset(self::$primaryDataByKey[$type][$libraryID])) {
			self::cachePrimaryDataByLibrary($libraryID);
		}
		
		if (!isset(self::$primaryDataByKey[$type][$libraryID][$key])) {
			return false;
		}
		
		return self::$primaryDataByKey[$type][$libraryID][$key];
	}
	
	
	private static function cachePrimaryDataByLibrary($libraryID) {
		Z_Core::debug("Caching primary data for library $libraryID");
		
		$type = static::field('object');
		$types = static::field('objects');
		
		if (!isset(self::$primaryDataByKey[$type][$libraryID])) {
			self::$primaryDataByKey[$type][$libraryID] = array();
		}
		
		if (!isset(self::$primaryDataByID[$type][$libraryID])) {
			self::$primaryDataByID[$type][$libraryID] = array();
		}
		
		self::$primaryDataByKey[$type][$libraryID] = array();
		self::$primaryDataByID[$type][$libraryID] = array();
		
		$cacheKey = $type . "Data_" . $libraryID . "_" . str_replace(" ", "_", Zotero_Libraries::getTimestamp($libraryID, true)) . "_" . self::$cacheVersion;
		$rows = Z_Core::$MC->get($cacheKey);
		if ($rows === false) {
			$className = "Zotero_" . ucwords($types);
			$sql = self::getPrimaryDataSQL() . "libraryID=?";
			
			$shardID = Zotero_Shards::getByLibraryID($libraryID);
			$rows = Zotero_DB::query($sql, $libraryID, $shardID);
			
			Z_Core::debug("Caching $cacheKey");
			Z_Core::$MC->set($cacheKey, $rows ? $rows : array());
		}
		else {
			Z_Core::debug("Retrieved " . $cacheKey);
		}
		
		if (!$rows) {
			return;
		}
		
		foreach ($rows as $row) {
			self::$primaryDataByKey[$type][$libraryID][$row['key']] = $row;
			self::$primaryDataByID[$type][$libraryID][$row['id']] =& self::$primaryDataByKey[$type][$libraryID][$row['key']];
		}
	}
	
	
	public static function cachePrimaryData($row) {
		$type = static::field('object');
		
		$libraryID = $row['libraryID'];
		
		if (!isset(self::$primaryDataByKey[$type][$libraryID])) {
			self::cachePrimaryDataByLibrary($libraryID);
		}
		
		$found = 0;
		$expected = sizeOf(static::$primaryFields);
		
		foreach ($row as $key=>$val) {
			if (isset(static::$primaryFields[$key])) {
				$found++;
			}
			else {
				throw new Exception("Unknown $type primary data field '$key'");
			}
		}
		
		if ($found != $expected) {
			throw new Exception("$found $type primary data fields provided -- excepted $expected");
		}
		
		self::$primaryDataByKey[$type][$libraryID][$row['key']] = $row;
		self::$primaryDataByID[$type][$libraryID][$row['id']] =& self::$primaryDataByKey[$type][$libraryID][$row['key']];
	}
	
	
	public static function uncachePrimaryData($libraryID, $key) {
		$type = static::field('object');
		
		if (isset(self::$primaryDataByKey[$type][$libraryID][$key])) {
			$id = self::$primaryDataByKey[$type][$libraryID][$key]['id'];
			unset(self::$primaryDataByKey[$type][$libraryID][$key]);
			unset(self::$primaryDataByID[$type][$libraryID][$id]);
		}
	}
	
	
	public static function getPrimaryDataSQL() {
		$fields = array();
		foreach (static::$primaryFields as $field => $dbField) {
			if ($dbField) {
				$fields[] = "`" . $dbField . "` AS `" . $field . "`";
			}
			else {
				$fields[] = "`" . $field . "`";
			}
		}
		return "SELECT " . implode(", ", $fields) . " FROM " . static::field('table') . " WHERE ";
	}
	
	
	public static function countUpdated($userID, $timestamp, $deletedCheckLimit=false) {
		$table = static::field('table');
		$id = static::field('id');
		$type = static::field('object');
		$types = static::field('objects');
		
		// First, see what libraries we actually need to check
		
		Zotero_DB::beginTransaction();
		
		// All libraries with update times >= $timestamp
		$updateTimes = Zotero_Libraries::getUserLibraryUpdateTimes($userID);
		$updatedLibraryIDs = array();
		foreach ($updateTimes as $libraryID=>$lastUpdated) {
			if ($lastUpdated >= $timestamp) {
				$updatedLibraryIDs[] = $libraryID;
			}
		}
		
		$count = self::getUpdated($userID, $timestamp, $updatedLibraryIDs, true);
		
		// Make sure we really have fewer than 5
		if ($deletedCheckLimit < 5) {
			$count += Zotero_Sync::countDeletedObjectKeys($userID, $timestamp, $updatedLibraryIDs);
		}
		
		Zotero_DB::commit();
		
		return $count;
	}
	
	
	/**
	 * Returns user's object ids updated since |timestamp|, keyed by libraryID,
	 * or count of all updated items if $countOnly is true
	 *
	 * @param	int			$libraryID			User ID
	 * @param	string		$timestamp			Unix timestamp of last sync time
	 * @param	array		$updatedLibraryIDs	Libraries with updated data
	 * @return	array|int
	 */
	public static function getUpdated($userID, $timestamp, $updatedLibraryIDs, $countOnly=false) {
		$table = static::field('table');
		$id = static::field('id');
		$type = static::field('object');
		$types = static::field('objects');
		
		// All joined groups have to be checked
		$joinedGroupIDs = Zotero_Groups::getJoined($userID, $timestamp);
		$joinedLibraryIDs = array();
		foreach ($joinedGroupIDs as $groupID) {
			$joinedLibraryIDs[] = Zotero_Groups::getLibraryIDFromGroupID($groupID);
		}
		
		// Separate libraries into shards for querying
		$libraryIDs = array_unique(array_merge($joinedLibraryIDs, $updatedLibraryIDs));
		$shardLibraryIDs = array();
		foreach ($libraryIDs as $libraryID) {
			$shardID = Zotero_Shards::getByLibraryID($libraryID);
			if (!isset($shardLibraryIDs[$shardID])) {
				$shardLibraryIDs[$shardID] = array(
					'updated' => array(),
					'joined' => array()
				);
			}
			if (in_array($libraryID, $joinedLibraryIDs)) {
				$shardLibraryIDs[$shardID]['joined'][] = $libraryID;
			}
			else {
				$shardLibraryIDs[$shardID]['updated'][] = $libraryID;
			}
		}
		
		if ($countOnly) {
			$count = 0;
			$fieldList = "COUNT(*)";
		}
		else {
			$updatedByLibraryID = array();
			$fieldList = "libraryID, $id AS id";
		}
		
		// Send query at each shard
		foreach ($shardLibraryIDs as $shardID=>$libraryIDs) {
			$sql = "SELECT $fieldList FROM $table WHERE ";
			if ($libraryIDs['updated']) {
				$sql .= "(libraryID IN (" .  implode(', ', array_fill(0, sizeOf($libraryIDs['updated']), '?')) . ")";
				$params = $libraryIDs['updated'];
				$sql .= " AND serverDateModified >= FROM_UNIXTIME(?))";
				$params[] = $timestamp;
			}
			
			if ($libraryIDs['joined']) {
				if ($libraryIDs['updated']) {
					$sql .= " OR ";
				}
				else {
					$params = array();
				}
				$sql .= "libraryID IN (" . implode(', ', array_fill(0, sizeOf($libraryIDs['joined']), '?')) . ")";
				$params = array_merge($params, $libraryIDs['joined']);
			}
			
			if ($countOnly) {
				$count += Zotero_DB::valueQuery($sql, $params, $shardID);
			}
			else {
				$rows = Zotero_DB::query($sql, $params, $shardID);
				if ($rows) {
					// Separate ids by libraryID
					foreach ($rows as $row) {
						$updatedByLibraryID[$row['libraryID']][] = $row['id'];
					}
				}
			}
		}
		
		return $countOnly ? $count : $updatedByLibraryID;
	}
	
	
	public static function delete($libraryID, $key, $updateLibrary=false) {
		$table = static::field('table');
		$id = static::field('id');
		$type = static::field('object');
		$types = static::field('objects');
		
		if (!$key) {
			throw new Exception("Invalid key $key");
		}
		
		// Get object (and trigger caching)
		$obj = static::getByLibraryAndKey($libraryID, $key);
		if (!$obj) {
			return;
		}
		static::editCheck($obj);
		
		Z_Core::debug("Deleting $type $libraryID/$key", 4);
		
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		
		Zotero_DB::beginTransaction();
		
		// Needed for API deletes to get propagated via sync
		if ($updateLibrary) {
			$timestamp = Zotero_Libraries::updateTimestamps($obj->libraryID);
			Zotero_DB::registerTransactionTimestamp($timestamp);
		}
		
		// Delete child items
		if ($type == 'item') {
			if ($obj->isRegularItem()) {
				$children = array_merge($obj->getNotes(), $obj->getAttachments());
				if ($children) {
					$children = Zotero_Items::get($libraryID, $children);
					foreach ($children as $child) {
						static::delete($child->libraryID, $child->key);
					}
				}
			}
		}
		
		if ($type == 'relation') {
			// TODO: add key column to relations to speed this up
			$sql = "DELETE FROM $table WHERE libraryID=? AND MD5(CONCAT(subject, '_', predicate, '_', object))=?";
			$deleted = Zotero_DB::query($sql, array($libraryID, $key), $shardID);
		}
		else {
			$sql = "DELETE FROM $table WHERE libraryID=? AND `key`=?";
			$deleted = Zotero_DB::query($sql, array($libraryID, $key), $shardID);
		}
		
		unset(self::$idCache[$type][$libraryID][$key]);
		
		if ($deleted) {
			$sql = "INSERT INTO syncDeleteLogKeys (libraryID, objectType, `key`, timestamp)
						VALUES (?, '$type', ?, ?) ON DUPLICATE KEY UPDATE timestamp=?";
			$timestamp = Zotero_DB::getTransactionTimestamp();
			$params = array($libraryID, $key, $timestamp, $timestamp);
			Zotero_DB::query($sql, $params, $shardID);
		}
		
		Zotero_DB::commit();
	}
	
	
	/**
	 * @param	SimpleXMLElement	$xml		Data necessary for delete as SimpleXML element
	 * @return	void
	 */
	public static function deleteFromXML(SimpleXMLElement $xml) {
		$parents = array();
		
		foreach ($xml->children() as $obj) {
			$libraryID = (int) $obj['libraryID'];
			$key = (string) $obj['key'];
			if ($obj->getName() == 'item') {
				$item = Zotero_Items::getByLibraryAndKey($libraryID, $key);
				if (!$item) {
					continue;
				}
				if (!$item->getSource()) {
					$parents[] = array('libraryID' => $libraryID, 'key' => $key);
					continue;
				}
			}
			static::delete($libraryID, $key);
		}
		
		foreach ($parents as $obj) {
			static::delete($obj['libraryID'], $obj['key']);
		}
	}
	
	
	public static function isEditable($obj) {
		$type = static::field('object');
		
		// Only enforce for sync controller for now
		if (empty($GLOBALS['controller']) || !($GLOBALS['controller'] instanceof SyncController)) {
			return true;
		}
		
		// Make sure user has access privileges to delete
		$userID = $GLOBALS['controller']->userID;
		if (!$userID) {
			return true;
		}
		
		$objectLibraryID = $obj->libraryID;
		
		$libraryType = Zotero_Libraries::getType($objectLibraryID);
		switch ($libraryType) {
			case 'user':
				if (!empty($GLOBALS['controller']->userLibraryID)) {
					$userLibraryID = $GLOBALS['controller']->userLibraryID;
				}
				else {
					$userLibraryID = Zotero_Users::getLibraryIDFromUserID($userID);
				}
				if ($objectLibraryID != $userLibraryID) {
					return false;
				}
				return true;
			
			case 'group':
				$groupID = Zotero_Groups::getGroupIDFromLibraryID($objectLibraryID);
				$group = Zotero_Groups::get($groupID);
				if (!$group->hasUser($userID) || !$group->userCanEdit($userID)) {
					return false;
				}
				
				if ($type == 'item' && $obj->isImportedAttachment() && !$group->userCanEditFiles($userID)) {
					return false;
				}
				return true;
			
			default:
				throw new Exception("Unsupported library type '$libraryType'");
		}
	}
	
	
	public static function editCheck($obj) {
		if (!static::isEditable($obj)) {
			throw new Exception("Cannot edit " . static::field('object') . " in library $obj->libraryID", Z_ERROR_LIBRARY_ACCESS_DENIED);
		}
	}
}
?>
