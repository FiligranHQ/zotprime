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
	
	
	public static function get($libraryID, $id) {
		if (!$libraryID) {
			throw new Exception("Library ID not set");
		}
		
		if (!$id) {
			throw new Exception("ID not set");
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
			$sql .= "ORDER BY $order";
			if (!empty($params['sort'])) {
				$sql .= " " . $params['sort'] . " ";
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
		$col = new Zotero_Collection;
		$col->libraryID = (int) $xml->getAttribute('libraryID');
		$col->key = $xml->getAttribute('key');
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
	 * Converts a SimpleXMLElement item to a Zotero_Collection object
	 *
	 * @param	SimpleXMLElement	$xml		Collection data as SimpleXML element
	 * @return	Zotero_Collection			Zotero collection object
	 */
/*	public static function convertXMLToCollection(SimpleXMLElement $xml) {
		$col = new Zotero_Collection;
		$col->libraryID = (int) $xml['libraryID'];
		$col->key = (string) $xml['key'];
		$col->name = (string) $xml['name'];
		$parentKey = (string) $xml['parent'];
		if ($parentKey) {
			$col->parentKey = $parentKey;
		}
		else {
			$col->parent = false;
		}
		$col->dateAdded = (string) $xml['dateAdded'];
		$col->dateModified = (string) $xml['dateModified'];
		
		// TODO: move from SyncController?
		
		return $col;
	}
*/	
	
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
			$parentCol = new Zotero_Collection;
			$parentCol->libraryID = $collection->libraryID;
			$parentCol->id = $collection->parent;
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
			$parentCol = new Zotero_Collection;
			$parentCol->libraryID = $collection->libraryID;
			$parentCol->id = $parent;
			$link = $xml->addChild("link");
			$link['rel'] = "up";
			$link['type'] = "application/atom+xml";
			$link['href'] = Zotero_Atom::getCollectionURI($parentCol);
		}
		
		$link = $xml->addChild('link');
		$link['rel'] = 'alternate';
		$link['type'] = 'text/html';
		$link['href'] = Zotero_URI::getCollectionURI($collection);
		
		$collections = $collection->getChildCollections();
		$xml->addChild(
			'zapi:numCollections',
			sizeOf($collections),
			Zotero_Atom::$nsZoteroAPI
		);
		$itemIDs = $collection->getChildItems();
		if ($itemIDs) {
			$deletedItems = Zotero_Items::getDeleted($collection->libraryID, true);
			$itemIDs = array_diff($itemIDs, $deletedItems);
		}
		$xml->addChild(
			'zapi:numItems',
			sizeOf($itemIDs),
			Zotero_Atom::$nsZoteroAPI
		);
		
		if ($content == 'html') {
			$xml->content['type'] = 'html';
			
			$fullStr = "<div/>";
			$fullXML = new SimpleXMLElement($fullStr);
			$fullXML->addAttribute(
				"xmlns", Zotero_Atom::$nsXHTML
			);
			$fNode = dom_import_simplexml($xml->content);
			$subNode = dom_import_simplexml($fullXML);
			$importedNode = $fNode->ownerDocument->importNode($subNode, true);
			$fNode->appendChild($importedNode);
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
