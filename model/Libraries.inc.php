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
	private static $originalTimestamps = array();
	private static $originalVersions = array();
	
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
	
	
	public static function getTimestamp($libraryID, $committedOnly=false) {
		if ($committedOnly && isset(self::$originalTimestamps[$libraryID])) {
			return self::$originalTimestamps[$libraryID];
		}
		
		$sql = "SELECT lastUpdated FROM libraries WHERE libraryID=?";
		return Zotero_DB::valueQuery($sql, $libraryID);
	}
	
	
	public static function getVersion($libraryID, $committedOnly=false) {
		if ($committedOnly && isset(self::$originalVersions[$libraryID])) {
			return self::$originalVersions[$libraryID];
		}
		
		$sql = "SELECT version FROM libraries WHERE libraryID=?";
		return Zotero_DB::valueQuery($sql, $libraryID);
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
		
		// Record the existing timestamp, since getTimestamp() needs it in $committedOnly mode
		foreach ($libraryIDs as $libraryID) {
			if (isset(self::$originalTimestamps[$libraryID])) {
				// TODO: limit to same transaction?
				//throw new Exception("Library timestamp cannot be updated more than once");
			}
			self::$originalTimestamps[$libraryID] = self::getTimestamp($libraryID);
			self::$originalVersions[$libraryID] = self::getVersion($libraryID);
		}
		
		$sql = "UPDATE libraries SET lastUpdated=NOW(), version=version+1 WHERE libraryID IN "
				. "(" . implode(',', array_fill(0, sizeOf($libraryIDs), '?')) . ")";
		Zotero_DB::query($sql, $libraryIDs);
		
		$sql = "SELECT UNIX_TIMESTAMP(lastUpdated) FROM libraries WHERE libraryID=?";
		$timestamp = Zotero_DB::valueQuery($sql, $libraryIDs[0]);
		
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
	
	
	public static function isLocked($libraryID) {
		$sql = "SELECT COUNT(*) FROM syncUploadQueueLocks WHERE libraryID=?";
		if (Zotero_DB::valueQuery($sql, $libraryID)) {
			return true;
		}
		$sql = "SELECT COUNT(*) FROM syncProcessLocks WHERE libraryID=?";
		return !!Zotero_DB::valueQuery($sql, $libraryID);
	}
	
	
	public static function clearAllData($libraryID) {
		if (empty($libraryID)) {
			throw new Exception("libraryID not provided");
		}
		
		Zotero_DB::beginTransaction();
		
		$tables = array(
			'collections', 'creators', 'items', 'relations', 'savedSearches', 'tags',
			'syncDeleteLogIDs', 'syncDeleteLogKeys'
		);
		
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		
		self::deleteCachedData($libraryID);
		
		foreach ($tables as $table) {
			// Delete notes and attachments first (since they may be child items)
			if ($table == 'items') {
				$sql = "DELETE FROM $table WHERE libraryID=? AND itemTypeID IN (1,14)";
				Zotero_DB::query($sql, $libraryID, $shardID);
			}
			
			$sql = "DELETE FROM $table WHERE libraryID=?";
			Zotero_DB::query($sql, $libraryID, $shardID);
		}
		
		$sql = "UPDATE libraries SET lastUpdated=NOW() WHERE libraryID=?";
		Zotero_DB::query($sql, $libraryID);
		
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
			$cacheKey = $type . 'IDsByKey_' . $libraryID;
			Z_Core::$MC->delete($cacheKey);
		}
	}
}
?>
