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
		
		$cacheKey = 'userAuthHash_' . sha1($username . $salt . $password);
		$userID = Z_Core::$MC->get($cacheKey);
		if ($userID) {
			return $userID;
		}
		
		// Query the database looking for an MD5 hashed password
		$passwordMd5 = md5($password);
		if (!$isEmailAddress) {
			// Try username
			$sql = "SELECT userID, username FROM $databaseName.users
					   WHERE username = ? AND password = ? LIMIT 1";
			$params = array($username, $passwordMd5);
			if (Z_Core::probability(2)) {
				try {
					$row = Zotero_WWW_DB_1::rowQuery($sql, $params);
				}
				catch (Exception $e) {
					$row = Zotero_WWW_DB_2::rowQuery($sql, $params);
				}
			}
			else {
				try {
					$row = Zotero_WWW_DB_2::rowQuery($sql, $params);
				}
				catch (Exception $e) {
					$row = Zotero_WWW_DB_1::rowQuery($sql, $params);
				}
			}
		}
		else {
			// Try both username and e-mail address
			$sql = "SELECT userID, username FROM $databaseName.users
					   WHERE username = ? AND password = ?
					   UNION
					   SELECT userID, username FROM $databaseName.users
					   WHERE email = ? AND password = ?
					   ORDER BY username = ? DESC
					   LIMIT 1";
			$params = array($username, $passwordMd5, $username, $passwordMd5, $username);
			if (Z_Core::probability(2)) {
				try {
					$row = Zotero_WWW_DB_1::rowQuery($sql, $params);
				}
				catch (Exception $e) {
					$row = Zotero_WWW_DB_2::rowQuery($sql, $params);
				}
			}
			else {
				try {
					$row = Zotero_WWW_DB_2::rowQuery($sql, $params);
				}
				catch (Exception $e) {
					$row = Zotero_WWW_DB_1::rowQuery($sql, $params);
				}
			}
		}
		
		if ($row) {
			self::updateUser($row['userID'], $row['username']);
			Z_Core::$MC->set($cacheKey, $row['userID'], 60);
			return $row['userID'];
		}
		
		// Query the database looking for a salted sha1 password
		$passwordSha1 = sha1($salt . $password);
		if (!$isEmailAddress) {
			// Try username
			$sql = "SELECT userID, username FROM $databaseName.users
					   WHERE username = ? AND password = ?
					   LIMIT 1";
			$params = array($username, $passwordSha1);
			if (Z_Core::probability(2)) {
				try {
					$row = Zotero_WWW_DB_1::rowQuery($sql, $params);
				}
				catch (Exception $e) {
					$row = Zotero_WWW_DB_2::rowQuery($sql, $params);
				}
			}
			else {
				try {
					$row = Zotero_WWW_DB_2::rowQuery($sql, $params);
				}
				catch (Exception $e) {
					$row = Zotero_WWW_DB_1::rowQuery($sql, $params);
				}
			}
		}
		else {
			// Try both username and e-mail address
			$sql = "SELECT userID, username FROM $databaseName.users
					   WHERE username = ? AND password = ?
					   UNION
					   SELECT userID, username FROM $databaseName.users
					   WHERE email = ? AND password = ?
					   ORDER BY username = ? DESC
					   LIMIT 1";
			$params = array($username, $passwordMd5, $username, $passwordSha1, $username);
			if (Z_Core::probability(2)) {
				try {
					$row = Zotero_WWW_DB_1::rowQuery($sql, $params);
				}
				catch (Exception $e) {
					$row = Zotero_WWW_DB_2::rowQuery($sql, $params);
				}
			}
			else {
				try {
					$row = Zotero_WWW_DB_2::rowQuery($sql, $params);
				}
				catch (Exception $e) {
					$row = Zotero_WWW_DB_1::rowQuery($sql, $params);
				}
			}
		}
		
		if ($row) {
			self::updateUser($row['userID'], $row['username']);
			Z_Core::$MC->set($cacheKey, $row['userID'], 60);
			return $row['userID'];
		}
		return false;
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
