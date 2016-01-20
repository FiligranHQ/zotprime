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

trait Zotero_DataObjects {
	public static $objectTypes = [
		'item' => ['singular'=>'Item', 'plural'=>'Items'],
		'collection' => ['singular'=>'Collection', 'plural'=>'Collections'],
		'search' => ['singular'=>'Search', 'plural'=>'Searches']
	];
	
	public static $classicObjectTypes = [
		'creator' => ['singular'=>'Creator', 'plural'=>'Creators'],
		'item' => ['singular'=>'Item', 'plural'=>'Items'],
		'collection' => ['singular'=>'Collection', 'plural'=>'Collections'],
		'search' => ['singular'=>'Search', 'plural'=>'Searches'],
		'tag' => ['singular'=>'Tag', 'plural'=>'Tags'],
		'relation' => ['singular'=>'Relation', 'plural'=>'Relations'],
		'setting' => ['singular'=>'Setting', 'plural'=>'Settings']
	];
	
	public static $idColumn;
	public static $table;
	public static $primaryFields;
	
	private static $ObjectType;
	private static $objectTypePlural;
	private static $ObjectTypePlural;
	private static $ObjectClass;
	
	public static $primaryDataSQLFrom;
	private static $primaryDataSQLWhere = 'WHERE 1';
	
	private static $cacheVersion = 3;
	
	private static $objectCache = [];
	private static $objectIDs = [];
	private static $loadedLibraries = [];
	
	
	public static function init() {
		if (!self::$objectType) {
			throw new Exception('self::$objectType must be set before calling Zotero_DataObjects initializer');
		}
		
		self::$ObjectType = ucwords(self::$objectType);
		self::$objectTypePlural = \Zotero\DataObjectUtilities::getObjectTypePlural(self::$objectType);
		self::$ObjectTypePlural = ucwords(self::$objectTypePlural);
		self::$idColumn = self::$objectType . "ID";
		self::$table = isset(self::$_table) ? self::$_table : self::$objectTypePlural;
		self::$ObjectClass = "Zotero_" . self::$objectType;
		self::$primaryFields = array_keys(self::$primaryDataSQLParts);
		self::$primaryDataSQLFrom = " "
			. (isset(self::$_primaryDataSQLFrom) ? self::$_primaryDataSQLFrom : "FROM " . self::$table . " O")
			. " " . self::$primaryDataSQLWhere;
	}
	
	
	protected static function getPrimaryDataSQL() {
		$parts = [];
		foreach (self::$primaryDataSQLParts as $key => $val) {
			$parts[] = "$val AS `$key`";
		}
		return "SELECT " . implode(', ', $parts) . " " . self::$primaryDataSQLFrom;
	}
	
	
	public static function getPrimaryDataSQLPart($part) {
		$sql = self::$primaryDataSQLParts[$part];
		if (!isset($sql)) {
			throw new Exception("Invalid primary data SQL part '$part'");
		}
		return $sql;
	}
	
	
	public static function isPrimaryField($field) {
		return in_array($field, self::$primaryFields);
	}
	
	
	/**
	 * Retrieves (and loads, if necessary) one or more items
	 *
	 * @param {Integer} libraryID
	 * @param {Array|Integer} ids  An individual object id or an array of object ids
	 * @param {Object} [options]
	 * @param {Boolean} [options.noCache=false] - Don't add object to cache after loading
	 * @return {Zotero.DataObject|Zotero.DataObject[]} - Either a data object, if a scalar id was
	 *    passed, or an array of data objects, if an array of ids was passed
	 */
	public static function get($libraryID, $ids, array $options = []) {
		$toLoad = [];
		$toReturn = [];
		
		if (!$ids) {
			throw new Exception("No arguments provided to " . self::$ObjectTypePlural . ".get()");
		}
		
		if (is_array($ids)) {
			$singleObject = false;
		}
		else {
			$singleObject = true;
			$ids = [$ids];
		}
		
		foreach ($ids as $id) {
			// Check if already loaded
			if (isset(self::$objectCache[$id])) {
				$toReturn[] = self::$objectCache[$id];
			}
			else {
				$toLoad[] = $id;
			}
		}
		
		// New object to load
		if ($toLoad) {
			$loaded = self::load($libraryID, $toLoad, $options);
			for ($i = 0; $i < sizeOf($toLoad); $i++) {
				$id = $toLoad[$i];
				$obj = isset($loaded[$id]) ? $loaded[$id] : false;
				if (!$obj) {
					Z_Core::debug(self::$ObjectType . " $id doesn't exist", 2);
					continue;
				}
				$toReturn[] = $obj;
			}
		}
		
		// If single id, return the object directly
		if ($singleObject) {
			return $toReturn ? $toReturn[0] : false;
		}
		
		return $toReturn;
	}
	
	
	public static function getByLibraryAndKey($libraryID, $key) {
		$type = self::$objectType;
		$types = self::$objectTypePlural;
		
		$exists = self::existsByLibraryAndKey($libraryID, $key);
		if (!$exists) {
			return false;
		}
		
		switch ($type) {
			default:
				$className = "Zotero_" . ucwords($types);
				return call_user_func([$className, 'get'], $libraryID, self::$objectIDs[$libraryID][$key]);
		}
	}
	
	
	public static function existsByLibraryAndKey($libraryID, $key) {
		if (!$libraryID || !is_numeric($libraryID)) {
			throw new Exception("libraryID '$libraryID' must be a positive integer");
		}
		
		$type = self::$objectType;
		
		if (!preg_match('/[A-Z0-9]{8}/', $key)) {
			throw new Exception("Invalid key '$key'");
		}
		
		return !!self::getIDFromLibraryAndKey($libraryID, $key);
	}
	
	
	public static function getIDFromLibraryAndKey($libraryID, $key) {
		if (isset(self::$objectIDs[$libraryID][$key])) {
			return self::$objectIDs[$libraryID][$key];
		}
		
		$sql = "SELECT " . self::$idColumn . " FROM " . self::$table
			. " WHERE libraryID=? AND `key`=?";
		$id = Zotero_DB::valueQuery(
			$sql, [$libraryID, $key], Zotero_Shards::getByLibraryID($libraryID)
		);
		return self::$objectIDs[$libraryID][$key] = $id;
	}
	
	
	/**
	 * Reload loaded data of loaded objects
	 *
	 * @param {Array|Number} ids - An id or array of ids
	 * @param {Array} [dataTypes] - Data types to reload (e.g., 'primaryData'), or all loaded
	 *                              types if not provided
	 * @param {Boolean} [reloadUnchanged=false] - Reload even data that hasn't changed internally.
	 *                                            This should be set to true for data that was
	 *                                            changed externally (e.g., globally renamed tags).
	 */
	public static function reload($ids, $dataTypes = null, $reloadUnchanged = false) {
		if (is_scalar($ids)) {
			$ids = [$ids];
		}
		
		Z_Core::debug("Reloading " . ($dataTypes ? '[' . implode(', ', dataTypes) . '] for ' : '')
			. self::$objectTypePlural . ' [' . implode(', ', $ids) . ']');
		
		foreach ($ids as $id) {
			if (self::$objectCache[$id]) {
				self::$objectCache[$id]->reload($dataTypes, $reloadUnchanged);
			}
		}
		
		return true;
	}
	
	
	public static function registerObject($obj) {
		$id = $obj->id;
		$libraryID = $obj->libraryID;
		$key = $obj->key;
		
		Z_Core::debug("Registering " . self::$objectType . " " . $id
			. " as " . $libraryID . "/" . $key);
		if (!isset(self::$objectIDs[$libraryID])) {
			self::$objectIDs[$libraryID] = [];
		}
		self::$objectIDs[$libraryID][$key] = $id;
		self::$objectCache[$id] = $obj;
		$obj->inCache = true;
	}
	
	
	/**
	 * Clear object from internal array
	 *
	 * @param {Integer[]} $ids - Object ids
	 */
	public static function unload($ids) {
		if (is_scalar($ids)) {
			$ids = [$ids];
		}
		
		foreach ($ids as $id) {
			if (!isset(self::$objectCache[$id])) {
				continue;
			}
			$obj = self::$objectCache[$id];
			$key = $obj->key;
			unset(self::$objectIDs[$obj->libraryID][$obj->key]);
			unset(self::$objectCache[$id]);
		}
	}
	
	
	public static function updateMultipleFromJSON($json, $requestParams, $libraryID, $userID,
			Zotero_Permissions $permissions, $requireVersion, $parent=null) {
		$type = self::$objectType;
		$types = self::$objectTypePlural;
		$keyProp = $type . "Key";
		
		switch ($type) {
		case 'collection':
		case 'search':
			if ($parent) {
				throw new Exception('$parent is not valid for ' . $type);
			}
			break;
		
		case 'item':
			break;
		
		default:
			throw new Exception("Function not valid for $type");
		}
		
		self::validateMultiObjectJSON($json, $requestParams);
		
		$results = new Zotero_Results($requestParams);
		
		if ($requestParams['v'] >= 2 && Zotero_DB::transactionInProgress()) {
			throw new Exception(
				"Transaction cannot be open when starting multi-object update"
			);
		}
		
		// If single collection object, stuff in array
		if ($requestParams['v'] < 2 && $type == 'collection' && !isset($json->collections)) {
			$json = [$json];
		}
		else if ($requestParams['v'] < 3) {
			$json = $json->$types;
		}
		
		$i = 0;
		foreach ($json as $prop => $jsonObject) {
			Zotero_DB::beginTransaction();
			
			try {
				if (!is_object($jsonObject)) {
					throw new Exception(
						"Invalid value for index $prop in uploaded data; expected JSON $type object",
						Z_ERROR_INVALID_INPUT
					);
				}
				
				$className = "Zotero_" . ucwords($type);
				$obj = new $className;
				$obj->libraryID = $libraryID;
				if ($type == 'item') {
					$changed = self::updateFromJSON(
						$obj, $jsonObject, $parent, $requestParams, $userID, $requireVersion, true
					);
				}
				else {
					$changed = self::updateFromJSON(
						$obj, $jsonObject, $requestParams, $userID, $requireVersion, true
					);
				}
				Zotero_DB::commit();
				
				if ($changed) {
					$results->addSuccessful($i, $obj->toResponseJSON($requestParams, $permissions));
				}
				else {
					$results->addUnchanged($i, $obj->key);
				}
			}
			catch (Exception $e) {
				Zotero_DB::rollback();
				
				if ($requestParams['v'] < 2) {
					throw ($e);
				}
				
				// If object key given, include that
				$resultKey = isset($jsonObject->$keyProp)
					? $jsonObject->$keyProp : '';
				
				$results->addFailure($i, $resultKey, $e);
			}
			$i++;
		}
		
		return $results->generateReport();
	}
	
	
	protected static function validateMultiObjectJSON($json, $requestParams) {
		$objectTypePlural = self::$objectTypePlural;
		
		if ($requestParams['v'] < 3) {
			if (!is_object($json)) {
				throw new Exception('Uploaded data must be a JSON object', Z_ERROR_INVALID_INPUT);
			}
			
			// Multiple-object format
			if (isset($json->$objectTypePlural)) {
				if (!is_array($json->$objectTypePlural)) {
					throw new Exception("'$objectTypePlural' must be an array", Z_ERROR_INVALID_INPUT);
				}
				foreach ($json as $key=>$val) {
					if ($key != $objectTypePlural) {
						throw new Exception("Invalid property '$key'", Z_ERROR_INVALID_INPUT);
					}
					$maxWriteKey = "maxWrite" . ucwords($objectTypePlural);
					if (sizeOf($val) > Zotero_API::$$maxWriteKey) {
						throw new Exception("Cannot add more than "
							. Zotero_API::$$maxWriteKey
							. " $objectTypePlural at a time", Z_ERROR_UPLOAD_TOO_LARGE);
					}
				}
			}
			// Single-collection format (collections only)
			else if ($requestParams['v'] < 2 && $objectTypePlural == 'collections') {
				if (!isset($json->name)) {
					throw new Exception("'collections' or 'name' must be provided", Z_ERROR_INVALID_INPUT);
				}
			}
			else {
				throw new Exception("'$objectTypePlural' must be provided", Z_ERROR_INVALID_INPUT);
			}
			
			return;
		}
			
		if (!is_array($json)) {
			throw new Exception('Uploaded data must be a JSON array', Z_ERROR_INVALID_INPUT);
		}
		$maxWriteKey = "maxWrite" . ucwords($objectTypePlural);
		if (sizeOf($json) > Zotero_API::$$maxWriteKey) {
			throw new Exception("Cannot add more than "
				. Zotero_API::$$maxWriteKey
				. " $objectTypePlural at a time", Z_ERROR_UPLOAD_TOO_LARGE);
		}
	}
	
	
	public static function countUpdated($userID, $timestamp, $deletedCheckLimit=false) {
		$table = self::$table;
		$id = self::$idColumn;
		$type = self::$objectType;
		$types = self::$objectTypePlural;
		
		// First, see what libraries we actually need to check
		
		Zotero_DB::beginTransaction();
		
		// All libraries with update times >= $timestamp
		$updateTimes = Zotero_Libraries::getUserLibraryUpdateTimes($userID);
		$updatedLibraryIDs = array();
		foreach ($updateTimes as $libraryID=>$lastUpdated) {
			if ($lastUpdated >= $timestamp) {
				$updatedLibraryIDs[] = $libraryID;
			}
		}
		
		$count = self::getUpdated($userID, $timestamp, $updatedLibraryIDs, true);
		
		// Make sure we really have fewer than 5
		if ($deletedCheckLimit < 5) {
			$count += Zotero_Sync::countDeletedObjectKeys($userID, $timestamp, $updatedLibraryIDs);
		}
		
		Zotero_DB::commit();
		
		return $count;
	}
	
	
	/**
	 * Returns user's object ids updated since |timestamp|, keyed by libraryID,
	 * or count of all updated items if $countOnly is true
	 *
	 * @param	int			$libraryID			User ID
	 * @param	string		$timestamp			Unix timestamp of last sync time
	 * @param	array		$updatedLibraryIDs	Libraries with updated data
	 * @return	array|int
	 */
	public static function getUpdated($userID, $timestamp, $updatedLibraryIDs, $countOnly=false) {
		$table = self::$table;
		$id = self::$idColumn;
		$type = self::$objectType;
		$types = self::$objectTypePlural;
		$timestampCol = "serverDateModified";
		
		// All joined groups have to be checked
		$joinedGroupIDs = Zotero_Groups::getJoined($userID, $timestamp);
		$joinedLibraryIDs = array();
		foreach ($joinedGroupIDs as $groupID) {
			$joinedLibraryIDs[] = Zotero_Groups::getLibraryIDFromGroupID($groupID);
		}
		
		// Separate libraries into shards for querying
		$libraryIDs = array_unique(array_merge($joinedLibraryIDs, $updatedLibraryIDs));
		$shardLibraryIDs = array();
		foreach ($libraryIDs as $libraryID) {
			$shardID = Zotero_Shards::getByLibraryID($libraryID);
			if (!isset($shardLibraryIDs[$shardID])) {
				$shardLibraryIDs[$shardID] = array(
					'updated' => array(),
					'joined' => array()
				);
			}
			if (in_array($libraryID, $joinedLibraryIDs)) {
				$shardLibraryIDs[$shardID]['joined'][] = $libraryID;
			}
			else {
				$shardLibraryIDs[$shardID]['updated'][] = $libraryID;
			}
		}
		
		if ($countOnly) {
			$count = 0;
			$fieldList = "COUNT(*)";
		}
		else {
			$updatedByLibraryID = array();
			$fieldList = "libraryID, $id AS id";
		}
		
		// Send query at each shard
		foreach ($shardLibraryIDs as $shardID=>$libraryIDs) {
			$sql = "SELECT $fieldList FROM $table WHERE ";
			if ($libraryIDs['updated']) {
				$sql .= "(libraryID IN (" .  implode(', ', array_fill(0, sizeOf($libraryIDs['updated']), '?')) . ")";
				$params = $libraryIDs['updated'];
				$sql .= " AND $timestampCol >= FROM_UNIXTIME(?))";
				$params[] = $timestamp;
			}
			
			if ($libraryIDs['joined']) {
				if ($libraryIDs['updated']) {
					$sql .= " OR ";
				}
				else {
					$params = array();
				}
				$sql .= "libraryID IN (" . implode(', ', array_fill(0, sizeOf($libraryIDs['joined']), '?')) . ")";
				$params = array_merge($params, $libraryIDs['joined']);
			}
			
			if ($countOnly) {
				$count += Zotero_DB::valueQuery($sql, $params, $shardID, [ 'cache' => false ]);
			}
			else {
				$rows = Zotero_DB::query($sql, $params, $shardID, [ 'cache' => false ]);
				if ($rows) {
					// Separate ids by libraryID
					foreach ($rows as $row) {
						$updatedByLibraryID[$row['libraryID']][] = $row['id'];
					}
				}
			}
		}
		
		return $countOnly ? $count : $updatedByLibraryID;
	}
	
	
	public static function getDeleteLogKeys($libraryID, $version, $versionIsTimestamp=false) {
		// Default empty library
		if ($libraryID === 0) {
			return [];
		}
		
		$type = self::$objectType;
		
		// TEMP: until classic syncing is deprecated and the objectType
		// 'tagName' is changed to 'tag'
		if ($type == 'tag') {
			$type = 'tagName';
		}
		
		$sql = "SELECT `key` FROM syncDeleteLogKeys "
			. "WHERE objectType=? AND libraryID=? AND ";
		// TEMP: sync transition
		$sql .= $versionIsTimestamp ? "timestamp>=FROM_UNIXTIME(?)" : "version>?";
		$keys = Zotero_DB::columnQuery(
			$sql,
			array($type, $libraryID, $version),
			Zotero_Shards::getByLibraryID($libraryID)
		);
		if (!$keys) {
			return array();
		}
		return $keys;
	}
	
	
	public static function editCheck($obj, $userID=false) {
		if (!$userID) {
			return true;
		}
		
		if (!Zotero_Libraries::userCanEdit($obj->libraryID, $userID, $obj)) {
			throw new Exception("Cannot edit " . self::$objectType
				. " in library $obj->libraryID", Z_ERROR_LIBRARY_ACCESS_DENIED);
		}
	}
	
	
	public static function delete($libraryID, $key) {
		$table = self::$table;
		$type = self::$objectType;
		$types = self::$objectTypePlural;
		
		if (!$key) {
			throw new Exception("Invalid key $key");
		}
		
		// Get object (and trigger caching)
		$obj = self::getByLibraryAndKey($libraryID, $key);
		if (!$obj) {
			return;
		}
		self::editCheck($obj);
		
		Z_Core::debug("Deleting $type $libraryID/$key", 4);
		
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		
		Zotero_DB::beginTransaction();
		
		// Delete child items
		if ($type == 'item') {
			if ($obj->isRegularItem()) {
				$children = array_merge($obj->getNotes(), $obj->getAttachments());
				if ($children) {
					$children = Zotero_Items::get($libraryID, $children);
					foreach ($children as $child) {
						self::delete($child->libraryID, $child->key);
					}
				}
			}
			
			// Remove relations (except for merge tracker)
			$uri = Zotero_URI::getItemURI($obj);
			Zotero_Relations::eraseByURI(
				$libraryID, $uri, array(Zotero_Relations::$deletedItemPredicate)
			);
		}
		// Tag deletions need to stored by tag for the API
		else if ($type == 'tag') {
			$tagName = $obj->name;
		}
		
		if ($type == 'item' && $obj->isAttachment()) {
			Zotero_FullText::deleteItemContent($obj);
		}
		
		$sql = "DELETE FROM $table WHERE libraryID=? AND `key`=?";
		$deleted = Zotero_DB::query($sql, array($libraryID, $key), $shardID);
		
		self::unload($obj->id);
		
		if ($deleted) {
			$sql = "INSERT INTO syncDeleteLogKeys
						(libraryID, objectType, `key`, timestamp, version)
						VALUES (?, '$type', ?, ?, ?)
						ON DUPLICATE KEY UPDATE timestamp=?, version=?";
			$timestamp = Zotero_DB::getTransactionTimestamp();
			$version = Zotero_Libraries::getUpdatedVersion($libraryID);
			$params = array(
				$libraryID, $key, $timestamp, $version, $timestamp, $version
			);
			Zotero_DB::query($sql, $params, $shardID);
			
			if ($type == 'tag') {
				$sql = "INSERT INTO syncDeleteLogKeys
							(libraryID, objectType, `key`, timestamp, version)
							VALUES (?, 'tagName', ?, ?, ?)
							ON DUPLICATE KEY UPDATE timestamp=?, version=?";
				$params = array(
					$libraryID, $tagName, $timestamp, $version, $timestamp, $version
				);
				Zotero_DB::query($sql, $params, $shardID);
			}
		}
		
		Zotero_DB::commit();
	}
	
	
	/**
	 * @param	SimpleXMLElement	$xml		Data necessary for delete as SimpleXML element
	 * @return	void
	 */
	public static function deleteFromXML(SimpleXMLElement $xml, $userID) {
		$parents = array();
		
		foreach ($xml->children() as $obj) {
			$libraryID = (int) $obj['libraryID'];
			$key = (string) $obj['key'];
			
			if ($userID && !Zotero_Libraries::userCanEdit($libraryID, $userID)) {
				throw new Exception("Cannot edit " . self::$objectType
					. " in library $libraryID", Z_ERROR_LIBRARY_ACCESS_DENIED);
			}
			
			if ($obj->getName() == 'item') {
				$item = Zotero_Items::getByLibraryAndKey($libraryID, $key);
				if (!$item) {
					continue;
				}
				if (!$item->getSource()) {
					$parents[] = array('libraryID' => $libraryID, 'key' => $key);
					continue;
				}
			}
			self::delete($libraryID, $key);
		}
		
		foreach ($parents as $obj) {
			self::delete($obj['libraryID'], $obj['key']);
		}
	}
	
	
	private static function load($libraryID, $ids = [], array $options = []) {
		$loaded = [];
		
		if (!$libraryID) {
			throw new Exception("libraryID must be provided");
		}
		
		if ($libraryID !== false && !empty(self::$loadedLibraries[$libraryID])) {
			return $loaded;
		}
		
		$sql = self::getPrimaryDataSQL() . ' AND O.libraryID=?';
		$params = [$libraryID];
		if ($ids) {
			$sql .= ' AND O.' . self::$idColumn . ' IN (' . implode(',', $ids) . ')';
		}
		
		$t = microtime();
		$rows = Zotero_DB::query(
			$sql, $params, Zotero_Shards::getByLibraryID($libraryID), [ 'cache' => false ]
		);
		foreach ($rows as $row) {
			$id = $row['id'];
			
			// Existing object -- reload in place
			if (isset(self::$objectCache[$id])) {
				self::$objectCache[$id]->loadFromRow($row, true);
				$obj = self::$objectCache[$id];
			}
			// Object doesn't exist -- create new object and stuff in cache
			else {
				$class = "Zotero_" . self::$ObjectType;
				$obj = new $class;
				$obj->loadFromRow($row, true);
				if (!$options || !$options->noCache) {
					self::registerObject($obj);
				}
			}
			$loaded[$id] = $obj;
		}
		Z_Core::debug("Loaded " . self::$objectTypePlural . " in " . (microtime() - $t) . "ms");
		
		if (!$ids) {
			self::$loadedLibraries[$libraryID] = true;
			
			// If loading all objects, remove cached objects that no longer exist
			foreach (self::$objectCache as $obj) {
				if ($libraryID !== false && obj.libraryID !== libraryID) {
					continue;
				}
				if (empty($loaded[$obj->id])) {
					self::unload($obj->id);
				}
			}
		}
		
		return $loaded;
	}
}
