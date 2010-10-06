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
	
	private static $idCache = array();
	
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
		if (!preg_match('/[A-Z0-9]{8}/', $key)) {
			throw new Exception("Invalid key '$key'");
		}
		
		$table = static::field('table');
		$id = static::field('id');
		$type = static::field('object');
		
		if (!isset(self::$idCache[$type])) {
			self::$idCache[$type] = array();
		}
		
		// Cache object ids in library if not done yet
		if (!isset(self::$idCache[$type][$libraryID])) {
			self::$idCache[$type][$libraryID] = array();
			
			$ids = Z_Core::$MC->get($type . 'IDsByKey_' . $libraryID);
			if ($ids) {
				self::$idCache[$type][$libraryID] = $ids;
			}
			else {
				$sql = "SELECT $id AS id, `key` FROM $table WHERE libraryID=?";
				$rows = Zotero_DB::query($sql, $libraryID, Zotero_Shards::getByLibraryID($libraryID));
				
				if (!$rows) {
					return false;
				}
				
				foreach ($rows as $row) {
					self::$idCache[$type][$libraryID][$row['key']] = $row['id'];
				}
				
				// TODO: remove expiration time
				Z_Core::$MC->set($type . 'IDsByKey_' . $libraryID, self::$idCache[$type][$libraryID], 1800);
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
		
		// TODO: remove expiration time
		Z_Core::$MC->set($type . 'IDsByKey_' . $libraryID, self::$idCache[$type][$libraryID], 1800);
	}
	
	
	public static function countUpdated($userID, $timestamp) {
		$libraryID = Zotero_Users::getLibraryIDFromUserID($userID);
		$idsByLibraryID = Zotero_Items::getUpdated($libraryID, $timestamp, true);
		if (!$idsByLibraryID) {
			return 0;
		}
		$count = 0;
		foreach ($idsByLibraryID as $libraryID=>$ids) {
			$count += sizeOf($ids);
		}
		return $count;
	}
	
	
	/**
	 * Returns user's object ids updated since |timestamp|, keyed by libraryID
	 *
	 * @param	int			$libraryID		Library ID
	 * @param	string		$timestamp		Unix timestamp + decimal ms of last sync time
	 * @return	array						An array of arrays of object ids, keyed by libraryID, or FALSE if none
	 */
	public static function getUpdated($libraryID, $timestamp, $includeAllUserObjects=false) {
		$table = static::field('table');
		$id = static::field('id');
		$type = static::field('object');
		$types = static::field('objects');
		
		// A subquery here was very slow in MySQL 5.1.33 but should work in MySQL 6
		
		if (strpos($timestamp, '.') === false) {
			$timestamp .= '.';
		}
		list($timestamp, $timestampMS) = explode(".", $timestamp);
		
		
		$updatedByLibraryID = array();
		
		// Personal library
		$sql = "SELECT $id FROM $table WHERE libraryID=? AND
				CONCAT(UNIX_TIMESTAMP(serverDateModified), '.', IFNULL(serverDateModifiedMS, 0)) > ?";
		$params = array($libraryID, $timestamp . '.' . ($timestampMS ? $timestampMS : 0));
		$ids = Zotero_DB::columnQuery($sql, $params, Zotero_Shards::getByLibraryID($libraryID));
		if ($ids) {
			$updatedByLibraryID[$libraryID] = $ids;
		}
		
		// Group libraries
		if ($includeAllUserObjects) {
			$userID = Zotero_Users::getUserIDFromLibraryID($libraryID);
			$groupIDs = Zotero_Groups::getUserGroups($userID);
			
			if ($groupIDs) {
				$joinedGroupIDs = Zotero_Groups::getJoined($userID, $timestamp);
				
				$shardGroupIDs = array();
				
				// Separate groups into shards for querying
				foreach ($groupIDs as $groupID) {
					$shardID = Zotero_Shards::getByGroupID($groupID);
					if (!isset($shardGroupIDs[$shardID])) {
						$shardGroupIDs[$shardID] = array();
					}
					$shardGroupIDs[$shardID][] = $groupID;
				}
				
				// Send query at each shard
				foreach ($shardGroupIDs as $shardID=>$groupIDs) {
					$sql = "SELECT groupID, $id AS id FROM $table "
							. "JOIN " . Z_CONFIG::$SHARD_MASTER_DB. ".groups "
							. "USING (libraryID) WHERE (groupID IN (";
					$q = array_fill(0, sizeOf($groupIDs), '?');
					$sql .= implode(', ', $q) . ")";
					$params = $groupIDs;
					$sql .= " AND CONCAT(UNIX_TIMESTAMP(serverDateModified), '.', IFNULL(serverDateModifiedMS, 0)) > ?)";
					$params[] = $timestamp . '.' . ($timestampMS ? $timestampMS : 0);
					
					if ($joinedGroupIDs) {
						$sql .= " OR groupID IN (";
						$params = array_merge($params, $joinedGroupIDs);
						$q = array_fill(0, sizeOf($joinedGroupIDs), '?');
						$sql .= implode(', ', $q) . ")";
					}
					
					$rows = Zotero_DB::query($sql, $params, Zotero_Shards::getByLibraryID($libraryID));
					if ($rows) {
						// Separate ids by libraryID
						foreach ($rows as $row) {
							$libraryID = Zotero_Groups::getLibraryIDFromGroupID($row['groupID']);
							$updatedByLibraryID[$libraryID][] = $row['id'];
						}
					}
				}
			}
		}
		
		return $updatedByLibraryID;
	}
	
	
	public static function delete($libraryID, $key) {
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
		
		$sql = "DELETE FROM $table WHERE libraryID=? AND `key`=?";
		$deleted = Zotero_DB::query($sql, array($libraryID, $key), $shardID);
		
		unset(self::$idCache[$type][$libraryID][$key]);
		Z_Core::$MC->set($type . 'IDsByKey_' . $libraryID, self::$idCache[$type][$libraryID], 1800);
		
		if ($deleted) {
			$sql = "INSERT INTO syncDeleteLogKeys (libraryID, objectType, `key`, timestamp, timestampMS)
						VALUES (?, '$type', ?, ?, ?) ON DUPLICATE KEY UPDATE timestamp=?, timestampMS=?";
			$timestamp = Zotero_DB::getTransactionTimestamp();
			$timestampMS = Zotero_DB::getTransactionTimestampMS();
			$params = array(
				$libraryID, $key, $timestamp, $timestampMS, $timestamp, $timestampMS
			);
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
