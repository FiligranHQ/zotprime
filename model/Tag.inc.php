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

class Zotero_Tag {
	private $id;
	private $libraryID;
	private $key;
	private $name;
	private $type;
	private $dateAdded;
	private $dateModified;
	
	private $loaded;
	private $changed;
	private $previousData;
	
	private $linkedItemsLoaded = false;
	private $linkedItems = array();
	
	public function __construct() {
		$numArgs = func_num_args();
		if ($numArgs) {
			throw new Exception("Constructor doesn't take any parameters");
		}
		
		$this->init();
	}
	
	
	private function init() {
		$this->loaded = false;
		
		$this->previousData = array();
		$this->linkedItemsLoaded = false;
		
		$this->changed = array();
		$props = array(
			'name',
			'type',
			'dateAdded',
			'dateModified',
			'linkedItems'
		);
		foreach ($props as $prop) {
			$this->changed[$prop] = false;
		}
	}
	
	
	public function __get($field) {
		if (($this->id || $this->key) && !$this->loaded) {
			$this->load(true);
		}
		
		if (!property_exists('Zotero_Tag', $field)) {
			throw new Exception("Zotero_Tag property '$field' doesn't exist");
		}
		
		return $this->$field;
	}
	
	
	public function __set($field, $value) {
		switch ($field) {
			case 'id':
			case 'libraryID':
			case 'key':
				if ($this->loaded) {
					throw new Exception("Cannot set $field after tag is already loaded");
				}
				$this->checkValue($field, $value);
				$this->$field = $value;
				return;
		}
		
		if ($this->id || $this->key) {
			if (!$this->loaded) {
				$this->load(true);
			}
		}
		else {
			$this->loaded = true;
		}
		
		$this->checkValue($field, $value);
		
		if ($this->$field != $value) {
			$this->prepFieldChange($field);
			$this->$field = $value;
		}
	}
	
	
	/**
	 * Check if tag exists in the database          
	 *
	 * @return	bool			TRUE if the item exists, FALSE if not
	 */
	public function exists() {
		if (!$this->id) {
			trigger_error('$this->id not set');
		}
		
		$sql = "SELECT COUNT(*) FROM tags WHERE tagID=?";
		return !!Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
	}
	
	
	public function addItem($key) {
		$current = $this->getLinkedItems(true);
		if (in_array($key, $current)) {
			Z_Core::debug("Item $key already has tag {$this->libraryID}/{$this->key}");
			return false;
		}
		
		$item = Zotero_Items::getByLibraryAndKey($this->libraryID, $key);
		if (!$item) {
			throw new Exception("Can't link invalid item $key to tag {$this->libraryID}/{$this->key}");
		}
		
		$this->prepFieldChange('linkedItems');
		$this->linkedItems[] = $key;
		return true;
	}
	
	
	public function removeItem($key) {
		$current = $this->getLinkedItems(true);
		$index = array_search($key, $current);
		
		if ($index === false) {
			Z_Core::debug("Item {$this->libraryID}/$key doesn't have tag {$this->key}");
			return false;
		}
		
		$this->prepFieldChange('linkedItems');
		array_splice($this->linkedItems, $index, 1);
		return true;
	}
	
	
	public function hasChanged() {
		return in_array(true, array_values($this->changed));
	}
	
	
	public function save($full=false) {
		if (!$this->libraryID) {
			trigger_error("Library ID must be set before saving", E_USER_ERROR);
		}
		
		Zotero_Tags::editCheck($this);
		
		if (!$this->hasChanged()) {
			Z_Core::debug("Tag $this->id has not changed");
			return false;
		}
		
		$shardID = Zotero_Shards::getByLibraryID($this->libraryID);
		
		Zotero_DB::beginTransaction();
		
		try {
			$tagID = $this->id ? $this->id : Zotero_ID::get('tags');
			$isNew = !$this->id;
			
			Z_Core::debug("Saving tag $tagID");
			
			$key = $this->key ? $this->key : $this->generateKey();
			$timestamp = Zotero_DB::getTransactionTimestamp();
			$dateAdded = $this->dateAdded ? $this->dateAdded : $timestamp;
			$dateModified = $this->dateModified ? $this->dateModified : $timestamp;
			
			$fields = "name=?, `type`=?, dateAdded=?, dateModified=?,
				libraryID=?, `key`=?, serverDateModified=?";
			$params = array(
				$this->name,
				$this->type ? $this->type : 0,
				$dateAdded,
				$dateModified,
				$this->libraryID,
				$key,
				$timestamp
			);
			
			try {
				if ($isNew) {
					$sql = "INSERT INTO tags SET tagID=?, $fields";
					$stmt = Zotero_DB::getStatement($sql, true, $shardID);
					Zotero_DB::queryFromStatement($stmt, array_merge(array($tagID), $params));
					
					// Remove from delete log if it's there
					$sql = "DELETE FROM syncDeleteLogKeys WHERE libraryID=? AND objectType='tag' AND `key`=?";
					Zotero_DB::query($sql, array($this->libraryID, $key), $shardID);
				}
				else {
					$sql = "UPDATE tags SET $fields WHERE tagID=?";
					$stmt = Zotero_DB::getStatement($sql, true, Zotero_Shards::getByLibraryID($this->libraryID));
					Zotero_DB::queryFromStatement($stmt, array_merge($params, array($tagID)));
				}
			}
			catch (Exception $e) {
				// If an incoming tag is the same as an existing tag, but with a different key,
				// then delete the old tag and add its linked items to the new tag
				if (preg_match("/Duplicate entry .+ for key 'uniqueTags'/", $e->getMessage())) {
					// GET existing tag
					$existing = Zotero_Tags::getIDs($this->libraryID, $this->name);
					if (!$existing) {
						throw new Exception("Existing tag not found");
					}
					foreach ($existing as $id) {
						$tag = Zotero_Tags::get($this->libraryID, $id, true);
						if ($tag->__get('type') == $this->type) {
							$linked = $tag->getLinkedItems(true);
							Zotero_Tags::delete($this->libraryID, $tag->key);
							break;
						}
					}
					
					// Save again
					if ($isNew) {
						$sql = "INSERT INTO tags SET tagID=?, $fields";
						$stmt = Zotero_DB::getStatement($sql, true, $shardID);
						Zotero_DB::queryFromStatement($stmt, array_merge(array($tagID), $params));
						
						// Remove from delete log if it's there
						$sql = "DELETE FROM syncDeleteLogKeys WHERE libraryID=? AND objectType='tag' AND `key`=?";
						Zotero_DB::query($sql, array($this->libraryID, $key), $shardID);
					}
					else {
						$sql = "UPDATE tags SET $fields WHERE tagID=?";
						$stmt = Zotero_DB::getStatement($sql, true, Zotero_Shards::getByLibraryID($this->libraryID));
						Zotero_DB::queryFromStatement($stmt, array_merge($params, array($tagID)));
					}
					
					$new = array_unique(array_merge($linked, $this->getLinkedItems(true)));
					$this->setLinkedItems($new);
				}
				else {
					throw $e;
				}
			}
			
			// Linked items
			if ($full || $this->changed['linkedItems']) {
				$removeKeys = array();
				$currentKeys = $this->getLinkedItems(true);
				
				if ($full) {
					$sql = "SELECT `key` FROM itemTags JOIN items "
						. "USING (itemID) WHERE tagID=?";
					$stmt = Zotero_DB::getStatement($sql, true, $shardID);
					$dbKeys = Zotero_DB::columnQueryFromStatement($stmt, $tagID);
					if ($dbKeys) {
						$removeKeys = array_diff($dbKeys, $currentKeys);
						$newKeys = array_diff($currentKeys, $dbKeys);
					}
					else {
						$newKeys = $currentKeys;
					}
				}
				else {
					if ($this->previousData['linkedItems']) {
						$removeKeys = array_diff(
							$this->previousData['linkedItems'], $currentKeys
						);
						$newKeys = array_diff(
							$currentKeys, $this->previousData['linkedItems']
						);
					}
					else {
						$newKeys = $currentKeys;
					}
				}
				
				if ($removeKeys) {
					$sql = "DELETE itemTags FROM itemTags JOIN items USING (itemID) "
						. "WHERE tagID=? AND items.key IN ("
						. implode(', ', array_fill(0, sizeOf($removeKeys), '?'))
						. ")";
					Zotero_DB::query(
						$sql,
						array_merge(array($this->id), $removeKeys),
						$shardID
					);
				}
				
				if ($newKeys) {
					$sql = "INSERT INTO itemTags (tagID, itemID) "
						. "SELECT ?, itemID FROM items "
						. "WHERE libraryID=? AND `key` IN ("
						. implode(', ', array_fill(0, sizeOf($newKeys), '?'))
						. ")";
					Zotero_DB::query(
						$sql,
						array_merge(array($tagID, $this->libraryID), $newKeys),
						$shardID
					);
				}
				
				//Zotero.Notifier.trigger('add', 'collection-item', $this->id . '-' . $itemID);
			}
			
			Zotero_DB::commit();
			
			Zotero_Tags::cachePrimaryData(
				array(
					'id' => $tagID,
					'libraryID' => $this->libraryID,
					'key' => $key,
					'name' => $this->name,
					'type' => $this->type ? $this->type : 0,
					'dateAdded' => $dateAdded,
					'dateModified' => $dateModified
				)
			);
		}
		catch (Exception $e) {
			Zotero_DB::rollback();
			throw ($e);
		}
		
		// If successful, set values in object
		if (!$this->id) {
			$this->id = $tagID;
		}
		if (!$this->key) {
			$this->key = $key;
		}
		
		$this->init();
		
		if ($isNew) {
			Zotero_Tags::cache($this);
			Zotero_Tags::cacheLibraryKeyID($this->libraryID, $key, $tagID);
		}
		
		return $this->id;
	}
	
	
	public function getLinkedItems($asKeys=false) {
		if (!$this->linkedItemsLoaded) {
			$this->loadLinkedItems();
		}
		
		if ($asKeys) {
			return $this->linkedItems;
		}
		
		// In PHP 5.4 $this->libraryID can be used directly in the closure
		$libraryID = $this->libraryID;
		
		return array_map(function ($key) use ($libraryID) {
			return Zotero_Items::getByLibraryAndKey($libraryID, $this->linkedItems);
		}, $this->linkedItems);
	}
	
	
	public function setLinkedItems($newKeys) {
		if (!$this->linkedItemsLoaded) {
			$this->loadLinkedItems();
		}
		
		if (!is_array($newKeys))  {
			throw new Exception('$newKeys must be an array');
		}
		
		$oldKeys = $this->getLinkedItems(true);
		
		if (!$newKeys && !$oldKeys) {
			Z_Core::debug("No linked items added", 4);
			return false;
		}
		
		$addKeys = array_diff($newKeys, $oldKeys);
		$removeKeys = array_diff($oldKeys, $newKeys);
		
		// Make sure all new keys exist
		foreach ($addKeys as $key) {
			if (!Zotero_Items::existsByLibraryAndKey($this->libraryID, $key)) {
				// Return a specific error for a wrong-library tag issue
				// that I can't reproduce
				throw new Exception("Linked item $key of tag "
					. "{$this->libraryID}/{$this->key} not found",
					Z_ERROR_TAG_LINKED_ITEM_NOT_FOUND);
			}
		}
		
		if ($addKeys || $removeKeys) {
			$this->prepFieldChange('linkedItems');
		}
		else {
			Z_Core::debug('Linked items not changed', 4);
			return false;
		}
		
		$this->linkedItems = $newKeys;
		return true;
	}
	
	
	public function serialize() {
		$obj = array(
			'primary' => array(
				'tagID' => $this->id,
				'dateAdded' => $this->dateAdded,
				'dateModified' => $this->dateModified,
				'key' => $this->key
			),
			'name' => $this->name,
			'type' => $this->type,
			'linkedItems' => $this->getLinkedItems(true),
		);
		
		return $obj;
	}
	
	
	/**
	 * Converts a Zotero_Tag object to a SimpleXMLElement item
	 *
	 * @param	object				$item		Zotero_Tag object
	 * @return	SimpleXMLElement				Tag data as SimpleXML element
	 */
	public function toXML($syncMode=false) {
		if (!$this->loaded) {
			$this->load();
		}
		
		$xml = '<tag';
		/*if (!$syncMode) {
			$xml .= ' xmlns="' . Zotero_Atom::$nsZoteroTransfer . '"';
		}*/
		$xml .= '/>';
		$xml = new SimpleXMLElement($xml);
		
		$xml['libraryID'] = $this->libraryID;
		$xml['key'] = $this->key;
		$xml['name'] = $this->name;
		$xml['dateAdded'] = $this->dateAdded;
		$xml['dateModified'] = $this->dateModified;
		if ($this->type) {
			$xml['type'] = $this->type;
		}
		
		if ($syncMode) {
			$itemKeys = $this->getLinkedItems(true);
			if ($itemKeys) {
				$xml->items = implode(" ", $itemKeys);
			}
		}
		
		return $xml;
	}
	
	
	/**
	 * Converts a Zotero_Tag object to a SimpleXMLElement Atom object
	 *
	 * @param	object				$tag		Zotero_Tag object
	 * @param	string				$content
	 * @return	SimpleXMLElement					Tag data as SimpleXML element
	 */
	public function toAtom($content=array('none'), $apiVersion=null, $fixedValues=null) {
		// TEMP: multi-format support
		$content = $content[0];
		
		$xml = new SimpleXMLElement(
			'<entry xmlns="' . Zotero_Atom::$nsAtom . '" '
			. 'xmlns:zapi="' . Zotero_Atom::$nsZoteroAPI . '" '
			. 'xmlns:zxfer="' . Zotero_Atom::$nsZoteroTransfer . '"/>'
		);
		
		$xml->title = $this->name;
		
		$author = $xml->addChild('author');
		$author->name = Zotero_Libraries::getName($this->libraryID);
		$author->uri = Zotero_URI::getLibraryURI($this->libraryID);
		
		$xml->id = Zotero_URI::getTagURI($this);
		
		$xml->published = Zotero_Date::sqlToISO8601($this->dateAdded);
		$xml->updated = Zotero_Date::sqlToISO8601($this->dateModified);
		
		$link = $xml->addChild("link");
		$link['rel'] = "self";
		$link['type'] = "application/atom+xml";
		$link['href'] = Zotero_Atom::getTagURI($this);
		
		$link = $xml->addChild('link');
		$link['rel'] = 'alternate';
		$link['type'] = 'text/html';
		$link['href'] = Zotero_URI::getTagURI($this);
		
		// Count user's linked items
		if (isset($fixedValues['numItems'])) {
			$numItems = $fixedValues['numItems'];
		}
		else {
			$numItems = sizeOf($this->getLinkedItems(true));
		}
		$xml->addChild(
			'zapi:numItems',
			$numItems,
			Zotero_Atom::$nsZoteroAPI
		);
		
		if ($content == 'html') {
			$xml->content['type'] = 'xhtml';
			
			//$fullXML = Zotero_Tags::convertTagToXML($tag);
			$fullStr = "<div/>";
			$fullXML = new SimpleXMLElement($fullStr);
			$fullXML->addAttribute(
				"xmlns", Zotero_Atom::$nsXHTML
			);
			$fNode = dom_import_simplexml($xml->content);
			$subNode = dom_import_simplexml($fullXML);
			$importedNode = $fNode->ownerDocument->importNode($subNode, true);
			$fNode->appendChild($importedNode);
			
			//$arr = $tag->serialize();
			//require_once("views/zotero/tags.php")
		}
		// Not for public consumption
		else if ($content == 'full') {
			$xml->content['type'] = 'application/xml';
			$fullXML = $this->toXML();
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
	
	
	private function load() {
		$libraryID = $this->libraryID;
		$id = $this->id;
		$key = $this->key;
		
		if (!$libraryID) {
			throw new Exception("Library ID not set");
		}
		
		if (!$id && !$key) {
			throw new Exception("ID or key not set");
		}
		
		// Cache tag data for the entire library
		if (true) {
			if ($id) {
				Z_Core::debug("Loading data for tag $this->libraryID/$this->id");
				$row = Zotero_Tags::getPrimaryDataByID($libraryID, $id);
			}
			else {
				Z_Core::debug("Loading data for tag $this->libraryID/$this->key");
				$row = Zotero_Tags::getPrimaryDataByKey($libraryID, $key);
			}
			
			$this->loaded = true;
			
			if (!$row) {
				return;
			}
			
			if ($row['libraryID'] != $libraryID) {
				throw new Exception("libraryID {$row['libraryID']} != $this->libraryID");
			}
			
			foreach ($row as $key=>$val) {
				$this->$key = $val;
			}
		}
		// Load tag row individually
		else {
			// Use cached check for existence if possible
			if ($libraryID && $key) {
				if (!Zotero_Tags::existsByLibraryAndKey($libraryID, $key)) {
					$this->loaded = true;
					return;
				}
			}
			
			$shardID = Zotero_Shards::getByLibraryID($libraryID);
			
			$sql = Zotero_Tags::getPrimaryDataSQL();
			if ($id) {
				$sql .= "tagID=?";
				$stmt = Zotero_DB::getStatement($sql, false, $shardID);
				$data = Zotero_DB::rowQueryFromStatement($stmt, $id);
			}
			else {
				$sql .= "libraryID=? AND `key`=?";
				$stmt = Zotero_DB::getStatement($sql, false, $shardID);
				$data = Zotero_DB::rowQueryFromStatement($stmt, array($libraryID, $key));
			}
			
			$this->loaded = true;
			
			if (!$data) {
				return;
			}
			
			if ($data['libraryID'] != $libraryID) {
				throw new Exception("libraryID {$data['libraryID']} != $libraryID");
			}
			
			foreach ($data as $k=>$v) {
				$this->$k = $v;
			}
		}
	}
	
	
	private function loadLinkedItems() {
		Z_Core::debug("Loading linked items for tag $this->id");
		
		if (!$this->id && !$this->key) {
			$this->linkedItemsLoaded = true;
			return;
		}
		
		if (!$this->loaded) {
			$this->load();
		}
		
		if (!$this->id) {
			$this->linkedItemsLoaded = true;
			return;
		}
		
		$sql = "SELECT `key` FROM itemTags JOIN items USING (itemID) WHERE tagID=?";
		$stmt = Zotero_DB::getStatement($sql, true, Zotero_Shards::getByLibraryID($this->libraryID));
		$keys = Zotero_DB::columnQueryFromStatement($stmt, $this->id);
		
		$this->linkedItems = $keys ? $keys : array();
		$this->linkedItemsLoaded = true;
	}
	
	
	private function checkValue($field, $value) {
		if (!property_exists($this, $field)) {
			trigger_error("Invalid property '$field'", E_USER_ERROR);
		}
		
		// Data validation
		switch ($field) {
			case 'id':
			case 'libraryID':
				if (!Zotero_Utilities::isPosInt($value)) {
					$this->invalidValueError($field, $value);
				}
				break;
			
			case 'key':
				// 'I' used to exist in client
				if (!preg_match('/^[23456789ABCDEFGHIJKLMNPQRSTUVWXYZ]{8}$/', $value)) {
					$this->invalidValueError($field, $value);
				}
				break;
			
			case 'dateAdded':
			case 'dateModified':
				if (!preg_match("/^[0-9]{4}\-[0-9]{2}\-[0-9]{2} ([0-1][0-9]|[2][0-3]):([0-5][0-9]):([0-5][0-9])$/", $value)) {
					$this->invalidValueError($field, $value);
				}
				break;
			
			case 'name':
				if (mb_strlen($value) > Zotero_Tags::$maxLength) {
					throw new Exception("Tag '" . $value . "' too long", Z_ERROR_TAG_TOO_LONG);
				}
				break;
		}
	}
	
	
	private function prepFieldChange($field) {
		$this->changed[$field] = true;
		
		// Save a copy of the data before changing
		// TODO: only save previous data if tag exists
		if ($this->id && $this->exists() && !$this->previousData) {
			$this->previousData = $this->serialize();
		}
	}
	
	
	private function generateKey() {
		return Zotero_ID::getKey();
	}
	
	
	private function invalidValueError($field, $value) {
		trigger_error("Invalid '$field' value '$value'", E_USER_ERROR);
	}
}
?>
