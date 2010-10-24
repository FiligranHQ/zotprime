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

class Zotero_Shards {
	private static $libraryShards = array();
	private static $shardInfo = array();
	
	public static function getShardInfo($shardID) {
		if (!$shardID) {
			throw new Exception('$shardID not provided');
		}
		
		if (isset(self::$shardInfo[$shardID])) {
			return self::$shardInfo[$shardID];
		}
		
		//$cacheKey = 'shardInfo_' . $shardID;
		//$shardInfo = Z_Core::$MC->get($cacheKey);
		//if ($shardInfo) {
		//	self::$shardInfo[$shardID] = $shardInfo;
		//	return $shardInfo;
		//}
		
		$sql = "SELECT address, port, username, password, db,
				CASE
					WHEN shardHosts.state='up' THEN shards.state
					WHEN shardHosts.state='readonly' THEN
						IF(shards.state='down', 'down', 'readonly')
					WHEN shardHosts.state='down' THEN 'down'
				END AS state
				FROM shards JOIN shardHosts USING (shardHostID) WHERE shardID=?";
		$shardInfo = Zotero_DB::rowQuery($sql, $shardID);
		if (!$shardInfo) {
			throw new Exception("Shard $shardID not found");
		}
		
		self::$shardInfo[$shardID] = $shardInfo;
		//Z_Core::$MC->set($cacheKey, $shardInfo);
		
		return $shardInfo;
	}
	
	
	public static function shardIsWriteable($shardID) {
		$shardInfo = self::getShardInfo($shardID);
		return $shardInfo['state'] == 'up';
	}
	
	
	public static function getByLibraryID($libraryID) {
		if (!$libraryID) {
			throw new Exception('$libraryID not provided');
		}
		
		if (isset(self::$libraryShards[$libraryID])) {
			return self::$libraryShards[$libraryID];
		}
		
		$cacheKey = 'libraryShard_' . $libraryID;
		$shardID = Z_Core::$MC->get($cacheKey);
		if ($shardID) {
			self::$libraryShards[$libraryID] = $shardID;
			return $shardID;
		}
		
		$sql = "SELECT shardID FROM libraries WHERE libraryID=?";
		$shardID = Zotero_DB::valueQuery($sql, $libraryID);
		if (!$shardID) {
			throw new Exception("Shard not found for library $libraryID");
		}
		
		self::$libraryShards[$libraryID] = $shardID;
		Z_Core::$MC->set($cacheKey, $shardID);
		
		return $shardID;
	}
	
	
	public static function getByUserID($userID) {
		$libraryID = Zotero_Users::getLibraryIDFromUserID($userID);
		return self::getByLibraryID($libraryID);
	}
	
	
	public static function getByGroupID($groupID) {
		$libraryID = Zotero_Groups::getLibraryIDFromGroupID($groupID);
		return self::getByLibraryID($libraryID);
	}
	
	
	public static function getNextShard() {
		// TODO: figure out best shard
		return 2;
	}
	
	
	private static function setShard($libraryID, $newShardID) {
		$currentShardID = self::getByLibraryID($libraryID);
		if ($currentShardID == $newShardID) {
			throw new Exception("Library $libraryID is already on shard $newShardID");
		}
		
		$cacheKey = 'libraryShard_' . $libraryID;
		Z_Core::$MC->delete($cacheKey);
		
		$sql = "UPDATE libraries SET shardID=? WHERE libraryID=?";
		Zotero_DB::query($sql, array($newShardID, $libraryID));
	}
	
	
	public static function moveLibrary($libraryID, $newShardID) {
		$currentShardID = self::getByLibraryID($libraryID);
		if ($currentShardID == $newShardID) {
			throw new Exception("Library $libraryID is already on shard $newShardID");
		}
		if (!self::shardIsWriteable($newShardID)) {
			throw new Exception("Shard $newShardID is not writeable");
		}
		
		if (Zotero_Libraries::isLocked($libraryID)) {
			throw new Exception("Library $libraryID is locked");
		}
		
		// Make sure there's no stale data on the new shard
		if (self::checkForLibrary($libraryID, $newShardID)) {
			throw new Exception("Library $libraryID data already exists on shard $newShardID");
		}
		
		Zotero_DB::beginTransaction();
		
		Zotero_DB::query("SET foreign_key_checks=0", false, $newShardID);
		
		$tables = array(
			'collections',
			'creators',
			'items',
			'relations',
			'savedSearches',
			'tags',
			'collectionItems',
			'deletedItems',
			'groupItems',
			'itemAttachments',
			'itemCreators',
			'itemData',
			'itemNotes',
			'itemRelated',
			'itemTags',
			'savedSearchConditions',
			'storageFileItems',
			'syncDeleteLogIDs',
			'syncDeleteLogKeys'
		);
		
		foreach ($tables as $table) {
			if (Zotero_Libraries::isLocked($libraryID)) {
				Zotero_DB::rollback();
				throw new Exception("Aborted due to library lock");
			}
			
			switch ($table) {
				case 'collections':
				case 'creators':
				case 'items':
				case 'relations':
				case 'savedSearches':
				case 'tags':
				case 'syncDeleteLogIDs':
				case 'syncDeleteLogKeys':
					$sql = "SELECT * FROM $table WHERE libraryID=?";
					break;
				
				case 'collectionItems':
					$sql = "SELECT CI.* FROM collectionItems CI
							JOIN collections USING (collectionID) WHERE libraryID=?";
					break;
				
				case 'deletedItems':
				case 'groupItems':
				case 'itemAttachments':
				case 'itemCreators':
				case 'itemData':
				case 'itemNotes':
				case 'itemRelated':
				case 'itemTags':
				case 'storageFileItems':
					$sql = "SELECT T.* FROM $table T JOIN items USING (itemID) WHERE libraryID=?";
					break;
				
				case 'savedSearchConditions':
					$sql = "SELECT SSC.* FROM savedSearchConditions SSC
							JOIN savedSearches USING (searchID) WHERE libraryID=?";
					break;
			}
			
			$rows = Zotero_DB::query($sql, $libraryID, $currentShardID);
			
			if ($rows) {
				$sets = array();
				foreach ($rows as $row) {
					$sets[] = array_values($row);
				}
				$sql = "INSERT INTO $table VALUES ";
				Zotero_DB::bulkInsert($sql, $sets, 50, false, $newShardID);
			}
		}
		
		
		Zotero_DB::query("SET foreign_key_checks=1", false, $newShardID);
		
		if (Zotero_Libraries::isLocked($libraryID)) {
			Zotero_DB::rollback();
			throw new Exception("Aborted due to library lock");
		}
		
		Zotero_DB::commit();
		
		if (Zotero_Libraries::isLocked($libraryID)) {
			self::deleteLibrary($libraryID, $newShardID);
			throw new Exception("Aborted due to library lock");
		}
		
		self::setShard($libraryID, $newShardID);
		
		self::deleteLibrary($libraryID, $currentShardID);
	}
	
	
	private static function checkForLibrary($libraryID, $shardID) {
		$tables = array(
			'collections',
			'creators',
			'items',
			'relations',
			'savedSearches',
			'tags',
			'syncDeleteLogIDs',
			'syncDeleteLogKeys'
		);
		
		foreach ($tables as $table) {
			$sql = "SELECT COUNT(*) FROM $table WHERE libraryID=?";
			if (Zotero_DB::valueQuery($sql, $libraryID, $shardID)) {
				return true;
			}
		}
		
		return false;
	}
	
	
	private static function deleteLibrary($libraryID, $shardID) {
		Zotero_DB::beginTransaction();
		
		$tables = array(
			'collections',
			'creators',
			'items',
			'relations',
			'savedSearches',
			'tags',
			'syncDeleteLogIDs',
			'syncDeleteLogKeys'
		);
		
		foreach ($tables as $table) {
			$sql = "DELETE FROM $table WHERE libraryID=?";
			Zotero_DB::query($sql, $libraryID, $shardID);
		}
		
		Zotero_DB::commit();
	}
}
?>
