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

class Zotero_Collection extends Zotero_DataObject {
	
	private $_name;
	private $_parent;
	private $_dateAdded;
	private $_dateModified;
	
	private $changed;
	
	private $childCollectionsLoaded;
	private $childCollections = array();
	
	private $itemsLoaded;
	private $items = array();
	
	public function __construct() {
		$numArgs = func_num_args();
		if ($numArgs) {
			throw new Exception("Constructor doesn't take any parameters");
		}
	}
	
	
	public function __get($field) {
		$val = parent::__get($field);
		if (!is_null($val)) {
			return $val;
		}
		
		if (isset($this->{'_' . $field})) {
			return $this->{'_' . $field};
		}
		
		if (($this->_id || $this->_key) && !$this->loaded) {
			$this->load(true);
		}
		
		switch ($field) {
			case 'parent':
				return $this->getParent();
			
			case 'parentKey':
				return $this->getParentKey();
				
			case 'etag':
				return $this->getETag();
		}
		
		if (!property_exists('Zotero_Collection', '_' . $field)) {
			throw new Exception("Zotero_Collection property '$field' doesn't exist");
		}
		$field = '_' . $field;
		return $this->$field;
	}
	
	
	public function __set($field, $value) {
		switch ($field) {
			case 'id':
			case 'libraryID':
			case 'key':
				if ($this->loaded) {
					trigger_error("Cannot set $field after collection is already loaded", E_USER_ERROR);
				}
				$this->checkValue($field, $value);
				$field = '_' . $field;
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
		
		switch ($field) {
		case 'version':
			$value = (int) $value;
			break;
			
		case 'parent':
			$this->setParent($value);
			return;
		
		case 'parentKey':
			$this->setParentKey($value);
			return;
		}
		
		$this->checkValue($field, $value);
		
		$field = '_' . $field;
		if ($this->$field != $value) {
			$this->changed = true;
			$this->$field = $value;
		}
	}
	
	
	/**
	 * Check if collection exists in the database
	 *
	 * @return	bool			TRUE if the item exists, FALSE if not
	 */
	public function exists() {
		if (!$this->id) {
			trigger_error('$this->id not set');
		}
		
		$sql = "SELECT COUNT(*) FROM collections WHERE collectionID=?";
		return !!Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
	}
	
	
	public function save($userID=false) {
		if (!$this->libraryID) {
			trigger_error("Library ID must be set before saving", E_USER_ERROR);
		}
		
		Zotero_Collections::editCheck($this, $userID);
		
		if (!$this->changed) {
			Z_Core::debug("Collection $this->id has not changed");
			return false;
		}
		
		Zotero_DB::beginTransaction();
		
		try {
			$collectionID = $this->id ? $this->id : Zotero_ID::get('collections');
			
			Z_Core::debug("Saving collection $this->id");
			
			$key = $this->key ? $this->key : Zotero_ID::getKey();
			
			$timestamp = Zotero_DB::getTransactionTimestamp();
			$dateAdded = $this->dateAdded ? $this->dateAdded : $timestamp;
			$dateModified = $this->dateModified ? $this->dateModified : $timestamp;
			$version = Zotero_Libraries::getUpdatedVersion($this->libraryID);
			
			// Verify parent
			if ($this->_parent) {
				if (is_int($this->_parent)) {
					$newParentCollection = Zotero_Collections::get($this->libraryID, $this->_parent);
				}
				else {
					$newParentCollection = Zotero_Collections::getByLibraryAndKey($this->libraryID, $this->_parent);
				}
				
				if (!$newParentCollection) {
					// TODO: clear caches
					throw new Exception("Cannot set parent to invalid collection $this->_parent");
				}
				
				if ($newParentCollection->id == $this->id) {
					trigger_error("Cannot move collection $this->id into itself!", E_USER_ERROR);
				}
				
				// If the designated parent collection is already within this
				// collection (which shouldn't happen), move it to the root
				if ($this->id && $this->hasDescendent('collection', $newParentCollection->id)) {
					$newParentCollection->parent = null;
					$newParentCollection->save();
				}
				
				$parent = $newParentCollection->id;
			}
			else {
				$parent = null;
			}
			
			$fields = "collectionName=?, parentCollectionID=?, libraryID=?, `key`=?,
						dateAdded=?, dateModified=?, serverDateModified=?, version=?";
			$params = array(
				$this->name,
				$parent,
				$this->libraryID,
				$key,
				$dateAdded,
				$dateModified,
				$timestamp,
				$version
			);
			
			$params = array_merge(array($collectionID), $params, $params);
			$shardID = Zotero_Shards::getByLibraryID($this->libraryID);
			
			$sql = "INSERT INTO collections SET collectionID=?, $fields
					ON DUPLICATE KEY UPDATE $fields";
			$insertID = Zotero_DB::query($sql, $params, $shardID);
			if (!$this->id) {
				if (!$insertID) {
					throw new Exception("Collection id not available after INSERT");
				}
				$collectionID = $insertID;
			}
			
			// Remove from delete log if it's there
			$sql = "DELETE FROM syncDeleteLogKeys WHERE libraryID=? AND objectType='collection' AND `key`=?";
			Zotero_DB::query($sql, array($this->libraryID, $key), $shardID);
			
			Zotero_DB::commit();
			
			Zotero_Collections::cachePrimaryData(
				array(
					'id' => $collectionID,
					'libraryID' => $this->libraryID,
					'key' => $key,
					'name' => $this->name,
					'dateAdded' => $dateAdded,
					'dateModified' => $dateModified,
					'parent' => $parent,
					'version' => $version
				)
			);
		}
		catch (Exception $e) {
			Zotero_DB::rollback();
			throw ($e);
		}
		
		if (!$this->_id) {
			$this->_id = $collectionID;
		}
		if (!$this->_key) {
			$this->_key = $key;
		}
		
		return $this->_id;
	}
	
	
	/**
	 * Update the collection's version without changing any data
	 */
	public function updateVersion($userID) {
		$this->changed = true;
		$this->save($userID);
	}
	
	
	/**
	 * Returns child collections
	 *
	 * @return {Integer[]}	Array of collectionIDs
	 */
	public function getChildCollections() {
		if (!$this->childCollectionsLoaded) {
			$this->loadChildCollections();
		}
		return $this->childCollections;
	}
	
	
	/*
	public function setChildCollections($collectionIDs) {
		Zotero_DB::beginTransaction();
		
		if (!$this->childCollectionsLoaded) {
			$this->loadChildCollections();
		}
		
		$current = $this->childCollections;
		$removed = array_diff($current, $collectionIDs);
		$new = array_diff($collectionIDs, $current);
		
		if ($removed) {
			$sql = "UPDATE collections SET parentCollectionID=NULL
					WHERE userID=? AND collectionID IN (";
			$q = array();
			$params = array($this->userID, $this->id);
			foreach ($removed as $collectionID) {
				$q[] = '?';
				$params[] = $collectionID;
			}
			$sql .= implode(',', $q) . ")";
			Zotero_DB::query($sql, $params);
		}
		
		if ($new) {
			$sql = "UPDATE collections SET parentCollectionID=?
					WHERE userID=? AND collectionID IN (";
			$q = array();
			$params = array($this->userID);
			foreach ($new as $collectionID) {
				$q[] = '?';
				$params[] = $collectionID;
			}
			$sql .= implode(',', $q) . ")";
			Zotero_DB::query($sql, $params);
		}
		
		$this->childCollections = $new;
		
		Zotero_DB::commit();
	}
	*/
	
	
	public function numCollections() {
		if ($this->childCollectionsLoaded) {
			return sizeOf($this->childCollections);
		}
		$sql = "SELECT COUNT(*) FROM collections WHERE parentCollectionID=?";
		$num = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		return $num;
	}
	
	
	public function numItems($includeDeleted=false) {
		$sql = "SELECT COUNT(*) FROM collectionItems ";
		if (!$includeDeleted) {
			$sql .= "LEFT JOIN deletedItems DI USING (itemID)";
		}
		$sql .= "WHERE collectionID=?";
		if (!$includeDeleted) {
			$sql .= " AND DI.itemID IS NULL";
		}
		return Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
	}
	
	
	/**
	 * Returns child items
	 *
	 * @return {Integer[]}	Array of itemIDs
	 */
	public function getItems($includeChildItems=false) {
		if (!$this->itemsLoaded) {
			$this->loadItems();
		}
		
		if ($includeChildItems) {
			$sql = "(SELECT INo.itemID FROM itemNotes INo "
				. "JOIN items I ON (INo.sourceItemID=I.itemID) "
				. "JOIN collectionItems CI ON (I.itemID=CI.itemID) "
				. "WHERE collectionID=?)"
				. " UNION "
				. "(SELECT IA.itemID FROM itemAttachments IA "
				. "JOIN items I ON (IA.sourceItemID=I.itemID) "
				. "JOIN collectionItems CI ON (I.itemID=CI.itemID) "
				. "WHERE collectionID=?)";
			$childItemIDs = Zotero_DB::columnQuery(
				$sql, array($this->id, $this->id), Zotero_Shards::getByLibraryID($this->libraryID)
			);
			if ($childItemIDs) {
				return array_merge($this->items, $childItemIDs);
			}
		}
		
		return $this->items;
	}
	
	
	public function setItems($itemIDs) {
		$shardID = Zotero_Shards::getByLibraryID($this->libraryID);
		
		Zotero_DB::beginTransaction();
		
		if (!$this->itemsLoaded) {
			$this->loadItems();
		}
		
		$current = $this->items;
		$removed = array_diff($current, $itemIDs);
		$new = array_diff($itemIDs, $current);
		
		if ($removed) {
			$sql = "DELETE FROM collectionItems WHERE collectionID=? AND itemID IN (";
			while ($chunk = array_splice($removed, 0, 500)) {
				array_unshift($chunk, $this->id);
				Zotero_DB::query(
					$sql . implode(', ', array_fill(0, sizeOf($chunk) - 1, '?')) . ")",
					$chunk,
					$shardID
				);
			}
		}
		
		if ($new) {
			$sql = "INSERT INTO collectionItems (collectionID, itemID) VALUES ";
			while ($chunk = array_splice($new, 0, 250)) {
				Zotero_DB::query(
					$sql . implode(',', array_fill(0, sizeOf($chunk), '(?,?)')),
					call_user_func_array(
						'array_merge',
						array_map(function ($itemID) {
							return [$this->id, $itemID];
						}, $chunk)
					),
					$shardID
				);
			}
		}
		
		$this->items = array_values(array_unique($itemIDs));
		
		//
		// TODO: remove UPDATE statements below once classic syncing is removed
		//
		// Update timestamp of collection
		$sql = "UPDATE collections SET serverDateModified=? WHERE collectionID=?";
		$ts = Zotero_DB::getTransactionTimestamp();
		Zotero_DB::query($sql, array($ts, $this->id), $shardID);
		
		// Update version of new and removed items
		if ($new || $removed) {
			$sql = "UPDATE items SET version=? WHERE itemID IN ("
				. implode(', ', array_fill(0, sizeOf($new) + sizeOf($removed), '?'))
				. ")";
			Zotero_DB::query(
				$sql,
				array_merge(
					array(Zotero_Libraries::getUpdatedVersion($this->libraryID)),
					$new,
					$removed
				),
				$shardID
			);
		}
		
		Zotero_DB::commit();
	}
	
	
	/**
	 * Add an item to the collection. The item's version must be updated
	 * separately.
	 */
	public function addItem($itemID) {
		if (!Zotero_Items::get($this->libraryID, $itemID)) {
			throw new Exception("Item does not exist");
		}
		
		if ($this->hasItem($itemID)) {
			Z_Core::debug("Item $itemID is already a child of collection $this->id");
			return;
		}
		
		$this->setItems(array_merge($this->getItems(), array($itemID)));
	}
	
	
	/**
	 * Add items to the collection. The items' versions must be updated
	 * separately.
	 */
	public function addItems($itemIDs) {
		$items = array_merge($this->getItems(), $itemIDs);
		$this->setItems($items);
	}
	
	
	/**
	 * Remove an item from the collection. The item's version must be updated
	 * separately.
	 */
	public function removeItem($itemID) {
		if (!$this->hasItem($itemID)) {
			Z_Core::debug("Item $itemID is not a child of collection $this->id");
			return false;
		}
		
		$items = $this->getItems();
		array_splice($items, array_search($itemID, $items), 1);
		$this->setItems($items);
		
		return true;
	}

	
	
	/**
	 * Check if an item belongs to the collection
	 */
	public function hasItem($itemID) {
		if (!$this->itemsLoaded) {
			$this->loadItems();
		}
		
		return in_array($itemID, $this->items);
	}
	
	
	public function hasDescendent($type, $id) {
		$descendents = $this->getChildren(true, false, $type);
		for ($i=0, $len=sizeOf($descendents); $i<$len; $i++) {
			if ($descendents[$i]['id'] == $id) {
				return true;
			}
		}
		return false;
	}
	
	
	/**
	 * Returns an array of descendent collections and items
	 *	(rows of 'id', 'type' ('item' or 'collection'), 'parent', and,
	 * 	if collection, 'name' and the nesting 'level')
	 *
	 * @param	bool		$recursive	Descend into subcollections
	 * @param	bool		$nested		Return multidimensional array with 'children'
	 *									nodes instead of flat array
	 * @param	string	$type		'item', 'collection', or FALSE for both
	 */
	public function getChildren($recursive=false, $nested=false, $type=false, $level=1) {
		$toReturn = array();
		
		// 0 == collection
		// 1 == item
		$children = Zotero_DB::query('SELECT collectionID AS id, 
				0 AS type, collectionName AS collectionName, `key`
				FROM collections WHERE parentCollectionID=?
				UNION SELECT itemID AS id, 1 AS type, NULL AS collectionName, `key`
				FROM collectionItems JOIN items USING (itemID) WHERE collectionID=?',
				array($this->id, $this->id),
				Zotero_Shards::getByLibraryID($this->libraryID)
		);
		
		if ($type) {
			switch ($type) {
				case 'item':
				case 'collection':
					break;
				default:
					throw ("Invalid type '$type'");
			}
		}
		
		for ($i=0, $len=sizeOf($children); $i<$len; $i++) {
			// This seems to not work without parseInt() even though
			// typeof children[i]['type'] == 'number' and
			// children[i]['type'] === parseInt(children[i]['type']),
			// which sure seems like a bug to me
			switch ($children[$i]['type']) {
				case 0:
					if (!$type || $type == 'collection') {
						$toReturn[] = array(
							'id' => $children[$i]['id'],
							'name' =>  $children[$i]['collectionName'],
							'key' => $children[$i]['key'],
							'type' =>  'collection',
							'level' =>  $level,
							'parent' =>  $this->id
						);
					}
					
					if ($recursive) {
						$col = Zotero_Collections::getByLibraryAndKey($this->libraryID, $children[$i]['key']);
						$descendents = $col->getChildren(true, $nested, $type, $level+1);
						
						if ($nested) {
							$toReturn[sizeOf($toReturn) - 1]['children'] = $descendents;
						}
						else {
							for ($j=0, $len2=sizeOf($descendents); $j<$len2; $j++) {
								$toReturn[] = $descendents[$j];
							}
						}
					}
				break;
				
				case 1:
					if (!$type || $type == 'item') {
						$toReturn[] = array(
							'id' => $children[$i]['id'],
							'key' => $children[$i]['key'],
							'type' => 'item',
							'parent' => $this->id
						);
					}
				break;
			}
		}
		
		return $toReturn;
	}
	
	
	private function getParent() {
		if ($this->_parent !== false) {
			if (!$this->_parent) {
				return null;
			}
			if (is_int($this->_parent)) {
				return $this->_parent;
			}
			$parentCollection = Zotero_Collections::getByLibraryAndKey($this->libraryID, $this->_parent);
			if (!$parentCollection) {
				throw new Exception("Source collection for keyed parent doesn't exist");
			}
			// Replace stored key with id
			$this->_parent = $parentCollection->id;
			return $parentCollection->id;
		}
		
		if (!$this->id) {
			return false;
		}
		
		$sql = "SELECT parentCollectionID FROM collections WHERE collectionID=?";
		$parentCollectionID = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$parentCollectionID) {
			$parentCollectionID = null;
		}
		$this->_parent = $parentCollectionID;
		return $parentCollectionID;
	}
	
	
	private function getParentKey() {
		if ($this->_parent !== false) {
			if (!$this->_parent) {
				return null;
			}
			if (is_string($this->_parent)) {
				return $this->_parent;
			}
			$parentCollection = Zotero_Collections::get($this->libraryID, $this->_parent);
			return $parentCollection->key;
		}
		
		if (!$this->id) {
			return false;
		}
		
		$sql = "SELECT B.`key` FROM collections A JOIN collections B
				ON (A.parentCollectionID=B.collectionID) WHERE A.collectionID=?";
		$key = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$key) {
			$key = null;
		}
		$this->_parent = $key;
		return $key;
	}
	
	
	private function setParent($parentCollectionID) {
		if ($this->id || $this->key) {
			if (!$this->loaded) {
				$this->load(true);
			}
		}
		else {
			$this->loaded = true;
		}
		
		$oldParentCollectionID = $this->getParent();
		if ($oldParentCollectionID == $parentCollectionID) {
			Z_Core::debug("Parent collection has not changed for collection $this->id");
			return false;
		}
		
/*		if ($this->id && $this->exists() && !$this->previousData) {
			$this->previousData = $this.serialize();
		}
*/		
		$this->_parent = $parentCollectionID ? (int) $parentCollectionID : null;
		$this->changed = true;
		return true;
	}
	
	
	public function setParentKey($parentCollectionKey) {
		if ($this->id || $this->key) {
			if (!$this->loaded) {
				$this->load(true);
			}
		}
		else {
			$this->loaded = true;
		}
		
		$oldParentCollectionID = $this->getParent();
		if ($oldParentCollectionID) {
			$parentCollection = Zotero_Collections::get($this->libraryID, $oldParentCollectionID);
			$oldParentCollectionKey = $parentCollection->key;
			if (!$oldParentCollectionKey) {
				throw new Exception("No key for parent collection $oldParentCollectionID"); 
			}
		}
		else {
			$oldParentCollectionKey = null;
		}
		if ($oldParentCollectionKey == $parentCollectionKey) {
			Z_Core::debug('Source collection has not changed in Zotero_Collection::setParentKey()');
			return false;
		}
		
		/*if ($this->id && $this->exists() && !$this->previousData) {
			$this->previousData = $this.serialize();
		}*/
		$this->_parent = $parentCollectionKey ? $parentCollectionKey : null;
		$this->changed = true;
		
		return true;
	}
	
	
	//
	// Methods dealing with relations
	//
	// save() is not required for relations functions
	//
	/**
	 * Returns all relations of the collection
	 *
	 * @return object Object with predicates as keys and URIs as values
	 */
	public function getRelations() {
		if (!$this->_id) {
			return array();
		}
		$relations = Zotero_Relations::getByURIs(
			$this->libraryID,
			Zotero_URI::getCollectionURI($this)
		);
		
		$toReturn = new stdClass;
		foreach ($relations as $relation) {
			$toReturn->{$relation->predicate} = $relation->object;
		}
		return $toReturn;
	}
	
	
	/**
	 * Updates the collection's relations. No separate save of the collection is required.
	 *
	 * @param object $newRelations Object with predicates as keys and URIs as values
	 * @param int $userID User making the change
	 */
	public function setRelations($newRelations, $userID) {
		if (!$this->_id) {
			throw new Exception('collectionID not set');
		}
		
		// An empty array is allowed by updateFromJSON()
		if (is_array($newRelations) && empty($newRelations)) {
			$newRelations = new stdClass;
		}
		
		Zotero_DB::beginTransaction();
		
		// Get arrays from objects
		$oldRelations = get_object_vars($this->getRelations());
		$newRelations = get_object_vars($newRelations);
		
		$toAdd = array_diff($newRelations, $oldRelations);
		$toRemove = array_diff($oldRelations, $newRelations);
		
		if (!$toAdd && !$toRemove) {
			Zotero_DB::commit();
			return false;
		}
		
		$subject = Zotero_URI::getCollectionURI($this);
		
		foreach ($toAdd as $predicate => $object) {
			Zotero_Relations::add(
				$this->libraryID,
				$subject,
				$predicate,
				$object
			);
		}
		
		foreach ($toRemove as $predicate => $object) {
			$relations = Zotero_Relations::getByURIs(
				$this->libraryID,
				$subject,
				$predicate,
				$object
			);
			foreach ($relations as $relation) {
				Zotero_Relations::delete($this->libraryID, $relation->key);
			}
		}
		
		$this->updateVersion($userID);
		
		Zotero_DB::commit();
		
		return true;
	}
	
	
	/**
	 * Returns all tags assigned to items in this collection
	 */
	public function getTags($asIDs=false) {
		$sql = "SELECT tagID FROM tags JOIN itemTags USING (tagID)
				JOIN collectionItems USING (itemID) WHERE collectionID=? ORDER BY name";
		$tagIDs = Zotero_DB::columnQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$tagIDs) {
			return false;
		}
		
		if ($asIDs) {
			return $tagIDs;
		}
		
		$tagObjs = array();
		foreach ($tagIDs as $tagID) {
			$tag = Zotero_Tags::get($tagID, true);
			$tagObjs[] = $tag;
		}
		return $tagObjs;
	}
	
	
	/*
	 * Returns an array keyed by tagID with the number of linked items for each tag
	 * in this collection
	 */
	public function getTagItemCounts() {
		$sql = "SELECT tagID, COUNT(*) AS numItems FROM tags JOIN itemTags USING (tagID)
				JOIN collectionItems USING (itemID) WHERE collectionID=? GROUP BY tagID";
		$rows = Zotero_DB::query($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$rows) {
			return false;
		}
		
		$counts = array();
		foreach ($rows as $row) {
			$counts[$row['tagID']] = $row['numItems'];
		}
		return $counts;
	}
	
	
	public function toResponseJSON($requestParams=[]) {
		$t = microtime(true);
		
		// Child collections and items can't be cached (easily)
		$numCollections = $this->numCollections();
		$numItems = $this->numItems();
		
		if (!$requestParams['uncached']) {
			$cacheKey = $this->getCacheKey($requestParams);
			$cached = Z_Core::$MC->get($cacheKey);
			if ($cached) {
				Z_Core::debug("Using cached JSON for $this->libraryKey");
				$cached['meta']->numCollections = $numCollections;
				$cached['meta']->numItems = $numItems;
				
				StatsD::timing("api.collections.toResponseJSON.cached", (microtime(true) - $t) * 1000);
				StatsD::increment("memcached.collections.toResponseJSON.hit");
				return $cached;
			}
		}
		
		$json = [
			'key' => $this->key,
			'version' => $this->version,
			'library' => Zotero_Libraries::toJSON($this->libraryID)
		];
		
		// 'links'
		$json['links'] = [
			'self' => [
				'href' => Zotero_API::getCollectionURI($this),
				'type' => 'application/json'
			],
			'alternate' => [
				'href' => Zotero_URI::getCollectionURI($this, true),
				'type' => 'text/html'
			]
		];
		
		$parent = $this->parent;
		if ($parent) {
			$parentCol = Zotero_Collections::get($this->libraryID, $parent);
			$json['links']['up'] = [
				'href' => Zotero_API::getCollectionURI($parentCol),
				'type' => "application/atom+xml"
			];
		}
		
		// 'meta'
		$json['meta'] = new stdClass;
		$json['meta']->numCollections = $numCollections;
		$json['meta']->numItems = $numItems;
		
		// 'include'
		$include = $requestParams['include'];
		
		foreach ($include as $type) {
			if ($type == 'data') {
				$json[$type] = $this->toJSON($requestParams);
			}
		}
		
		if (!$requestParams['uncached']) {
			Z_Core::$MC->set($cacheKey, $json);
			
			StatsD::timing("api.collections.toResponseJSON.uncached", (microtime(true) - $t) * 1000);
			StatsD::increment("memcached.collections.toResponseJSON.miss");
		}
		
		return $json;
	}
	
	
	public function toJSON(array $requestParams=[]) {
		if (!$this->loaded) {
			$this->load();
		}
		
		if ($requestParams['v'] >= 3) {
			$arr['key'] = $this->key;
			$arr['version'] = $this->version;
		}
		else {
			$arr['collectionKey'] = $this->key;
			$arr['collectionVersion'] = $this->version;
		}
		
		$arr['name'] = $this->name;
		$parentKey = $this->getParentKey();
		if ($requestParams['v'] >= 2) {
			$arr['parentCollection'] = $parentKey ? $parentKey : false;
			$arr['relations'] = $this->getRelations();
		}
		else {
			$arr['parent'] = $parentKey ? $parentKey : false;
		}
		
		return $arr;
	}
	
	
	private function load() {
		$libraryID = $this->_libraryID;
		$id = $this->_id;
		$key = $this->_key;
		
		Z_Core::debug("Loading data for collection $libraryID/" . ($id ? $id : $key));
		
		if (!$libraryID) {
			throw new Exception("Library ID not set");
		}
		
		if (!$id && !$key) {
			throw new Exception("ID or key not set");
		}
		
		if ($id) {
			$row = Zotero_Collections::getPrimaryDataByID($libraryID, $id);
		}
		else {
			$row = Zotero_Collections::getPrimaryDataByKey($libraryID, $key);
		}
		
		$this->loaded = true;
		
		if (!$row) {
			return;
		}
		
		if ($row['libraryID'] != $libraryID) {
			throw new Exception("libraryID {$row['libraryID']} != $this->libraryID");
		}
		
		foreach ($row as $key=>$val) {
			$field = '_' . $key;
			$this->$field = $val;
		}
	}
	
	
	private function loadChildCollections() {
		Z_Core::debug("Loading subcollections for collection $this->id");
		
		if ($this->childCollectionsLoaded) {
			trigger_error("Subcollections for collection $this->id already loaded", E_USER_ERROR);
		}
		
		if (!$this->id) {
			trigger_error('$this->id not set', E_USER_ERROR);
		}
		
		$sql = "SELECT collectionID FROM collections WHERE parentCollectionID=?";
		$ids = Zotero_DB::columnQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		
		$this->childCollections = $ids ? $ids : array();
		$this->childCollectionsLoaded = true;
	}
	
	
	private function loadItems() {
		Z_Core::debug("Loading child items for collection $this->id");
		
		if ($this->itemsLoaded) {
			trigger_error("Child items for collection $this->id already loaded", E_USER_ERROR);
		}
		
		if (!$this->id) {
			trigger_error('$this->id not set', E_USER_ERROR);
		}
		
		$sql = "SELECT itemID FROM collectionItems WHERE collectionID=?";
		$ids = Zotero_DB::columnQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		
		$this->items = $ids ? $ids : array();
		$this->itemsLoaded = true;
	}
	
	
	private function checkValue($field, $value) {
		if (!property_exists($this, '_' . $field)) {
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
				if (!Zotero_ID::isValidKey($value)) {
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
				if (mb_strlen($value) > Zotero_Collections::$maxLength) {
					throw new Exception("Collection '" . $value . "' too long", Z_ERROR_COLLECTION_TOO_LONG);
				}
				break;
		}
	}
	
	
	private function getCacheKey($requestParams) {
		$cacheKey = implode("\n", [
			$this->libraryID,
			$this->key,
			$this->version,
			implode(',', $requestParams['include']),
			$requestParams['v']
		]);
		return md5($cacheKey);
	}
	
	
	private function getETag() {
		if (!$this->loaded) {
			$this->load();
		}
		
		return md5($this->name . "_" . $this->getParent());
	}
	
	
	private function invalidValueError($field, $value) {
		trigger_error("Invalid '$field' value '$value'", E_USER_ERROR);
	}
}
?>
