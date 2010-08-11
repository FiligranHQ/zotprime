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
	
	public static function getLibraryIDFromUserID($userID) {
		$cacheKey = 'userLibraryID_' . $userID;
		$libraryID = Z_Core::$MC->get($cacheKey);
		if ($libraryID) {
			return $libraryID;
		}
		$sql = "SELECT libraryID FROM users WHERE userID=?";
		$libraryID = Zotero_DB::valueQuery($sql, $userID);
		if (!$libraryID) {
			throw new Exception("User $userID does not exist", Z_ERROR_USER_NOT_FOUND);
		}
		Z_Core::$MC->set($cacheKey, $libraryID);
		return $libraryID;
	}
	
	
	public static function getUserIDFromLibraryID($libraryID) {
		$cacheKey = 'libraryUserID_' . $libraryID;
		$userID = Z_Core::$MC->get($cacheKey);
		if ($userID) {
			return $userID;
		}
		$sql = "SELECT userID FROM users WHERE libraryID=?";
		$userID = Zotero_DB::valueQuery($sql, $libraryID);
		if (!$userID) {
			throw new Exception("User with libraryID $libraryID does not exist");
		}
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
	
	
	public static function getUsername($userID, $skipAutoAdd=false) {
		$sql = "SELECT username FROM users WHERE userID=?";
		$username = Zotero_DB::valueQuery($sql, $userID);
		if (!$username && !$skipAutoAdd) {
			// TODO: get from replicated table first
			if (!self::exists($userID)) {
				self::addFromAPI($userID);
			}
			else {
				self::updateFromAPI($userID);
			}
			$sql = "SELECT username FROM users WHERE userID=?";
			$username = Zotero_DB::valueQuery($sql, $userID);
			if (!$username) {
				throw new Exception("Username for userID $userID not found after fetching from API", Z_ERROR_USER_NOT_FOUND);
			}
		}
		return $username;
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
		
		$libraryID = Zotero_Libraries::add('user');
		
		$sql = "INSERT INTO users (userID, libraryID, username) VALUES (?, ?, ?)";
		Zotero_DB::query($sql, array($userID, $libraryID, $username));
		
		Zotero_DB::commit();
		
		return $libraryID;
	}
	
	
	public static function addFromAPI($userID) {
		if (self::exists($userID)) {
			throw new Exception("User $userID already exists");
		}
		// Throws an error if user not found
		$userData = self::getXMLFromAPI($userID);
		$username = self::getUsernameFromAPIXML($userData);
		self::add($userID, $username);
	}
	
	
	public static function updateFromAPI($userID) {
		// Throws an error if user not found
		$userData = self::getXMLFromAPI($userID);
		$username = self::getUsernameFromAPIXML($userData);
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
	
	
	public static function updateUsernameFromAPI($username) {
		$xml = self::getXMLFromAPIByUsername($username);
		$userID = self::getUserIDFromAPIXML($xml);
		self::updateUsername($userID, $username);
		return $userID;
	}
	
	
	public static function getLastStorageSync($userID) {
		$sql = "SELECT UNIX_TIMESTAMP(serverDateModified) AS time FROM users JOIN items USING (libraryID) WHERE userID=?
				UNION
				SELECT UNIX_TIMESTAMP(serverDateModified) AS time FROM groupUsers JOIN groups USING (groupID)
				JOIN items USING (libraryID) JOIN storageFileItems USING (itemID)
				WHERE userID=?
				ORDER BY time DESC LIMIT 1";
		return Zotero_DB::valueQuery($sql, array($userID, $userID));
	}
	
	
	public static function clearAllData($userID) {
		if (empty($userID)) {
			throw new Exception("userID not provided");
		}
		
		Zotero_DB::beginTransaction();
		
		$tables = array(
			'collections', 'creators', 'items', 'tags', 'savedSearches',
			'syncDeleteLogIDs', 'syncDeleteLogKeys'
		);
		
		$libraryID = self::getLibraryIDFromUserID($userID);
		
		foreach ($tables as $table) {
			// Delete notes and attachments first (since they may be child items)
			if ($table == 'items') {
				$sql = "DELETE FROM $table WHERE libraryID=? AND itemTypeID IN (1,14)";
				Zotero_DB::query($sql, $libraryID);
			}
			
			$sql = "DELETE FROM $table WHERE libraryID=?";
			Zotero_DB::query($sql, $libraryID);
		}
		
		// TODO: Better handling of locked out sessions elsewhere
		//$sql = "UPDATE zotero_sessions.sessions SET exclusive=0 WHERE userID=?";
		$sql = "UPDATE sessions SET timestamp='0000-00-00 00:00:00',
					exclusive=0 WHERE userID=? AND exclusive=1";
		Z_SessionsDB::query($sql, $userID);
		
		Zotero_DB::commit();
	}
	
	
	private static function getXMLFromAPI($userID) {
		if (Z_ENV_DEV_SITE) {
			throw new Exception("External requests disabled on dev site");
		}
		
		if (isset(self::$userXMLHash[$userID])) {
			return self::$userXMLHash[$userID];
		}
		
		$url = Zotero_API::getUserURI($userID);
		$xml = @file_get_contents($url);
		if (!$xml) {
			throw new Exception("User $userID not found", Z_ERROR_USER_NOT_FOUND);
		}
		$xml = @new SimpleXMLElement($xml);
		if (!$xml) {
			throw new Exception("Invalid XML for user $userID");
		}
		self::$userXMLHash[$userID] = $xml;
		return $xml;
	}
	
	
	private static function getXMLFromAPIByUsername($username) {
		if (Z_ENV_DEV_SITE) {
			throw new Exception("External requests disabled on dev site");
		}
		
		$url = Zotero_API::getUserURIFromUsername($username);
		$xml = @file_get_contents($url);
		if (!$xml) {
			throw new Exception("User '$username' not found", Z_ERROR_USER_NOT_FOUND);
		}
		$xml = @new SimpleXMLElement($xml);
		if (!$xml) {
			throw new Exception("Invalid XML for user $username");
		}
		$userID = self::getUserIDFromAPIXML($xml);
		self::$userXMLHash[$userID] = $xml;
		return $xml;
	}
	
	
	private static function getUsernameFromAPIXML($xml) {
		return (string) $xml->title;
	}
	
	
	private static function getUserIDFromAPIXML($xml) {
		$url = (string) $xml->id;
		$userID = substr(strrchr($url, '/'), 1);
		if (!is_numeric($userID)) {
			throw new Exception("Error parsing userID from XML");
		}
		return (int) $userID;
	}
	
	
	public static function getEarliestDataTimestamp($userID) {
		$sql = '';
		
		$libraryID = self::getLibraryIDFromUserID($userID);
		
		$params = array();
		foreach (Zotero_DataObjects::$objectTypes as $type) {
			$className = 'Zotero_' . $type['plural'];
			$table = call_user_func(array($className, 'field'), 'table');
			if ($table == 'relations') {
				$field = 'serverDateModified';
			}
			else {
				$field = 'dateModified';
			}
			
			$sql .= "SELECT UNIX_TIMESTAMP($table.$field) AS time FROM $table WHERE libraryID=?
						UNION ";
			$params[] = $libraryID;
			
			$sql .= "SELECT UNIX_TIMESTAMP($table.$field) AS time FROM $table
					JOIN groups USING (libraryID)
					JOIN groupUsers USING (groupID) WHERE userID=?
					UNION ";
			$params[] = $userID;
		}
		
		$sql = substr($sql, 0, -6) . " ORDER BY time ASC LIMIT 1";
		
		return Zotero_DB::valueQuery($sql, $params);
	}
}
?>
