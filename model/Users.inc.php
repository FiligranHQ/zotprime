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

class Zotero_Users {
	public static $userXMLHash = array();
	
	private static $usernamesByID = [];
	private static $realNamesByID = [];
	private static $userLibraryIDs = [];
	private static $libraryUserIDs = [];
	
	
	/**
	 * Get the id of the library of the given type associated with the given user
	 *
	 * @param int $userID
	 * @param string [$libraryType='user']
	 * @throws Exception with code Z_ERROR_USER_NOT_FOUND if user library missing
	 * @return int|false Library ID, or false if library not found (except user library,
	 *     which throws)
	 */
	public static function getLibraryIDFromUserID($userID, $libraryType='user') {
		if (isset(self::$userLibraryIDs[$libraryType][$userID])) {
			return self::$userLibraryIDs[$libraryType][$userID];
		}
		$cacheKey = 'user' . ucwords($libraryType) . 'LibraryID_' . $userID;
		$libraryID = Z_Core::$MC->get($cacheKey);
		if ($libraryID) {
			self::$userLibraryIDs[$libraryType][$libraryID] = $libraryID;
			return $libraryID;
		}
		switch ($libraryType) {
		case 'user':
			$sql = "SELECT libraryID FROM users WHERE userID=?";
			break;
		
		case 'publications':
			$sql = "SELECT libraryID FROM userPublications WHERE userID=?";
			break;
		
		case 'group':
			throw new Exception("Can't get single group libraryID from userID");
		}
			
		$libraryID = Zotero_DB::valueQuery($sql, $userID);
		if (!$libraryID) {
			if ($libraryType == 'publications') {
				return false;
			}
			throw new Exception(ucwords($libraryType) . " library not found for user $userID",
				Z_ERROR_USER_NOT_FOUND);
		}
		self::$userLibraryIDs[$libraryType][$userID] = $libraryID;
		Z_Core::$MC->set($cacheKey, $libraryID);
		return $libraryID;
	}
	
	
	public static function getUserIDFromLibraryID($libraryID, $libraryType=null) {
		if (isset(self::$libraryUserIDs[$libraryID])) {
			return self::$libraryUserIDs[$libraryID];
		}
		$cacheKey = 'libraryUserID_' . $libraryID;
		$userID = Z_Core::$MC->get($cacheKey);
		if ($userID) {
			self::$libraryUserIDs[$libraryID] = $userID;
			return $userID;
		}
		
		if ($libraryType == null) {
			$libraryType = Zotero_Libraries::getType($libraryID);
		}
		
		switch ($libraryType) {
		case 'user':
			$sql = "SELECT userID FROM users WHERE libraryID=?";
			break;
		
		case 'publications':
			$sql = "SELECT userID FROM userPublications WHERE libraryID=?";
			break;
		
		case 'group':
			$sql = "SELECT userID FROM groupUsers JOIN groups USING (groupID) "
				. "WHERE libraryID=? AND role='owner'";
			break;
		}
		
		$userID = Zotero_DB::valueQuery($sql, $libraryID);
		if (!$userID) {
			if (!Zotero_Libraries::exists($libraryID)) {
				throw new Exception("Library $libraryID does not exist");
			}
			// Wrong library type passed
			error_log("Wrong library type passed for library $libraryID "
				. "in Zotero_Users::getUserIDFromLibraryID()");
			return self::getUserIDFromLibraryID($libraryID);
		}
		self::$libraryUserIDs[$libraryID] = $userID;
		Z_Core::$MC->set($cacheKey, $userID);
		return $userID;
	}
	
	
	/**
	 * Warning: This method may lie or return false..
	 */
	public static function getUserIDFromUsername($username) {
		$sql = "SELECT userID FROM users WHERE username=?";
		return Zotero_DB::valueQuery($sql, $username);
	}
	
	
	public static function getUserIDFromSessionID($sessionID) {
		$sql = "SELECT userID FROM sessions WHERE id=?
				AND UNIX_TIMESTAMP() < modified + lifetime";
		try {
			$userID = Zotero_WWW_DB_2::valueQuery($sql, $sessionID);
			Zotero_WWW_DB_2::close();
		}
		catch (Exception $e) {
			Z_Core::logError("WARNING: $e -- retrying on primary");
			$userID = Zotero_WWW_DB_1::valueQuery($sql, $sessionID);
			Zotero_WWW_DB_1::close();
		}
		
		return $userID;
	}
	
	
	public static function getUsername($userID, $skipAutoAdd=false) {
		if (!empty(self::$usernamesByID[$userID])) {
			return self::$usernamesByID[$userID];
		}
		
		$cacheKey = "usernameByID_" . $userID;
		$username = Z_Core::$MC->get($cacheKey);
		if ($username) {
			self::$usernamesByID[$userID] = $username;
			return $username;
		}
		
		$sql = "SELECT username FROM users WHERE userID=?";
		$username = Zotero_DB::valueQuery($sql, $userID);
		if (!$username && !$skipAutoAdd) {
			if (!self::exists($userID)) {
				self::addFromWWW($userID);
			}
			else {
				self::updateFromWWW($userID);
			}
			$sql = "SELECT username FROM users WHERE userID=?";
			$username = Zotero_DB::valueQuery($sql, $userID);
			if (!$username) {
				throw new Exception("Username for userID $userID not found after fetching from API", Z_ERROR_USER_NOT_FOUND);
			}
		}
		
		self::$usernamesByID[$userID] = $username;
		Z_Core::$MC->set($cacheKey, $username);
		
		return $username;
	}
	
	
	public static function getRealName($userID) {
		if (!empty(self::$realNamesByID[$userID])) {
			return self::$realNamesByID[$userID];
		}
		
		$cacheKey = "userRealNameByID_" . $userID;
		$name = Z_Core::$MC->get($cacheKey);
		if ($name) {
			self::$realNamesByID[$userID] = $name;
			return $name;
		}
		
		$sql = "SELECT metaValue FROM users_meta WHERE userID=? AND metaKey='profile_realname'";
		$params = [$userID];
		try {
			$name = Zotero_WWW_DB_2::valueQuery($sql, $params);
			Zotero_WWW_DB_2::close();
		}
		catch (Exception $e) {
			try {
				Z_Core::logError("WARNING: $e -- retrying on primary");
				$name = Zotero_WWW_DB_1::valueQuery($sql, $params);
				Zotero_WWW_DB_1::close();
			}
			catch (Exception $e2) {
				Z_Core::logError("WARNING: " . $e2);
			}
		}
		
		if (!$name) {
			return false;
		}
		
		self::$realNamesByID[$userID] = $name;
		Z_Core::$MC->set($cacheKey, $name);
		
		return $name;
	}
	
	
	public static function toJSON($userID) {
		$realName = Zotero_Users::getRealName($userID);
		$json = [
			'id' => $userID,
			'username' => Zotero_Users::getUsername($userID),
			'name' => $realName !== false ? $realName : ""
		];
		$json['links'] = [
			'alternate' => [
				'href' => Zotero_URI::getUserURI($userID, true),
				'type' => 'text/html'
			]
		];
		
		return $json;
	}
	
	
	public static function exists($userID) {
		if (Z_Core::$MC->get('userExists_' . $userID)) {
			return true;
		}
		$sql = "SELECT COUNT(*) FROM users WHERE userID=?";
		$exists = Zotero_DB::valueQuery($sql, $userID);
		if ($exists) {
			Z_Core::$MC->set('userExists_' . $userID, 1, 86400);
			return true;
		}
		return false;
	}
	
	
	public static function authenticate($method, $authData) {
		return call_user_func(array('Zotero_AuthenticationPlugin_' . ucwords($method), 'authenticate'), $authData);
	}
	
	
	public static function add($userID, $username='') {
		Zotero_DB::beginTransaction();
		
		$shardID = Zotero_Shards::getNextShard();
		$libraryID = Zotero_Libraries::add('user', $shardID);
		
		$sql = "INSERT INTO users (userID, libraryID, username) VALUES (?, ?, ?)";
		Zotero_DB::query($sql, array($userID, $libraryID, $username));
		
		Zotero_DB::commit();
		
		return $libraryID;
	}
	
	
	public static function addFromWWW($userID) {
		if (self::exists($userID)) {
			throw new Exception("User $userID already exists");
		}
		// Throws an error if user not found
		$username = self::getUsernameFromWWW($userID);
		self::add($userID, $username);
	}
	
	
	public static function updateFromWWW($userID) {
		// Throws an error if user not found
		$username = self::getUsernameFromWWW($userID);
		self::updateUsername($userID, $username);
	}
	
	
	public static function update($userID, $username=false) {
		$sql = "UPDATE users SET ";
		$params = array();
		if ($username) {
			$sql .= "username=?, ";
			$params[] = $username;
		}
		$sql .= "lastSyncTime=NOW() WHERE userID=?";
		$params[] = $userID;
		return Zotero_DB::query($sql, $params);
	}
	
	
	public static function updateUsername($userID, $username) {
		$sql = "UPDATE users SET username=? WHERE userID=?";
		return Zotero_DB::query($sql, array($username, $userID));
	}
	
	
	/**
	 * Get a key to represent the current state of all of a user's libraries
	 */
	public static function getUpdateKey($userID, $oldStyle=false) {
		$libraryIDs = Zotero_Libraries::getUserLibraries($userID);
		$parts = array();
		foreach ($libraryIDs as $libraryID) {
			if ($oldStyle) {
				$sql = "SELECT UNIX_TIMESTAMP(lastUpdated) FROM shardLibraries WHERE libraryID=?";
			}
			else {
				$sql = "SELECT version FROM shardLibraries WHERE libraryID=?";
			}
			$timestamp = Zotero_DB::valueQuery(
				$sql, $libraryID, Zotero_Shards::getByLibraryID($libraryID)
			);
			$parts[] = $libraryID . ':' . $timestamp;
		}
		return md5(implode(',', $parts));
	}
	
	
	public static function getEarliestDataTimestamp($userID) {
		$earliest = false;
		
		$libraryIDs = Zotero_Libraries::getUserLibraries($userID);
		$shardIDs = Zotero_Shards::getUserShards($userID);
		
		foreach ($shardIDs as $shardID) {
			$sql = '';
			$params = array();
			
			foreach (Zotero_DataObjects::$classicObjectTypes as $type) {
				$className = 'Zotero_' . $type['plural'];
				// ClassicDataObjects
				if (method_exists($className, "field")) {
					$table = call_user_func([$className, 'field'], 'table');
				}
				else {
					$table = $className::$table;
				}
				if ($table == 'relations') {
					$field = 'serverDateModified';
				}
				else if ($table == 'settings') {
					$field = 'lastUpdated';
				}
				else {
					$field = 'dateModified';
				}
				
				$sql .= "SELECT UNIX_TIMESTAMP($table.$field) AS time FROM $table
						WHERE libraryID IN ("
						. implode(', ', array_fill(0, sizeOf($libraryIDs), '?'))
						. ") UNION ";
				$params = array_merge($params, $libraryIDs);
			}
			
			$sql = substr($sql, 0, -6) . " ORDER BY time ASC LIMIT 1";
			$time = Zotero_DB::valueQuery($sql, $params, $shardID);
			if ($time && (!$earliest || $time < $earliest)) {
				$earliest = $time;
			}
		}
		
		return $earliest;
	}
	
	
	public static function getLastStorageSync($userID) {
		$lastModified = false;
		
		$libraryIDs = Zotero_Libraries::getUserLibraries($userID);
		$shardIDs = Zotero_Shards::getUserShards($userID);
		
		foreach ($shardIDs as $shardID) {
			$sql = "SELECT UNIX_TIMESTAMP(serverDateModified) AS time FROM items
					JOIN storageFileItems USING (itemID)
					WHERE libraryID IN ("
					. implode(', ', array_fill(0, sizeOf($libraryIDs), '?'))
					. ")
					ORDER BY time DESC LIMIT 1";
			$time = Zotero_DB::valueQuery($sql, $libraryIDs, $shardID);
			if ($time > $lastModified) {
				$lastModified = $time;
			}
		}
		
		return $lastModified;
	}
	
	
	public static function isValidUser($userID) {
		if (!$userID) {
			throw new Exception("Invalid user");
		}
		
		$cacheKey = "validUser_" . $userID;
		$valid = Z_Core::$MC->get($cacheKey);
		if ($valid === 1) {
			return true;
		}
		else if ($valid === 0) {
			return false;
		}
		
		$valid = !!self::getValidUsersDB(array($userID));
		
		Z_Core::$MC->set($cacheKey, $valid ? 1 : 0, 300);
		
		return $valid;
	}
	
	
	public static function getValidUsers($userIDs) {
		if (!$userIDs) {
			return array();
		}
		
		$newUserIDs = array();
		foreach ($userIDs as $id) {
			if (Zotero_Users::isValidUser($id)) {
				$newUserIDs[] = $id;
			}
		}
		
		return $newUserIDs;
	}
	
	
	public static function getValidUsersDB($userIDs) {
		if (!$userIDs) {
			return array();
		}
		
		$invalid = array();
		
		// Get any of these users that are known to be invalid
		$sql = "SELECT UserID FROM LUM_User WHERE RoleID=2 AND UserID IN ("
			. implode(', ', array_fill(0, sizeOf($userIDs), '?'))
			. ")";
		
		try {
			$invalid = Zotero_WWW_DB_2::columnQuery($sql, $userIDs);
			Zotero_WWW_DB_2::close();
		}
		catch (Exception $e) {
			try {
				Z_Core::logError("WARNING: $e -- retrying on primary");
				$invalid = Zotero_WWW_DB_1::columnQuery($sql, $userIDs);
				Zotero_WWW_DB_1::close();
			}
			catch (Exception $e2) {
				Z_Core::logError("WARNING: " . $e2);
				
				// If not available, assume valid
			}
		}
		
		if ($invalid) {
			$userIDs = array_diff($userIDs, $invalid);
		}
		
		return $userIDs;
	}
	
	
	public static function clearAllData($userID) {
		if (empty($userID)) {
			throw new Exception("userID not provided");
		}
		
		Zotero_DB::beginTransaction();
		
		$libraryID = self::getLibraryIDFromUserID($userID, 'publications');
		if ($libraryID) {
			Zotero_Libraries::clearAllData($libraryID);
		}
		
		$libraryID = self::getLibraryIDFromUserID($userID);
		Zotero_Libraries::clearAllData($libraryID);
		
		// TODO: Better handling of locked out sessions elsewhere
		$sql = "UPDATE sessions SET timestamp='0000-00-00 00:00:00',
					exclusive=0 WHERE userID=? AND exclusive=1";
		Zotero_DB::query($sql, $userID);
		
		Zotero_DB::commit();
	}
	
	
	public static function deleteUser($userID) {
		if (empty($userID)) {
			throw new Exception("userID not provided");
		}
		
		$username = Zotero_Users::getUsername($userID, true);
		
		$sql = "SELECT LUM_Role.Name FROM LUM_User JOIN LUM_Role USING (RoleID) WHERE UserID=?";
		try {
			$role = Zotero_WWW_DB_2::valueQuery($sql, $userID);
		}
		catch (Exception $e) {
			Z_Core::logError("WARNING: $e -- retrying on primary");
			$role = Zotero_WWW_DB_1::valueQuery($sql, $userID);
		}
		if ($role != 'Deleted') {
			throw new Exception("User '$username' does not have role 'Deleted'");
		}
		
		Zotero_DB::beginTransaction();
		
		if (Zotero_Groups::getUserOwnedGroups($userID)) {
			throw new Exception("Cannot delete user '$username' with owned groups");
		}
		
		// Remove user from any groups they're a member of
		//
		// This isn't strictly necessary thanks to foreign key cascades,
		// but it removes some extra keyPermissions rows
		$groupIDs = Zotero_Groups::getUserGroups($userID);
		foreach ($groupIDs as $groupID) {
			$group = Zotero_Groups::get($groupID, true);
			$group->removeUser($userID);
		}
		
		// Remove all data
		Zotero_Users::clearAllData($userID);
		
		// Remove user publications library
		$libraryID = self::getLibraryIDFromUserID($userID, 'publications');
		if ($libraryID) {
			$shardID = Zotero_Shards::getByLibraryID($libraryID);
			Zotero_DB::query("DELETE FROM shardLibraries WHERE libraryID=?", $libraryID, $shardID);
			Zotero_DB::query("DELETE FROM libraries WHERE libraryID=?", $libraryID);
		}
		
		// Remove user/library rows
		$libraryID = self::getLibraryIDFromUserID($userID);
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		Zotero_DB::query("DELETE FROM shardLibraries WHERE libraryID=?", $libraryID, $shardID);
		Zotero_DB::query("DELETE FROM libraries WHERE libraryID=?", $libraryID);
		
		Zotero_DB::commit();
	}
	
	
	public static function hasPublicationsInUserLibrary($userID) {
		$sql = "SELECT COUNT(*) > 0 FROM publicationsItems JOIN items WHERE libraryID=?";
		$libraryID = self::getLibraryIDFromUserID($userID);
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		return !!Zotero_DB::valueQuery($sql, $libraryID, $shardID);
	}
	
	
	public static function hasPublicationsInLegacyLibrary($userID) {
		$libraryID = Zotero_Users::getLibraryIDFromUserID($userID, 'publications');
		if (!$libraryID) {
			return false;
		}
		$sql = "SELECT COUNT(*) > 0 FROM items WHERE libraryID=?";
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		return !!Zotero_DB::valueQuery($sql, $libraryID, $shardID);
	}
	
	
	private static function getUsernameFromWWW($userID) {
		$sql = "SELECT username FROM users WHERE userID=?";
		try {
			$username = Zotero_WWW_DB_2::valueQuery($sql, $userID);
		}
		catch (Exception $e) {
			Z_Core::logError("WARNING: $e -- retrying on primary");
			$username = Zotero_WWW_DB_1::valueQuery($sql, $userID);
		}
		if (!$username) {
			throw new Exception("User $userID not found", Z_ERROR_USER_NOT_FOUND);
		}
		return $username;
	}
}
?>
