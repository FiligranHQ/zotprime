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

class Zotero_Keys {
	public static function getByKey($key) {
		$sql = "SELECT keyID FROM `keys` WHERE `key`=?";
		$keyID = Zotero_DB::valueQuery($sql, $key);
		if (!$keyID) {
			return false;
		}
		$keyObj = new Zotero_Key;
		$keyObj->id = $keyID;
		return $keyObj;
	}
	
	
	public static function getUserKeys($userID) {
		$keys = array();
		$keyIDs = Zotero_DB::columnQuery("SELECT keyID FROM `keys` WHERE userID=?", $userID);
		if ($keyIDs) {
			foreach ($keyIDs as $keyID) {
				$keyObj = new Zotero_Key;
				$keyObj->id = $keyID;
				$keys[] = $keyObj;
			}
		}
		return $keys;
	}
	
	
	public static function authenticate($key) {
		$keyObj = self::getByKey($key);
		if (!$keyObj) {
			// TODO: log auth failure
			return false;
		}
		$keyObj->logAccess();
		return $keyObj;
	}
	
	
	public static function generate() {
		$tries = 5;
		while ($tries > 0) {
			$str = Zotero_Utilities::randomString(24, 'mixed');
			$sql = "SELECT COUNT(*) FROM `keys` WHERE `key`=?";
			if (Zotero_DB::valueQuery($sql, $str)) {
				$tries--;
				continue;
			}
			return $str;
		}
		throw new Exception("Unique key could not be generated");
	}
}
?>
