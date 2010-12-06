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
	public static function add($type, $shardID) {
		if (!$shardID) {
			throw new Exception('$shardID not provided');
		}
		
		$sql = "INSERT INTO libraries (libraryType, shardID) VALUES (?,?)";
		return Zotero_DB::query($sql, array($type, $shardID));
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
		
		$cacheKey = 'libraryType_' . $libraryID;
		$libraryType = Z_Core::$MC->get($cacheKey);
		if ($libraryType) {
			return $libraryType;
		}
		$sql = "SELECT libraryType FROM libraries WHERE libraryID=?";
		$libraryType = Zotero_DB::valueQuery($sql, $libraryID);
		if (!$libraryType) {
			trigger_error("Library $libraryID does not exist", E_USER_ERROR);
		}
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
	
	
	public static function isLocked($libraryID) {
		$sql = "SELECT COUNT(*) FROM syncUploadQueueLocks WHERE libraryID=?";
		$locked = Zotero_DB::valueQuery($sql, $libraryID);
		if ($locked) {
			return true;
		}
		$sql = "SELECT COUNT(*) FROM syncProcessLocks WHERE libraryID=?";
		return !!Zotero_DB::valueQuery($sql, $libraryID);
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
