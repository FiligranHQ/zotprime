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

class Zotero_API {
	private static $defaultQueryParams = array(
		'format' => "atom",
		'order' => "dateAdded",
		'sort' => "desc",
		'start' => 0,
		'limit' => 50,
		'q' => null,
		'fq' => null,
		'content' => "html",
		'pprint' => false
	);
	
	
	public static function getLibraryURI($libraryID) {
		$libraryType = Zotero_Libraries::getType($libraryID);
		switch ($libraryType) {
			case 'user':
				$id = Zotero_Users::getUserIDFromLibraryID($libraryID);
				return self::getBaseURI() . "users/$id";
			
			case 'group':
				$id = Zotero_Groups::getGroupIDFromLibraryID($libraryID);
				return self::getBaseURI() . "groups/$id";
		}
	}
	
	
	public static function getUserURI($userID) {
		return self::getBaseWWWURI() . "users/$userID";
	}
	
	
	public static function getUserURIFromUsername($username) {
		return self::getBaseWWWURI() . "users/" . Zotero_Utilities::slugify($username);
	}
	
	
	public static function getItemURI(Zotero_Item $item) {
		return self::getLibraryURI($item->libraryID) . "/items/$item->id";
	}
	
	
	public static function getKeyURI(Zotero_Key $key) {
		return self::getbaseURI() . $key->userID . "/keys/" . urlencode($key->key);
	}
	
	
	public static function getDefaultQueryParams() {
		return self::$defaultQueryParams;
	}
	
	
	public static function getNonDefaultQueryParams($params) {
		$nonDefault = array();
		foreach ($params as $key=>$val) {
			if (isset(self::$defaultQueryParams[$key]) && self::$defaultQueryParams[$key] != $val) {
				$nonDefault[$key] = $val;
			}
		}
		return $nonDefault;
	}
	
	private static function getBaseURI() {
		return Z_CONFIG::$API_BASE_URI;
	}
	
	// TEMP
	private static function getBaseWWWURI() {
		if (!empty(Z_CONFIG::$API_BASE_URI_WWW)) {
			$baseURI = Z_CONFIG::$API_BASE_URI_WWW;
		}
		else {
			$baseURI = Z_CONFIG::$API_BASE_URI;
		}
		
		return preg_replace('/(https?:\/\/)/', "$1" . Z_CONFIG::$API_SUPER_USERNAME . ":" . Z_CONFIG::$API_SUPER_PASSWORD . "@", $baseURI);
	}
}
?>
