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

class Zotero_Libraries {
	private static $libraryTypeCache = array();
	private static $originalVersions = array();
	private static $updatedVersions = array();
	
	public static function add($type, $shardID) {
		if (!$shardID) {
			throw new Exception('$shardID not provided');
		}
		
		Zotero_DB::beginTransaction();
		
		$sql = "INSERT INTO libraries (libraryType, shardID) VALUES (?,?)";
		$libraryID = Zotero_DB::query($sql, array($type, $shardID));
		
		$sql = "INSERT INTO shardLibraries (libraryID, libraryType) VALUES (?,?)";
		Zotero_DB::query($sql, array($libraryID, $type), $shardID);
		
		Zotero_DB::commit();
		
		return $libraryID;
	}
	
	
	public static function exists($libraryID) {
		$sql = "SELECT COUNT(*) FROM libraries WHERE libraryID=?";
		return !!Zotero_DB::valueQuery($sql, $libraryID);
	}
	
	
	public static function getName($libraryID) {
		$type = self::getType($libraryID);
		switch ($type) {
			case 'user':
				$userID = Zotero_Users::getUserIDFromLibraryID($libraryID);
				return Zotero_Users::getUsername($userID);
				
			case 'group':
				$groupID = Zotero_Groups::getGroupIDFromLibraryID($libraryID);
				$group = Zotero_Groups::get($groupID);
				return $group->name;
		}
	}
	
	
	/**
	 * Get the type-specific id (userID or groupID) of the library
	 */
	public static function getLibraryTypeID($libraryID) {
		$type = self::getType($libraryID);
		switch ($type) {
			case 'user':
				return Zotero_Users::getUserIDFromLibraryID($libraryID);
			
			case 'group':
				return Zotero_Groups::getGroupIDFromLibraryID($libraryID);
		}
	}
	
	
	public static function getType($libraryID) {
		if (!$libraryID) {
			throw new Exception("Library not provided");
		}
		
		if (isset(self::$libraryTypeCache[$libraryID])) {
			return self::$libraryTypeCache[$libraryID];
		}
		
		$cacheKey = 'libraryType_' . $libraryID;
		$libraryType = Z_Core::$MC->get($cacheKey);
		if ($libraryType) {
			self::$libraryTypeCache[$libraryID] = $libraryType;
			return $libraryType;
		}
		$sql = "SELECT libraryType FROM libraries WHERE libraryID=?";
		$libraryType = Zotero_DB::valueQuery($sql, $libraryID);
		if (!$libraryType) {
			trigger_error("Library $libraryID does not exist", E_USER_ERROR);
		}
		
		self::$libraryTypeCache[$libraryID] = $libraryType;
		Z_Core::$MC->set($cacheKey, $libraryType);
		
		return $libraryType;
	}
	
	
	public static function getOwner($libraryID) {
		$type = self::getType($libraryID);
		switch ($type) {
			case 'user':
				return Zotero_Users::getUserIDFromLibraryID($libraryID);
				
			case 'group':
				$groupID = Zotero_Groups::getGroupIDFromLibraryID($libraryID);
				$group = Zotero_Groups::get($groupID);
				return $group->ownerUserID;
		}
	}
	
	
	public static function getUserLibraries($userID) {
		return array_merge(
			array(Zotero_Users::getLibraryIDFromUserID($userID)),
			Zotero_Groups::getUserGroupLibraries($userID)
		);
	}
	
	
	public static function updateVersionAndTimestamp($libraryID) {
		self::updateVersion($libraryID);
		$timestamp = self::updateTimestamps($libraryID);
		Zotero_DB::registerTransactionTimestamp($timestamp);
	}
	
	
	public static function getTimestamp($libraryID) {
		$sql = "SELECT lastUpdated FROM shardLibraries WHERE libraryID=?";
		return Zotero_DB::valueQuery(
			$sql, $libraryID, Zotero_Shards::getByLibraryID($libraryID)
		);
	}
	
	
	public static function getUserLibraryUpdateTimes($userID) {
		$libraryIDs = Zotero_Libraries::getUserLibraries($userID);
		$sql = "SELECT libraryID, UNIX_TIMESTAMP(lastUpdated) AS lastUpdated FROM libraries
				WHERE libraryID IN ("
				. implode(',', array_fill(0, sizeOf($libraryIDs), '?'))
				. ") LOCK IN SHARE MODE";
		$rows = Zotero_DB::query($sql, $libraryIDs);
		$updateTimes = array();
		foreach ($rows as $row) {
			$updateTimes[$row['libraryID']] = $row['lastUpdated'];
		}
		return $updateTimes;
	}
	
	
	public static function updateTimestamps($libraryIDs) {
		if (is_scalar($libraryIDs)) {
			if (!is_numeric($libraryIDs)) {
				throw new Exception("Invalid library ID");
			}
			$libraryIDs = array($libraryIDs);
		}
		
		Zotero_DB::beginTransaction();
		
		// TODO: replace with just shardLibraries after sync code removal
		$sql = "UPDATE libraries SET lastUpdated=NOW() WHERE libraryID IN "
				. "(" . implode(',', array_fill(0, sizeOf($libraryIDs), '?')) . ")";
		Zotero_DB::query($sql, $libraryIDs);
		
		$sql = "SELECT UNIX_TIMESTAMP(lastUpdated) FROM libraries WHERE libraryID=?";
		$timestamp = Zotero_DB::valueQuery($sql, $libraryIDs[0]);
		
		$sql = "UPDATE shardLibraries SET lastUpdated=FROM_UNIXTIME(?) WHERE libraryID=?";
		foreach ($libraryIDs as $libraryID) {
			Zotero_DB::query(
				$sql,
				array(
					$timestamp,
					$libraryID
				),
				Zotero_Shards::getByLibraryID($libraryID)
			);
		}
		
		Zotero_DB::commit();
		
		return $timestamp;
	}
	
	
	public static function setTimestampLock($libraryIDs, $timestamp) {
		$fail = false;
		
		for ($i=0, $len=sizeOf($libraryIDs); $i<$len; $i++) {
			$libraryID = $libraryIDs[$i];
			if (!Z_Core::$MC->add("libraryTimestampLock_" . $libraryID . "_" . $timestamp, 1, 60)) {
				$fail = true;
				break;
			}
		}
		
		if ($fail) {
			if ($i > 0) {
				for ($j=$i-1; $j>=0; $j--) {
					$libraryID = $libraryIDs[$i];
					Z_Core::$MC->delete("libraryTimestampLock_" . $libraryID . "_" . $timestamp);
				}
			}
			return false;
		}
		
		return true;
	}
	
	
	/**
	 * Get library version from the database
	 */
	public static function getVersion($libraryID) {
		$sql = "SELECT version FROM shardLibraries WHERE libraryID=?";
		$version = Zotero_DB::valueQuery(
			$sql, $libraryID, Zotero_Shards::getByLibraryID($libraryID)
		);
		
		// TEMP
		if (!$version || $version == 1) {
			$shardID = Zotero_Shards::getByLibraryID($libraryID);
			
			$sql = "SELECT lastUpdated, version FROM libraries WHERE libraryID=?";
			$row = Zotero_DB::rowQuery($sql, $libraryID);
			
			$sql = "UPDATE shardLibraries SET version=?, lastUpdated=? WHERE libraryID=?";
			Zotero_DB::query(
				$sql,
				array($row['version'], $row['lastUpdated'], $libraryID),
				$shardID
			);
			$sql = "SELECT IFNULL(IF(MAX(version)=0, 1, MAX(version)), 1) FROM items WHERE libraryID=?";
			$version = Zotero_DB::valueQuery($sql, $libraryID, $shardID);
			
			$sql = "UPDATE shardLibraries SET version=? WHERE libraryID=?";
			Zotero_DB::query($sql, array($version, $libraryID), $shardID);
		}
		
		// Store original version for use by getOriginalVersion()
		if (!isset(self::$originalVersions[$libraryID])) {
			self::$originalVersions[$libraryID] = $version;
		}
		return $version;
	}
	
	
	/**
	 * Get the first library version retrieved during this request, or the
	 * database version if none
	 *
	 * Since the library version is updated at the start of a request,
	 * but write operations may cache data before making changes, the
	 * original, pre-update version has to be used in cache keys.
	 * Otherwise a subsequent request for the new library version might
	 * omit data that was written with that version. (The new data can't
	 * just be written with the same version because a cache write
	 * could fail.)
	 */
	public static function getOriginalVersion($libraryID) {
		if (isset(self::$originalVersions[$libraryID])) {
			return self::$originalVersions[$libraryID];
		}
		$version = self::getVersion($libraryID);
		self::$originalVersions[$libraryID] = $version;
		return $version;
	}
	
	
	/**
	 * Get the latest library version set during this request, or the original
	 * version if none
	 */
	public static function getUpdatedVersion($libraryID) {
		if (isset(self::$updatedVersions[$libraryID])) {
			return self::$updatedVersions[$libraryID];
		}
		return self::getOriginalVersion($libraryID);
	}
	
	
	public static function updateVersion($libraryID) {
		self::getOriginalVersion($libraryID);
		
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		$sql = "UPDATE shardLibraries SET version=LAST_INSERT_ID(version+1)
				WHERE libraryID=?";
		Zotero_DB::query($sql, $libraryID, $shardID);
		$version = Zotero_DB::valueQuery("SELECT LAST_INSERT_ID()", false, $shardID);
		// Store new version for use by getUpdatedVersion()
		self::$updatedVersions[$libraryID] = $version;
		return $version;
	}
	
	
	public static function isLocked($libraryID) {
		$sql = "SELECT COUNT(*) FROM syncUploadQueueLocks WHERE libraryID=?";
		if (Zotero_DB::valueQuery($sql, $libraryID)) {
			return true;
		}
		$sql = "SELECT COUNT(*) FROM syncProcessLocks WHERE libraryID=?";
		return !!Zotero_DB::valueQuery($sql, $libraryID);
	}
	
	
	public static function userCanEdit($libraryID, $userID, $obj=null) {
		$libraryType = Zotero_Libraries::getType($libraryID);
		switch ($libraryType) {
			case 'user':
				$userLibraryID = Zotero_Users::getLibraryIDFromUserID($userID);
				if ($libraryID != $userLibraryID) {
					return false;
				}
				return true;
			
			case 'group':
				$groupID = Zotero_Groups::getGroupIDFromLibraryID($libraryID);
				$group = Zotero_Groups::get($groupID);
				if (!$group->hasUser($userID) || !$group->userCanEdit($userID)) {
					return false;
				}
				
				if ($obj && $obj instanceof Zotero_Item
						&& $obj->isImportedAttachment()
						&& !$group->userCanEditFiles($userID)) {
					return false;
				}
				return true;
			
			default:
				throw new Exception("Unsupported library type '$libraryType'");
		}
	}
	
	
	public static function getLastStorageSync($libraryID) {
		$sql = "SELECT UNIX_TIMESTAMP(serverDateModified) AS time FROM items
				JOIN storageFileItems USING (itemID) WHERE libraryID=?
				ORDER BY time DESC LIMIT 1";
		return Zotero_DB::valueQuery(
			$sql, $libraryID, Zotero_Shards::getByLibraryID($libraryID)
		);
	}
	
	
	public static function toJSON($libraryID) {
		// TODO: cache
		
		$libraryType = Zotero_Libraries::getType($libraryID);
		if ($libraryType == 'user') {
			$objectUserID = Zotero_Users::getUserIDFromLibraryID($libraryID);
			$json = [
				'type' => $libraryType,
				'id' => $objectUserID,
				'name' => Zotero_Users::getUsername($objectUserID),
				'links' => [
					'alternate' => [
						'href' => Zotero_URI::getUserURI($objectUserID, true),
						'type' => 'text/html'
					]
				]
			];
		}
		else if ($libraryType == 'group') {
			$objectGroupID = Zotero_Groups::getGroupIDFromLibraryID($libraryID);
			$group = Zotero_Groups::get($objectGroupID);
			$json = [
				'type' => $libraryType,
				'id' => $objectGroupID,
				'name' => $group->name,
				'links' => [
					'alternate' => [
						'href' => Zotero_URI::getGroupURI($group, true),
						'type' => 'text/html'
					]
				]
			];
		}
		
		return $json;
	}
	
	
	public static function clearAllData($libraryID) {
		if (empty($libraryID)) {
			throw new Exception("libraryID not provided");
		}
		
		Zotero_DB::beginTransaction();
		
		$tables = array(
			'collections', 'creators', 'items', 'relations', 'savedSearches', 'tags',
			'syncDeleteLogIDs', 'syncDeleteLogKeys', 'settings'
		);
		
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		
		self::deleteCachedData($libraryID);
		
		// Because of the foreign key constraint on the itemID, delete MySQL full-text rows
		// first, and then clear from Elasticsearch below
		Zotero_FullText::deleteByLibraryMySQL($libraryID);
		
		foreach ($tables as $table) {
			// Delete notes and attachments first (since they may be child items)
			if ($table == 'items') {
				$sql = "DELETE FROM $table WHERE libraryID=? AND itemTypeID IN (1,14)";
				Zotero_DB::query($sql, $libraryID, $shardID);
			}
			
			$sql = "DELETE FROM $table WHERE libraryID=?";
			Zotero_DB::query($sql, $libraryID, $shardID);
		}
		
		Zotero_FullText::deleteByLibrary($libraryID);
		
		self::updateVersion($libraryID);
		self::updateTimestamps($libraryID);
		
		Zotero_Notifier::trigger("clear", "library", $libraryID);
		
		Zotero_DB::commit();
	}
	
	
	
	/**
	 * Delete data from memcached
	 */
	public static function deleteCachedData($libraryID) {
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		
		// Clear itemID-specific memcache values
		$sql = "SELECT itemID FROM items WHERE libraryID=?";
		$itemIDs = Zotero_DB::columnQuery($sql, $libraryID, $shardID);
		if ($itemIDs) {
			$cacheKeys = array(
				"itemCreators",
				"itemIsDeleted",
				"itemRelated",
				"itemUsedFieldIDs",
				"itemUsedFieldNames"
			);
			foreach ($itemIDs as $itemID) {
				foreach ($cacheKeys as $key) {
					Z_Core::$MC->delete($key . '_' . $itemID);
				}
			}
		}
		
		foreach (Zotero_DataObjects::$objectTypes as $type=>$arr) {
			$className = "Zotero_" . $arr['plural'];
			call_user_func(array($className, "clearPrimaryDataCache"), $libraryID);
		}
	}
}
?>
