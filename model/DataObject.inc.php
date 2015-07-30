<?php
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2015 Center for History and New Media
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

class Zotero_DataObject {
	protected $ObjectType;
	protected $objectTypePlural;
	protected $ObjectTypePlural;
	protected $objectsClass;
	
	protected $_id;
	protected $_libraryID;
	protected $_key;
	protected $_version;
	protected $_parentID;
	protected $_parentKey;
	
	protected $_relations = [];
	
	// Set in DataObjects
	public $inCache = false;
	
	protected $dataTypes = ['primaryData'];
	protected $dataTypesExtended = [];
	protected $identified;
	protected $loaded = [];
	protected $skipDataTypeLoad = [];
	protected $changed = [];
	protected $previousData = [];
	
	private $disabled = false;
	
	public function __construct() {
		$objectType = $this->objectType;
		$this->ObjectType = ucwords($objectType);
		$this->objectTypePlural = \Zotero\DataObjectUtilities::getObjectTypePlural($objectType);
		$this->ObjectTypePlural = ucwords($this->objectTypePlural);
		$this->objectsClass = "Zotero_" . $this->ObjectTypePlural;
		
		$this->dataTypes = array_merge($this->dataTypes, $this->dataTypesExtended);
		
		$this->markAllDataTypeLoadStates(false);
		$this->clearChanged();
	}
	
	
	public function __get($field) {
		if ($field == 'libraryKey') {
			return $this->libraryID . "/" . $this->key;
		}
		
		if ($field != 'id') $this->disabledCheck();
		
		if (!property_exists($this, "_$field")) {
			throw new Exception("Invalid property '$field'");
		}
		
		if (!is_null($this->{"_$field"})) {
			return $this->{"_$field"};
		}
		
		if ($this->identified && empty($this->loaded['primaryData'])) {
			$this->loadPrimaryData();
		}
		return $this->{"_$field"};
	}
	
	
	public function __set($field, $value) {
		$this->disabledCheck();
		
		if ($field == 'id' || $field == 'libraryID' || $field == 'key') {
			return $this->setIdentifier($field, $value);
		}
		
		if ($field == 'parentKey') {
			$this->setParentKey($value);
			return;
		}
		if ($field == 'parentID') {
			$this->setParentID($value);
			return;
		}
		
		if ($this->identified) {
			$this->loadPrimaryData();
		}
		else {
			$this->loaded['primaryData'] = true;
		}
		
		switch ($field) {
		case 'name':
			if ($this->objectType == 'item') {
				throw new Exception("Invalid " . $this->objectType . " property '$field'");
			}
			$value = Normalizer::normalize(trim($value));
			break;
		
		case 'version':
			$value = (int) $value;
			break;
		}
		
		if ($this->{"_$field"} !== $value) {
			//$this->markFieldChange(field, this['_' + field]);
			if (!isset($this->changed['primaryData'])) {
				$this->changed['primaryData'] = [];
			}
			$this->changed['primaryData'][$field] = true;
			
			switch ($field) {
			default:
				$this->{"_$field"} = $value;
			}
		}
	}
	
	
	public function setIdentifier($field, $value) {
		switch ($field) {
		case 'id':
			$value = \Zotero\DataObjectUtilities::checkID($value);
			if ($this->_id) {
				if ($value === $this->_id) {
					return;
				}
				throw new Exception("ID cannot be changed");
			}
			if ($this->_key) {
				throw new Exception("Cannot set id if key is already set");
			}
			break;
			
		case 'libraryID':
			//value = \Zotero\DataObjectUtilities\checkLibraryID(value);
			break;
			
		case 'key':
			if (is_null($this->_libraryID)) {
				throw new Exception("libraryID must be set before key");
			}
			$value = \Zotero\DataObjectUtilities::checkKey($value);
			if ($this->_key) {
				if ($value === $this->_key) {
					return;
				}
				throw new Exception("Key cannot be changed");
			}
			if ($this->_id) {
				throw new Exception("Cannot set key if id is already set");
			}
		}
		
		if ($value === $this->{"_$field"}) {
			return;
		}
		
		// If primary data is loaded, the only allowed identifier change is libraryID, and then
		// only for unidentified objects, and then only if a libraryID isn't yet set (because
		// primary data gets marked as loaded when fields are set for new items, but some methods
		// (setCollections(), save()) automatically set the user library ID after that if none is
		// specified)
		if (!empty($this->loaded['primaryData'])) {
			if (!(!$this->identified && $field == 'libraryID')) {
				throw new Exception("Cannot change $field after object is already loaded");
			}
		}
		
		if ($field == 'id' || $field == 'key') {
			$this->identified = true;
		}
		
		$this->{"_$field"} = $value;
	}
	
	
	/**
	 * Get the id of the parent object
	 *
	 * @return {Integer|false|undefined}  The id of the parent object, false if none, or undefined
	 *                                      on object types to which it doesn't apply (e.g., searches)
	 */
	public function getParentID() {
		if ($this->_parentID !== null) {
			return $this->_parentID;
		}
		if (!$this->_parentKey) {
			if ($this->objectType == 'search') {
				return null;
			}
			return false;
		}
		$objectsClass = $this->objectsClass;
		return $this->_parentID = $objectsClass::getIDFromLibraryAndKey($this->_libraryID, $this->_parentKey);
	}
	
	
	/**
	 * Set the id of the parent object
	 *
	 * @param {Number|false} [id=false]
	 * @return {Boolean} True if changed, false if stayed the same
	 */
	public function setParentID($id) {
		$objectsClass = $this->objectsClass;
		return $this->_setParentKey(
			$id
			? $objectsClass::getLibraryAndKeyFromID(\Zotero\DataObjectUtilities::checkID($id))->key
			: false
		);
	}
	
	
	public function getParentKey() {
		if ($this->objectType == 'search') {
			return null;
		}
		return $this->_parentKey ? $this->_parentKey : false;
	}
	
	/**
	 * Set the key of the parent object
	 *
	 * @param {String|false} [key=false]
	 * @return {Boolean} True if changed, false if stayed the same
	 */
	public function setParentKey($key) {
		if ($this->objectType == 'search') {
			throw new Exception("Cannot set parent key for search");
		}
		
		$key = \Zotero\DataObjectUtilities::checkKey($key);
		if (!$key) {
			$key = false;
		}
		
		if ($key === $this->_parentKey || (!$this->_parentKey && !$key)) {
			return false;
		}
		
		Z_Core::debug("Changing parent key from '$this->_parentKey' to '$key' for "
			. $this->objectType . " " . $this->libraryKey);
		
		//$this->_markFieldChange('parentKey', this._parentKey);
		$this->changed['parentKey'] = true;
		$this->_parentKey = $key;
		$this->_parentID = null;
		return true;
	}
	
	
	/**
	 * Set the object's version to the version found in the DB. This can be set by search code
	 * (which should grab the version) to allow a cached copy of the object to be used. Otherwise,
	 * the primary data would need to be loaded just to get the version number needed to get the
	 * cached object.)
	 */
	public function setAvailableVersion($version) {
		$version = (int) $version;
		if ($this->loaded && $this->_version != $version) {
			throw new Exception("Version does not match current value ($version != $this->_version)");
		}
		$this->_version = $version;
	}
	
	
	protected function finalizeSave($env) {
		if (!empty($env['id'])) {
			$this->_id = $env['id'];
		}
		if (!empty($env['key'])) {
			$this->_key = $env['key'];
		}
		$this->identified = true;
		
		$this->loadPrimaryData(true);
		$this->reload();
		if ($env['isNew']) {
			//Zotero_Items::cache($this);
			$this->markAllDataTypeLoadStates(true);
		}
		$this->clearChanged();
		
		$objectsClass = $this->objectsClass;
		$objectsClass::registerObject($this);
	}
	
	
	/**
	 * Build object from database
	 */
	public function loadPrimaryData($reload = false, $failOnMissing = false) {
		if (!$this->identified) return;
		if ($this->loaded['primaryData'] && !$reload) return;
		
		$libraryID = $this->_libraryID;
		$id = $this->_id;
		$key = $this->_key;
		
		$objectsClass = $this->objectsClass;
		
		$columns = [];
		$join = [];
		$where = [];
		
		if (!$this->_id && !$this->_key) {
			throw new Exception("id or key must be set to load primary data");
		}
		
		$primaryFields = $objectsClass::$primaryFields;
		$idField = $objectsClass::$idColumn;
		foreach ($primaryFields as $field) {
			// If field not already set
			if ($field == $idField || $this->{'_' . $field} === null || $reload) {
				$columns[] = $objectsClass::getPrimaryDataSQLPart($field) . " AS `$field`";
			}
		}
		if (!$columns) {
			return;
		}
		
		/*if ($id) {
			Z_Core::debug("Loading data for item $libraryID/$id");
			$row = Zotero_Items::getPrimaryDataByID($libraryID, $id);
		}
		else {
			Z_Core::debug("Loading data for item $libraryID/$key");
			$row = Zotero_Items::getPrimaryDataByKey($libraryID, $key);
		}*/
		
		// This should match Zotero.*.primaryDataSQL, but without
		// necessarily including all columns
		$sql = "SELECT " . join(", ", $columns) . $objectsClass::$primaryDataSQLFrom;
		if ($id) {
			$sql .= " AND O.$idField=? ";
			$params = $id;
		}
		else {
			$sql .= " AND O.key=? AND O.libraryID=? ";
			$params = [$key, $libraryID];
		}
		$sql .= (sizeOf($where) ? ' AND ' . join(' AND ', $where) : '');
		$row = Zotero_DB::rowQuery($sql, $params, Zotero_Shards::getByLibraryID($libraryID));
		
		if (!$row) {
			if ($failOnMissing) {
				throw new Exception(
					$this->ObjectType . " " . ($id ? $id : $libraryID . "/" . $key) . " not found"
				);
			}
			$this->clearChanged('primaryData');
			
			// If object doesn't exist, mark all data types as loaded
			$this->markAllDataTypeLoadStates(true);
			
			return;
		}
		
		$this->loadFromRow($row, $reload);
	}
	
	
	public function loadFromRow($row) {
		foreach ($row as $key => $val) {
			$field = '_' . $key;
			if (!property_exists($this, $field)) {
				throw new Exception($this->ObjectType . " property '$field' doesn't exist");
			}
			$this->$field = $val;
		}
		
		$this->loaded['primaryData'] = true;
		$this->clearChanged('primaryData');
		$this->identified = true;
	}
	
	
	/**
	 * Reloads loaded, changed data
	 *
	 * @param {String[]} [dataTypes] - Data types to reload, or all loaded types if not provide
	 * @param {Boolean} [reloadUnchanged=false] - Reload even data that hasn't changed internally.
	 *                                            This should be set to true for data that was
	 *                                            changed externally (e.g., globally renamed tags).
	 */
	public function reload($dataTypes = null, $reloadUnchanged = false) {
		if (!$this->_id) {
			return;
		}
		
		if (!$dataTypes) {
			$dataTypes = array_filter(array_keys($this->loaded), function ($val) {
				return $this->loaded[$val];
			});
		}
		
		foreach ($dataTypes as $dataType) {
			if (empty($this->loaded[$dataType]) || isset($this->skipDataTypeLoad[$dataType])
					|| (!$reloadUnchanged && empty($this->changed[$dataType]))) {
				continue;
			}
			$this->loadDataType($dataType, true);
		}
	}
	
	
	/**
	 * Checks whether a given data type has been loaded
	 *
	 * @param {String} [dataType=primaryData] Data type to check
	 * @throws {Zotero.DataObjects.UnloadedDataException} If not loaded, unless the
	 *   data has not yet been "identified"
	 */
	protected function requireData($dataType) {
		if (!isset($this->loaded[$dataType])) {
			throw new Exception("$dataType is not a valid data type for $this->ObjectType objects");
		}
		
		if ($dataType != 'primaryData') {
			$this->requireData('primaryData');
		}
		
		if (!$this->identified) {
			$this->loaded[$dataType] = true;
		}
		else if (empty($this->loaded[$dataType])) {
			throw new Exception(
				"'$dataType' not loaded for $this->objectType ("
					. $this->_id . "/" . $this->_libraryID . "/" . $this->_key . ")"
			);
		}
	}
	
	
	/**
	 * Loads data for a given data type
	 * @param {String} dataType
	 * @param {Boolean} reload
	 */
	private function loadDataType($dataType, $reload = false) {
		return $this->{"load" . ucwords($dataType)}($reload);
	}
	
	protected function markAllDataTypeLoadStates($loaded) {
		foreach ($this->dataTypes as $dataType) {
			$this->loaded[$dataType] = $loaded;
		}
	}
	
	
	public function hasChanged() {
		$changed = array_filter(array_keys($this->changed), function ($dataType) {
			return $this->changed[$dataType];
		});
		foreach ($changed as $dataType) {
			if ($dataType == 'primaryData' && is_array($this->changed['primaryData'])) {
				foreach ($this->changed['primaryData'] as $field => $val) {
					Z_Core::debug("$field has changed for item $this->libraryKey");
				}
			}
			else {
				Z_Core::debug("$dataType has changed for item $this->libraryKey");
			}
		}
		return !!$changed;
	}
	
	
	/**
	 * Clears log of changed values
	 * @param {String} [dataType] data type/field to clear. Defaults to clearing everything
	 */
	protected function clearChanged($dataType = null) {
		if ($dataType) {
			unset($this->changed[$dataType]);
			unset($this->previousData[$dataType]);
		}
		else {
			$this->changed = [];
			$this->previousData = [];
		}
	}
	
	/**
	 * Clears field change log
	 * @param {String} field
	 */
	private function clearFieldChange($field) {
		unset($this->previousData[$field]);
	}
	
	
	private function disabledCheck() {
		if ($this->disabled) {
			throw new Exception("$this->ObjectType is disabled");
		}
	}
}
