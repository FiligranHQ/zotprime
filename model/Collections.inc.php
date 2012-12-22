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
		'parent' => 'parentCollectionID'
	);
	
	
	public static function get($libraryID, $id, $skipCheck=false) {
		if (!$libraryID) {
			throw new Exception("Library ID not set");
		}
		
		if (!$id) {
			throw new Exception("ID not set");
		}
		
		if (!$skipCheck) {
			$sql = 'SELECT COUNT(*) FROM collections WHERE collectionID=?';
			$result = Zotero_DB::valueQuery($sql, $id, Zotero_Shards::getByLibraryID($libraryID));
			if (!$result) {
				return false;
			}
		}
		
		$collection = new Zotero_Collection;
		$collection->libraryID = $libraryID;
		$collection->id = $id;
		return $collection;
	}
	
	
	public static function getAllAdvanced($libraryID, $onlyTopLevel=false, $params) {
		$results = array('collections' => array(), 'total' => 0);
		
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		$sql = "SELECT SQL_CALC_FOUND_ROWS collectionID FROM collections
				WHERE libraryID=? ";
		$sqlParams = array($libraryID);
		
		if ($onlyTopLevel) {
			$sql .= "AND parentCollectionID IS NULL ";
		}
		
		$keys = array();
		if (!empty($params['collectionKey'])) {
			$keys = explode(',', $params['collectionKey']);
		}
		
		if ($keys) {
			$sql .= "AND `key` IN ("
					. implode(', ', array_fill(0, sizeOf($keys), '?'))
					. ") ";
			$sqlParams = array_merge($sqlParams, $keys);
		}
		
		if (!empty($params['order'])) {
			switch ($params['order']) {
			case 'title':
				$orderSQL = 'collectionName';
				break;
			
			case 'keyList':
				$orderSQL = "FIELD(`key`,"
						. implode(',', array_fill(0, sizeOf($keys), '?')) . ")";
				$sqlParams = array_merge($sqlParams, $keys);
				break;
			
			default:
				$orderSQL = $params['order'];
			}
			
			$sql .= "ORDER BY $orderSQL ";
			if (!empty($params['sort'])) {
				$sql .= $params['sort'] . " ";
			}
		}
		
		if (!empty($params['limit'])) {
			$sql .= "LIMIT ?, ?";
			$sqlParams[] = $params['start'] ? $params['start'] : 0;
			$sqlParams[] = $params['limit'];
		}
		$ids = Zotero_DB::columnQuery($sql, $sqlParams, $shardID);
		
		if ($ids) {
			$results['total'] = Zotero_DB::valueQuery("SELECT FOUND_ROWS()", false, $shardID);
			
			$collections = array();
			foreach ($ids as $id) {
				$collections[] = self::get($libraryID, $id);
			}
			$results['collections'] = $collections;
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
	 * @param	object				$item		Zotero_Collection object
	 * @param	string				$content
	 * @return	SimpleXMLElement					Collection data as SimpleXML element
	 */
	public static function convertCollectionToAtom(Zotero_Collection $collection, $content=array('none')) {
		// TEMP: multi-format support
		$content = $content[0];
		
		$xml = new SimpleXMLElement(
			'<entry xmlns="' . Zotero_Atom::$nsAtom . '" xmlns:zapi="' . Zotero_Atom::$nsZoteroAPI . '"/>'
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
		$link['href'] = Zotero_Atom::getCollectionURI($collection);
		
		$parent = $collection->parent;
		if ($parent) {
			$parentCol = self::get($collection->libraryID, $parent);
			$link = $xml->addChild("link");
			$link['rel'] = "up";
			$link['type'] = "application/atom+xml";
			$link['href'] = Zotero_Atom::getCollectionURI($parentCol);
		}
		
		$link = $xml->addChild('link');
		$link['rel'] = 'alternate';
		$link['type'] = 'text/html';
		$link['href'] = Zotero_URI::getCollectionURI($collection);
		
		$xml->addChild('zapi:key', $collection->key, Zotero_Atom::$nsZoteroAPI);
		
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
			$xml->content->addAttribute(
				'zapi:etag',
				$collection->etag,
				Zotero_Atom::$nsZoteroAPI
			);
			// TODO: remove non-namespaced attribute
			$xml->content['etag'] = $collection->etag;
			$xml->content = $collection->toJSON();
		}
		else if ($content == 'full') {
			$xml->content['type'] = 'application/xml';
			
			$fullXML = Zotero_Collections::convertCollectionToXML($collection);
			$fullXML->addAttribute(
				"xmlns", Zotero_Atom::$nsZoteroTransfer
			);
			$fNode = dom_import_simplexml($xml->content);
			$subNode = dom_import_simplexml($fullXML);
			$importedNode = $fNode->ownerDocument->importNode($subNode, true);
			$fNode->appendChild($importedNode);
		}
		
		return $xml;
	}
	
	
	public static function addFromJSON($json, $libraryID) {
		self::validateJSONCollections($json);
		
		// If single collection object, stuff in 'collections' array
		if (!isset($json->collections)) {
			$newJSON = new stdClass;
			$newJSON->collections = array($json);
			$json = $newJSON;
		}
		
		$keys = array();
		
		Zotero_DB::beginTransaction();
		
		foreach ($json->collections as $jsonCollection) {
			$collection = new Zotero_Collection;
			$collection->libraryID = $libraryID;
			self::updateFromJSON($collection, $jsonCollection, true);
			$keys[] = $collection->key;
		}
		
		Zotero_DB::commit();
		
		return $keys;
	}
	
	
	public static function updateFromJSON(Zotero_Collection $collection, $json, $isNew=false) {
		self::validateJSONCollection($json);
		
		Zotero_DB::beginTransaction();
		
		$timestamp = Zotero_Libraries::updateTimestamps($collection->libraryID);
		Zotero_DB::registerTransactionTimestamp($timestamp);
		
		$collection->name = $json->name;
		if (isset($json->parent)) {
			$collection->parentKey = $json->parent;
		}
		else {
			$collection->parent = false;
		}
		$collection->save();
		
		Zotero_DB::commit();
	}
	
	
	private static function validateJSONCollections($json) {
		if (!is_object($json)) {
			throw new Exception('$json must be a decoded JSON object');
		}
		
		if (isset($json->collections)) {
			foreach ($json as $key=>$val) {
				if ($key != 'collections') {
					throw new Exception("Invalid property '$key'", Z_ERROR_INVALID_INPUT);
				}
				if (sizeOf($val) > Zotero_API::$maxWriteCollections) {
					throw new Exception("Cannot add more than "
						. Zotero_API::$maxWriteCollections
						. " collections at a time", Z_ERROR_UPLOAD_TOO_LARGE);
				}
			}
		}
		else if (!isset($json->name)) {
			throw new Exception("'collections' or 'name' must be provided", Z_ERROR_INVALID_INPUT);
		}
	}
	
	
	private static function validateJSONCollection($json) {
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
				case 'name':
					if (!is_string($val)) {
						throw new Exception("'name' must be a string", Z_ERROR_INVALID_INPUT);
					}
					
					if (mb_strlen($val) > 255) {
						throw new Exception("Collection name cannot be longer than 255 characters", Z_ERROR_INVALID_INPUT);
					}
					break;
					
				case 'parent':
					if (!is_string($val) && !empty($val)) {
						throw new Exception("'parent' must be a collection key or FALSE (" . gettype($val) . ")", Z_ERROR_INVALID_INPUT);
					}
					break;
				
				default:
					throw new Exception("Invalid property '$key'", Z_ERROR_INVALID_INPUT);
			}
		}
	}
}
?>
