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
	public static $maxWriteCollections = 50;
	public static $maxWriteItems = 50;
	public static $maxWriteSearches = 50;
	public static $maxWriteSettings = 50;
	public static $maxTranslateItems = 10;
	
	private static $defaultQueryParams = array(
		'format' => "atom",
		
		// format='atom'
		'content' => array("html"),
		
		// format='bib'
		'style' => "chicago-note-bibliography",
		'css' => "inline",
		'linkwrap' => 0,
		
		// search
		'fq' => '',
		'q' => '',
		'qmode' => 'titleCreatorYear',
		'itemType' => '',
		'itemKey' => array(),
		'collectionKey' => array(),
		'searchKey' => array(),
		'tag' => '',
		'tagType' => '',
		'newer' => 0,
		'newertime' => 1,
		
		'order' => "dateAdded",
		'sort' => "desc",
		'start' => 0,
		'limit' => 50,
		
		// For internal use only
		'apiVersion' => 1,
		'emptyFirst' => false
	);
	
	
	/**
	 * Parse query string into parameters, validating and filling in defaults
	 */
	public static function parseQueryParams($queryString, $action, $singleObject, $apiVersion=false) {
		// Handle multiple identical parameters in the CGI-standard way instead of
		// PHP's foo[]=bar way
		$getParams = Zotero_URL::proper_parse_str($queryString);
		$queryParams = array();
		
		foreach (self::getDefaultQueryParams() as $key=>$val) {
			// Don't overwrite field if already derived from another field
			if (!empty($queryParams[$key])) {
				continue;
			}
			
			if ($key == 'apiVersion' && $apiVersion == 2) {
				$val = $apiVersion;
			}
			
			if ($key == 'limit') {
				$val = self::getDefaultLimit(isset($getParams['format']) ? $getParams['format'] : "");
			}
			
			// Fill defaults
			$queryParams[$key] = $val;
			
			// Ignore private parameters in the URL
			if (in_array($key, self::getPrivateQueryParams($key)) && isset($getParams[$key])) {
				continue;
			}
			
			// If no parameter passed, use default
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
				else if (in_array($getParams['format'], array('keys', 'versions'))) {
					switch ($key) {
						// Invalid parameters
						case 'start':
							throw new Exception("'$key' is not valid for format={$getParams['format']}", Z_ERROR_INVALID_INPUT);
					}
				}
			}
			
			switch ($key) {
				case 'format':
					$format = $getParams[$key];
					$isExportFormat = in_array($format, Zotero_Translate::$exportFormats);
					
					if (!self::isValidFormatForAction($action, $format, $singleObject)) {
						throw new Exception("Invalid 'format' value '$format'", Z_ERROR_INVALID_INPUT);
					}
					
					// Since the export formats and csljson don't give a clear indication
					// of limiting or rel="next" links, require an explicit limit
					// for everything other than single items and itemKey queries
					if ($isExportFormat || $format == 'csljson') {
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
					break;
				
				case 'newer':
				case 'newertime':
					if (!is_numeric($getParams[$key])) {
						throw new Exception("Invalid value for '$key' parameter", Z_ERROR_INVALID_INPUT);
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
						case 'serverDateModified':
						
						case 'collectionKeyList':
						case 'itemKeyList':
						case 'searchKeyList':
							
							switch ($getParams[$key]) {
								// numItems is valid only for tags requests
								case 'numItems':
									if ($action != 'tags') {
										throw new Exception("Invalid 'order' value '" . $getParams[$key] . "'", Z_ERROR_INVALID_INPUT);
									}
									break;
								
								case 'collectionKeyList':
									if ($action != 'collections') {
										throw new Exception("order=collectionKeyList is not valid for this request");
									}
									if (!isset($getParams['collectionKey'])) {
										throw new Exception("order=collectionKeyList requires the collectionKey parameter");
									}
									break;
								
								case 'itemKeyList':
									if ($action != 'items') {
										throw new Exception("order=itemKeyList is not valid for this request");
									}
									if (!isset($getParams['itemKey'])) {
										throw new Exception("order=itemKeyList requires the itemKey parameter");
									}
									break;
								
								case 'searchKeyList':
									if ($action != 'searches') {
										throw new Exception("order=searchKeyList is not valid for this request");
									}
									if (!isset($getParams['searchKey'])) {
										throw new Exception("order=searchKeyList requires the searchKey parameter");
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
					if (!in_array($getParams[$key], array('asc', 'desc'))) {
						throw new Exception("Invalid '$key' value '" . $getParams[$key] . "'", Z_ERROR_INVALID_INPUT);
					}
					break;
				
				case 'qmode':
					if (!in_array($getParams[$key], array('titleCreatorYear', 'everything'))) {
						throw new Exception("Invalid '$key' value '" . $getParams[$key] . "'", Z_ERROR_INVALID_INPUT);
					}
					break;
				
				case 'collectionKey':
				case 'itemKey':
				case 'searchKey':
					// Allow leading/trailing commas
					$objectKeys = trim($getParams[$key], ",");
					$objectKeys = explode(",", $objectKeys);
					// Make sure all keys are plausible
					foreach ($objectKeys as $objectKey) {
						if (!Zotero_ID::isValidKey($objectKey)) {
							throw new Exception("Invalid '$key' value '" . $getParams[$key] . "'", Z_ERROR_INVALID_INPUT);
						}
					}
					$getParams[$key] = $objectKeys;
					break;
			}
			
			$queryParams[$key] = $getParams[$key];
		}
		
		return $queryParams;
	}
	
	
	public static function getBaseURI() {
		return Z_CONFIG::$API_BASE_URI;
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
	
	
	public static function getUserURI($userID) {
		return self::getBaseURI() . "users/$userID";
	}
	
	
	public static function getGroupURI(Zotero_Group $group) {
		return self::getBaseURI() . "groups/$group->id";
	}
	
	
	public static function getGroupUserURI(Zotero_Group $group, $userID) {
		return self::getGroupURI($group) . "/users/$userID";
	}
	
	
	public static function getCollectionURI(Zotero_Collection $collection) {
		return self::getLibraryURI($collection->libraryID) . "/collections/$collection->key";
	}
	
	
	public static function getCollectionsURI($libraryID) {
		return self::getLibraryURI($libraryID) . "/collections";
	}
	
	
	public static function getCreatorURI(Zotero_Creator $creator) {
		return self::getLibraryURI($creator->libraryID) . "/creators/$creator->key";
	}
	
	
	public static function getItemURI(Zotero_Item $item) {
		return self::getLibraryURI($item->libraryID) . "/items/$item->key";
	}
	
	
	public static function getItemsURI($libraryID) {
		return self::getLibraryURI($libraryID) . "/items";
	}
	
	
	public static function getSearchURI(Zotero_Search $search) {
		return self::getLibraryURI($search->libraryID) . "/searches/$search->key";
	}
	
	
	public static function getTagURI(Zotero_Tag $tag) {
		return self::getLibraryURI($tag->libraryID) . "/tags/" . urlencode($tag->name);
	}
	
	
	public static function getKeyURI(Zotero_Key $key) {
		return self::getBaseURI() . $key->userID . "/keys/" . urlencode($key->key);
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
	
	
	public static function getPublicQueryParams($params) {
		$private = self::getPrivateQueryParams();
		$filtered = array();
		foreach ($params as $key => $val) {
			if (!in_array($key, $private)) {
				$filtered[$key] = $val;
			}
		}
		return $filtered;
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
	
	
	public static function isValidFormatForAction($action, $format, $singleObject=false) {
		$isExportFormat = in_array($format, Zotero_Translate::$exportFormats);
		
		if ($action == 'items') {
			if ($isExportFormat) {
				return true;
			}
			
			switch ($format) {
				case 'atom':
				case 'bib':
				case 'globalKeys':
					return true;
				
				case 'keys':
				case 'versions':
					if (!$singleObject) {
						return true;
					}
					break;
			}
			return false;
		}
		else if ($action == 'collections' || $action == 'searches') {
			switch ($format) {
			case 'keys':
			case 'versions':
				if (!$singleObject) {
					return true;
				}
				break;
			}
		}
		else if ($action == 'fulltext') {
			return $format == 'versions';
		}
		else if ($action == 'groups') {
			switch ($format) {
				case 'atom':
				case 'etags':
					return true;
			}
			return false;
		}
		// All other actions must be Atom
		return $format == 'atom';
	}
	
	
	public static function getDefaultLimit($format="") {
		switch ($format) {
			case 'keys':
			case 'versions':
				return 0;
			
			case 'bib':
				return self::$maxBibliographyItems;
		}
		
		return self::$defaultQueryParams['limit'];
	}
	
	
	public static function getLimitMax($format="") {
		switch ($format) {
			case 'keys':
			case 'versions':
				return 0;
			
			case 'bib':
				return self::$maxBibliographyItems;
		}
		
		return 100;
	}
	
	
	public static function getSortEmptyFirst($field) {
		switch ($field) {
			case 'title':
			case 'date':
			case 'collectionKeyList':
			case 'itemKeyList':
			case 'searchKeyList':
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
	
	
	/**
	 * Validate the object key from JSON and load the passed object with it
	 *
	 * @param object $object  Zotero_Item, Zotero_Collection, or Zotero_Search
	 * @param json $json
	 * @return boolean  True if the object exists, false if not
	 */
	public static function processJSONObjectKey($object, $json) {
		$objectType = Zotero_Utilities::getObjectTypeFromObject($object);
		if (!in_array($objectType, array('item', 'collection', 'search'))) {
			throw new Exception("Invalid object type");
		}
		
		$keyProp = $objectType . "Key";
		$versionProp = $objectType . "Version";
		$objectVersionProp = $objectType == 'item' ? 'itemVersion' : 'version';
		
		// Validate the object key if present and determine if the object is new
		if (isset($json->$keyProp)) {
			if (!is_string($json->$keyProp)) {
				throw new Exception(
					"'$keyProp' must be a string", Z_ERROR_INVALID_INPUT
				);
			}
			if (!Zotero_ID::isValidKey($json->$keyProp)) {
				throw new Exception("'" . $json->$keyProp . "' "
					. "is not a valid $objectType key", Z_ERROR_INVALID_INPUT
				);
			}
			if ($object->key) {
				if ($json->$keyProp != $object->key) {
					throw new HTTPException("$keyProp in JSON does not match "
						. "$objectType key of request", 409);
				}
				
				$exists = !!$object->id;
			}
			else {
				$object->key = $json->$keyProp;
				$exists = !!$object->id;
			}
		}
		else {
			$exists = !!$object->key;
		}
		
		return $exists;
	}
	
	
	/**
	 * @param object $object Zotero object (Zotero_Item, Zotero_Collection, Zotero_Search, Zotero_Setting)
	 * @param object $json JSON object to check
	 * @param array $requestParams
	 * @param int $requireVersion If 0, don't require; if 1, require if there's
	 *                            an object key property in the JSON; if 2,
	 *                            always require
	 */
	public static function checkJSONObjectVersion($object, $json, $requestParams, $requireVersion) {
		$objectType = Zotero_Utilities::getObjectTypeFromObject($object);
		if (!in_array($objectType, array('item', 'collection', 'search', 'setting'))) {
			throw new Exception("Invalid object type");
		}
		
		$keyProp = $objectType . "Key";
		$versionProp = $objectType == 'setting' ? 'version' : $objectType . "Version";
		$objectVersionProp = $objectType == 'item' ? 'itemVersion' : 'version';
		
		if (isset($json->$versionProp)) {
			if ($requestParams['apiVersion'] < 2) {
				throw new Exception(
					"Invalid property '$versionProp'", Z_ERROR_INVALID_INPUT
				);
			}
			if (!is_numeric($json->$versionProp)) {
				throw new Exception(
					"'$versionProp' must be an integer", Z_ERROR_INVALID_INPUT
				);
			}
			if (!isset($json->$keyProp) && $objectType != 'setting') {
				throw new Exception(
					"'$versionProp' is valid only with '$keyProp' property",
					Z_ERROR_INVALID_INPUT
				);
			}
			if ($object->$objectVersionProp > $json->$versionProp) {
				throw new HTTPException(ucwords($objectType)
					. " has been modified since specified version "
					. "(expected {$json->$versionProp}, found {$object->$objectVersionProp})"
					, 412);
			}
		}
		else {
			if ($requireVersion == 1 && isset($json->$keyProp)) {
				if ($objectType == 'setting') {
					throw new HTTPException(
						"Either If-Unmodified-Since-Version or "
						. "'$versionProp' property must be provided", 428
					);
				}
				else {
					throw new HTTPException(
						"Either If-Unmodified-Since-Version or "
						. "'$versionProp' property must be provided for "
						. "'$keyProp'-based writes", 428
					);
				}
			}
			else if ($requireVersion == 2) {
				throw new HTTPException(
					"Either If-Unmodified-Since-Version or "
					. "'$versionProp' property must be provided for "
					. "single-$objectType writes", 428
				);
			}
		}
	}
	
	
	private static function getPrivateQueryParams() {
		return array('apiVersion', 'emptyFirst');
	}
}
?>
