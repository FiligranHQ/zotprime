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
	const MAX_BIBLIOGRAPHY_ITEMS = 150;
	const MAX_OBJECT_KEYS = 50;
	
	public static $maxWriteCollections = 50;
	public static $maxWriteItems = 50;
	public static $maxWriteSearches = 50;
	public static $maxWriteSettings = 50;
	public static $maxTranslateItems = 10;
	
	private static $validAPIVersions = [1, 2, 3];
	
	
	private static $defaultParams = [
		'v' => 3,
		
		'format' => [
			'v' => [
				'default' => [
					'action' => [
						'default' => 'json',
						'fulltext' => 'versions'
					]
				],
				1 => [
					'action' => [
						'default' => 'atom',
						'fulltext' => 'versions',
						'itemContent' => 'json'
					]
				],
				2 => [
					'action' => [
						'default' => 'atom',
						'fulltext' => 'versions',
						'itemContent' => 'json',
						'deleted' => 'json',
						'settings' => 'json'
					]
				]
			]
		],
		
		'include' => ['data'],
		'content' => ['html'],
		
		// format='bib'
		'style' => "chicago-note-bibliography",
		'css' => "inline",
		'linkwrap' => 0,
		
		// search
		'fq' => '',
		'q' => '',
		'qmode' => 'titleCreatorYear',
		'itemType' => '',
		'itemKey' => [],
		'collectionKey' => [],
		'searchKey' => [],
		'tag' => '',
		'tagType' => '',
		'since' => null,
		'sincetime' => null,
		
		'sort' => [
			'v' => [
				'default' => [
					'format' => [
						'default' => 'dateModified',
						'atom' => 'dateAdded'
					]
				],
				1 => 'dateAdded',
				2 => 'dateAdded'
			]
		],
		'direction' => 'desc',
		'start' => 0,
		'limit' => [
			'v' => [
				'default' => [
					'format' => [
						'default' => 25,
						'bib' => self::MAX_BIBLIOGRAPHY_ITEMS,
						'keys' => 0,
						'versions' => 0
					]
				],
				1 => [
					'format' => [
						'default' => 50,
						'bib' => self::MAX_BIBLIOGRAPHY_ITEMS,
						'keys' => 0,
						'versions' => 0
					]
				],
				2 => [
					'format' => [
						'default' => 50,
						'bib' => self::MAX_BIBLIOGRAPHY_ITEMS,
						'keys' => 0,
						'versions' => 0
					]
				]
			]
		],
		
		// For internal use only
		'emptyFirst' => false
	];
	
	
	/**
	 * Parse query string into parameters, validating and filling in defaults
	 */
	public static function parseQueryParams($queryString, $action, $singleObject, $apiVersion=false, $atomAccepted=false) {
		// Handle multiple identical parameters in the CGI-standard way instead of
		// PHP's foo[]=bar way
		$queryParams = Zotero_URL::proper_parse_str($queryString);
		$finalParams = [];
		
		//
		// Handle some special cases
		//
		// If client accepts Atom, serve it if an explicit format isn't requested
		if ($atomAccepted && empty($queryParams['format'])) {
			$queryParams['format'] = 'atom';
		}
		
		// Set API version based on header
		if ($apiVersion) {
			if (!empty($queryParams['v']) && $apiVersion != $queryParams['v']) {
				throw new Exception("Zotero-API-Version header does not match 'v' query parameter", Z_ERROR_INVALID_INPUT);
			}
			$queryParams['v'] = $apiVersion;
		}
		// v1 documentation specifies 'version' query parameter
		else if (isset($queryParams['version']) && $queryParams['version'] == 1 && !isset($queryParams['v'])) {
			$queryParams['v'] = 1;
			unset($queryParams['version']);
		}
		
		// If format=json, override version to 3
		if (!isset($queryParams['v']) && isset($queryParams['format']) && $queryParams['format'] == 'json') {
			$queryParams['v'] = 3;
		}
		
		$apiVersion = isset($queryParams['v']) ? $queryParams['v'] : self::$defaultParams['v'];
		
		// If 'content', override 'format' to 'atom'
		if (!isset($queryParams['format']) && isset($queryParams['content'])) {
			$queryParams['format'] = 'atom';
		}
		
		// Handle deprecated (in v3) 'order' parameter
		if (isset($queryParams['order'])) {
			// If 'order' is a direction, move it to 'direction'
			if (in_array($queryParams['order'], ['asc', 'desc'])) {
				$finalParams['direction'] = $queryParams['direction'] = $queryParams['order'];
			}
			// Otherwise it's a field, so move it to 'sort'
			else {
				// If 'sort' already has a direction, move that to 'direction' first
				if (isset($queryParams['sort']) && in_array($queryParams['sort'], ['asc', 'desc'])) {
					$finalParams['direction'] = $queryParams['direction'] = $queryParams['sort'];
				}
				
				$queryParams['sort'] = $queryParams['order'];
			}
			unset($queryParams['order']);
		}
		
		// Handle deprecated (in v3) 'newer' and 'newertime' parameters
		if (isset($queryParams['newer'])) {
			if (!isset($queryParams['since'])) {
				$queryParams['since'] = $queryParams['newer'];
			}
			unset($queryParams['newer']);
		}
		if (isset($queryParams['newertime'])) {
			if (!isset($queryParams['sincetime'])) {
				$queryParams['sincetime'] = $queryParams['newertime'];
			}
			unset($queryParams['newertime']);
		}
		
		foreach (self::resolveDefaultParams($action, self::$defaultParams, $queryParams) as $key => $val) {
			// Don't overwrite field if already set (either above or derived from another field)
			if (!empty($finalParams[$key])) {
				continue;
			}
			
			// Fill defaults
			$finalParams[$key] = $val;
			
			// Ignore private parameters in the URL
			if (in_array($key, self::getPrivateParams($key)) && isset($queryParams[$key])) {
				continue;
			}
			
			// If no parameter passed, use default
			if (!isset($queryParams[$key])) {
				continue;
			}
			
			if (isset($finalParams['format'])) {
				$format = $finalParams['format'];
				
				// Some formats need special parameter handling
				if ($format == 'bib') {
					switch ($key) {
					// Invalid parameters
					case 'order':
					case 'sort':
					case 'start':
					case 'limit':
					case 'direction':
						throw new Exception("'$key' is not valid for format=bib", Z_ERROR_INVALID_INPUT);
					}
				}
				else if ($apiVersion < 3 && in_array($format, array('keys', 'versions'))) {
					switch ($key) {
					// Invalid parameters
					case 'start':
						throw new Exception("'$key' is not valid for format=$format", Z_ERROR_INVALID_INPUT);
					}
				}
			}
			
			switch ($key) {
			case 'v':
				if (!in_array($val, self::$validAPIVersions)) {
					throw new Exception("Invalid API version '$val'", Z_ERROR_INVALID_INPUT);
				}
				break;
			
			case 'format':
				if (!self::isValidFormatForAction($action, $val, $singleObject)) {
					throw new Exception("Invalid 'format' value '$val'", Z_ERROR_INVALID_INPUT);
				}
				break;
			
			case 'since':
			case 'sincetime':
				if (!is_numeric($queryParams[$key])) {
					throw new Exception("Invalid value for '$key' parameter", Z_ERROR_INVALID_INPUT);
				}
				break;
			
			case 'start':
				$finalParams[$key] = (int) $queryParams[$key];
				continue 2;
			
			case 'limit':
				// Maximum limit depends on 'format'
				$limitMax = self::getLimitMax($format);
				
				// Since the export formats and csljson don't give a clear indication of limiting or
				// rel="next" links in API v1/2 (before the Link header), require an explicit limit for
				// everything other than single items and itemKey queries
				if ($apiVersion < 3
						&& (in_array($format, Zotero_Translate::$exportFormats) || $format == 'csljson')
						&& !$singleObject
						&& empty($queryParams['itemKey'])) {
					if (empty($queryParams['limit'])) {
						throw new Exception("'limit' is required for format=$format", Z_ERROR_INVALID_INPUT);
					}
					// Also enforce maximum limit
					// TODO: Do this for all formats?
					else if ($queryParams['limit'] > $limitMax) {
						throw new Exception("'limit' cannot be greater than $limitMax for format=$format", Z_ERROR_INVALID_INPUT);
					}
				}
				
				// If there's a maximum, enforce it
				if ($limitMax && (int) $queryParams['limit'] > $limitMax) {
					$queryParams['limit'] = $limitMax;
				}
				// Use default if 0 or invalid
				else if ((int) $queryParams['limit'] == 0) {
					continue 2;
				}
				$finalParams['limit'] = (int) $queryParams['limit'];
				continue 2;
			
			case 'include':
			case 'content':
				if ($key == 'content' && $format != 'atom') {
					throw new Exception("'content' is valid only for format=atom", Z_ERROR_INVALID_INPUT);
				}
				else if ($key == 'include' && $format != 'json') {
					throw new Exception("'include' is valid only for format=json", Z_ERROR_INVALID_INPUT);
				}
				$queryParams[$key] = array_values(array_unique(explode(',', $queryParams[$key])));
				sort($queryParams[$key]);
				foreach ($queryParams[$key] as $value) {
					switch ($value) {
						case 'none':
							if (sizeOf($queryParams[$key]) > 1) {
								throw new Exception(
									"$key=$value is not valid in multi-format responses",
									Z_ERROR_INVALID_INPUT
								);
							}
							break;
							
						case 'html':
						case 'citation':
						case 'bib':
						case 'csljson':
							break;
						
						case 'json':
							if ($format != 'atom') {
								throw new Exception("$key=$value is valid only for format=atom", Z_ERROR_INVALID_INPUT);
							}
							break;
						
						case 'data':
							if ($format != 'json') {
								throw new Exception("$key=$value is valid only for format=json", Z_ERROR_INVALID_INPUT);
							}
							break;
						
						default:
							if (in_array($value, Zotero_Translate::$exportFormats)) {
								break;
							}
							throw new Exception("Invalid '$key' value '$value'", Z_ERROR_INVALID_INPUT);
					}
				}
				break;
			
			case 'sort':
				// If direction, move to 'direction' and use default 'sort' value
				if (in_array($queryParams[$key], array('asc', 'desc'))) {
					$finalParams['direction'] = $queryParams['direction'] = $queryParams[$key];
					continue 2;
				}
				
				// Whether to sort empty values first
				$finalParams['emptyFirst'] = self::getSortEmptyFirst($queryParams[$key]);
				
				switch ($queryParams[$key]) {
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
						
						switch ($queryParams[$key]) {
							// numItems is valid only for tags requests
							case 'numItems':
								if ($action != 'tags') {
									throw new Exception("Invalid 'order' value '" . $queryParams[$key] . "'", Z_ERROR_INVALID_INPUT);
								}
								break;
							
							case 'collectionKeyList':
								if ($action != 'collections') {
									throw new Exception("order=collectionKeyList is not valid for this request");
								}
								if (!isset($queryParams['collectionKey'])) {
									throw new Exception("order=collectionKeyList requires the collectionKey parameter");
								}
								break;
							
							case 'itemKeyList':
								if ($action != 'items') {
									throw new Exception("order=itemKeyList is not valid for this request");
								}
								if (!isset($queryParams['itemKey'])) {
									throw new Exception("order=itemKeyList requires the itemKey parameter");
								}
								break;
							
							case 'searchKeyList':
								if ($action != 'searches') {
									throw new Exception("order=searchKeyList is not valid for this request");
								}
								if (!isset($queryParams['searchKey'])) {
									throw new Exception("order=searchKeyList requires the searchKey parameter");
								}
								break;
						}
						
						if (!isset($queryParams['direction'])) {
							$finalParams['direction'] = self::getDefaultDirection($queryParams[$key]);
						}
						break;
					
					default:
						throw new Exception("Invalid 'sort' value '" . $queryParams[$key] . "'", Z_ERROR_INVALID_INPUT);
				}
				break;
			
			case 'direction':
				if (!in_array($queryParams[$key], array('asc', 'desc'))) {
					throw new Exception("Invalid '$key' value '" . $queryParams[$key] . "'", Z_ERROR_INVALID_INPUT);
				}
				break;
			
			case 'qmode':
				if (!in_array($queryParams[$key], array('titleCreatorYear', 'everything'))) {
					throw new Exception("Invalid '$key' value '" . $queryParams[$key] . "'", Z_ERROR_INVALID_INPUT);
				}
				break;
			
			case 'collectionKey':
			case 'itemKey':
			case 'searchKey':
				// Allow leading/trailing commas
				$objectKeys = trim($queryParams[$key], ",");
				$objectKeys = explode(",", $objectKeys);
				// Make sure all keys are plausible
				foreach ($objectKeys as $objectKey) {
					if (!Zotero_ID::isValidKey($objectKey)) {
						throw new Exception("Invalid '$key' value '" . $queryParams[$key] . "'", Z_ERROR_INVALID_INPUT);
					}
				}
				$queryParams[$key] = $objectKeys;
				
				// Force limit if explicit object keys are used
				$finalParams['limit'] = self::MAX_OBJECT_KEYS;
				break;
			}
			
			$finalParams[$key] = $queryParams[$key];
		}
		
		return $finalParams;
	}
	
	
	/**
	 * Return the parameters not set to their default values
	 */
	public static function getNonDefaultParams($action, $params) {
		$defaults = self::resolveDefaultParams($action, self::$defaultParams, $params);
		
		$nonDefaults = [];
		foreach ($params as $key => $val) {
			switch ($key) {
			case 'direction':
				if ($val == self::getDefaultDirection($params['sort'])) {
					continue 2;
				}
				break;
			}
			
			if (!isset($defaults[$key])) {
				continue;
			}
			
			// Convert default array values to strings
			$default = is_array($defaults[$key]) ? implode(',', $defaults[$key]) : $defaults[$key];
			if ($defaults[$key] == $val) {
				continue;
			}
			
			$nonDefaults[$key] = $val;
		}
		return $nonDefaults;
	}
	
	
	/**
	 * Build query string from given parameters, removing private parameters, adjusting
	 * parameter names based on API version, and converting arrays to strings
	 */
	public static function buildQueryString($apiVersion, $action, $params, $excludeParams=[]) {
		$params = self::removeParams($params, $excludeParams);
		$params = self::removePrivateParams($params);
		$tmpParams = [];
		foreach ($params as $key => $val) {
			if ($apiVersion < 3) {
				if ($key == 'sort') {
					$key = 'order';
				}
				else if ($key == 'direction') {
					$key = 'sort';
				}
			}
			$tmpParams[$key] = $val;
		}
		$params = $tmpParams;
		
		if (!$params) {
			return "";
		}
		// Sort parameters alphabetically
		ksort($params);
		foreach ($params as $key => $val) {
			if (is_array($val)) {
				$params[$key] = implode(',', $val);
			}
		}
		return '?' . http_build_query($params);
	}
	
	
	/**
	 * Generate self/first/prev/next/last/alternate links
	 */
	public static function buildLinks($action, $path, $totalResults, $queryParams, $nonDefaultParams, $excludeParams=[]) {
		$apiVersion = $queryParams['v'];
		$baseURI = Zotero_API::getBaseURI() . substr($path, 1);
		$alternateBaseURI = Zotero_URI::getBaseWWWURI() . substr($path, 1);
		
		$links = [];
		
		//
		// Generate URIs for 'self', 'first', 'next' and 'last' links
		//
		// 'self'
		$links['self'] = $baseURI;
		if ($nonDefaultParams) {
			$links['self'] .= Zotero_API::buildQueryString($apiVersion, $action, $nonDefaultParams, $excludeParams);
		}
		
		// 'first'
		$links['first'] = $baseURI;
		if ($nonDefaultParams) {
			$p = $nonDefaultParams;
			unset($p['start']);
			$links['first'] .= Zotero_API::buildQueryString($apiVersion, $action, $p, $excludeParams);
		}
		
		// 'last'
		if (!$queryParams['start'] && $queryParams['limit'] >= $totalResults) {
			$links['last'] = $links['self'];
		}
		else if ($queryParams['limit'] != 0) {
			// 'start' past results
			if ($queryParams['start'] >= $totalResults) {
				$lastStart = $totalResults - $queryParams['limit'];
			}
			else {
				$lastStart = $totalResults - ($totalResults % $queryParams['limit']);
				if ($lastStart == $totalResults) {
					$lastStart = $totalResults - $queryParams['limit'];
				}
			}
			$p = $nonDefaultParams;
			if ($lastStart > 0) {
				$p['start'] = $lastStart;
			}
			else {
				unset($p['start']);
			}
			$links['last'] = $baseURI . Zotero_API::buildQueryString($apiVersion, $action, $p, $excludeParams);
			
			// 'next'
			$nextStart = $queryParams['start'] + $queryParams['limit'];
			if ($nextStart < $totalResults) {
				$p = $nonDefaultParams;
				$p['start'] = $nextStart;
				$links['next'] = $baseURI . Zotero_API::buildQueryString($apiVersion, $action, $p, $excludeParams);
			}
		}
		
		$links['alternate'] = $alternateBaseURI;
		
		return $links;
	}
	
	
	private static function isValidFormatForAction($action, $format, $singleObject=false) {
		$isExportFormat = in_array($format, Zotero_Translate::$exportFormats);
		
		if ($action == 'items') {
			if ($isExportFormat) {
				return true;
			}
			
			switch ($format) {
				case 'atom':
				case 'bib':
				case 'globalKeys':
				case 'json':
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
			case 'json':
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
				case 'json':
				case 'etags':
				case 'versions':
					return true;
			}
			return false;
		}
		
		// Ignore format for other actions
		return true;
	}
	
	
	private static function getDefaultDirection($field="") {
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
	
	
	public static function getLimitMax($format="") {
		switch ($format) {
			case 'keys':
			case 'versions':
				return 0;
			
			case 'bib':
				return self::MAX_BIBLIOGRAPHY_ITEMS;
		}
		
		return 100;
	}
	
	
	private static function getSortEmptyFirst($field) {
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
	
	
	private static function resolveDefaultParams($action, $defaultParams, $requestParams) {
		$params = [];
		foreach ($defaultParams as $key => $val) {
			$params[$key] = self::resolveDefaultParam($key, $action, $defaultParams, $requestParams);
		}
		return $params;
	}
	
	
	/**
	 * Get the default value for a given parameter, which may be dependent on other parameters
	 * (either defaults or those set by the request)
	 */
	private static function resolveDefaultParam($param, $action, $defaultParams, $requestParams, $useRequestParams=false) {
		if ($useRequestParams && !empty($requestParams[$param])) {
			return $requestParams[$param];
		}
		if ($param == 'action') {
			return $action;
		}
		try {
			return self::resolveDefaultParamRecursive($action, $defaultParams, $requestParams, $defaultParams[$param]);
		}
		catch (Exception $e) {
			throw new Exception("Can't resolve default value for '$param' (" . $e->getMessage() . ")");
		}
	}
	
	
	private static function resolveDefaultParamRecursive($action, $defaultParams, $requestParams, $block) {
		// If we've reached a regular value or array, just return it
		if (is_scalar($block) || !$block || (is_array($block) && array_keys($block)[0] === 0)) {
			return $block;
		}
		// Otherwise, get the dependency, which should be the sole property
		if (sizeOf($block) != 1) {
			throw new Exception("Invalid default parameter value: " . json_encode($block));
		}
		$depKey = array_keys($block)[0];
		
		// Get the value for the dependency (including in the request, since it's the
		// active parameter that matters here)
		$depVal = self::resolveDefaultParam($depKey, $action, $defaultParams, $requestParams, true);
		
		$resolved = false;
		// And follow its dependency chain
		if (isset($block[$depKey][$depVal])) {
			$resolved = self::resolveDefaultParamRecursive($action, $defaultParams, $requestParams, $block[$depKey][$depVal]);
		}
		// If that wasn't fruitful, use 'default'
		if ($resolved === false) {
			if (!isset($block[$depKey]['default'])) {
				throw new Exception("No default value");
			}
			$resolved = self::resolveDefaultParamRecursive($action, $defaultParams, $requestParams, $block[$depKey]['default']);
			if ($resolved === false) {
				throw new Exception("Cannot resolve default value");
			}
		}
		return $resolved;
	}
	
	
	private static function removeParams($params, $excludeParams) {
		$filtered = [];
		foreach ($params as $key => $val) {
			if (!in_array($key, $excludeParams)) {
				$filtered[$key] = $val;
			}
		}
		return $filtered;
	}
	
	
	private static function removePrivateParams($params) {
		return self::removeParams($params, self::getPrivateParams());
	}
	
	
	private static function getPrivateParams() {
		return ['emptyFirst'];
	}
	
	
	//
	// URI generation
	//
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
	
	public static function outputContentType($format) {
		$contentType = self::getContentTypeForFormat($format);
		if ($contentType !== false) {
			header('Content-Type: ' . $contentType);
		}
	}
	
	public static function getContentTypeForFormat($format) {
		switch ($format) {
		case 'atom':
			return 'application/atom+xml';
		
		case 'bib':
			return 'text/html; charset=UTF-8';
		
		case 'csljson':
			return 'application/vnd.citationstyles.csl+json';
		
		case 'json':
			return 'application/json';
		
		case 'keys':
			return 'text/plain';
		
		case 'versions':
		case 'writereport':
			return 'application/json';
		
		// Export formats -- normally we get these from translation-server, but we hard-code them
		// here for HEAD requests, which don't run the translation. This should match
		// SERVER_CONTENT_TYPES in src/server_translation.js in translation-server.
		case 'bibtex':
			return 'application/x-bibtex';
		
		case 'bookmarks':
		case 'coins':
			return 'text/html';
		
		case 'mods':
			return 'application/mods+xml';
		
		case 'rdf_bibliontology':
		case 'rdf_dc':
		case 'rdf_zotero':
			return 'application/rdf+xml';
		
		case 'refer':
		case 'ris':
			return 'application/x-research-info-systems';
		
		case 'tei':
			return 'text/xml';
		
		case 'wikipedia':
			return 'text/x-wiki';
		}
		
		return false;
	}
	
	
	public static function buildLinkHeader($action, $url, $totalResults, array $queryParams) {
		$path = parse_url($url, PHP_URL_PATH);
		$nonDefaultParams = self::getNonDefaultParams($action, $queryParams);
		$links = self::buildLinks($action, $path, $totalResults, $queryParams, $nonDefaultParams);
		
		$parts = [];
		if (isset($links['first']) && $links['first'] != $links['self']) {
			$parts[] = '<' . $links['first'] . '>; rel="first"';
		}
		if (isset($links['prev'])) {
			$parts[] = '<' . $links['prev'] . '>; rel="previous"';
		}
		if (isset($links['next'])) {
			$parts[] = '<' . $links['next'] . '>; rel="next"';
		}
		if (isset($links['last']) && $links['last'] != $links['self']) {
			$parts[] = '<' . $links['last'] . '>; rel="last"';
		}
		$parts[] = '<' . $links['alternate'] . '>; rel="alternate"';
		
		return $parts ? 'Link: ' . implode(', ', $parts) : false;
	}
	
	
	public static function multiResponse($options, $overrideFormat=false) {
		$format = $overrideFormat ? $overrideFormat : $options['requestParams']['format'];
		
		if (empty($options['results'])) {
			$options['results'] = [
				'results' => [],
				'total' => 0
			];
		}
		
		if ($options['results'] && isset($options['results']['results'])) {
			$totalResults = $options['results']['total'];
			$options['results'] = $options['results']['results'];
			if ($options['requestParams']['v'] >= 3) {
				header("Total-Results: $totalResults");
			}
		}
		
		switch ($format) {
		case 'atom':
		case 'csljson':
		case 'json':
		case 'keys':
		case 'versions':
			$link = Zotero_API::buildLinkHeader($options['action'], $options['uri'], $totalResults, $options['requestParams']);
			if ($link) {
				header($link);
			}
			break;
		}
		
		if (!empty($options['head'])) {
			return;
		}
		
		switch ($format) {
			case 'atom':
				$t = microtime(true);
				$response = Zotero_Atom::createAtomFeed(
					$options['action'],
					$options['title'],
					$options['uri'],
					$options['results'],
					$totalResults,
					$options['requestParams'],
					$options['permissions'],
					isset($options['fixedValues']) ? $options['fixedValues'] : null
				);
				StatsD::timing("api." . $options['action'] . ".multiple.createAtomFeed."
					. implode("-", $options['requestParams']['content']), (microtime(true) - $t) * 1000);
				return $response;
			
			case 'csljson':
				$json = Zotero_Cite::getJSONFromItems($options['results'], true);
				echo Zotero_Utilities::formatJSON($json);
				break;
			
			case 'json':
				echo Zotero_API::createJSONResponse($options['results'], $options['requestParams'], $options['permissions']);
				break;
			
			case 'keys':
				echo implode("\n", $options['results']) . "\n";
				break;
			
			case 'versions':
				if (!empty($options['results'])) {
					echo Zotero_Utilities::formatJSON($options['results']);
				}
				else {
					echo Zotero_Utilities::formatJSON(new stdClass);
				}
				break;
				
			case 'writereport':
				echo Zotero_Utilities::formatJSON($options['results']);
				break;
			
			default:
				throw new Exception("Unexpected format '" . $options['requestParams']['format'] . "'");
		}
	}
	
	
	//
	// JSON processing
	//
	public static function createJSONResponse($entries, array $queryParams, Zotero_Permissions $permissions=null) {
		$json = [];
		foreach ($entries as $entry) {
			$json[] = $entry->toResponseJSON($queryParams, $permissions);
		}
		return Zotero_Utilities::formatJSON($json);
	}
	
	
	/**
	 * Trim full repsonse JSON to editable JSON
	 */
	public static function extractEditableJSON($json) {
		if (isset($json->data)) {
			$json = $json->data;
		}
		return $json;
	}
	
	
	/**
	 * Validate the object key from JSON and load the passed object with it
	 *
	 * @param object $object  Zotero_Item, Zotero_Collection, or Zotero_Search
	 * @param json $json
	 * @return boolean  True if the object exists, false if not
	 */
	public static function processJSONObjectKey($object, $json, $requestParams) {
		$objectType = Zotero_Utilities::getObjectTypeFromObject($object);
		if (!in_array($objectType, array('item', 'collection', 'search'))) {
			throw new Exception("Invalid object type");
		}
		
		if ($requestParams['v'] >= 3) {
			$keyProp = 'key';
			$versionProp = 'version';
		}
		else {
			$keyProp = $objectType . "Key";
			$versionProp = $objectType == 'setting' ? 'version' : $objectType . "Version";
		}
		
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
					throw new HTTPException("'$keyProp' property in JSON does not match "
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
		
		$oldKeyProp = $objectType . "Key";
		$oldVersionProp = $objectType == 'setting' ? 'version' : $objectType . "Version";
		$newKeyProp = 'key';
		$newVersionProp = 'version';
		
		if ($requestParams['v'] >= 3) {
			$keyProp = $newKeyProp;
			$versionProp = $newVersionProp;
			
			// Disallow old properties
			if (isset($json->$oldKeyProp)) {
				throw new Exception("'$oldKeyProp' property is now '"
					. $newKeyProp. "'", Z_ERROR_INVALID_INPUT);
			}
			else if (isset($json->$oldVersionProp) && $oldVersionProp != $newVersionProp) {
				throw new Exception("'$oldVersionProp' property is now '"
					. $newVersionProp . "'", Z_ERROR_INVALID_INPUT);
			}
		}
		else {
			$keyProp = $oldKeyProp;
			$versionProp = $oldVersionProp;
			
			// Disallow new properties
			if (isset($json->$newKeyProp)) {
				throw new Exception("Invalid property '$newKeyProp'", Z_ERROR_INVALID_INPUT);
			}
			else if (isset($json->$newVersionProp) && $oldVersionProp != $newVersionProp) {
				throw new Exception("Invalid property '$newVersionProp'", Z_ERROR_INVALID_INPUT);
			}
		}
		
		if (isset($json->$versionProp)) {
			if ($requestParams['v'] < 2) {
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
			$originalVersion = Zotero_Libraries::getOriginalVersion($object->libraryID);
			$updatedVersion = Zotero_Libraries::getUpdatedVersion($object->libraryID);
			// Make sure the object hasn't been modified since the specified version
			if ($object->version > $json->$versionProp) {
				// Unless it was modified in this request
				if ($updatedVersion != $originalVersion && $object->version == $updatedVersion) {
					return;
				}
				throw new HTTPException(ucwords($objectType)
					. " has been modified since specified version "
					. "(expected {$json->$versionProp}, found {$object->version})"
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
	
	
	/**
	 * Parse search parameters
	 */
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
}
?>
