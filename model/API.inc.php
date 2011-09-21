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
	public static $maxBibliographyItems = 150;
	public static $maxWriteItems = 50;
	
	private static $defaultQueryParams = array(
		'format' => "atom",
		'order' => "dateAdded",
		'sort' => "desc",
		'emptyFirst' => false,
		'start' => 0,
		'limit' => 50,
		'fq' => '',
		'q' => '',
		'pprint' => false,
		
		// format='atom'
		'content' => "html",
		
		// format='bib'
		'style' => "chicago-note-bibliography",
		'css' => "inline",
		
		// search
		'itemKey' => '',
		'tag' => '',
		'tagType' => ''
	);
	
	
	/**
	 * Parse query string into parameters, validating and filling in defaults
	 */
	public static function parseQueryParams($queryString) {
		// Handle multiple identical parameters in the CGI-standard way instead of
		// PHP's foo[]=bar way
		$getParams = Zotero_URL::proper_parse_str($queryString);
		$queryParams = array();
		
		foreach (self::getDefaultQueryParams() as $key=>$val) {
			// Don't overwrite 'sort' or 'emptyFirst' if already derived from 'order'
			if (($key == 'sort' && !empty($queryParams['sort']))
				|| ($key == 'emptyFirst' && !empty($queryParams['emptyFirst']))) {
				continue;
			}
			
			// Fill defaults
			$queryParams[$key] = $val;
			
			// Set an arbitrary limit in bib mode, above which we'll return an
			// error below, since sorting and limiting doesn't make sense for
			// bibliographies sorted by citeproc-js
			if (isset($getParams['format']) && $getParams['format'] == 'bib') {
				switch ($key) {
					case 'limit':
						// Use +1 here, since test elsewhere uses just $maxBibliographyItems
						$queryParams['limit'] = Zotero_API::$maxBibliographyItems + 1;
						break;
					
					// Use defaults
					case 'order':
					case 'sort':
					case 'start':
					case 'content':
						continue 2;
				}
			}
			
			if (!isset($getParams[$key])) {
				continue;
			}
			
			switch ($key) {
				case 'format':
					switch ($getParams[$key]) {
						case 'atom':
						case 'bib':
							break;
						
						default:
							if (in_array($getParams[$key], Zotero_Translate::$exportFormats)) {
								break;
							}
							throw new Exception("Invalid 'format' value '" . $getParams[$key] . "'", Z_ERROR_INVALID_INPUT);
					}
					break;
				
				case 'start':
				case 'limit':
					if ($key == 'limit') {
						// Enforce max on 'limit'
						if ((int) $getParams[$key] > 100) {
							$getParams[$key] = 100;
						}
						// Use default if 0 or invalid
						else if ((int) $getParams[$key] == 0) {
							continue 2;
						}
					}
					$queryParams[$key] = (int) $getParams[$key];
					continue 2;
				
				case 'content':
					switch ($getParams[$key]) {
						case 'none':
						case 'html':
						case 'bib':
						case 'json':
						case 'full':
							break;
						
						default:
							if (in_array($getParams[$key], Zotero_Translate::$exportFormats)) {
								break;
							}
							throw new Exception("Invalid 'content' value '" . $getParams[$key] . "'", Z_ERROR_INVALID_INPUT);
					}
					break;
				
				case 'order':
					// Whether to sort empty values first
					$queryParams['emptyFirst'] = Zotero_API::getSortEmptyFirst($getParams[$key]);
					
					switch ($getParams[$key]) {
						// Valid fields to sort by
						//
						// Allow all fields available in client
						case 'dateAdded':
						case 'dateModified':
						case 'title':
						case 'creator':
						case 'type':
						case 'date':
						case 'publisher':
						case 'publication':
						case 'journalAbbreviation':
						case 'language':
						case 'accessDate':
						case 'libraryCatalog':
						case 'callNumber':
						case 'rights':
						case 'dateAdded':
						case 'dateModified':
						//case 'numChildren':
						
						case 'addedBy':
						case 'numItems':
							
							// numItems is valid only for tags requests
							switch ($getParams[$key]) {
								case 'numItems':
									if ($action != 'tags') {
										throw new Exception("Invalid 'order' value '" . $getParams[$key] . "'", Z_ERROR_INVALID_INPUT);
									}
									break;
							}
							
							if (!isset($getParams['sort']) || !in_array($getParams['sort'], array('asc', 'desc'))) {
								$queryParams['sort'] = self::getDefaultSort($getParams[$key]);
							}
							else {
								$queryParams['sort'] = $getParams['sort'];
							}
							break;
						
						default:
							throw new Exception("Invalid 'order' value '" . $getParams[$key] . "'", Z_ERROR_INVALID_INPUT);
					}
					break;
				
				// If sort and no order
				case 'sort':
					if (!isset($getParams['order']) || !in_array($getParams['sort'], array('asc', 'desc'))) {
						$queryParams['sort'] = self::getDefaultSort($getParams[$key]);
						continue 2;
					}
					break;
			}
			
			$queryParams[$key] = $getParams[$key];
		}
		
		return $queryParams;
	}
	
	
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
	
	
	public static function getCollectionURI(Zotero_Collection $collection) {
		return self::getLibraryURI($collection->libraryID) . "/collections/$collection->key";
	}
	
	
	public static function getItemsURI($libraryID) {
		return self::getLibraryURI($libraryID) . "/items";
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
	
	
	public static function getSortEmptyFirst($field) {
		switch ($field) {
			case 'title':
			case 'date':
				return true;
		}
		
		return false;
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
			$parts = preg_split("/\s+\|\|\s+/", $val);
			
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
}
?>
