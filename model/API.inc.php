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
	public static $maxBibliographyItems = 500;
	
	private static $defaultQueryParams = array(
		'format' => "atom",
		'order' => "dateAdded",
		'sort' => "desc",
		'start' => 0,
		'limit' => 50,
		'q' => '',
		'pprint' => false,
		
		// format='atom'
		'content' => "html",
		
		// format='bib'
		'style' => "chicago-note-bibliography",
		'css' => "inline",
		
		// search
		'tag' => '',
		'tagType' => ''
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
	
	
	public static function getCollectionURI(Zotero_Collection $collection) {
		return self::getLibraryURI($collection->libraryID) . "/collections/$collection->key";
	}
	
	
	public static function getItemURI(Zotero_Item $item) {
		return self::getLibraryURI($item->libraryID) . "/items/$item->key";
	}
	
	
	public static function getTagURI(Zotero_Tag $tag) {
		return self::getLibraryURI($tag->libraryID) . "/tags/$tag->key";
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
				// Don't include 'limit' as a non-default parameter in bib mode,
				// since it's just used internally to enforce a maximum bib size
				if ($key == 'limit' && $params['format'] == 'bib' && $val > self::$maxBibliographyItems) {
					continue;
				}
				
				$nonDefault[$key] = $val;
			}
		}
		return $nonDefault;
	}
	
	
	public static function getDefaultSort($field) {
		// Use descending for date fields
		// TODO: use predefined field formats
		if (strpos($field, 'date') === 0) {
			return 'desc';
		}
		
		switch ($field) {
			default:
				return 'asc';
		}
	}
	
	
	public static function getSearchParamValues($params, $param) {
		if (!isset($params[$param])) {
			return false;
		}
		
		$vals = is_array($params[$param]) ? $params[$param] : array($params[$param]);
		
		$sets = array();
		
		foreach ($vals as $val) {
			$val = trim($val);
			if ($val === '') {
				continue;
			}
			
			$negation = false;
			
			// Negation
			if ($val[0] == "-") {
				$negation = true;
				$val = substr($val, 1);
			}
			// Literal hyphen
			else if (substr($val, 0, 2) == '\-') {
				$val = substr($val, 1);
			}
			
			// Separate into boolean OR parts
			$parts = preg_split("/\s+OR\s+/", $val);
			
			$val = array(
				'negation' => $negation,
				'values' => $parts
			);
			
			$sets[] = $val;
		}
		
		return $sets;
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
