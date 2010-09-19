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
	public static function getShardInfo($shardID) {
		if (!$shardID) {
			throw new Exception('$shardID not provided');
		}
		
		// TODO: enable caching
		//$cacheKey = 'shardInfo_' . $shardID;
		//$shardInfo = Z_Core::$MC->get($cacheKey);
		//if ($shardInfo) {
		//	return $shardInfo;
		//}
		
		$sql = "SELECT * FROM shards JOIN shardHosts USING (shardHostID) WHERE shardID=?";
		$shardInfo = Zotero_DB::rowQuery($sql, $shardID);
		
		//Z_Core::$MC->set($cacheKey, $shardInfo)
		
		return $shardInfo;
	}
	
	
	public static function shardIsWriteable($shardID) {
		$shardInfo = self::getShardInfo($shardID);
		if (!$shardInfo) {
			throw new Exception("Shard $shardID not found");
		}
		return $shardInfo['state'] == 'up';
	}
	
	
	public static function getByLibraryID($libraryID) {
		if (!$libraryID) {
			throw new Exception('$libraryID not provided');
		}
		
		$cacheKey = 'libraryShard_' . $libraryID;
		$shardID = Z_Core::$MC->get($cacheKey);
		if ($shardID) {
			return $shardID;
		}
		
		$sql = "SELECT shardID FROM libraries WHERE libraryID=?";
		$shardID = Zotero_DB::valueQuery($sql, $libraryID);
		
		if (!$shardID) {
			throw new Exception("Shard not found for library $libraryID");
		}
		
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
		return 1;
	}
}
?>
