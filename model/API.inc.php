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
		'content' => array("html"),
		
		// format='bib'
		'style' => "chicago-note-bibliography",
		'css' => "inline",
		
		// search
		'itemKey' => '',
		'itemType' => '',
		'tag' => '',
		'tagType' => ''
	);
	
	
	/**
	 * Parse query string into parameters, validating and filling in defaults
	 */
	public static function parseQueryParams($queryString, $action, $singleObject) {
		// Handle multiple identical parameters in the CGI-standard way instead of
		// PHP's foo[]=bar way
		$getParams = Zotero_URL::proper_parse_str($queryString);
		$queryParams = array();
		
		foreach (self::getDefaultQueryParams() as $key=>$val) {
			// Don't overwrite field if already derived from another field
			if (!empty($queryParams[$key])) {
				continue;
			}
			
			if ($key == 'limit') {
				$val = self::getDefaultLimit(isset($getParams['format']) ? $getParams['format'] : "");
			}
			
			// Fill defaults
			$queryParams[$key] = $val;
			
			// If no parameter passed, used default
			if (!isset($getParams[$key])) {
				continue;
			}
			
			// Some formats need special parameter handling
			if (isset($getParams['format'])) {
				if ($getParams['format'] == 'bib') {
					switch ($key) {
						// Invalid parameters
						case 'order':
						case 'sort':
						case 'start':
						case 'limit':
							throw new Exception("'$key' is not valid for format=bib", Z_ERROR_INVALID_INPUT);
					}
				}
				else if ($getParams['format'] == 'keys') {
					switch ($key) {
						// Invalid parameters
						case 'start':
							throw new Exception("'$key' is not valid for format=bib", Z_ERROR_INVALID_INPUT);
					}
				}
			}
			
			switch ($key) {
				case 'format':
					$format = $getParams[$key];
					$isExportFormat = in_array($format, Zotero_Translate::$exportFormats);
					
					// All actions other than items must be Atom
					if ($action != 'items') {
						if ($format != 'atom') {
							throw new Exception("Invalid 'format' value '$format'", Z_ERROR_INVALID_INPUT);
						}
					}
					// Since the export formats and csljson don't give a clear indication
					// of limiting or rel="next" links, require an explicit limit
					// for everything other than single items and itemKey queries
					else if ($isExportFormat || $format == 'csljson') {
						if ($singleObject || !empty($getParams['itemKey'])) {
							break;
						}
						
						$limitMax = self::getLimitMax($format);
						if (empty($getParams['limit'])) {
							throw new Exception("'limit' is required for format=$format", Z_ERROR_INVALID_INPUT);
						}
						// Also make the maximum limit explicit
						// TODO: Do this for all formats?
						else if ($getParams['limit'] > $limitMax) {
							throw new Exception("'limit' cannot be greater than $limitMax for format=$format", Z_ERROR_INVALID_INPUT);
						}
					}
					else {
						switch ($format) {
							case 'atom':
							case 'bib':
								break;
							
							default:
								if ($format == 'keys' && !$singleObject) {
									break;
								}
								throw new Exception("Invalid 'format' value '$format' for request", Z_ERROR_INVALID_INPUT);
						}
					}
					break;
				
				case 'start':
					$queryParams[$key] = (int) $getParams[$key];
					continue 2;
					
				case 'limit':
					// Maximum limit depends on 'format'
					$limitMax = self::getLimitMax(isset($getParams['format']) ? $getParams['format'] : "");
					
					// If there's a maximum, enforce it
					if ($limitMax && (int) $getParams[$key] > $limitMax) {
						$getParams[$key] = $limitMax;
					}
					// Use default if 0 or invalid
					else if ((int) $getParams[$key] == 0) {
						continue 2;
					}
					$queryParams[$key] = (int) $getParams[$key];
					continue 2;
				
				case 'content':
					if (isset($getParams['format']) && $getParams['format'] != 'atom') {
						throw new Exception("'content' is valid only for format=atom", Z_ERROR_INVALID_INPUT);
					}
					$getParams[$key] = array_values(array_unique(explode(',', $getParams[$key])));
					sort($getParams[$key]);
					foreach ($getParams[$key] as $value) {
						switch ($value) {
							case 'none':
							case 'full':
								if (sizeOf($getParams[$key]) > 1) {
									throw new Exception(
										"content=$value is not valid in "
											. "multi-format responses",
										Z_ERROR_INVALID_INPUT
									);
								}
								break;
								
							case 'html':
							case 'citation':
							case 'bib':
							case 'json':
							case 'csljson':
								break;
							
							default:
								if (in_array($value, Zotero_Translate::$exportFormats)) {
									break;
								}
								throw new Exception("Invalid 'content' value '$value'", Z_ERROR_INVALID_INPUT);
						}
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
						case 'itemType':
						case 'date':
						case 'publisher':
						case 'publicationTitle':
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
							
							if (!isset($getParams['sort'])) {
								$queryParams['sort'] = self::getDefaultSort($getParams[$key]);
							}
							else if (!in_array($getParams['sort'], array('asc', 'desc'))) {
								throw new Exception("Invalid 'sort' value '" . $getParams['sort'] . "'", Z_ERROR_INVALID_INPUT);
							}
							else {
								$queryParams['sort'] = $getParams['sort'];
							}
							break;
						
						default:
							throw new Exception("Invalid 'order' value '" . $getParams[$key] . "'", Z_ERROR_INVALID_INPUT);
					}
					break;
				
				case 'sort':
					if (!in_array($getParams['sort'], array('asc', 'desc'))) {
						throw new Exception("Invalid 'sort' value '" . $getParams[$key] . "'", Z_ERROR_INVALID_INPUT);
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
				$nonDefault[$key] = $val;
			}
		}
		return $nonDefault;
	}
	
	
	public static function getDefaultSort($field="") {
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
	
	
	public static function getDefaultLimit($format="") {
		switch ($format) {
			case 'keys':
				return 0;
		}
		
		return self::$defaultQueryParams['limit'];
	}
	
	
	public static function getLimitMax($format="") {
		switch ($format) {
			case 'keys':
				return 0;
		}
		
		return 100;
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
