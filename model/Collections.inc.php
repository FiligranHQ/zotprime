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
	protected static $ZDO_object = 'collection';
	
	private static $maxLength = 255;
	
	
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
	
	
	public static function getAllAdvanced($libraryID, $params) {
		$results = array('collections' => array(), 'total' => 0);
		
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		$sql = "SELECT SQL_CALC_FOUND_ROWS collectionID FROM collections
				WHERE libraryID=? ";
		
		if (!empty($params['order'])) {
			$order = $params['order'];
			if ($order == 'title') {
				$order = 'collectionName';
			}
			$sql .= "ORDER BY $order ";
			if (!empty($params['sort'])) {
				$sql .= $params['sort'] . " ";
			}
		}
		$sqlParams = array($libraryID);
		
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
	public static function convertCollectionToAtom(Zotero_Collection $collection, $content='none') {
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
		self::validateJSONCollection($json);
		
		// TODO: lock checks
		
		// new item
		$collection = new Zotero_Collection;
		$collection->libraryID = $libraryID;
		self::updateFromJSON($collection, $json, true);
		
		return $collection;
	}
	
	
	public static function updateFromJSON(Zotero_Collection $collection, $json, $isNew=false) {
		self::validateJSONCollection($json);
		
		if (!$isNew) {
			// TODO: lock checks
		}
		
		$collection->name = $json->name;
		$parentKey = $json->parent;
		if ($parentKey) {
			$collection->parentKey = $parentKey;
		}
		else {
			$collection->parent = false;
		}
		$collection->save();
	}
	
	
	public static function validateJSONCollection($json) {
		if (!is_object($json)) {
			throw new Exception('$json must be a decoded JSON object');
		}
		
		$requiredProps = array('name', 'parent');
		
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
	
	
	/**
	* Loads collection data from DB and adds to internal cache
	**/
	/*
	public static function reloadAll($userID) {
		Z_Core::debug('Loading all collections');
		
		// This should be the same as the query in Zotero.Collection.load(),
		// just without a specific collectionID
		$sql = "SELECT C.*,
			(SELECT COUNT(*) FROM collections WHERE
			parentCollectionID=C.collectionID)!=0 AS hasChildCollections,
			(SELECT COUNT(*) FROM collectionItems WHERE
			collectionID=C.collectionID)!=0 AS hasChildItems
			FROM collections C WHERE userID=?";
		$rows = Zotero_DB::query($sql, $userID);
		
		$collectionIDs = array();
		
		if ($result) {
			foreach ($rows as $row) {
				$collectionID = $row['collectionID'];
				$collectionIDs[] = $collectionID;
				
				// If collection doesn't exist, create new object and stuff in array
				if (!self::$collections[$userID][$collectionID]) {
					self::$collections[$userID][$collectionID] = new Zotero.Collection;
				}
				self::$collections[$userID][$collectionID]->loadFromRow($row);
			}
		}
		
		// Remove old collections that no longer exist
		foreach (self::$collections[$userID] as $c)
			if (!in_array($c->id, $collectionIDs) {
				self::unload($c->id);
			}
		}
		
		self::$collectionsLoaded = true;
	}
	*/
}
?>
