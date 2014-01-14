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

class Zotero_Collections extends Zotero_DataObjects {
	public static $maxLength = 255;
	
	protected static $ZDO_object = 'collection';
	
	protected static $primaryFields = array(
		'id' => 'collectionID',
		'libraryID' => '',
		'key' => '',
		'name' => 'collectionName',
		'dateAdded' => '',
		'dateModified' => '',
		'parent' => 'parentCollectionID',
		'version' => ''
	);
	
	
	public static function search($libraryID, $onlyTopLevel=false, $params) {
		$results = array('results' => array(), 'total' => 0);
		
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		
		$sql = "SELECT SQL_CALC_FOUND_ROWS DISTINCT ";
		if ($params['format'] == 'keys') {
			$sql .= "`key`";
		}
		else if ($params['format'] == 'versions') {
			$sql .= "`key`, version";
		}
		else {
			$sql .= "collectionID";
		}
		$sql .= " FROM collections WHERE libraryID=? ";
		$sqlParams = array($libraryID);
		
		if ($onlyTopLevel) {
			$sql .= "AND parentCollectionID IS NULL ";
		}
		
		// Pass a list of collectionIDs, for when the initial search is done via SQL
		$collectionIDs = !empty($params['collectionIDs'])
			? $params['collectionIDs'] : array();
		$collectionKeys = $params['collectionKey'];
		
		if ($collectionIDs) {
			$sql .= "AND collectionID IN ("
					. implode(', ', array_fill(0, sizeOf($collectionIDs), '?'))
					. ") ";
			$sqlParams = array_merge($sqlParams, $collectionIDs);
		}
		
		if ($collectionKeys) {
			$sql .= "AND `key` IN ("
					. implode(', ', array_fill(0, sizeOf($collectionKeys), '?'))
					. ") ";
			$sqlParams = array_merge($sqlParams, $collectionKeys);
		}
		
		if (!empty($params['q'])) {
			$sql .= "AND collectionName LIKE ? ";
			$sqlParams[] = '%' . $params['q'] . '%';
		}
		
		if (!empty($params['newer'])) {
			$sql .= "AND version > ? ";
			$sqlParams[] = $params['newer'];
		}
		
		// TEMP: for sync transition
		if (!empty($params['newertime'])) {
			$sql .= "AND serverDateModified >= FROM_UNIXTIME(?) ";
			$sqlParams[] = $params['newertime'];
		}
		
		if (!empty($params['order'])) {
			switch ($params['order']) {
			case 'title':
				$orderSQL = 'collectionName';
				break;
			
			case 'collectionKeyList':
				$orderSQL = "FIELD(`key`,"
						. implode(',', array_fill(0, sizeOf($collectionKeys), '?')) . ")";
				$sqlParams = array_merge($sqlParams, $collectionKeys);
				break;
			
			default:
				$orderSQL = $params['order'];
			}
			
			$sql .= "ORDER BY $orderSQL";
			if (!empty($params['sort'])) {
				$sql .= " {$params['sort']}";
			}
			$sql .= ", ";
		}
		$sql .= "version " . (!empty($params['sort']) ? $params['sort'] : "ASC")
			. ", collectionID " . (!empty($params['sort']) ? $params['sort'] : "ASC") . " ";
		
		if (!empty($params['limit'])) {
			$sql .= "LIMIT ?, ?";
			$sqlParams[] = $params['start'] ? $params['start'] : 0;
			$sqlParams[] = $params['limit'];
		}
		
		if ($params['format'] == 'versions') {
			$rows = Zotero_DB::query($sql, $sqlParams, $shardID);
		}
		// keys and ids
		else {
			$rows = Zotero_DB::columnQuery($sql, $sqlParams, $shardID);
		}
		
		if ($rows) {
			$results['total'] = Zotero_DB::valueQuery("SELECT FOUND_ROWS()", false, $shardID);
			
			if ($params['format'] == 'keys') {
				$results['results'] = $rows;
			}
			else if ($params['format'] == 'versions') {
				foreach ($rows as $row) {
					$results['results'][$row['key']] = $row['version'];
				}
			}
			else {
				$collections = array();
				foreach ($rows as $id) {
					$collections[] = self::get($libraryID, $id);
				}
				$results['results'] = $collections;
			}
		}
		
		return $results;
	}
	
	
	public static function getLongDataValueFromXML(DOMDocument $doc) {
		$xpath = new DOMXPath($doc);
		$attr = $xpath->evaluate('//collections/collection[string-length(@name) > ' . self::$maxLength . ']/@name');
		return $attr->length ? $attr->item(0)->value : false;
	}
	
	
	/**
	 * Converts a DOMElement item to a Zotero_Collection object
	 *
	 * @param	DOMElement		$xml		Collection data as DOMElement
	 * @return	Zotero_Collection			Zotero collection object
	 */
	public static function convertXMLToCollection(DOMElement $xml) {
		$libraryID = (int) $xml->getAttribute('libraryID');
		$col = self::getByLibraryAndKey($libraryID, $xml->getAttribute('key'));
		if (!$col) {
			$col = new Zotero_Collection;
			$col->libraryID = $libraryID;
			$col->key = $xml->getAttribute('key');
		}
		$col->name = $xml->getAttribute('name');
		$parentKey = $xml->getAttribute('parent');
		if ($parentKey) {
			$col->parentKey = $parentKey;
		}
		else {
			$col->parent = false;
		}
		$col->dateAdded = $xml->getAttribute('dateAdded');
		$col->dateModified = $xml->getAttribute('dateModified');
		
		// TODO: move from SyncController?
		
		return $col;
	}
	
	
	/**
	 * Converts a Zotero_Collection object to a SimpleXMLElement item
	 *
	 * @param	object				$item		Zotero_Collection object
	 * @return	SimpleXMLElement					Collection data as SimpleXML element
	 */
	public static function convertCollectionToXML(Zotero_Collection $collection) {
		$xml = new SimpleXMLElement('<collection/>');
		$xml['libraryID'] = $collection->libraryID;
		$xml['key'] = $collection->key;
		$xml['name'] = $collection->name;
		$xml['dateAdded'] = $collection->dateAdded;
		$xml['dateModified'] = $collection->dateModified;
		if ($collection->parent) {
			$parentCol = self::get($collection->libraryID, $collection->parent);
			$xml['parent'] = $parentCol->key;
		}
		
		$children = $collection->getChildren();
		if ($children) {
			$keys = array();
			foreach($children as $child) {
				if ($child['type'] == 'item') {
					$keys[] = $child['key'];
				}
			}
			
			if ($keys) {
				$xml->items = implode(' ', $keys);
			}
		}
		
		return $xml;
	}
	
	
	/**
	 * Converts a Zotero_Collection object to a SimpleXMLElement Atom object
	 *
	 * @param Zotero_Collection  $collection  Zotero_Collection object
	 * @param array  $requestParams
	 * @return SimpleXMLElement  Collection data as SimpleXML element
	 */
	public static function convertCollectionToAtom(Zotero_Collection $collection, $requestParams) {
		// TEMP: multi-format support
		if (!empty($requestParams['content'])) {
			$content = $requestParams['content'];
		}
		else {
			$content = array('none');
		}
		$content = $content[0];
		
		$xml = new SimpleXMLElement(
			'<?xml version="1.0" encoding="UTF-8"?>'
			. '<entry xmlns="' . Zotero_Atom::$nsAtom
			. '" xmlns:zapi="' . Zotero_Atom::$nsZoteroAPI . '"/>'
		);
		
		$title = $collection->name ? $collection->name : '[Untitled]';
		$xml->title = $title;
		
		$author = $xml->addChild('author');
		// TODO: group item creator
		$author->name = Zotero_Libraries::getName($collection->libraryID);
		$author->uri = Zotero_URI::getLibraryURI($collection->libraryID);
		
		$xml->id = Zotero_URI::getCollectionURI($collection);
		
		$xml->published = Zotero_Date::sqlToISO8601($collection->dateAdded);
		$xml->updated = Zotero_Date::sqlToISO8601($collection->dateModified);
		
		$link = $xml->addChild("link");
		$link['rel'] = "self";
		$link['type'] = "application/atom+xml";
		$link['href'] = Zotero_API::getCollectionURI($collection);
		
		$parent = $collection->parent;
		if ($parent) {
			$parentCol = self::get($collection->libraryID, $parent);
			$link = $xml->addChild("link");
			$link['rel'] = "up";
			$link['type'] = "application/atom+xml";
			$link['href'] = Zotero_API::getCollectionURI($parentCol);
		}
		
		$link = $xml->addChild('link');
		$link['rel'] = 'alternate';
		$link['type'] = 'text/html';
		$link['href'] = Zotero_URI::getCollectionURI($collection);
		
		$xml->addChild('zapi:key', $collection->key, Zotero_Atom::$nsZoteroAPI);
		$xml->addChild('zapi:version', $collection->version, Zotero_Atom::$nsZoteroAPI);
		
		$collections = $collection->getChildCollections();
		$xml->addChild(
			'zapi:numCollections',
			sizeOf($collections),
			Zotero_Atom::$nsZoteroAPI
		);
		$xml->addChild(
			'zapi:numItems',
			$collection->numItems(),
			Zotero_Atom::$nsZoteroAPI
		);
		
		if ($content == 'json') {
			$xml->content['type'] = 'application/json';
			// Deprecated
			if ($requestParams['apiVersion'] < 2) {
				$xml->content->addAttribute(
					'zapi:etag',
					$collection->etag,
					Zotero_Atom::$nsZoteroAPI
				);
				$xml->content['etag'] = $collection->etag;
			}
			$xml->content = $collection->toJSON(false, $requestParams);
		}
		
		return $xml;
	}
	
	
	/**
	 * @param Zotero_Collection $collection The collection object to update;
	 *                                      this should be either an existing
	 *                                      collection or a new collection
	 *                                      with a library assigned.
	 * @param object $json Collection data to write
	 * @param boolean [$requireVersion=0] See Zotero_API::checkJSONObjectVersion()
	 * @return boolean True if the collection was changed, false otherwise
	 */
	public static function updateFromJSON(Zotero_Collection $collection,
	                                      $json,
	                                      $requestParams,
	                                      $userID,
	                                      $requireVersion=0) {
		Zotero_API::processJSONObjectKey($collection, $json);
		self::validateJSONCollection($json, $requestParams);
		Zotero_API::checkJSONObjectVersion(
			$collection, $json, $requestParams, $requireVersion
		);
		
		$changed = false;
		
		if (!Zotero_DB::transactionInProgress()) {
			Zotero_DB::beginTransaction();
			$transactionStarted = true;
		}
		else {
			$transactionStarted = false;
		}
		
		$collection->name = $json->name;
		
		if ($requestParams['apiVersion'] >= 2 && isset($json->parentCollection)) {
			$collection->parentKey = $json->parentCollection;
		}
		else if ($requestParams['apiVersion'] < 2 && isset($json->parent)) {
			$collection->parentKey = $json->parent;
		}
		else {
			$collection->parent = false;
		}
		
		$changed = $collection->save() || $changed;
		
		if ($requestParams['apiVersion'] >= 2) {
			if (isset($json->relations)) {
				$changed = $collection->setRelations($json->relations, $userID) || $changed;
			}
			else {
				$changed = $collection->setRelations(new stdClass(), $userID) || $changed;
			}
		}
		
		if ($transactionStarted) {
			Zotero_DB::commit();
		}
		
		return $changed;
	}
	
	
	private static function validateJSONCollection($json, $requestParams) {
		if (!is_object($json)) {
			throw new Exception('$json must be a decoded JSON object');
		}
		
		$requiredProps = array('name');
		
		foreach ($requiredProps as $prop) {
			if (!isset($json->$prop)) {
				throw new Exception("'$prop' property not provided", Z_ERROR_INVALID_INPUT);
			}
		}
		
		foreach ($json as $key=>$val) {
			switch ($key) {
				// Handled by Zotero_API::checkJSONObjectVersion()
				case 'collectionKey':
				case 'collectionVersion':
					break;
				
				case 'name':
					if (!is_string($val)) {
						throw new Exception("'name' must be a string", Z_ERROR_INVALID_INPUT);
					}
					
					if ($val === "") {
						throw new Exception("Collection name cannot be empty", Z_ERROR_INVALID_INPUT);
					}
					
					if (mb_strlen($val) > 255) {
						throw new Exception("Collection name cannot be longer than 255 characters", Z_ERROR_INVALID_INPUT);
					}
					break;
					
				case 'parent':
					if ($requestParams['apiVersion'] >= 2) {
						throw new Exception("'parent' property is now 'parentCollection'", Z_ERROR_INVALID_INPUT);
					}
					if (!is_string($val) && !empty($val)) {
						throw new Exception("'$key' must be a collection key or FALSE (" . gettype($val) . ")", Z_ERROR_INVALID_INPUT);
					}
					break;
				
				case 'parentCollection':
					if ($requestParams['apiVersion'] < 2) {
						throw new Exception("Invalid property '$key'", Z_ERROR_INVALID_INPUT);
					}
					if (!is_string($val) && !empty($val)) {
						throw new Exception("'$key' must be a collection key or FALSE (" . gettype($val) . ")", Z_ERROR_INVALID_INPUT);
					}
					break;
				
				case 'relations':
					if ($requestParams['apiVersion'] < 2) {
						throw new Exception("Invalid property '$key'", Z_ERROR_INVALID_INPUT);
					}
					
					if (!is_object($val)) {
						throw new Exception("'$key' property must be an object", Z_ERROR_INVALID_INPUT);
					}
					foreach ($val as $predicate => $object) {
						switch ($predicate) {
						case 'owl:sameAs':
							break;
						
						default:
							throw new Exception("Unsupported predicate '$predicate'", Z_ERROR_INVALID_INPUT);
						}
						
						if (!preg_match('/^http:\/\/zotero.org\/(users|groups)\/[0-9]+\/collections\/[A-Z0-9]{8}$/', $object)) {
							throw new Exception("'$key' values currently must be Zotero collection URIs", Z_ERROR_INVALID_INPUT);
						}
					}
					break;
				
				default:
					throw new Exception("Invalid property '$key'", Z_ERROR_INVALID_INPUT);
			}
		}
	}
}
?>
