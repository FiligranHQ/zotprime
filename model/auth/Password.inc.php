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

class Zotero_AuthenticationPlugin_Password implements Zotero_AuthenticationPlugin {
	public static function authenticate($data) {
		$salt = Z_CONFIG::$AUTH_SALT;
		
		// TODO: config
		$dev = Z_ENV_TESTING_SITE ? "_test" : "";
		$databaseName = "zotero_www{$dev}";
		
		$username = $data['username'];
		$password = $data['password'];
		$isEmailAddress = strpos($username, '@') !== false;
		
		$cacheKey = 'userAuthHash_' . hash('sha256', $username . password_hash($password, PASSWORD_DEFAULT));
		$userID = Z_Core::$MC->get($cacheKey);
		if ($userID) {
			return $userID;
		}
		
		// Username
		if (!$isEmailAddress) {
			$sql = "SELECT userID, username, password AS hash FROM $databaseName.users WHERE username=?";
			$params = [$username];
		}
		else {
			$sql = "SELECT userID, username, password AS hash FROM $databaseName.users
			   WHERE username = ?
			   UNION
			   SELECT userID, username, password AS hash FROM $databaseName.users
			   WHERE email = ?
			   ORDER BY username = ? DESC";
			$params = [$username, $username, $username];
		}
		
		try {
			$retry = true;
			$rows = Zotero_WWW_DB_2::query($sql, $params);
			if (!$rows) {
				$retry = false;
				$rows = Zotero_WWW_DB_1::query($sql, $params);
			}
		}
		catch (Exception $e) {
			if ($retry) {
				Z_Core::logError("WARNING: $e -- retrying on primary");
				$rows = Zotero_WWW_DB_1::query($sql, $params);
			}
		}
		
		if (!$rows) {
			return false;
		}
		
		$found = false;
		foreach ($rows as $row) {
			// Try bcrypt
			$found = password_verify($password, $row['hash']);
			
			// Try salted SHA1
			if (!$found) {
				$found = sha1($salt . $password) == $row['hash'];
			}
			
			// Try MD5
			if (!$found) {
				$found = md5($password) == $row['hash'];
			}
			
			if ($found) {
				$foundRow = $row;
				break;
			}
		}
		
		if (!$found) {
			return false;
		}
		
		self::updateUser($foundRow['userID'], $foundRow['username']);
		Z_Core::$MC->set($cacheKey, $foundRow['userID'], 60);
		return $foundRow['userID'];
	}
	
	
	private static function updateUser($userID, $username) {
		if (Zotero_Users::exists($userID)) {
			$currentUsername = Zotero_Users::getUsername($userID, true);
			if ($currentUsername != $username) {
				Zotero_Users::update($userID, $username);
			}
		}
		else {
			Zotero_Users::add($userID, $username);
			Zotero_Users::update($userID);
		}
	}
}
?>
