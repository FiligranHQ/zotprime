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

class Zotero_Item {
	private $id;
	private $libraryID;
	private $key;
	private $itemTypeID;
	private $dateAdded;
	private $dateModified;
	private $serverDateModified;
	private $firstCreator;
	private $numNotes;
	private $numAttachments;
	
	private $primaryDataLoaded = false;
	private $creatorsLoaded = false;
	private $itemDataLoaded = false;
	private $relatedItemsLoaded = false;
	
	private $firstCreatorHashes = array();
	private $itemData = array();
	private $creators = array();
	
	private $sourceItem;
	private $noteTitle = null;
	private $noteText = null;
	
	private $loaded = false;
	private $changed = array();
	private $changedPrimaryData = array();
	private $changedItemData = array();
	private $changedCreators = array();
	private $changedDeleted = false;
	private $changedNote = false;
	private $changedSource = false;
	private $changedAttachmentData = array();
	
	private $previousData;
	
	private $deleted = null;
	
	private $attachmentData = array(
		'linkMode' => null,
		'mimeType' => null,
		'charset' => null,
		'storageModTime' => null,
		'storageHash' => null,
		'path' => null
	);
	
	private $relatedItems = array();
	
	
	public function __construct() {
		$numArgs = func_num_args();
		if ($numArgs) {
			throw new Exception("Constructor doesn't take any parameters");
		}
		
/*		if ($libraryID || $key) {
			if ($libraryID) {
				if (!is_integer($libraryID)) {
					trigger_error("Library ID must be an integer (was " . gettype($libraryID) . ")", E_USER_ERROR);
				}
				$this->libraryID = $libraryID;
			}
			if ($key) {
				if (!is_string($key)) {
					trigger_error("Key must be a string (was " . gettype($key) . ")", E_USER_ERROR);
				}
				$this->key = $key;
			}
		}
*/	}
	
	
	public function __get($field) {
		if ($field == 'id' || in_array($field, Zotero_Items::$primaryFields)) {
			if (!property_exists('Zotero_Item', $field)) {
				trigger_error("Zotero_Item property '$field' doesn't exist", E_USER_ERROR);
			}
			return $this->getField($field);
		}
		switch ($field) {
			case 'deleted':
				return $this->getDeleted();
			
			case 'createdByUserID':
				return $this->getCreatedByUserID();
			
			case 'lastModifiedByUserID':
				return $this->getLastModifiedByUserID();
			
			case 'attachmentLinkMode':
				return $this->getAttachmentLinkMode();
				
			case 'attachmentMIMEType':
				return $this->getAttachmentMIMEType();
				
			case 'attachmentCharset':
				return $this->getAttachmentCharset();
			
			case 'attachmentPath':
				return $this->getAttachmentPath();
				
			case 'attachmentStorageModTime':
				return $this->getAttachmentStorageModTime();
			
			case 'attachmentStorageHash':
				return $this->getAttachmentStorageHash();
			
			case 'relatedItems':
				return $this->getRelatedItems();
		}
		
		trigger_error("'$field' is not a primary or attachment field", E_USER_ERROR);
	}
	
	
	public function __set($field, $val) {
		//Z_Core::debug("Setting field $field to '$val'");
		
		if ($field == 'id' || in_array($field, Zotero_Items::$primaryFields)) {
			if (!property_exists('Zotero_Item', $field)) {
				trigger_error("Zotero_Item property '$field' doesn't exist", E_USER_ERROR);
			}
			return $this->setField($field, $val);
		}
		
		switch ($field) {
			case 'deleted':
				return $this->setDeleted($val);
			
			case 'attachmentLinkMode':
				$this->setAttachmentField('linkMode', $val);
				return;
				
			case 'attachmentMIMEType':
				$this->setAttachmentField('mimeType', $val);
				return;
				
			case 'attachmentCharset':
				$this->setAttachmentField('charset', $val);
				return;
			
			case 'attachmentStorageModTime':
				$this->setAttachmentField('storageModTime', $val);
				return;
			
			case 'attachmentStorageHash':
				$this->setAttachmentField('storageHash', $val);
				return;
			
			case 'attachmentPath':
				$this->setAttachmentField('path', $val);
				return;
			
			case 'relatedItems':
				$this->setRelatedItems($val);
				return;
		}
		
		trigger_error("'$field' is not a valid Zotero_Item property", E_USER_ERROR);
	}
	
	
	public function getField($field, $unformatted=false, $includeBaseMapped=false, $skipValidation=false) {
		Z_Core::debug("Requesting field '$field' for item $this->id", 4);
		
		if (($this->id || $this->key) && !$this->primaryDataLoaded) {
			$this->loadPrimaryData(true);
		}
		
		if ($field == 'id' || in_array($field, Zotero_Items::$primaryFields)) {
			// Generate firstCreator string if we only have hashes
			if ($field == 'firstCreator' && !$this->firstCreator && $this->firstCreatorHashes) {
				$this->firstCreator = Zotero_Items::getFirstCreator($this->firstCreatorHashes);
			}
			
			Z_Core::debug("Returning '{$this->$field}' for field $field", 4);
			
			return $this->$field;
		}
		if ($this->isNote()) {
			switch ($field) {
				case 'title':
					return $this->getNoteTitle();
				
				default:
					return '';
			}
		}
		
		if ($includeBaseMapped) {
			$fieldID = Zotero_ItemFields::getFieldIDFromTypeAndBase(
				$this->getField('itemTypeID'), $field
			);
		}
		
		if (empty($fieldID)) {
			$fieldID = Zotero_ItemFields::getID($field);
		}
		
		// If field is not valid for this (non-custom) type, return empty string
		if (!Zotero_ItemTypes::isCustomType($this->itemTypeID)
				&& !Zotero_ItemFields::isCustomField($fieldID)
				&& !array_key_exists($fieldID, $this->itemData)) {
			$msg = "Field '$field' doesn't exist for item $this->id of type {$this->itemTypeID}";
			if (!$skipValidation) {
				throw new Exception($msg);
			}
			Z_Core::debug($msg . "—returning ''", 4);
			return '';
		}
		
		if ($this->id && is_null($this->itemData[$fieldID]) && !$this->itemDataLoaded) {
			$this->loadItemData();
		}
		
		$value = $this->itemData[$fieldID] ? $this->itemData[$fieldID] : '';
		
        if (!$unformatted) {
			// Multipart date fields
			if (Zotero_ItemFields::isFieldOfBase($fieldID, 'date')) {
				$value = Zotero_Date::multipartToStr($value);
			}
		}
		
		Z_Core::debug("Returning '$value' for field $field", 4);
		return $value;
	}
	
	
	public function getDisplayTitle($includeAuthorAndDate=false) {
		$title = $this->getField('title', false, true);
		$itemTypeID = $this->itemTypeID;
		$itemTypeName = Zotero_ItemTypes::getName($itemTypeID);
		
		if (!$title && ($itemTypeID == 8 || $itemTypeID == 10)) { // 'letter' and 'interview' itemTypeIDs
			$creators = $this->getCreators();
			$authors = array();
			$participants = array();
			if ($creators) {
				foreach ($creators as $creator) {
					if (($itemTypeID == 8 && $creator['creatorTypeID'] == 16) || // 'letter'/'recipient'
							($itemTypeID == 10 && $creator['creatorTypeID'] == 7)) { // 'interview'/'interviewer'
						$participants[] = $creator;
					}
					else if (($itemTypeID == 8 && $creator['creatorTypeID'] == 1) ||   // 'letter'/'author'
							($itemTypeID == 10 && $creator['creatorTypeID'] == 6)) { // 'interview'/'interviewee'
						$authors[] = $creator;
					}
				}
			}
			
			$strParts = array();
			
			if ($includeAuthorAndDate) {
				$names = array();
				foreach($authors as $author) {
					$names[] = $author['ref']->lastName;
				}
				
				// TODO: Use same logic as getFirstCreatorSQL() (including "et al.")
				if ($names) {
					// TODO: was localeJoin() in client
					$strParts[] = implode(', ', $names);
				}
			}
			
			if ($participants) {
				$names = array();
				foreach ($participants as $participant) {
					$names[] = $participant['ref']->lastName;
				}
				switch (sizeOf($names)) {
					case 1:
						//$str = 'oneParticipant';
						$nameStr = $names[0];
						break;
						
					case 2:
						//$str = 'twoParticipants';
						$nameStr = "{$names[0]} and {$names[1]}";
						break;
						
					case 3:
						//$str = 'threeParticipants';
						$nameStr = "{$names[0]}, {$names[1]}, and {$names[2]}";
						break;
						
					default:
						//$str = 'manyParticipants';
						$nameStr = "{$names[0]} et al.";
				}
				
				/*
				pane.items.letter.oneParticipant		= Letter to %S
				pane.items.letter.twoParticipants		= Letter to %S and %S
				pane.items.letter.threeParticipants	= Letter to %S, %S, and %S
				pane.items.letter.manyParticipants		= Letter to %S et al.
				pane.items.interview.oneParticipant	= Interview by %S
				pane.items.interview.twoParticipants	= Interview by %S and %S
				pane.items.interview.threeParticipants	= Interview by %S, %S, and %S
				pane.items.interview.manyParticipants	= Interview by %S et al.
				*/
				
				//$strParts[] = Zotero.getString('pane.items.' + itemTypeName + '.' + str, names);
				
				$loc = Zotero_ItemTypes::getLocalizedString($itemTypeName);
				// Letter
				if ($itemTypeName == 'letter') {
					$loc .= ' to ';
				}
				// Interview
				else {
					$loc .= ' by ';
				}
				$strParts[] = $loc . $nameStr;
				
			}
			else {
				$strParts[] = Zotero_ItemTypes::getLocalizedString($itemTypeName);
			}
			
			if ($includeAuthorAndDate) {
				$d = $this->getField('date');
				if ($d) {
					$strParts[] = $d;
				}
			}
			
			$title = '[';
			$title .= join('; ', $strParts);
			$title .= ']';
		}
		
		return $title;
	}
	
	
	/**
	 * Returns all fields used in item
	 *
	 * @param	bool		$asNames		Return as field names
	 * @return	array				Array of field ids or names
	 */
	public function getUsedFields($asNames=false) {
		if (!$this->id) {
			return array();
		}
		
		$cacheKey = ($asNames ? "itemUsedFieldNames" : "itemUsedFieldIDs") . '_' . $this->id;
		
		$fields = Z_Core::$MC->get($cacheKey);
		if ($fields !== false) {
			return $fields;
		}
		
		$sql = "SELECT fieldID FROM itemData WHERE itemID=?";
		if ($asNames) {
			$sql = "SELECT fieldName FROM " . Z_CONFIG::$SHARD_MASTER_DB . ".fields WHERE fieldID IN ($sql)";
		}
		$fields = Zotero_DB::columnQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$fields) {
			$fields = array();
		}
		
		Z_Core::$MC->set($cacheKey, $fields);
		
		return $fields;
	}
	
	
	/**
	 * Check if item exists in the database
	 *
	 * @return	bool			TRUE if the item exists, FALSE if not
	 */
	public function exists() {
		if (!$this->id) {
			trigger_error('$this->id not set');
		}
		
		$sql = "SELECT COUNT(*) FROM items WHERE itemID=?";
		return !!Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
	}
	
	
	private function load($allowFail=false) {
		$this->loadPrimaryData($allowFail);
		$this->loadItemData();
		$this->loadCreators();
	}
	
	
	private function loadPrimaryData($allowFail=false) {
		Z_Core::debug("Loading primary data for item $this->id");
		
		if ($this->primaryDataLoaded) {
			throw new Exception("Primary data already loaded for item $this->id");
		}
		
		$libraryID = $this->libraryID;
		$id = $this->id;
		$key = $this->key;
		
		if (!$libraryID) {
			throw new Exception("Library ID not set");
		}
		
		if (!$id && !$key) {
			throw new Exception("ID or key not set");
		}
		
		// Use cached check for existence if possible
		if ($libraryID && $key) {
			if (!Zotero_Items::existsByLibraryAndKey($libraryID, $key)) {
				$this->primaryDataLoaded = true;
				
				if ($allowFail) {
					return false;
				}
				
				throw new Exception("Item " . ($id ? $id : "$libraryID/$key") . " not found");
			}
		}
		
		$columns = array();
		foreach (Zotero_Items::$primaryFields as $field) {
			$colSQL = '';
			if (is_null($field == 'itemID' ? $this->id : $this->$field)) {
				switch ($field) {
					case 'itemID':
					case 'itemTypeID':
					case 'dateAdded':
					case 'dateModified':
					case 'libraryID':
					case 'key':
					case 'serverDateModified':
						$colSQL = 'I.' . $field;
						break;
					
					case 'firstCreator':
						$colSQL = Zotero_Items::getFirstCreatorHashesSQL();
						break;
					
					case 'numNotes':
						$colSQL = '(SELECT COUNT(*) FROM itemNotes INo
							WHERE sourceItemID=I.itemID AND INo.itemID NOT IN
							(SELECT itemID FROM deletedItems)) AS numNotes';
						break;
						
					case 'numAttachments':
						$colSQL = '(SELECT COUNT(*) FROM itemAttachments IA
							WHERE sourceItemID=I.itemID AND IA.itemID NOT IN
							(SELECT itemID FROM deletedItems)) AS numAttachments';
						break;
					
					case 'numNotes':
						$colSQL = '(SELECT COUNT(*) FROM itemNotes
									WHERE sourceItemID=I.itemID) AS numNotes';
						break;
						
					case 'numAttachments':
						$colSQL = '(SELECT COUNT(*) FROM itemAttachments
									WHERE sourceItemID=I.itemID) AS numAttachments';
						break;
				}
				if ($colSQL) {
					$columns[] = $colSQL;
				}
			}
		}
		
		$sql = 'SELECT ' . implode(', ', $columns) . " FROM items I WHERE ";
		
		if ($id) {
			if (!is_numeric($id)) {
				trigger_error("Invalid itemID '$id'", E_USER_ERROR);
			}
			$sql .= "itemID=?";
			$stmt = Zotero_DB::getStatement($sql, 'loadPrimaryData_id', Zotero_Shards::getByLibraryID($libraryID));
			$row = Zotero_DB::rowQueryFromStatement($stmt, array($id));
		}
		else {
			if (!is_numeric($libraryID)) {
				trigger_error("Invalid libraryID '$libraryID'", E_USER_ERROR);
			}
			if (!preg_match('/[A-Z0-9]{8}/', $key)) {
				trigger_error("Invalid key '$key'!", E_USER_ERROR);
			}
			$sql .= "libraryID=? AND `key`=?";
			$stmt = Zotero_DB::getStatement($sql, 'loadPrimaryData_key', Zotero_Shards::getByLibraryID($libraryID));
			$row = Zotero_DB::rowQueryFromStatement($stmt, array($libraryID, $key));
		}
		
		$this->primaryDataLoaded = true;
		
		if (!$row) {
			if ($allowFail) {
				return false;
			}
			throw new Exception("Item " . ($id ? $id : "$libraryID/$key") . " not found");
		}
		
		$this->loadFromRow($row);
		
		return true;
	}
	
	
	public function loadFromRow($row, $reload=false) {
		/*
		if ($reload) {
			$this->init();
		}
		
		// If necessary or reloading, set the type, initialize this._itemData,
		// and reset _itemDataLoaded
		if (reload || (!this._itemTypeID && row.itemTypeID)) {
			this.setType(row.itemTypeID, true);
		}
		*/
		
		foreach ($row as $field=>$val) {
			// Only accept primary field data through loadFromRow()
			//
			// firstCreatorHashes is generated via SQL and turns into the firstCreator primary field
			if (in_array($field, Zotero_Items::$primaryFields) || $field == 'firstCreatorHashes') {
				//Zotero.debug("Setting field '" + col + "' to '" + row[col] + "' for item " + this.id);
				switch ($field) {
					case 'itemID':
						$this->id = $val;
						break;
						
					case 'itemTypeID':
						$this->setType($val, true);
						break;
					
					case 'firstCreatorHashes':
						$this->firstCreator = '';
						$this->firstCreatorHashes = $val ? explode(',', $val) : array();
						break;
					
					default:
						$this->$field = $val;
				}
			}
			else {
				Z_Core::debug("'$field' is not a valid primary field", 1);
			}
		}
		
		$this->primaryDataLoaded = true;
	}
	
	
	private function setType($itemTypeID, $loadIn=false) {
		if ($this->itemTypeID == $itemTypeID) {
			return false;
		}
		
		// TODO: block switching to/from note or attachment
		
		$copiedFields = array();
		
		// If there's an existing type
		if ($this->itemTypeID) {
			$obsoleteFields = $this->getFieldsNotInType($itemTypeID);
			if ($obsoleteFields) {
				foreach($obsoleteFields as $oldFieldID) {
					// Try to get a base type for this field
					$baseFieldID =
						Zotero_ItemFields::getBaseIDFromTypeAndField($this->itemTypeID, $oldFieldID);
					
					if ($baseFieldID) {
						$newFieldID =
							Zotero_ItemFields::getFieldIDFromTypeAndBase($itemTypeID, $baseFieldID);
						
						// If so, save value to copy to new field
						if ($newFieldID) {
							$copiedFields[] = array($newFieldID, $this->getField($oldFieldID));
						}
					}
					
					// Clear old field
					$this->setField($oldFieldID, false);
				}
			}
			
			if (!$loadIn) {
				foreach ($this->itemData as $fieldID=>$value) {
					if ($this->itemData[$fieldID] && // why?
							(!$obsoleteFields || !in_array($fieldID, $obsoleteFields))) {
						$copiedFields[] = array($fieldID, $this->getField($fieldID));
					}
				}
			}
			
			// And reset custom creator types to the default
			$creators = $this->getCreators();
			if ($creators) {
				foreach ($creators as $orderIndex=>$creator) {
					if (Zotero_CreatorTypes::isCustomType($creator['creatorTypeID'])) {
						continue;
					}
					if (!Zotero_CreatorTypes::isValidForItemType($creator['creatorTypeID'], $itemTypeID)) {
						// TODO: port
						
						// Reset to contributor (creatorTypeID 2), which exists in all
						$this->setCreator($orderIndex, $creator['ref'], 2);
					}
				}
			}
		}
		
		$this->itemTypeID = $itemTypeID;
		
		// If not custom item type, initialize $this->itemData with type-specific fields
		$this->itemData = array();
		if (!Zotero_ItemTypes::isCustomType($itemTypeID)) {
			$fields = Zotero_ItemFields::getItemTypeFields($itemTypeID);
			foreach($fields as $fieldID) {
				$this->itemData[$fieldID] = null;
			}
		}
		
		if ($copiedFields) {
			foreach($copiedFields as $copiedField) {
				$this->setField($copiedField[0], $copiedField[1]);
			}
		}
		
		if ($loadIn) {
			$this->itemDataLoaded = false;
		}
		else {
			$this->changedPrimaryData['itemTypeID'] = true;
		}
		
		return true;
	}
	
	
	/*
	 * Find existing fields from current type that aren't in another
	 *
	 * If _allowBaseConversion_, don't return fields that can be converted
	 * via base fields (e.g. label => publisher => studio)
	 */
	public function getFieldsNotInType($itemTypeID, $allowBaseConversion=false) {
		$masterDB = Z_CONFIG::$SHARD_MASTER_DB;
		
		$sql = "SELECT fieldID FROM $masterDB.itemTypeFields
				WHERE itemTypeID=? AND fieldID IN
					(SELECT fieldID FROM itemData WHERE itemID=?) AND
				fieldID NOT IN
					(SELECT fieldID FROM $masterDB.itemTypeFields WHERE itemTypeID=?)";
			
		if ($allowBaseConversion) {
			trigger_error("Unimplemented", E_USER_ERROR);
			/*
			// Not the type-specific field for a base field in the new type
			sql += " AND fieldID NOT IN (SELECT fieldID FROM baseFieldMappings "
				+ "WHERE itemTypeID=?1 AND baseFieldID IN "
				+ "(SELECT fieldID FROM itemTypeFields WHERE itemTypeID=?3)) AND ";
			// And not a base field with a type-specific field in the new type
			sql += "fieldID NOT IN (SELECT baseFieldID FROM baseFieldMappings "
				+ "WHERE itemTypeID=?3) AND ";
			// And not the type-specific field for a base field that has
			// a type-specific field in the new type
			sql += "fieldID NOT IN (SELECT fieldID FROM baseFieldMappings "
				+ "WHERE itemTypeID=?1 AND baseFieldID IN "
				+ "(SELECT baseFieldID FROM baseFieldMappings WHERE itemTypeID=?3))";
			*/
		}
		
		return Zotero_DB::columnQuery(
			$sql,
			array($this->itemTypeID, $this->id, $itemTypeID),
			Zotero_Shards::getByLibraryID($this->libraryID)
		);
	}
	
	
	
	/**
	 * @param 	string|int	$field				Field name or ID
	 * @param	mixed		$value				Field value
	 * @param	bool		$loadIn				Populate the data fields without marking as changed
	 * @param	bool 		$skipValidation		Don't check item type/field validity, etc.
	 */
	public function setField($field, $value, $loadIn=false, $skipValidation=false) {
		if (empty($field)) {
			trigger_error("Field not specified", E_USER_ERROR);
		}
		
		// Set id, libraryID, and key without loading data first
		switch ($field) {
			case 'id':
			case 'libraryID':
			case 'key':
				if ($this->primaryDataLoaded) {
					throw new Exception("Cannot set $field after item is already loaded");
				}
				//this._checkValue(field, val);
				$this->$field = $value;
				return;
		}
		
		if ($this->id || $this->key) {
			if (!$this->primaryDataLoaded) {
				$this->loadPrimaryData(true);
			}
		}
		else {
			$this->primaryDataLoaded = true;
		}
		
		// Primary field
		if (in_array($field, Zotero_Items::$primaryFields)) {
			if ($loadIn) {
				throw new Exception("Cannot set primary field $field in loadIn mode");
			}
			
			switch ($field) {
				case 'itemID':
				case 'serverDateModified':
				case 'firstCreator':
				case 'numNotes':
				case 'numAttachments':
					trigger_error("Primary field '$field' cannot be changed through setField()", E_USER_ERROR);
			}
			
			// Only allow primary field changes if $skipValidation is true, since it means
			// we're loading in data from a client
			if ($skipValidation) {
				if (!Zotero_ItemFields::validate($field, $value)) {
					trigger_error("Value '$value' of type " . gettype($value) . " does not validate for field '$field'", E_USER_ERROR);
				}
				
				if ($loadIn) {
					trigger_error('Is this allowed?', E_USER_ERROR);
				}
				
				if ($this->$field != $value) {
					Z_Core::debug("Field $field has changed from {$this->$field} to $value", 4);
					
					if ($field == 'itemTypeID') {
						$this->setType($value, $loadIn);
					}
					else {
						$this->$field = $value;
						$this->changedPrimaryData[$field] = true;
					}
				}
				return true;
			}
			
			trigger_error("Primary field '$field' cannot be changed through setField() if \$skipValidation is false", E_USER_ERROR);
		}
		
		//
		// itemData field
		//
		
		if (!$this->itemTypeID) {
			trigger_error('Item type must be set before setting field data', E_USER_ERROR);
		}
		
		// If existing item, load field data first unless we're already in
		// the middle of a load
		if ($this->id) {
			if (!$loadIn && !$this->itemDataLoaded) {
				$this->loadItemData();
			}
		}
		else {
			$this->itemDataLoaded = true;
		}
		
		$fieldID = Zotero_ItemFields::getID($field);
		
		if (!$fieldID) {
			trigger_error("'$field' is not a valid itemData field.", E_USER_ERROR);
		}
		
		if (!$skipValidation && !Zotero_ItemFields::isValidForType($fieldID, $this->itemTypeID)) {
			trigger_error("'$field' is not a valid field for type " . $this->itemTypeID, E_USER_ERROR);
		}
		
		if (!$loadIn) {
			// TODO: port
			/*
			// Save date field as multipart date
			if (Zotero_ItemFields::isFieldOfBase(fieldID, 'date') &&
					!Zotero.Date.isMultipart(value)) {
				value = Zotero.Date.strToMultipart(value);
			}
			// Validate access date
			else if (fieldID == Zotero.ItemFields.getID('accessDate')) {
				if (value && (!Zotero.Date.isSQLDate(value) &&
						!Zotero.Date.isSQLDateTime(value) &&
						value != 'CURRENT_TIMESTAMP')) {
					Zotero.debug("Discarding invalid accessDate '" + value
						+ "' in Item.setField()");
					return false;
				}
			}
			*/
			
			// If existing value, make sure it's actually changing
			if (!$loadIn &&
					(!isset($this->itemData[$fieldID]) && !$value) ||
					(isset($this->itemData[$fieldID]) && $this->itemData[$fieldID] == $value)) {
				return false;
			}
			
			/*
			// Save a copy of the object before modifying
			if (!this._preChangeArray) {
				this._preChangeArray = this.toArray();
			}
			*/
		}
		
		$this->itemData[$fieldID] = $value;
		
		if (!$loadIn) {
			$this->changedItemData[$fieldID] = true;
		}
		return true;
	}
	
	
	public function isNote() {
		return Zotero_ItemTypes::getName($this->getField('itemTypeID')) == 'note';
	}
	
	
	public function isAttachment() {
		return Zotero_ItemTypes::getName($this->getField('itemTypeID')) == 'attachment';
	}
	
	
	public function isImportedAttachment() {
		if (!$this->isAttachment()) {
			return false;
		}
		$linkMode = $this->attachmentLinkMode;
		// TODO: get from somewhere
		return $linkMode == 0 || $linkMode == 1;
	}
	
	
	public function hasChanged() {
		return $this->changed
			|| $this->changedPrimaryData
			|| $this->changedItemData
			|| $this->changedCreators
			|| $this->changedDeleted
			|| $this->changedNote
			|| $this->changedSource
			|| $this->changedAttachmentData;
	}
	
	
	private function getDeleted() {
		if ($this->deleted !== null) {
			return $this->deleted;
		}
		
		if (!$this->__get('id')) {
			return false;
		}
		
		if (!is_numeric($this->id)) {
			trigger_error("Invalid itemID '$this->id'", E_USER_ERROR);
		}
		
		$cacheKey = "itemIsDeleted_" . $this->id;
		$deleted = Z_Core::$MC->get($cacheKey);
		if ($deleted !== false) {
			$deleted = !!$deleted;
			$this->deleted = $deleted;
			return $deleted;
		}
		
		$sql = "SELECT COUNT(*) FROM deletedItems WHERE itemID=?";
		$deleted = !!Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		$this->deleted = $deleted;
		
		// Memcache returns false for empty keys, so use integers
		Z_Core::$MC->set($cacheKey, $deleted ? 1 : 0);
		
		return $deleted;
	}
	
	
	private function setDeleted($val) {
		$deleted = !!$val;
		
		if ($this->getDeleted() == $deleted) {
			Z_Core::debug("Deleted state ($deleted) hasn't changed for item $this->id");
			return;
		}
		
		if (!$this->changedDeleted) {
			$this->changedDeleted = true;
		}
		$this->deleted = $deleted;
	}
	
	
	private function getCreatedByUserID() {
		$sql = "SELECT createdByUserID FROM groupItems WHERE itemID=?";
		return Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
	}
	
	
	private function getLastModifiedByUserID() {
		$sql = "SELECT lastModifiedByUserID FROM groupItems WHERE itemID=?";
		return Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
	}
	
	
	public function addRelatedItem($itemID) {
		if ($itemID == $this->id) {
			Z_Core::debug("Can't relate item to itself in Zotero_Item.addRelatedItem()", 2);
			return false;
		}
		
		$current = $this->getRelatedItems();
		if ($current && in_array($itemID, $current)) {
			Z_Core::debug("Item $this->id already related to
				item $itemID in Zotero_Item.addItem()");
			return false;
		}
		
		$item = Zotero_Items::get($this->libraryID, $itemID);
		if (!$item) {
			trigger_error("Can't relate item to invalid item $itemID
				in Zotero.Item.addRelatedItem()", E_USER_ERROR);
		}
		$otherCurrent = $item->relatedItems;
		if ($otherCurrent && in_array($this->id, $otherCurrent)) {
			Z_Core::debug("Other item $itemID already related to item
				$this->id in Zotero_Item.addItem()");
			return false;
		}
		
		$this->prepFieldChange('relatedItems');
		$this->relatedItems[] = $itemID;
		return true;
	}
	
	
	public function removeRelatedItem($itemID) {
		$current = $this->getRelatedItems();
		if ($current) {
			$index = array_search($itemID, $current);
		}
		
		if (!$current || $index === false) {
			Z_Core::debug("Item $this->id isn't related to item $itemID
				in Zotero_Item.removeRelatedItem()");
			return false;
		}
		
		$this->prepFieldChange('relatedItems');
		unset($this->relatedItems[$index]);
		return true;
	}
	
	
	public function save($userID=false) {
		if (!$this->libraryID) {
			trigger_error("Library ID must be set before saving", E_USER_ERROR);
		}
		
		Zotero_Items::editCheck($this);
		
		if (!$this->hasChanged()) {
			Z_Core::debug("Item $this->id has not changed");
			return false;
		}
		
		// Make sure there are no gaps in the creator indexes
		$creators = $this->getCreators();
		$lastPos = -1;
		foreach ($creators as $pos=>$creator) {
			if ($pos != $lastPos + 1) {
				trigger_error("Creator index $pos out of sequence for item $this->id", E_USER_ERROR);
			}
			$lastPos++;
		}
		
		$shardID = Zotero_Shards::getByLibraryID($this->libraryID);
		
		Zotero_DB::beginTransaction();
		
		try {
			//
			// New item, insert and return id
			//
			if (!$this->id || !$this->exists()) {
				Z_Core::debug('Saving data for new item to database');
				
				$isNew = true;
				$sqlColumns = array();
				$sqlValues = array();
				
				//
				// Primary fields
				//
				$itemID = $this->id ? $this->id : Zotero_ID::get('items');
				$key = $this->key ? $this->key : $this->generateKey();
				
				$sqlColumns = array('itemID', 'itemTypeID', 'libraryID', 'key',
					'dateAdded', 'dateModified', 'serverDateModified', 'serverDateModifiedMS');
				$timestamp = Zotero_DB::getTransactionTimestamp();
				$timestampMS = Zotero_DB::getTransactionTimestampMS();
				$sqlValues = array(
					$itemID,
					$this->itemTypeID,
					$this->libraryID,
					$key,
					$this->dateAdded ? $this->dateAdded : $timestamp,
					$this->dateModified ? $this->dateModified : $timestamp,
					$timestamp,
					$timestampMS
				);
				
				//
				// Primary fields
				//
				$sql = 'INSERT INTO items (`' . implode('`, `', $sqlColumns) . '`) VALUES (';
				// Insert placeholders for bind parameters
				for ($i=0; $i<sizeOf($sqlValues); $i++) {
					$sql .= '?, ';
				}
				$sql = substr($sql, 0, -2) . ')';
				
				// Save basic data to items table
				$insertID = Zotero_DB::query($sql, $sqlValues, $shardID);
				if (!$this->id) {
					if (!$insertID) {
						throw new Exception("Item id not available after INSERT");
					}
					$itemID = $insertID;
					Zotero_Items::cacheLibraryKeyID($this->libraryID, $key, $insertID);
				}
				
				// Group item data
				if (Zotero_Libraries::getType($this->libraryID) == 'group' && $userID) {
					$sql = "INSERT INTO groupItems VALUES (?, ?, ?)";
					Zotero_DB::query($sql, array($itemID, $userID, null), $shardID);
				}
				
				//
				// ItemData
				//
				if ($this->changedItemData) {
					// Use manual bound parameters to speed things up
					$origInsertSQL = "INSERT INTO itemData VALUES ";
					$insertSQL = $origInsertSQL;
					$insertParams = array();
					$insertCounter = 0;
					$maxInsertGroups = 40;
					
					$fieldIDs = array_keys($this->changedItemData);
					
					foreach ($fieldIDs as $fieldID) {
						$value = $this->getField($fieldID, true, false, true);
						
						if ($value == 'CURRENT_TIMESTAMP'
								&& Zotero_ItemFields::getID('accessDate') == $fieldID) {
							$value = Zotero_DB::getTransactionTimestamp();
						}
						
						try {
							$hash = Zotero_Items::getDataValueHash($value, true);
						}
						catch (Exception $e) {
							$msg = $e->getMessage();
							if (strpos($msg, "Data too long for column 'value'") !== false) {
								$fieldName = Zotero_ItemFields::getLocalizedString(
									$this->itemTypeID, $fieldID
								);
								throw new Exception("=$fieldName field " .
									 "'" . substr($value, 0, 50) . "...' too long");
							}
							throw ($e);
						}
						
						if ($insertCounter < $maxInsertGroups) {
							$insertSQL .= "(?,?,?),";
							$insertParams = array_merge(
								$insertParams,
								array($itemID, $fieldID, $hash)
							);
						}
						
						if ($insertCounter == $maxInsertGroups - 1) {
							$insertSQL = substr($insertSQL, 0, -1);
							$stmt = Zotero_DB::getStatement($insertSQL, true, $shardID);
							Zotero_DB::queryFromStatement($stmt, $insertParams);
							$insertSQL = $origInsertSQL;
							$insertParams = array();
							$insertCounter = -1;
						}
						
						$insertCounter++;
					}
					
					if ($insertCounter > 0 && $insertCounter < $maxInsertGroups) {
						$insertSQL = substr($insertSQL, 0, -1);
						$stmt = Zotero_DB::getStatement($insertSQL, true, $shardID);
						Zotero_DB::queryFromStatement($stmt, $insertParams);
					}
					
					// Update memcached with used fields
					Z_Core::$MC->set("itemUsedFieldIDs_" . $itemID, $fieldIDs);
					$names = array();
					foreach ($fieldIDs as $fieldID) {
						$names[] = Zotero_ItemFields::getName($fieldID);
					}
					Z_Core::$MC->set("itemUsedFieldNames_" . $itemID, $names);
				}
				
				
				//
				// Creators
				//
				if ($this->changedCreators) {
					$indexes = array_keys($this->changedCreators);
					
					// TODO: group queries
					
					$sql = "INSERT INTO itemCreators
								(itemID, creatorID, creatorTypeID, orderIndex) VALUES ";
					$placeholders = array();
					$sqlValues = array();
					
					$cacheRows = array();
					
					foreach ($indexes as $orderIndex) {
						Z_Core::debug('Adding creator in position ' . $orderIndex, 4);
						$creator = $this->getCreator($orderIndex);
						
						if (!$creator) {
							continue;
						}
						
						if ($creator['ref']->hasChanged()) {
							Z_Core::debug("Auto-saving changed creator {$creator['ref']->id}");
							$creator['ref']->save();
						}
						
						$placeholders[] = "(?, ?, ?, ?)";
						array_push(
							$sqlValues,
							$itemID,
							$creator['ref']->id,
							$creator['creatorTypeID'],
							$orderIndex
						);
						
						$cacheRows[] = array(
							'creatorID' => $creator['ref']->id,
							'creatorTypeID' => $creator['creatorTypeID'],
							'orderIndex' => $orderIndex
						);
					}
					
					if ($sqlValues) {
						$sql = $sql . implode(',', $placeholders);
						Zotero_DB::query($sql, $sqlValues, $shardID);
					}
					
					// Just in case creators aren't in order
					usort($cacheRows, function ($a, $b) {
						return ($a['orderIndex'] < $b['orderIndex']) ? -1 : 1; 
					});
					Z_Core::$MC->set("itemCreators_" . $itemID, $cacheRows);
				}
				
				
				// Deleted item
				if ($this->changedDeleted) {
					if ($this->deleted) {
						$sql = "REPLACE INTO deletedItems (itemID) VALUES (?)";
					}
					else {
						$sql = "DELETE FROM deletedItems WHERE itemID=?";
					}
					Zotero_DB::query($sql, $itemID, $shardID);
					$deleted = Z_Core::$MC->set("itemIsDeleted_" . $itemID, $this->deleted ? 1 : 0);
				}
				
				
				// Note
				if ($this->isNote() || $this->changedNote) {
					$title = Zotero_Notes::noteToTitle($this->noteText);
					
					$sql = "INSERT INTO itemNotes
							(itemID, sourceItemID, note, title, hash) VALUES
							(?,?,?,?,?)";
					$parent = $this->isNote() ? $this->getSource() : null;
					$noteText = $this->noteText ? $this->noteText : '';
					$hash = $noteText ? md5($noteText) : '';
					$bindParams = array(
						$itemID,
						$parent ? $parent : null,
						$noteText,
						$title,
						$hash
					);
					
					Zotero_DB::query($sql, $bindParams, $shardID);
					Zotero_Notes::updateNoteCache($this->libraryID, $itemID, $noteText);
					Zotero_Notes::updateHash($this->libraryID, $itemID, $hash);
				}
				
				
				// Attachment
				if ($this->isAttachment()) {
					$sql = "INSERT INTO itemAttachments
							(itemID, sourceItemID, linkMode, mimeType, charsetID, path, storageModTime, storageHash)
							VALUES (?,?,?,?,?,?,?,?)";
					$parent = $this->getSource();
					if ($parent) {
						$parentItem = Zotero_Items::get($this->libraryID, $parent);
						if (!$parentItem) {
							throw new Exception("Parent item $parent not found");
						}
						if ($parentItem->getSource()) {
							trigger_error("Parent item cannot be a child attachment", E_USER_ERROR);
						}
					}
					
					$linkMode = $this->attachmentLinkMode;
					$charsetID = Zotero_CharacterSets::getID($this->attachmentCharset);
					$path = $this->attachmentPath;
					$storageModTime = $this->attachmentStorageModTime;
					$storageHash = $this->attachmentStorageHash;
					
					$bindParams = array(
						$itemID,
						$parent ? $parent : null,
						$linkMode + 1,
						$this->attachmentMIMEType,
						$charsetID ? $charsetID : null,
						$path ? $path : '',
						$storageModTime ? $storageModTime : null,
						$storageHash ? $storageHash : null
					);
					Zotero_DB::query($sql, $bindParams, $shardID);
				}
				
				
				//
				// Source item id
				//
				if (false && $this->getSource()) {
					trigger_error("Unimplemented", E_USER_ERROR);
					// NOTE: don't need much of this on insert
					
					//$notifierData = array();
					//$notifierData[$this->id] = { old: $this->serialize };
					
					$newItem = Zotero_Items::get($this->libraryID, $sourceItemID);
					// FK check
					if ($newItem) {
						if ($sourceItemID) {
							//$newItemNotifierData = {};
							//newItemNotifierData[newItem.id] = { old: newItem.serialize };
						}
						else {
							trigger_error("Cannot set $type source to invalid item $sourceItemID", E_USER_ERROR);
						}
					}
					
					$oldSourceItemID = $this->getSource();
					
					if ($oldSourceItemID == $sourceItemID) {
						Z_Core::debug("$Type source hasn't changed", 4);
					}
					else {
						$oldItem = Zotero_Items::get($this->libraryID, $oldSourceItemID);
						if ($oldSourceItemID && $oldItem) {
							//$oldItemNotifierData = {};
							//oldItemNotifierData[oldItem.id] = { old: oldItem.serialize };
						}
						else {
							//$oldItemNotifierData = null;
							Z_Core::debug("Old source item $oldSourceItemID didn't exist in setSource()", 2);
						}
						
						// If this was an independent item, remove from any collections where it
						// existed previously and add source instead if there is one
						if (!$oldSourceItemID) {
							$sql = "SELECT collectionID FROM collectionItems WHERE itemID=?";
							$changedCollections = Zotero_DB::query($sql, $itemID, $shardID);
							if ($changedCollections) {
								trigger_error("Unimplemented", E_USER_ERROR);
								if ($sourceItemID) {
									$sql = "UPDATE OR REPLACE collectionItems "
										. "SET itemID=? WHERE itemID=?";
									Zotero_DB::query($sql, array($sourceItemID, $this->id), $shardID);
								}
								else {
									$sql = "DELETE FROM collectionItems WHERE itemID=?";
									Zotero_DB::query($sql, $this->id, $shardID);
								}
							}
						}
						
						$sql = "UPDATE item{$Type}s SET sourceItemID=?
								WHERE itemID=?";
						$bindParams = array(
							$sourceItemID ? $sourceItemID : null,
							$itemID
						);
						Zotero_DB::query($sql, $bindParams, $shardID);
						
						//Zotero.Notifier.trigger('modify', 'item', $this->id, notifierData);
						
						// Update the counts of the previous and new sources
						if ($oldItem) {
							/*
							switch ($type) {
								case 'note':
									$oldItem->decrementNoteCount();
									break;
								case 'attachment':
									$oldItem->decrementAttachmentCount();
									break;
							}
							*/
							//Zotero.Notifier.trigger('modify', 'item', oldSourceItemID, oldItemNotifierData);
						}
						
						if ($newItem) {
							/*
							switch ($type) {
								case 'note':
									$newItem->incrementNoteCount();
									break;
								case 'attachment':
									$newItem->incrementAttachmentCount();
									break;
							}
							*/
							//Zotero.Notifier.trigger('modify', 'item', sourceItemID, newItemNotifierData);
						}
					}
				}
				
 				
				// Related items
				if (!empty($this->changed['relatedItems'])) {
					$removed = array();
					$newids = array();
					$currentIDs = $this->relatedItems;
					
					if (!$currentIDs) {
						$currentIDs = array();
					}
					
					if ($this->previousData['related']) {
						foreach($this->previousData['related'] as $id) {
							if (!in_array($id, $currentIDs)) {
								$removed[] = $id;
							}
						}
					}
					
					foreach ($currentIDs as $id) {
						if ($this->previousData['related'] &&
								in_array($id, $this->previousData['related'])) {
							continue;
						}
						$newids[] = $id;
					}
					
					if ($removed) {
						$sql = "DELETE FROM itemRelated WHERE itemID=?
								AND linkedItemID IN (";
						$sql .= implode(', ', array_fill(0, sizeOf($removed), '?')) . ")";
						Zotero_DB::query(
							$sql,
							array_merge(array($this->id), $removed),
							$shardID
						);
					}
					
					if ($newids) {
						$sql = "INSERT INTO itemRelated (itemID, linkedItemID)
								VALUES (?,?)";
						$insertStatement = Zotero_DB::getStatement($sql, false, $shardID);
						
						foreach ($newids as $linkedItemID) {
							$insertStatement->execute(array($itemID, $linkedItemID));
						}
					}
					
					Z_Core::$MC->set("itemRelated_" . $itemID, $currentIDs);
				}
			}
			
			//
			// Existing item, update
			//
			else {
				Z_Core::debug('Updating database with new item data', 4);
				
				$isNew = false;
				
				//
				// Primary fields
				//
				$sql = "UPDATE items SET ";
				$sqlValues = array();
				
				$updateFields = array('itemTypeID', 'libraryID', 'key', 'dateAdded', 'dateModified');
				foreach ($updateFields as $updateField) {
					if (in_array($updateField, $this->changedPrimaryData)) {
						$sql .= "`$updateField`=?, ";
						$sqlValues[] = $this->$updateField;
					}
				}
				// TODO: update dateModified if change is on server?
				$sql .= "serverDateModified=?, serverDateModifiedMS=? WHERE itemID=?";
				array_push(
					$sqlValues,
					Zotero_DB::getTransactionTimestamp(),
					Zotero_DB::getTransactionTimestampMS(),
					$this->id
				);
				
				Zotero_DB::query($sql, $sqlValues, $shardID);
				
				
				// Group item data
				if (Zotero_Libraries::getType($this->libraryID) == 'group' && $userID) {
					$sql = "INSERT INTO groupItems VALUES (?, ?, ?)
								ON DUPLICATE KEY UPDATE lastModifiedByUserID=?";
					Zotero_DB::query($sql, array($this->id, null, $userID, $userID), $shardID);
				}
				
				
				//
				// ItemData
				//
				if ($this->changedItemData) {
					$del = array();
					
					$origReplaceSQL = "REPLACE INTO itemData VALUES ";
					$replaceSQL = $origReplaceSQL;
					$replaceParams = array();
					$replaceCounter = 0;
					$maxReplaceGroups = 40;
					
					$fieldIDs = array_keys($this->changedItemData);
					
					foreach ($fieldIDs as $fieldID) {
						$value = $this->getField($fieldID, true, false, true);
						
						// If field changed and is empty, mark row for deletion
						if (!$value) {
							$del[] = $fieldID;
							continue;
						}
						
						if ($value == 'CURRENT_TIMESTAMP'
								&& Zotero_ItemFields::getID('accessDate') == $fieldID) {
							$value = Zotero_DB::getTransactionTimestamp();
						}
						
						try {
							$hash = Zotero_Items::getDataValueHash($value, true);
						}
						catch (Exception $e) {
							$msg = $e->getMessage();
							if (strpos($msg, "Data too long for column 'value'") !== false) {
								$fieldName = Zotero_ItemFields::getLocalizedString(
									$this->itemTypeID, $fieldID
								);
								throw new Exception("=$fieldName field " .
									 "'" . substr($value, 0, 50) . "...' too long");
							}
							throw ($e);
						}
						
						if ($replaceCounter < $maxReplaceGroups) {
							$replaceSQL .= "(?,?,?),";
							$replaceParams = array_merge($replaceParams,
								array($this->id, $fieldID, $hash)
							);
						}
						
						if ($replaceCounter == $maxReplaceGroups - 1) {
							$replaceSQL = substr($replaceSQL, 0, -1);
							$stmt = Zotero_DB::getStatement($replaceSQL, true, $shardID);
							Zotero_DB::queryFromStatement($stmt, $replaceParams);
							$replaceSQL = $origReplaceSQL;
							$replaceParams = array();
							$replaceCounter = -1;
						}
						$replaceCounter++;
					}
					
					if ($replaceCounter > 0 && $replaceCounter < $maxReplaceGroups) {
						$replaceSQL = substr($replaceSQL, 0, -1);
						$stmt = Zotero_DB::getStatement($replaceSQL, true, $shardID);
						Zotero_DB::queryFromStatement($stmt, $replaceParams);
					}
					
					// Update memcached with used fields
					$fids = array();
					foreach ($this->itemData as $fieldID=>$value) {
						if ($value !== false && $value !== null) {
							$fids[] = $fieldID;
						}
					}
					Z_Core::$MC->set("itemUsedFieldIDs_" . $this->id, $fids);
					$names = array();
					foreach ($fids as $fieldID) {
						$names[] = Zotero_ItemFields::getName($fieldID);
					}
					Z_Core::$MC->set("itemUsedFieldNames_" . $this->id, $names);
					
					// Delete blank fields
					if ($del) {
						$sql = 'DELETE from itemData WHERE itemID=? AND fieldID IN (';
						$sqlParams = array($this->id);
						foreach ($del as $d) {
							$sql .= '?, ';
							$sqlParams[] = $d;
						}
						$sql = substr($sql, 0, -2) . ')';
						
						Zotero_DB::query($sql, $sqlParams, $shardID);
					}
				}
				
				//
				// Creators
				//
				if ($this->changedCreators) {
					$indexes = array_keys($this->changedCreators);
					
					$sql = "INSERT INTO itemCreators
								(itemID, creatorID, creatorTypeID, orderIndex) VALUES ";
					$placeholders = array();
					$sqlValues = array();
					
					$cacheRows = array();
					
					foreach ($indexes as $orderIndex) {
						Z_Core::debug('Creator in position ' . $orderIndex . ' has changed', 4);
						$creator = $this->getCreator($orderIndex);
						
						$sql2 = 'DELETE FROM itemCreators WHERE itemID=? AND orderIndex=?';
						Zotero_DB::query($sql2, array($this->id, $orderIndex), $shardID);
						
						if (!$creator) {
							continue;
						}
						
						if ($creator['ref']->hasChanged()) {
							Z_Core::debug("Auto-saving changed creator {$creator['ref']->id}");
							$creator['ref']->save();
						}
						
						
						$placeholders[] = "(?, ?, ?, ?)";
						array_push(
							$sqlValues,
							$this->id,
							$creator['ref']->id,
							$creator['creatorTypeID'],
							$orderIndex
						);
					}
					
					if ($sqlValues) {
						$sql = $sql . implode(',', $placeholders);
						Zotero_DB::query($sql, $sqlValues, $shardID);
					}
					
					// Update memcache
					$cacheRows = array();
					$cs = $this->getCreators();
					foreach ($cs as $orderIndex=>$c) {
						$cacheRows[] = array(
							'creatorID' => $c['ref']->id,
							'creatorTypeID' => $c['creatorTypeID'],
							'orderIndex' => $orderIndex
						);
					}
					Z_Core::$MC->set("itemCreators_" . $this->id, $cacheRows);
				}
				
				// Deleted item
				if ($this->changedDeleted) {
					if ($this->deleted) {
						$sql = "REPLACE INTO deletedItems (itemID) VALUES (?)";
					}
					else {
						$sql = "DELETE FROM deletedItems WHERE itemID=?";
					}
					Zotero_DB::query($sql, $this->id, $shardID);
					$deleted = Z_Core::$MC->set("itemIsDeleted_" . $this->id, $this->deleted ? 1 : 0);
				}
				
				
				// In case this was previously a standalone item,
				// delete from any collections it may have been in
				if ($this->changedSource && $this->getSource()) {
					$sql = "DELETE FROM collectionItems WHERE itemID=?";
					Zotero_DB::query($sql, $this->id, $shardID);
				}
				
				//
				// Note or attachment note
				//
				if ($this->changedNote) {
					// Only record sourceItemID in itemNotes for notes
					if ($this->isNote()) {
						$sourceItemID = $this->getSource();
					}
					$sourceItemID = !empty($sourceItemID) ? $sourceItemID : null;
					$noteText = $this->noteText ? $this->noteText : '';
					$title = Zotero_Notes::noteToTitle($this->noteText);
					$hash = $noteText ? md5($noteText) : '';
					$sql = "INSERT INTO itemNotes
							(itemID, sourceItemID, note, title, hash) VALUES
							(?,?,?,?,?) ON DUPLICATE KEY UPDATE
							sourceItemID=?, note=?, title=?, hash=?";
					$bindParams = array(
						$this->id,
						$sourceItemID, $noteText, $title, $hash,
						$sourceItemID, $noteText, $title, $hash
					);
					Zotero_DB::query($sql, $bindParams, $shardID);
					Zotero_Notes::updateNoteCache($this->libraryID, $this->id, $noteText);
					Zotero_Notes::updateHash($this->libraryID, $this->id, $hash);
					
					// TODO: handle changed source?
				}
				
				
				// Attachment
				if ($this->changedAttachmentData) {
					$sql = "REPLACE INTO itemAttachments
						(itemID, sourceItemID, linkMode, mimeType, charsetID, path, storageModTime, storageHash)
						VALUES (?,?,?,?,?,?,?,?)";
					$parent = $this->getSource();
					if ($parent) {
						$parentItem = Zotero_Items::get($this->libraryID, $parent);
						if (!$parentItem) {
							throw new Exception("Parent item $parent not found");
						}
						if ($parentItem->getSource()) {
							trigger_error("Parent item cannot be a child attachment", E_USER_ERROR);
						}
					}
					
					$linkMode = $this->attachmentLinkMode;
					$charsetID = Zotero_CharacterSets::getID($this->attachmentCharset);
					$path = $this->attachmentPath;
					$storageModTime = $this->attachmentStorageModTime;
					$storageHash = $this->attachmentStorageHash;
					
					$bindParams = array(
						$this->id,
						$parent ? $parent : null,
						$linkMode + 1,
						$this->attachmentMIMEType,
						$charsetID ? $charsetID : null,
						$path ? $path : '',
						$storageModTime ? $storageModTime : null,
						$storageHash ? $storageHash : null
					);
					Zotero_DB::query($sql, $bindParams, $shardID);
				}
				
				//
				// Source item id
				//
				if ($this->changedSource) {
					$type = Zotero_ItemTypes::getName($this->itemTypeID);
					$Type = ucwords($type);
					
					// Update DB, if not a note or attachment we already changed above
					if (!$this->changedAttachmentData && (!$this->changedNote || !$this->isNote())) {
						$sql = "UPDATE item" . $Type . "s SET sourceItemID=? WHERE itemID=?";
						$parent = $this->getSource();
						$bindParams = array(
							$parent ? $parent : null,
							$this->id
						);
						Zotero_DB::query($sql, $bindParams, $shardID);
					}
				}
				
				
				if (false && $this->changedSource) {
					trigger_error("Unimplemented", E_USER_ERROR);
					//$notifierData = array();
					//$notifierData[$this->id] = { old: $this->serialize };
					
					$newItem = Zotero_Items::get($this->libraryID, $sourceItemID);
					// FK check
					if ($newItem) {
						if ($sourceItemID) {
							//$newItemNotifierData = {};
							//newItemNotifierData[newItem.id] = { old: newItem.serialize };
						}
						else {
							trigger_error("Cannot set $type source to invalid item $sourceItemID", E_USER_ERROR);
						}
					}
					
					$oldSourceItemID = $this->getSource();
					
					if ($oldSourceItemID == $sourceItemID) {
						Z_Core::debug("$Type source hasn't changed", 4);
					}
					else {
						$oldItem = Zotero_Items::get($this->libraryID, $oldSourceItemID);
						if ($oldSourceItemID && $oldItem) {
							//$oldItemNotifierData = {};
							//oldItemNotifierData[oldItem.id] = { old: oldItem.serialize };
						}
						else {
							//$oldItemNotifierData = null;
							Z_Core::debug("Old source item $oldSourceItemID didn't exist in setSource()", 2);
						}
						
						// If this was an independent item, remove from any collections where it
						// existed previously and add source instead if there is one
						if (!$oldSourceItemID) {
							$sql = "SELECT collectionID FROM collectionItems WHERE itemID=?";
							$changedCollections = Zotero_DB::query($sql, $itemID, $shardID);
							if ($changedCollections) {
								trigger_error("Unimplemented", E_USER_ERROR);
								if ($sourceItemID) {
									$sql = "UPDATE OR REPLACE collectionItems "
										. "SET itemID=? WHERE itemID=?";
									Zotero_DB::query($sql, array($sourceItemID, $this->id), $shardID);
								}
								else {
									$sql = "DELETE FROM collectionItems WHERE itemID=?";
									Zotero_DB::query($sql, $this->id, $shardID);
								}
							}
						}
						
						$sql = "UPDATE item{$Type}s SET sourceItemID=?
								WHERE itemID=?";
						$bindParams = array(
							$sourceItemID ? $sourceItemID : null,
							$itemID
						);
						Zotero_DB::query($sql, $bindParams, $shardID);
						
						//Zotero.Notifier.trigger('modify', 'item', $this->id, notifierData);
						
						// Update the counts of the previous and new sources
						if ($oldItem) {
							/*
							switch ($type) {
								case 'note':
									$oldItem->decrementNoteCount();
									break;
								case 'attachment':
									$oldItem->decrementAttachmentCount();
									break;
							}
							*/
							//Zotero.Notifier.trigger('modify', 'item', oldSourceItemID, oldItemNotifierData);
						}
						
						if ($newItem) {
							/*
							switch ($type) {
								case 'note':
									$newItem->incrementNoteCount();
									break;
								case 'attachment':
									$newItem->incrementAttachmentCount();
									break;
							}
							*/
							//Zotero.Notifier.trigger('modify', 'item', sourceItemID, newItemNotifierData);
						}
					}
				}
				
				// Related items
				if (!empty($this->changed['relatedItems'])) {
					$removed = array();
					$newids = array();
					$currentIDs = $this->relatedItems;
					
					if (!$currentIDs) {
						$currentIDs = array();
					}
					
					if ($this->previousData['related']) {
						foreach($this->previousData['related'] as $id) {
							if (!in_array($id, $currentIDs)) {
								$removed[] = $id;
							}
						}
					}
					
					foreach ($currentIDs as $id) {
						if ($this->previousData['related'] &&
								in_array($id, $this->previousData['related'])) {
							continue;
						}
						$newids[] = $id;
					}
					
					if ($removed) {
						$sql = "DELETE FROM itemRelated WHERE itemID=?
								AND linkedItemID IN (";
						$q = array_fill(0, sizeOf($removed), '?');
						$sql .= implode(', ', $q) . ")";
						Zotero_DB::query(
							$sql,
							array_merge(array($this->id), $removed),
							$shardID
						);
					}
					
					if ($newids) {
						$sql = "INSERT INTO itemRelated (itemID, linkedItemID)
								VALUES (?,?)";
						$insertStatement = Zotero_DB::getStatement($sql, false, $shardID);
						
						foreach ($newids as $linkedItemID) {
							$insertStatement->execute(array($this->id, $linkedItemID));
						}
					}
					
					Z_Core::$MC->set("itemRelated_" . $this->id, $currentIDs);
				}
			}
			
			Zotero_DB::commit();
		}
		
		catch (Exception $e) {
			Zotero_DB::rollback();
			throw ($e);
		}
		
		if (!$this->id) {
			$this->id = $itemID;
		}
		if (!$this->key) {
			$this->key = $key;
		}
		
		$this->previousData = array();
		
		// TODO: invalidate memcache
		//Zotero_Items::reload($$this->getID());
		
		if ($isNew) {
			Zotero_Items::cache($this);
			
			//Zotero.Notifier.trigger('add', 'item', $this->getID());
			return $this->id;
		}
		
		//Zotero.Notifier.trigger('modify', 'item', $this->getID(), { old: $this->_preChangeArray });
		return true;
	}
	
	
	/*
	 * Returns the number of creators for this item
	 */
	public function numCreators() {
		if ($this->id && !$this->creatorsLoaded) {
			$this->loadCreators();
		}
		return sizeOf($this->creators);
	}
	
	
	/**
	 * @param	int
	 * @return	Zotero_Creator
	 */
	public function getCreator($orderIndex) {
		if ($this->id && !$this->creatorsLoaded) {
			$this->loadCreators();
		}
		
		return isset($this->creators[$orderIndex])
			? $this->creators[$orderIndex] : false;
	}
	
	
	/**
	 * Gets the creators in this object
	 *
	 * @return	array				Array of Zotero_Creator objects
	 */
	public function getCreators() {
		if ($this->id && !$this->creatorsLoaded) {
			$this->loadCreators();
		}
		
		return $this->creators;
	}
	
	
	public function setCreator($orderIndex, Zotero_Creator $creator, $creatorTypeID) {
		if ($this->id && !$this->creatorsLoaded) {
			$this->loadCreators();
		}
		
		if (!is_integer($orderIndex)) {
			trigger_error("orderIndex must be an integer", E_USER_ERROR);
		}
		if (!($creator instanceof Zotero_Creator)) {
			trigger_error("creator must be a Zotero_Creator object", E_USER_ERROR);
		}
		if (!is_integer($creatorTypeID)) {
			trigger_error("creatorTypeID must be an integer", E_USER_ERROR);
		}
		
		if (!Zotero_CreatorTypes::getID($creatorTypeID)) {
			trigger_error("Invalid creatorTypeID '$creatorTypeID'", E_USER_ERROR);
		}
		
		// If creator already exists at this position, cancel
		if (isset($this->creators[$orderIndex])
				&& $this->creators[$orderIndex]['ref']->id == $creator->id
				&& $this->creators[$orderIndex]['creatorTypeID'] == $creatorTypeID
				&& !$creator->hasChanged()) {
			Z_Core::debug("Creator in position $orderIndex hasn't changed", 4);
			return false;
		}
		
		$this->creators[$orderIndex]['ref'] = $creator;
		$this->creators[$orderIndex]['creatorTypeID'] = $creatorTypeID;
		$this->changedCreators[$orderIndex] = true;
		return true;
	}
	
	
	/*
	* Remove a creator and shift others down
	*/
	public function removeCreator($orderIndex) {
		if ($this->id && !$this->creatorsLoaded) {
			$this->loadCreators();
		}
		
		if (!isset($this->creators[$orderIndex])) {
			trigger_error("No creator exists at position $orderIndex", E_USER_ERROR);
		}
		
		$this->creators[$orderIndex] = false;
		array_splice($this->creators, $orderIndex, 1);
		for ($i=$orderIndex, $max=sizeOf($this->creators)+1; $i<$max; $i++) {
			$this->changedCreators[$i] = true;
		}
		return true;
	}
	
	
	public function isRegularItem() {
		return !($this->isNote() || $this->isAttachment());
	}
	
	
	public function numChildren($includeTrashed=false) {
		return $this->numNotes($includeTrashed) + $this->numAttachments($includeTrashed);
	}

	
	
	//
	//
	// Child item methods
	//
	//
	/**
	* Get the itemID of the source item for a note or file
	**/
	public function getSource() {
		if (isset($this->sourceItem)) {
			if (!$this->sourceItem) {
				return false;
			}
			if (is_int($this->sourceItem)) {
				return $this->sourceItem;
			}
			$sourceItem = Zotero_Items::getByLibraryAndKey($this->libraryID, $this->sourceItem);
			if (!$sourceItem) {
				throw new Exception("Source item $this->libraryID/$this->sourceItem for keyed source doesn't exist", Z_ERROR_ITEM_NOT_FOUND);
			}
			// Replace stored key with id
			$this->sourceItem = $sourceItem->id;
			return $sourceItem->id;
		}
		
		if (!$this->id) {
			return false;
		}
		
		if ($this->isNote()) {
			$Type = 'Note';
		}
		else if ($this->isAttachment()) {
			$Type = 'Attachment';
		}
		else {
			return false;
		}
		
		$sql = "SELECT sourceItemID FROM item{$Type}s WHERE itemID=?";
		$sourceItemID = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		// Temporary sanity check
		if ($sourceItemID && !is_int($sourceItemID)) {
			trigger_error("sourceItemID is not an integer", E_USER_ERROR);
		}
		if (!$sourceItemID) {
			$sourceItemID = false;
		}
		$this->sourceItem = $sourceItemID;
		return $sourceItemID;
	}
	
	
	/**
	 * Get the key of the source item for a note or file
	 * @return	{String}
	 */
	public function getSourceKey() {
		if (isset($this->sourceItem)) {
			if (is_int($this->sourceItem)) {
				$sourceItem = Zotero_Items::get($this->libraryID, $this->sourceItem);
				return $sourceItem->key;
			}
			return $this->sourceItem;
		}
		
		if (!$this->id) {
			return false;
		}
		
		if ($this->isNote()) {
			$Type = 'Note';
		}
		else if ($this->isAttachment()) {
			$Type = 'Attachment';
		}
		else {
			return false;
		}
		
		$sql = "SELECT `key` FROM item{$Type}s A JOIN items B ON (A.sourceItemID=B.itemID) WHERE A.itemID=?";
		$key = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$key) {
			$key = false;
		}
		$this->sourceItem = $key;
		return $key;
	}
	
	
	public function setSource($sourceItemID) {
		if ($this->isNote()) {
			$type = 'note';
			$Type = 'Note';
		}
		else if ($this->isAttachment()) {
			$type = 'attachment';
			$Type = 'Attachment';
		}
		else {
			trigger_error("setSource() can only be called on notes and attachments", E_USER_ERROR);
		}
		
		$this->sourceItem = $sourceItemID;
		$this->changedSource = true;
	}
	
	
	public function setSourceKey($sourceItemKey) {
		if ($this->isNote()) {
			$type = 'note';
			$Type = 'Note';
		}
		else if ($this->isAttachment()) {
			$type = 'attachment';
			$Type = 'Attachment';
		}
		else {
			throw new Exception("setSourceKey() can only be called on notes and attachments");
		}
		
		$oldSourceItemID = $this->getSource();
		if ($oldSourceItemID) {
			$sourceItem = Zotero_Items::get($this->libraryID, $oldSourceItemID);
			$oldSourceItemKey = $sourceItem->key;
		}
		else {
			$oldSourceItemKey = null;
		}
		if ($oldSourceItemKey == $sourceItemKey) {
			Z_Core::debug("Source item has not changed in Zotero_Item->setSourceKey()");
			return false;
		}
		
		$this->sourceItem = $sourceItemKey ? $sourceItemKey : null;
		$this->changedSource = true;
		
		return true;
	}
	
	
	/**
	 * Returns number of child attachments of item
	 *
	 * @param	{Boolean}	includeTrashed		Include trashed child items in count
	 * @return	{Integer}
	 */
	public function numAttachments($includeTrashed=false) {
		if (!$this->isRegularItem()) {
			trigger_error("numAttachments() can only be called on regular items", E_USER_ERROR);
		}
		
		if (!$this->id) {
			return 0;
		}
		
		$deleted = 0;
		if ($includeTrashed) {
			$sql = "SELECT COUNT(*) FROM itemAttachments JOIN deletedItems USING (itemID)
					WHERE sourceItemID=?";
			$deleted = (int) Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		}
		
		return $this->numAttachments + $deleted;
	}
	
	
	//
	//
	// Note methods
	//
	//
	/**
	 * Get the first line of the note for display in the items list
	 *
	 * Note: Note titles can also come from Zotero.Items.cacheFields()!
	 *
	 * @return	{String}
	 */
	public function getNoteTitle() {
		if (!$this->isNote() && !$this->isAttachment()) {
			throw ("getNoteTitle() can only be called on notes and attachments");
		}
		
		if ($this->noteTitle !== null) {
			return $this->noteTitle;
		}
		
		if (!$this->id) {
			return '';
		}
		
		$sql = "SELECT title FROM itemNotes WHERE itemID=?";
		$title = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		
		$this->noteTitle = $title ? $title : '';
		return $this->noteTitle;
	}

	
	
	/**
	* Get the text of an item note
	**/
	public function getNote() {
		if (!$this->isNote() && !$this->isAttachment()) {
			throw new Exception("getNote() can only be called on notes and attachments");
		}
		
		if (!$this->id) {
			return '';
		}
		
		// Store access time for later garbage collection
		//$this->noteAccessTime = new Date();
		
		if (!is_null($this->noteText)) {
			return $this->noteText;
		}
		
		$note = Zotero_Notes::getCachedNote($this->libraryID, $this->id);
		if ($note === false) {
			$sql = "SELECT note FROM itemNotes WHERE itemID=?";
			$note = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		}
		
		$this->noteText = $note ? $note : '';
		
		return $this->noteText;
	}
	
	
	public function getNoteHash() {
		if (!$this->isNote() && !$this->isAttachment()) {
			trigger_error("getNoteHash() can only be called on notes and attachments", E_USER_ERROR);
		}
		
		if (!$this->id) {
			return '';
		}
		
		// Store access time for later garbage collection
		//$this->noteAccessTime = new Date();
		
		return Zotero_Notes::getHash($this->libraryID, $this->id);
	}
	
	
	/**
	* Set an item note
	*
	* Note: This can only be called on notes and attachments
	**/
	public function setNote($text) {
		if (!$this->isNote() && !$this->isAttachment()) {
			trigger_error("setNote() can only be called on notes and attachments", E_USER_ERROR);
		}
		
		$currentHash = $this->getNoteHash();
		$hash = $text ? md5($text) : false;
		if ($currentHash == $hash) {
			Z_Core::debug("Note text hasn't changed in setNote()");
			return;
		}
		
		$this->noteText = $text;
		$this->changedNote = true;
	}
	
	
	/**
	 * Returns number of child notes of item
	 *
	 * @param	{Boolean}	includeTrashed		Include trashed child items in count
	 * @return	{Integer}
	 */
	public function numNotes($includeTrashed=false) {
		if ($this->isNote()) {
			throw new Exception("numNotes() cannot be called on items of type 'note'");
		}
		
		if (!$this->id) {
			return 0;
		}
		
		$deleted = 0;
		if ($includeTrashed) {
			$sql = "SELECT COUNT(*) FROM itemNotes WHERE sourceItemID=? AND
					itemID IN (SELECT itemID FROM deletedItems)";
			$deleted = (int) Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		}
		
		return $this->numNotes + $deleted;
	}
	
	
	
	
	/*
	 * Convert the item object into a persistent form
	 *	for use by the export functions
	 *
	 * Modes:
	 *
	 * 1 == e.g. [Letter to Valee]
	 * 2 == e.g. [Stothard; Letter to Valee; May 8, 1928]
	 */
	public function serialize($mode=false) {
		if ($this->id || $this->key) {
			if (!$this->primaryDataLoaded) {
				$this->loadPrimaryData(true);
			}
			if (!$this->itemDataLoaded) {
				$this->loadItemData();
			}
		}
		
		$arr = array();
		$arr['primary'] = array();
		$arr['virtual'] = array();
		$arr['fields'] = array();
		
		// Primary and virtual fields
		foreach (Zotero_Items::$primaryFields as $field) {
			switch ($field) {
				case 'itemID':
					$arr['primary'][$field] = $this->id;
					continue;
					
				case 'itemTypeID':
					$arr['primary']['itemType'] = Zotero_ItemTypes::getName($this->itemTypeID);
					continue;
				
				case 'firstCreator':
					$arr['virtual'][$field] = $this->getField('firstCreator');
					continue;
					
				case 'numNotes':
				case 'numAttachments':
					$arr['virtual'][$field] = $this->$field;
					continue;
				
				// For the rest, just copy over
				default:
					$arr['primary'][$field] = $this->$field;
			}
		}
		
		// Item metadata
		foreach ($this->itemData as $field=>$value) {
			$arr['fields'][Zotero_ItemFields::getName($field)] =
				$this->itemData[$field] ? $this->itemData[$field] : '';
		}
		
		if ($mode == 1 || $mode == 2) {
			if (!$arr['fields']['title'] &&
					($this->itemTypeID == Zotero_ItemTypes::getID('letter') ||
					$this->itemTypeID == Zotero_ItemTypes::getID('interview'))) {
				$arr['fields']['title'] = $this->getDisplayTitle($mode == 2);
			}
		}
		
		
		if ($this->isRegularItem()) {
			// Creators
			$arr['creators'] = array();
			$creators = $this->getCreators();
			foreach ($creators as $creator) {
				$creatorArr = array();
				// Convert creatorTypeIDs to text
				$creator['creatorType'] =
					Zotero_CreatorTypes::getName($creator['creatorTypeID']);
				$creator['creatorID'] = $creator['ref']->id;
				$creator['firstName'] = $creator['ref']->firstName;
				$creator['lastName'] = $creator['ref']->lastName;
				$creator['fieldMode'] = $creator['ref']->fieldMode;
				$arr['creators'][] = $creator;
			}
			
			// Attach children of regular items
			
			// Append attached notes
			$arr['notes'] = array();
			$noteIDs = $this->getNotes();
			if ($noteIDs) {
				foreach ($noteIDs as $noteID) {
					$note = Zotero_Items::get($this->libraryID, $noteID);
					$arr['notes'][] = $note->serialize();
				}
			}
			
			// Append attachments
			$arr['attachments'] = array();
			$attachmentIDs = $this->getAttachments();
			if ($attachmentIDs) {
				foreach ($attachmentIDs as $attachmentID) {
					$attachment = Zotero_Items::get($this->libraryID, $attachmentID);
					$arr['attachments'][] = $attachment->serialize();
				}
			}
		}
		// Notes and embedded attachment notes
		else {
			if ($this->isAttachment()) {
				$arr['attachment'] = array();
				$arr['attachment']['linkMode'] = $this->attachmentLinkMode;
				$arr['attachment']['mimeType'] = $this->attachmentMIMEType;
				$arr['attachment']['charset'] = Zotero_CharacterSets::getName($this->attachmentCharset);
				$arr['attachment']['path'] = $this->attachmentPath;
			}
			
			$arr['note'] = $this->getNote();
			$parent = $this->getSource();
			if ($parent) {
				$arr['sourceItemID'] = $parent;
			}
		}
		
		$arr['tags'] = array();
		$tags = $this->getTags();
		if ($tags) {
			foreach ($tags as $tag) {
				$arr['tags'][] = $tag->serialize();
			}
		}
		
		$related = $this->getRelatedItems();
		$arr['related'] = $related ? $related : array();
		
		return $arr;
	}
	
	
	//
	//
	// Methods dealing with item notes
	//
	//
	/**
	* Returns an array of note itemIDs for this item
	**/
	public function getNotes() {
		if ($this->isNote()) {
			throw new Exception("getNotes() cannot be called on items of type 'note'");
		}
		
		if (!$this->id) {
			return array();
		}
		
		$sql = "SELECT N.itemID FROM itemNotes N NATURAL JOIN items
				WHERE sourceItemID=? ORDER BY title";
		
		/*
		if (Zotero.Prefs.get('sortNotesChronologically')) {
			sql += " ORDER BY dateAdded";
			return Zotero.DB.columnQuery(sql, $this->id);
		}
		*/
		
		$itemIDs = Zotero_DB::columnQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$itemIDs) {
			return array();
		}
		return $itemIDs;
	}

	
	
	//
	//
	// Attachment methods
	//
	//
	/**
	 * Get the link mode of an attachment
	 *
	 * Possible return values specified as constants in Zotero.Attachments
	 * (e.g. Zotero.Attachments.LINK_MODE_LINKED_FILE)
	 */
	private function getAttachmentLinkMode() {
		if (!$this->isAttachment()) {
			trigger_error("attachmentLinkMode can only be retrieved for attachment items", E_USER_ERROR);
		}
		
		if ($this->attachmentData['linkMode'] !== null) {
			return $this->attachmentData['linkMode'];
		}
		
		if (!$this->id) {
			return null;
		}
		
		// Return ENUM as 0-index integer
		$sql = "SELECT linkMode - 1 FROM itemAttachments WHERE itemID=?";
		// DEBUG: why is this returned as a float without the cast?
		$linkMode = (int) Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		$this->attachmentData['linkMode'] = $linkMode;
		return $linkMode;
	}
	
	
	/**
	 * Get the MIME type of an attachment (e.g. 'text/plain')
	 */
	private function getAttachmentMIMEType() {
		if (!$this->isAttachment()) {
			trigger_error("attachmentMIMEType can only be retrieved for attachment items", E_USER_ERROR);
		}
		
		if ($this->attachmentData['mimeType'] !== null) {
			return $this->attachmentData['mimeType'];
		}
		
		if (!$this->id) {
			return '';
		}
		
		$sql = "SELECT mimeType FROM itemAttachments WHERE itemID=?";
		$mimeType = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$mimeType) {
			$mimeType = '';
		}
		$this->attachmentData['mimeType'] = $mimeType;
		return $mimeType;
	}
	
	
	/**
	 * Get the character set of an attachment
	 *
	 * @return	string					Character set name
	 */
	private function getAttachmentCharset() {
		if (!$this->isAttachment()) {
			trigger_error("attachmentCharset can only be retrieved for attachment items", E_USER_ERROR);
		}
		
		if ($this->attachmentData['charset'] !== null) {
			return $this->attachmentData['charset'];
		}
		
		if (!$this->id) {
			return '';
		}
		
		$sql = "SELECT charset FROM itemAttachments
				JOIN " . Z_CONFIG::$SHARD_MASTER_DB . ".charsets USING (charsetID)
				WHERE itemID=?";
		$charset = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$charset) {
			$charset = '';
		}
		$this->attachmentData['charset'] = $charset;
		return $charset;
	}
	
	
	private function getAttachmentPath() {
		if (!$this->isAttachment()) {
			trigger_error("attachmentPath can only be retrieved for attachment items", E_USER_ERROR);
		}
		
		if ($this->attachmentData['path'] !== null) {
			return $this->attachmentData['path'];
		}
		
		if (!$this->id) {
			return '';
		}
		
		$sql = "SELECT path FROM itemAttachments WHERE itemID=?";
		$path = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$path) {
			$path = '';
		}
		$this->attachmentData['path'] = $path;
		return $path;
	}
	
	
	private function getAttachmentStorageModTime() {
		if (!$this->isAttachment()) {
			trigger_error("attachmentStorageModTime can only be retrieved
				for attachment items", E_USER_ERROR);
		}
		
		if ($this->attachmentData['storageModTime'] !== null) {
			return $this->attachmentData['storageModTime'];
		}
		
		if (!$this->id) {
			return null;
		}
		
		$sql = "SELECT storageModTime FROM itemAttachments WHERE itemID=?";
		$val = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		$this->attachmentData['storageModTime'] = $val;
		return $val;
	}
	
	
	private function getAttachmentStorageHash() {
		if (!$this->isAttachment()) {
			trigger_error("attachmentStorageHash can only be retrieved
				for attachment items", E_USER_ERROR);
		}
		
		if ($this->attachmentData['storageHash'] !== null) {
			return $this->attachmentData['storageHash'];
		}
		
		if (!$this->id) {
			return null;
		}
		
		$sql = "SELECT storageHash FROM itemAttachments WHERE itemID=?";
		$val = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		$this->attachmentData['storageHash'] = $val;
		return $val;
	}
	
	
	private function setAttachmentField($field, $val) {
		Z_Core::debug("Setting attachment field $field to '$val'");
		switch ($field) {
			case 'mimeType':
				$field = 'mimeType';
				$fieldCap = 'MIMEType';
				break;
			
			case 'linkMode':
			case 'charset':
			case 'storageModTime':
			case 'storageHash':
			case 'path':
				$fieldCap = ucwords($field);
				break;
				
			default:
				trigger_error("Invalid attachment field $field", E_USER_ERROR);
		}
		
		if (!$this->isAttachment()) {
			trigger_error("attachment$fieldCap can only be set for attachment items", E_USER_ERROR);
		}
		
		if ($field == 'linkMode') {
			switch ($val) {
				// TODO: get these constants from somewhere
				// TODO: validate field for this link mode
				case 0:
				case 1:
				case 2:
				case 3:
					break;
					
				default:
					trigger_error("Invalid attachment link mode '$val' in "
						. "Zotero_Item::attachmentLinkMode setter", E_USER_ERROR);
			}
		}
		
		if (!is_int($val) && !$val) {
			$val = '';
		}
		
		$fieldName = 'attachment' . $fieldCap;
		
		if ($val === $this->$fieldName) {
			return;
		}
		
		$this->changedAttachmentData[$field] = true;
		$this->attachmentData[$field] = $val;
	}
	
	
	/**
	* Returns an array of attachment itemIDs that have this item as a source,
	* or FALSE if none
	**/
	public function getAttachments() {
		if ($this->isAttachment()) {
			throw new Exception("getAttachments() cannot be called on attachment items");
		}
		
		if (!$this->id) {
			return false;
		}
		
		$sql = "SELECT itemID FROM items NATURAL JOIN itemAttachments WHERE sourceItemID=?";
		
		// TODO: reimplement sorting by title using values from MongoDB?
		
		/*
		if (Zotero.Prefs.get('sortAttachmentsChronologically')) {
			sql +=  " ORDER BY dateAdded";
			return Zotero.DB.columnQuery(sql, this.id);
		}
		*/
		
		$itemIDs = Zotero_DB::columnQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$itemIDs) {
			return array();
		}
		return $itemIDs;
	}
	
	
	
	//
	// Methods dealing with tags
	//
	
	public function numTags() {
		if (!$this->id) {
			return 0;
		}
		$sql = "SELECT COUNT(*) FROM itemTags WHERE itemID=?";
		return (int) Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
	}
	
	/**
	 * Returns all tags assigned to an item
	 *
	 * @return	array			Array of Zotero.Tag objects
	 */
	public function getTags($asIDs=false) {
		if (!$this->id) {
			return false;
		}
		$sql = "SELECT T.tagID FROM tags T JOIN itemTags IT ON (T.tagID=IT.tagID)
				WHERE itemID=? ORDER BY name";
		$tagIDs = Zotero_DB::columnQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$tagIDs) {
			return false;
		}
		
		if ($asIDs) {
			return $tagIDs;
		}
		
		$tagObjs = array();
		foreach ($tagIDs as $tagID) {
			$tag = Zotero_Tags::get($this->libraryID, $tagID, true);
			$tagObjs[] = $tag;
		}
		return $tagObjs;
	}
	
	
	public function toAtom($content='none', $apiVersion=null) {
		return Zotero_Items::convertItemToAtom($this, $content, $apiVersion);
	}
	
	
	public function toSolrDocument() {
		$doc = new SolrInputDocument();
		
		$uri = Zotero_Atom::getItemURI($this);
		$doc->addField("uri", str_replace(Zotero_Atom::getBaseURI(), '', $uri));
		
		// Primary fields
		foreach (Zotero_Items::$primaryFields as $field) {
			switch ($field) {
				case 'itemID':
				case 'firstCreator':
				case 'numAttachments':
				case 'numNotes':
					continue (2);
				
				case 'itemTypeID':
					$xmlField = 'itemType';
					$xmlValue = Zotero_ItemTypes::getName($this->$field);
					break;
				
				case 'dateAdded':
				case 'dateModified':
				case 'serverDateModified':
					$xmlField = $field;
					$xmlValue = Zotero_Date::sqlToISO8601($this->$field);
					break;
				
				default:
					$xmlField = $field;
					$xmlValue = $this->$field;
			}
			
			$doc->addField($xmlField, $xmlValue);
		}
		
		// Item data
		$fieldIDs = $this->getUsedFields();
		foreach ($fieldIDs as $fieldID) {
			$val = $this->getField($fieldID);
			if ($val == '') {
				continue;
			}
			
			$fieldName = Zotero_ItemFields::getName($fieldID);
			
			switch ($fieldName) {
				// As is
				case 'title':
					break;
				
				// Date fields
				case 'date':
					// Add user part as text
					$doc->addField($fieldName . "_t", Zotero_Date::multipartToStr($val));
					
					// Add as proper date, if there is one
					$sqlDate = Zotero_Date::multipartToSQL($val);
					if (!$sqlDate || $sqlDate == '0000-00-00') {
						continue 2;
					}
					$fieldName .= "_tdt";
					$val = Zotero_Date::sqlToISO8601($sqlDate);
					break;
				
				case 'accessDate':
					$fieldName .= "_tdt";
					$val = Zotero_Date::sqlToISO8601($val);
					break;
				
				default:
					$fieldName .= "_t";
			}
			
			//var_dump('===========');
			//var_dump($fieldName);
			//var_dump($val);
			$doc->addField($fieldName, $val);
		}
		
		// Deleted item flag
		if ($this->deleted) {
			$doc->addField('deleted', true);
		}
		
		if ($this->isNote() || $this->isAttachment()) {
			$sourceItemID = $this->getSource();
			if ($sourceItemID) {
				$sourceItem = Zotero_Items::get($this->libraryID, $sourceItemID);
				if (!$sourceItem) {
					throw new Exception("Source item $sourceItemID not found");
				}
				$doc->addField('sourceItem', $sourceItem->key);
			}
		}
		
		// Group modification info
		$createdByUserID = null;
		$lastModifiedByUserID = null;
		switch (Zotero_Libraries::getType($this->libraryID)) {
			case 'group':
				$createdByUserID = $this->createdByUserID;
				$lastModifiedByUserID = $this->lastModifiedByUserID;
				break;
		}
		if ($createdByUserID) {
			$doc->addField('createdByUserID', $createdByUserID);
		}
		if ($lastModifiedByUserID) {
			$doc->addField('lastModifiedByUserID', $lastModifiedByUserID);
		}
		
		// Note
		if ($this->isNote()) {
			$doc->addField('note', $this->getNote());
		}
		
		if ($this->isAttachment()) {
			$doc->addField('linkMode', $this->attachmentLinkMode);
			$doc->addField('mimeType', $this->attachmentMIMEType);
			if ($this->attachmentCharset) {
				$doc->addField('charset', $this->attachmentCharset);
			}
			
			// TODO: get from a constant
			if ($this->attachmentLinkMode != 3) {
				$doc->addField('path', $this->attachmentPath);
			}
			
			$note = $this->getNote();
			if ($note) {
				$doc->addField('note', $note);
			}
		}
		
		// Creators
		$creators = $this->getCreators();
		if ($creators) {
			foreach ($creators as $index => $creator) {
				$c = $creator['ref'];
				
				$doc->addField('creatorKey', $c->key);
				if ($c->fieldMode == 0) {
					$doc->addField('creatorFirstName', $c->firstName);
				}
				$doc->addField('creatorLastName', $c->lastName);
				$doc->addField('creatorType', Zotero_CreatorTypes::getName($creator['creatorTypeID']));
				$doc->addField('creatorIndex', $index);
			}
		}
		
		// Tags
		$tags = $this->getTags();
		if ($tags) {
			foreach ($tags as $tag) {
				$doc->addField('tagKey', $tag->key);
				$doc->addField('tag', $tag->name);
				$doc->addField('tagType', $tag->type);
			}
		}
		
		// Related items
		$related = $this->relatedItems;
		if ($related) {
			$related = Zotero_Items::get($this->libraryID, $related);
			$keys = array();
			foreach ($related as $item) {
				$doc->addField('relatedItem', $item->key);
			}
		}
		
		return $doc;
	}
	
	
	//
	//
	// Private methods
	//
	//
	private function loadItemData() {
		Z_Core::debug("Loading item data for item $this->id");
		
		// TODO: remove?
		if ($this->itemDataLoaded) {
			trigger_error("Item data for item $this->id already loaded", E_USER_ERROR);
		}
		
		if (!$this->id) {
			trigger_error('Item ID not set before attempting to load data', E_USER_ERROR);
		}
		
		if (!is_numeric($this->id)) {
			trigger_error("Invalid itemID '$this->id'", E_USER_ERROR);
		}
		
		$sql = "SELECT fieldID, itemDataValueHash AS hash FROM itemData WHERE itemID=?";
		$stmt = Zotero_DB::getStatement($sql, true, Zotero_Shards::getByLibraryID($this->libraryID));
		$fields = Zotero_DB::queryFromStatement($stmt, $this->id);
		
		$itemTypeFields = Zotero_ItemFields::getItemTypeFields($this->itemTypeID);
		
		if ($fields) {
			foreach($fields as $field) {
				// TEMP
				if (!$field['hash']) {
					$s = Zotero_Shards::getByLibraryID($this->libraryID);
					$sql = "SELECT MD5(value) AS hash FROM itemDataOld JOIN itemDataValues USING (itemDataValueID) WHERE itemID=? AND fieldID=?";
					$hash = Zotero_DB::valueQuery($sql, array($this->id, $field['fieldID']), $s);
					if (!$hash) {
						throw new Exception("Hash not available for item $this->id AND field {$field['fieldID']}");
					}
					$sql = "UPDATE itemData SET itemDataValueHash=? WHERE itemID=? AND fieldID=?";
					Zotero_DB::query($sql, array($hash, $this->id, $field['fieldID']), $s);
					$field['hash'] = $hash;
				}
				
				$value = Zotero_Items::getDataValue($field['hash']);
				if ($value === false) {
					throw new Exception("Item data value for hash '{$field['hash']}' not found");
				}
				$this->setField($field['fieldID'], $value, true, true);
			}
		}
		
		// Mark nonexistent fields as loaded
		if ($itemTypeFields) {
			foreach($itemTypeFields as $fieldID) {
				if (is_null($this->itemData[$fieldID])) {
					$this->itemData[$fieldID] = false;
				}
			}
		}
		
		$this->itemDataLoaded = true;
	}
	
	
	private function loadCreators() {
		if (!$this->id) {
			trigger_error('Item ID not set for item before attempting to load creators', E_USER_ERROR);
		}
		
		if (!is_numeric($this->id)) {
			trigger_error("Invalid itemID '$this->id'", E_USER_ERROR);
		}
		
		$cacheKey = "itemCreators_" . $this->id;
		$creators = Z_Core::$MC->get($cacheKey);
		if ($creators === false) {
			$sql = "SELECT creatorID, creatorTypeID, orderIndex FROM itemCreators
					WHERE itemID=? ORDER BY orderIndex";
			$stmt = Zotero_DB::getStatement($sql, true, Zotero_Shards::getByLibraryID($this->libraryID));
			$creators = Zotero_DB::queryFromStatement($stmt, $this->id);
			
			Z_Core::$MC->set($cacheKey, $creators ? $creators : array());
		}
		
		$this->creators = array();
		$this->creatorsLoaded = true;
		
		if (!$creators) {
			return;
		}
		
		foreach ($creators as $creator) {
			$creatorObj = Zotero_Creators::get($this->libraryID, $creator['creatorID']);
			if (!$creatorObj) {
				Z_Core::$MC->delete($cacheKey);
				throw new Exception("Creator {$creator['creatorID']} not found");
			}
			$this->creators[$creator['orderIndex']] = array(
				'creatorTypeID' => $creator['creatorTypeID'],
				'ref' => $creatorObj
			);
		}
	}
	
	
	private function loadRelatedItems() {
		if (!$this->id) {
			return;
		}
		
		Z_Core::debug("Loading related items for item $this->id");
		
		if ($this->relatedItemsLoaded) {
			trigger_error("Related items for item $this->id already loaded", E_USER_ERROR);
		}
		
		if (!$this->primaryDataLoaded) {
			$this->loadPrimaryData(true);
		}
		
		// TODO: use a prepared statement
		if (!is_numeric($this->id)) {
			trigger_error("Invalid itemID '$this->id'", E_USER_ERROR);
		}
		
		$cacheKey = "itemRelated_" . $this->id;
		$ids = Z_Core::$MC->get($cacheKey);
		if ($ids !== false) {
			$this->relatedItems = $ids;
			$this->relatedItemsLoaded = true;
			return;
		}
		
		$sql = "SELECT linkedItemID FROM itemRelated WHERE itemID=?";
		$ids = Zotero_DB::columnQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		
		$this->relatedItems = $ids ? $ids : array();
		$this->relatedItemsLoaded = true;
		
		Z_Core::$MC->set($cacheKey, $this->relatedItems);
	}
	
	
	private function getRelatedItems() {
		if (!$this->relatedItemsLoaded) {
			$this->loadRelatedItems();
		}
		return $this->relatedItems;
	}
	
	
	private function setRelatedItems($itemIDs) {
		if (!$this->relatedItemsLoaded) {
			$this->loadRelatedItems();
		}
		
		if (!is_array($itemIDs))  {
			trigger_error('$itemIDs must be an array', E_USER_ERROR);
		}
		
		$currentIDs = $this->relatedItems;
		if (!$currentIDs) {
			$currentIDs = array();
		}
		$oldIDs = array(); // children being kept
		$newIDs = array(); // new children
		
		if (!$itemIDs) {
			if (!$currentIDs) {
				Z_Core::debug("No related items added", 4);
				return false;
			}
		}
		else {
			foreach ($itemIDs as $itemID) {
				if ($itemID == $this->id) {
					Z_Core::debug("Can't relate item to itself in Zotero.Item.setRelatedItems()", 2);
					continue;
				}
				
				if (in_array($itemID, $currentIDs)) {
					Z_Core::debug("Item {$this->id} is already related to item $itemID");
					$oldIDs[] = $itemID;
					continue;
				}
				
				// TODO: check if related on other side (like client)?
				
				$newIDs[] = $itemID;
			}
		}
		
		// Mark as changed if new or removed ids
		if ($newIDs || sizeOf($oldIDs) != sizeOf($currentIDs)) {
			$this->prepFieldChange('relatedItems');
		}
		else {
			Z_Core::debug('Related items not changed', 4);
			return false;
		}
		
		$this->relatedItems = array_merge($oldIDs, $newIDs);
		return true;
	}
	
	
	private function prepFieldChange($field) {
		if (!$this->changed) {
			$this->changed = array();
		}
		$this->changed[$field] = true;
		
		// Save a copy of the data before changing
		if ($this->id && $this->exists() && !$this->previousData) {
			$this->previousData = $this->serialize();
		}
	}
	
	
	private function generateKey() {
		trigger_error('Unimplemented', E_USER_ERROR);
		//return md5('server_' . $this->userID . '_' . ??? . date('Y-m-d H:i:s'));
	}
}
?>
