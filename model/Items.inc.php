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

class Zotero_Items extends Zotero_DataObjects {
	protected static $ZDO_object = 'item';
	
	public static $primaryFields = array('itemID', 'libraryID', 'key', 'itemTypeID',
		'dateAdded', 'dateModified', 'serverDateModified',
		'numNotes', 'numAttachments');
	public static $maxDataValueLength = 65535;
	public static $cacheVersion = 1;
	
	private static $itemsByID = array();
	
	
	public static function get($libraryID, $itemIDs) {
		$numArgs = func_num_args();
		if ($numArgs != 2) {
			throw new Exception('Zotero_Items::get() takes two parameters');
		}
		
		if (!$itemIDs) {
			throw new Exception('$itemIDs cannot be null');
		}
		
		if (is_scalar($itemIDs)) {
			$single = true;
			$itemIDs = array($itemIDs);
		}
		else {
			$single = false;
		}
		
		$toLoad = array();
		
		foreach ($itemIDs as $itemID) {
			if (!isset(self::$itemsByID[$itemID])) {
				array_push($toLoad, $itemID);
			}
		}
		
		if ($toLoad) {
			self::loadItems($libraryID, $toLoad);
		}
		
		$loaded = array();
		
		// Make sure items exist
		foreach ($itemIDs as $itemID) {
			if (!isset(self::$itemsByID[$itemID])) {
				Z_Core::debug("Item $itemID doesn't exist");
				continue;
			}
			$loaded[] = self::$itemsByID[$itemID];
		}
		
		if ($single) {
			return !empty($loaded) ? $loaded[0] : false;
		}
		
		return $loaded;
	}
	
	
	/**
	 *
	 * TODO: support limit?
	 *
	 * @param	{Integer[]}
	 * @param	{Boolean}
	 */
	public static function getDeleted($libraryID, $asIDs) {
		$sql = "SELECT itemID FROM deletedItems JOIN items USING (itemID) WHERE libraryID=?";
		$ids = Zotero_DB::columnQuery($sql, $libraryID, Zotero_Shards::getByLibraryID($libraryID));
		if (!$ids) {
			return array();
		}
		if ($asIDs) {
			return $ids;
		}
		return self::get($libraryID, $ids);
	}
	
	
	public static function search($libraryID, $onlyTopLevel=false, $params=array(), $includeTrashed=false, $asKeys=false) {
		$rnd = "_" . uniqid($libraryID . "_");
		
		if ($asKeys) {
			$results = array('keys' => array(), 'total' => 0);
		}
		else {
			$results = array('items' => array(), 'total' => 0);
		}
		
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		
		$itemIDs = array();
		$keys = array();
		$deleteTempTable = array();
		
		// Pass a list of itemIDs, for when the initial search is done via SQL
		if (!empty($params['itemIDs'])) {
			$itemIDs = $params['itemIDs'];
		}
		
		if (!empty($params['itemKey'])) {
			$keys = explode(',', $params['itemKey']);
		}
		
		$titleSort = !empty($params['order']) && $params['order'] == 'title';
		
		$sql = "SELECT SQL_CALC_FOUND_ROWS DISTINCT "
				. ($asKeys ? "I.key" : "I.itemID")
				. " FROM items I ";
		$sqlParams = array($libraryID);
		
		if (!empty($params['q']) || $titleSort) {
			$titleFieldIDs = array_merge(
				array(Zotero_ItemFields::getID('title')),
				Zotero_ItemFields::getTypeFieldsFromBase('title')
			);
			$sql .= "LEFT JOIN itemData IDT ON (IDT.itemID=I.itemID AND IDT.fieldID IN ("
				. implode(',', $titleFieldIDs) . ")) ";
		}
		
		if (!empty($params['q'])) {
			$sql .= "LEFT JOIN itemCreators IC ON (IC.itemID=I.itemID)
					LEFT JOIN creators C ON (C.creatorID=IC.creatorID) ";
		}
		if ($onlyTopLevel || !empty($params['q']) || $titleSort) {
			$sql .= "LEFT JOIN itemNotes INo ON (INo.itemID=I.itemID) ";
		}
		if ($onlyTopLevel) {
			$sql .= "LEFT JOIN itemAttachments IA ON (IA.itemID=I.itemID) ";
		}
		if (!$includeTrashed) {
			$sql .= "LEFT JOIN deletedItems DI ON (DI.itemID=I.itemID) ";
		}
		if (!empty($params['order'])) {
			switch ($params['order']) {
				case 'title':
				case 'creator':
					$sql .= "LEFT JOIN itemSortFields ISF ON (ISF.itemID=I.itemID) ";
					break;
				
				case 'date':
					$dateFieldIDs = array_merge(
						array(Zotero_ItemFields::getID('date')),
						Zotero_ItemFields::getTypeFieldsFromBase('date')
					);
					
					$sql .= "LEFT JOIN itemData IDD ON (IDD.itemID=I.itemID AND IDD.fieldID IN ("
						. implode(',', $dateFieldIDs) . ")) ";
					break;
				
				case 'itemType':
					// Create temporary table to store item type names
					//
					// We use IF NOT EXISTS just to make sure there are
					// no problems with restoration from the binary log
					$sql2 = "CREATE TEMPORARY TABLE IF NOT EXISTS tmpItemTypeNames$rnd
							(itemTypeID SMALLINT UNSIGNED NOT NULL,
							itemTypeName VARCHAR(255) NOT NULL,
							PRIMARY KEY (itemTypeID),
							INDEX (itemTypeName))";
					Zotero_DB::query($sql2, false, $shardID);
					$deleteTempTable['tmpItemTypeNames'] = true;
				
					$types = Zotero_ItemTypes::getAll('en-US');
					foreach ($types as $type) {
						$sql2 = "INSERT INTO tmpItemTypeNames$rnd VALUES (?, ?)";
						Zotero_DB::query($sql2, array($type['id'], $type['localized']), $shardID);
					}
					
					// Join temp table to query
					$sql .= "JOIN tmpItemTypeNames$rnd TITN ON (TITN.itemTypeID=I.itemTypeID) ";
					break;
				
				case 'addedBy':
					$isGroup = Zotero_Libraries::getType($libraryID) == 'group';
					if ($isGroup) {
						// Create temporary table to store usernames
						//
						// We use IF NOT EXISTS just to make sure there are
						// no problems with restoration from the binary log
						$sql2 = "CREATE TEMPORARY TABLE IF NOT EXISTS tmpCreatedByUsers$rnd
								(userID INT UNSIGNED NOT NULL,
								username VARCHAR(255) NOT NULL,
								PRIMARY KEY (userID),
								INDEX (username))";
						Zotero_DB::query($sql2, false, $shardID);
						$deleteTempTable['tmpCreatedByUsers'] = true;
						
						$sql2 = "SELECT DISTINCT createdByUserID FROM items
								JOIN groupItems USING (itemID) WHERE
								createdByUserID IS NOT NULL AND ";
						if ($itemIDs) {
							$sql2 .= "itemID IN ("
									. implode(', ', array_fill(0, sizeOf($itemIDs), '?'))
									. ") ";
							$createdByUserIDs = Zotero_DB::columnQuery($sql2, $itemIDs, $shardID);
						}
						else {
							$sql2 .= "libraryID=?";
							$createdByUserIDs = Zotero_DB::columnQuery($sql2, $libraryID, $shardID);
						}
						
						// Populate temp table with usernames
						if ($createdByUserIDs) {
							$toAdd = array();
							foreach ($createdByUserIDs as $createdByUserID) {
								$toAdd[] = array(
									$createdByUserID,
									Zotero_Users::getUsername($createdByUserID)
								);
							}
							
							$sql2 = "INSERT IGNORE INTO tmpCreatedByUsers$rnd VALUES ";
							Zotero_DB::bulkInsert($sql2, $toAdd, 50, false, $shardID);
							
							// Join temp table to query
							$sql .= "JOIN groupItems GI ON (GI.itemID=I.itemID)
									JOIN tmpCreatedByUsers$rnd TCBU ON (TCBU.userID=GI.createdByUserID) ";
						}
					}
					break;
			}
		}
		
		$sql .= "WHERE I.libraryID=? ";
		
		if ($onlyTopLevel) {
			$sql .= "AND INo.sourceItemID IS NULL AND IA.sourceItemID IS NULL ";
		}
		if (!$includeTrashed) {
			$sql .= "AND DI.itemID IS NULL ";
		}
		
		// Search on title and creators
		if (!empty($params['q'])) {
			$sql .= "AND (";
			
			$sql .= "IDT.value LIKE ? ";
			$sqlParams[] = '%' . $params['q'] . '%';
			
			$sql .= "OR title LIKE ? ";
			$sqlParams[] = '%' . $params['q'] . '%';
			
			$sql .= "OR TRIM(CONCAT(firstName, ' ', lastName)) LIKE ?";
			$sqlParams[] = '%' . $params['q'] . '%';
			
			$sql .= ") ";
		}
		
		// Search on itemType
		if (!empty($params['itemType'])) {
			$itemTypes = Zotero_API::getSearchParamValues($params, 'itemType');
			if ($itemTypes) {
				if (sizeOf($itemTypes) > 1) {
					throw new Exception("Cannot specify 'itemType' more than once", Z_ERROR_INVALID_INPUT);
				}
				$itemTypes = $itemTypes[0];
				
				$itemTypeIDs = array();
				foreach ($itemTypes['values'] as $itemType) {
					$itemTypeID = Zotero_ItemTypes::getID($itemType);
					if (!$itemTypeID) {
						throw new Exception("Invalid itemType '{$itemType}'", Z_ERROR_INVALID_INPUT);
					}
					$itemTypeIDs[] = $itemTypeID;
				}
				
				$sql .= "AND I.itemTypeID " . ($itemTypes['negation'] ? "NOT " : "") . "IN ("
						. implode(',', array_fill(0, sizeOf($itemTypeIDs), '?'))
						. ") ";
				$sqlParams = array_merge($sqlParams, $itemTypeIDs);
			}
		}
		
		// Tags
		//
		// ?tag=foo
		// ?tag=foo bar // phrase
		// ?tag=-foo // negation
		// ?tag=\-foo // literal hyphen (only for first character)
		// ?tag=foo&tag=bar // AND
		// ?tag=foo&tagType=0
		// ?tag=foo bar || bar&tagType=0
		$tagSets = Zotero_API::getSearchParamValues($params, 'tag');
		
		if ($tagSets) {
			$sql2 = "SELECT itemID FROM items WHERE 1 ";
			$sqlParams2 = array();
			
			if ($tagSets) {
				foreach ($tagSets as $set) {
					$positives = array();
					$negatives = array();
					$tagIDs = array();
					
					foreach ($set['values'] as $tag) {
						$ids = Zotero_Tags::getIDs($libraryID, $tag);
						if (!$ids) {
							$ids = array(0);
						}
						$tagIDs = array_merge($tagIDs, $ids);
					}
					
					$tagIDs = array_unique($tagIDs);
					
					if ($set['negation']) {
						$negatives = array_merge($negatives, $tagIDs);
					}
					else {
						$positives = array_merge($positives, $tagIDs);
					}
					
					if ($positives) {
						$sql2 .= "AND itemID IN (SELECT itemID FROM items JOIN itemTags USING (itemID)
								WHERE tagID IN (" . implode(',', array_fill(0, sizeOf($positives), '?')) . ")) ";
						$sqlParams2 = array_merge($sqlParams2, $positives);
					}
					
					if ($negatives) {
						$sql2 .= "AND itemID NOT IN (SELECT itemID FROM items JOIN itemTags USING (itemID)
								WHERE tagID IN (" . implode(',', array_fill(0, sizeOf($negatives), '?')) . ")) ";
						$sqlParams2 = array_merge($sqlParams2, $negatives);
					}
				}
			}
			
			$tagItems = Zotero_DB::columnQuery($sql2, $sqlParams2, $shardID);
			
			// No matches
			if (!$tagItems) {
				return $results;
			}
			
			// Combine with passed keys
			if ($itemIDs) {
				$itemIDs = array_intersect($itemIDs, $tagItems);
				// None of the tag matches match the passed keys
				if (!$itemIDs) {
					return $results;
				}
			}
			else {
				$itemIDs = $tagItems;
			}
		}
		
		if ($itemIDs) {
			$sql .= "AND I.itemID IN ("
					. implode(', ', array_fill(0, sizeOf($itemIDs), '?'))
					. ") ";
			$sqlParams = array_merge($sqlParams, $itemIDs);
		}
		
		if ($keys) {
			$sql .= "AND `key` IN ("
					. implode(', ', array_fill(0, sizeOf($keys), '?'))
					. ") ";
			$sqlParams = array_merge($sqlParams, $keys);
		}
		
		$sql .= "ORDER BY ";
		
		if (!empty($params['order'])) {
			switch ($params['order']) {
				case 'dateAdded':
				case 'dateModified':
					$orderSQL = "I." . $params['order'];
					break;
				
				case 'itemType';
					$orderSQL = "TITN.itemTypeName";
					break;
				
				case 'title':
					$orderSQL = "IFNULL(COALESCE(sortTitle, IDT.value, INo.title), '')";
					break;
				
				case 'creator':
					$orderSQL = "ISF.creatorSummary";
					break;
				
				// TODO: generic base field mapping-aware sorting
				case 'date':
					$orderSQL = "IDD.value";
					break;
				
				case 'addedBy':
					if ($isGroup && $createdByUserIDs) {
						$orderSQL = "TCBU.username";
					}
					else {
						$orderSQL = "1";
					}
					break;
				
				default:
					$fieldID = Zotero_ItemFields::getID($params['order']);
					if (!$fieldID) {
						throw new Exception("Invalid order field '" . $params['order'] . "'");
					}
					$orderSQL = "(SELECT value FROM itemData WHERE itemID=I.itemID AND fieldID=?)";
					if (!$params['emptyFirst']) {
						$sqlParams[] = $fieldID;
					}
					$sqlParams[] = $fieldID;
			}
			
			if (!empty($params['sort'])) {
				$dir = $params['sort'];
			}
			else {
				$dir = "ASC";
			}
			
			
			if (!$params['emptyFirst']) {
				$sql .= "IFNULL($orderSQL, '') = '' $dir, ";
			}
			
			$sql .= $orderSQL;
			
			$sql .= " $dir, ";
		}
		$sql .= "I.itemID " . (!empty($params['sort']) ? $params['sort'] : "ASC") . " ";
		if (!empty($params['limit'])) {
			$sql .= "LIMIT ?, ?";
			$sqlParams[] = $params['start'] ? $params['start'] : 0;
			$sqlParams[] = $params['limit'];
		}
		$itemIDs = Zotero_DB::columnQuery($sql, $sqlParams, $shardID);
		
		$results['total'] = Zotero_DB::valueQuery("SELECT FOUND_ROWS()", false, $shardID);
		if ($itemIDs) {
			if ($asKeys) {
				$results['keys'] = $itemIDs;
			}
			else {
				$results['items'] = Zotero_Items::get($libraryID, $itemIDs);
			}
		}
		
		if (!empty($deleteTempTable['tmpCreatedByUsers'])) {
			$sql = "DROP TEMPORARY TABLE IF EXISTS tmpCreatedByUsers$rnd";
			Zotero_DB::query($sql, false, $shardID);
		}
		if (!empty($deleteTempTable['tmpItemTypeNames'])) {
			$sql = "DROP TEMPORARY TABLE IF EXISTS tmpItemTypeNames$rnd";
			Zotero_DB::query($sql, false, $shardID);
		}
		
		return $results;
	}
	
	
	/**
	 * Store item in internal id-based cache
	 */
	public static function cache(Zotero_Item $item) {
		if (isset(self::$itemsByID[$item->id])) {
			Z_Core::debug("Item $item->id is already cached");
		}
		
		self::$itemsByID[$item->id] = $item;
	}
	
	
	public static function reload($libraryID, $itemIDs) {
		if (is_scalar($itemIDs)) {
			$itemIDs = array($itemIDs);
		}
		
		self::loadItems($libraryID, $itemIDs);
	}
	
	
	public static function getDataValuesFromXML(DOMDocument $doc) {
		$xpath = new DOMXPath($doc);
		$fields = $xpath->evaluate('//items/item/field');
		$vals = array();
		foreach ($fields as $f) {
			$vals[] = $f->firstChild->nodeValue;
		}
		$vals = array_unique($vals);
		return $vals;
	}
	
	
	public static function getLongDataValueFromXML(DOMDocument $doc) {
		$xpath = new DOMXPath($doc);
		$fields = $xpath->evaluate('//items/item/field[string-length(text()) > ' . self::$maxDataValueLength . ']');
		return $fields->length ? $fields->item(0) : false;
	}
	
	
	/**
	 * Converts a DOMElement item to a Zotero_Item object
	 *
	 * @param	DOMElement		$xml		Item data as DOMElement
	 * @return	Zotero_Item					Zotero item object
	 */
	public static function convertXMLToItem(DOMElement $xml) {
		// Get item type id, adding custom type if necessary
		$itemTypeName = $xml->getAttribute('itemType');
		$itemTypeID = Zotero_ItemTypes::getID($itemTypeName);
		if (!$itemTypeID) {
			$itemTypeID = Zotero_ItemTypes::addCustomType($itemTypeName);
		}
		
		// Primary fields
		$libraryID = (int) $xml->getAttribute('libraryID');
		$itemObj = self::getByLibraryAndKey($libraryID, $xml->getAttribute('key'));
		if (!$itemObj) {
			$itemObj = new Zotero_Item;
			$itemObj->libraryID = $libraryID;
			$itemObj->key = $xml->getAttribute('key');
		}
		$itemObj->setField('itemTypeID', $itemTypeID, false, true);
		$itemObj->setField('dateAdded', $xml->getAttribute('dateAdded'), false, true);
		$itemObj->setField('dateModified', $xml->getAttribute('dateModified'), false, true);
		
		$xmlFields = array();
		$xmlCreators = array();
		$xmlNote = null;
		$xmlPath = null;
		$xmlRelated = null;
		$childNodes = $xml->childNodes;
		foreach ($childNodes as $child) {
			switch ($child->nodeName) {
				case 'field':
					$xmlFields[] = $child;
					break;
				
				case 'creator':
					$xmlCreators[] = $child;
					break;
				
				case 'note':
					$xmlNote = $child;
					break;
				
				case 'path':
					$xmlPath = $child;
					break;
				
				case 'related':
					$xmlRelated = $child;
					break;
			}
		}
		
		// Item data
		$setFields = array();
		foreach ($xmlFields as $field) {
			// TODO: add custom fields
			
			$fieldName = $field->getAttribute('name');
			$itemObj->setField($fieldName, $field->nodeValue, false, true);
			$setFields[$fieldName] = true;
		}
		$previousFields = $itemObj->getUsedFields(true);
		
		foreach ($previousFields as $field) {
			if (!isset($setFields[$field])) {
				$itemObj->setField($field, false, false, true);
			}
		}
		
		$deleted = $xml->getAttribute('deleted');
		$itemObj->deleted = ($deleted == 'true' || $deleted == '1');
		
		// Creators
		$i = 0;
		foreach ($xmlCreators as $creator) {
			// TODO: add custom creator types
			
			$pos = (int) $creator->getAttribute('index');
			if ($pos != $i) {
				throw new Exception("No creator in position $i for item " . $itemObj->key);
			}
			
			$key = $creator->getAttribute('key');
			$creatorObj = Zotero_Creators::getByLibraryAndKey($libraryID, $key);
			// If creator doesn't exist locally (e.g., if it was deleted locally
			// and appears in a new/modified item remotely), get it from within
			// the item's creator block, where a copy should be provided
			if (!$creatorObj) {
				$subcreator = $creator->getElementsByTagName('creator')->item(0);
				if (!$subcreator) {
					throw new Exception("Data for missing local creator $key not provided", Z_ERROR_CREATOR_NOT_FOUND);
				}
				$creatorObj = Zotero_Creators::convertXMLToCreator($subcreator, $libraryID);
				if ($creatorObj->key != $key) {
					throw new Exception("Creator key " . $creatorObj->key .
						" does not match item creator key $key");
				}
			}
			$creatorTypeID = Zotero_CreatorTypes::getID($creator->getAttribute('creatorType'));
			$itemObj->setCreator($pos, $creatorObj, $creatorTypeID);
			$i++;
		}
		
		// Remove item's remaining creators not in XML
		$numCreators = $itemObj->numCreators();
		$rem = $numCreators - $i;
		for ($j=0; $j<$rem; $j++) {
			// Keep removing last creator
			$itemObj->removeCreator($i);
		}
		
		// Both notes and attachments might have parents and notes
		if ($itemTypeName == 'note' || $itemTypeName == 'attachment') {
			$sourceItemKey = $xml->getAttribute('sourceItem');
			$itemObj->setSource($sourceItemKey ? $sourceItemKey : false);
			$itemObj->setNote($xmlNote ? $xmlNote->nodeValue : "");
		}
		
		// Attachment metadata
		if ($itemTypeName == 'attachment') {
			$itemObj->attachmentLinkMode = (int) $xml->getAttribute('linkMode');
			$itemObj->attachmentMIMEType = $xml->getAttribute('mimeType');
			$itemObj->attachmentCharset = $xml->getAttribute('charset');
			$storageModTime = (int) $xml->getAttribute('storageModTime');
			$itemObj->attachmentStorageModTime = $storageModTime ? $storageModTime : null;
			$storageHash = $xml->getAttribute('storageHash');
			$itemObj->attachmentStorageHash = $storageHash ? $storageHash : null;
			$itemObj->attachmentPath = $xmlPath ? $xmlPath->nodeValue : "";
		}
		
		$related = $xmlRelated ? $xmlRelated->nodeValue : null;
		$relatedIDs = array();
		if ($related) {
			$related = explode(' ', $related);
			foreach ($related as $key) {
				$relItem = Zotero_Items::getByLibraryAndKey($itemObj->libraryID, $key, 'items'); // TODO:
				if (!$relItem) {
					throw new Exception("Related item $itemObj->libraryID/$key
						doesn't exist in Zotero.Sync.Server.Data.xmlToItem()");
				}
				$relatedIDs[] = $relItem->id;
			}
		}
		$itemObj->relatedItems = $relatedIDs;
		return $itemObj;
	}
	
	
	/**
	 * Temporarily remove and store related items that don't
	 * yet exist
	 *
	 * @param	DOMElement		$xmlElement
	 * @return	array
	 */
	public static function removeMissingRelatedItems(DOMElement $xmlElement) {
		$missing = array();
		$related = $xmlElement->getElementsByTagName('related')->item(0);
		if ($related && $related->nodeValue) {
			$relKeys = explode(' ', $related->nodeValue);
			$exist = array();
			$missing = array();
			foreach ($relKeys as $key) {
				$item = Zotero_Items::getByLibraryAndKey((int) $xmlElement->getAttribute('libraryID'), $key);
				if ($item) {
					$exist[] = $key;
				}
				else {
					$missing[] = $key;
				}
			}
			$related->nodeValue = implode(' ', $exist);
		}
		return $missing;
	}
	
	
	/**
	 * Converts a Zotero_Item object to a SimpleXMLElement item
	 *
	 * @param	object				$item		Zotero_Item object
	 * @param	array				$data
	 * @return	SimpleXMLElement					Item data as SimpleXML element
	 */
	public static function convertItemToXML(Zotero_Item $item, $data=array(), $apiVersion=null) {
		$xml = new SimpleXMLElement('<item/>');
		
		// Primary fields
		foreach (Zotero_Items::$primaryFields as $field) {
			switch ($field) {
				case 'itemID':
				case 'serverDateModified':
				case 'numAttachments':
				case 'numNotes':
					continue (2);
				
				case 'itemTypeID':
					$xmlField = 'itemType';
					$xmlValue = Zotero_ItemTypes::getName($item->$field);
					break;
				
				default:
					$xmlField = $field;
					$xmlValue = $item->$field;
			}
			
			$xml[$xmlField] = $xmlValue;
		}
		
		// Item data
		$fieldIDs = $item->getUsedFields();
		foreach ($fieldIDs as $fieldID) {
			$val = $item->getField($fieldID);
			if ($val == '') {
				continue;
			}
			$f = $xml->addChild('field', htmlspecialchars($val));
			$f['name'] = htmlspecialchars(Zotero_ItemFields::getName($fieldID));
		}
		
		// Deleted item flag
		if ($item->deleted) {
			$xml['deleted'] = '1';
		}
		
		if ($item->isNote() || $item->isAttachment()) {
			$sourceItemID = $item->getSource();
			if ($sourceItemID) {
				$sourceItem = Zotero_Items::get($item->libraryID, $sourceItemID);
				if (!$sourceItem) {
					throw new Exception("Source item $sourceItemID not found");
				}
				$xml['sourceItem'] = $sourceItem->key;
			}
		}
		
		// Group modification info
		$createdByUserID = null;
		$lastModifiedByUserID = null;
		switch (Zotero_Libraries::getType($item->libraryID)) {
			case 'group':
				$createdByUserID = $item->createdByUserID;
				$lastModifiedByUserID = $item->lastModifiedByUserID;
				break;
		}
		if ($createdByUserID) {
			$xml['createdByUserID'] = $createdByUserID;
		}
		if ($lastModifiedByUserID) {
			$xml['lastModifiedByUserID'] = $lastModifiedByUserID;
		}
		
		if ($item->isAttachment()) {
			$xml['linkMode'] = $item->attachmentLinkMode;
			$xml['mimeType'] = $item->attachmentMIMEType;
			if ($apiVersion == 1 || $item->attachmentCharset) {
				$xml['charset'] = $item->attachmentCharset;
			}
			
			$storageModTime = $item->attachmentStorageModTime;
			if ($apiVersion > 1 && $storageModTime) {
				$xml['storageModTime'] = $storageModTime;
			}
			
			$storageHash = $item->attachmentStorageHash;
			if ($apiVersion > 1 && $storageHash) {
				$xml['storageHash'] = $storageHash;
			}
			
			// TODO: get from a constant
			if ($item->attachmentLinkMode != 3) {
				$xml->addChild('path', htmlspecialchars($item->attachmentPath));
			}
		}
		
		// Note
		if ($item->isNote() || $item->isAttachment()) {
			// Get htmlspecialchars'ed note
			$note = $item->getNote(false, true);
			if ($note !== '') {
				$xml->addChild('note', $note);
			}
			else if ($item->isNote()) {
				$xml->addChild('note', '');
			}
		}
		
		// Creators
		$creators = $item->getCreators();
		if ($creators) {
			foreach ($creators as $index => $creator) {
				$c = $xml->addChild('creator');
				$c['key'] = $creator['ref']->key;
				$c['creatorType'] = htmlspecialchars(
					Zotero_CreatorTypes::getName($creator['creatorTypeID'])
				);
				$c['index'] = $index;
				if (empty($data['updatedCreators']) ||
						!in_array($creator['ref']->id, $data['updatedCreators'])) {
					$cNode = dom_import_simplexml($c);
					$creatorXML = Zotero_Creators::convertCreatorToXML($creator['ref'], $cNode->ownerDocument);
					$cNode->appendChild($creatorXML);
				}
			}
		}
		
		// Related items
		$related = $item->relatedItems;
		if ($related) {
			$related = Zotero_Items::get($item->libraryID, $related);
			$keys = array();
			foreach ($related as $item) {
				$keys[] = $item->key;
			}
			if ($keys) {
				$xml->related = implode(' ', $keys);
			}
		}
		
		return $xml;
	}
	
	
	/**
	 * Converts a Zotero_Item object to a SimpleXMLElement Atom object
	 *
	 * @param	object				$item		Zotero_Item object
	 * @param	string				$content
	 * @return	SimpleXMLElement					Item data as SimpleXML element
	 */
	public static function convertItemToAtom(Zotero_Item $item, $queryParams, $apiVersion=null, $permissions=null) {
		$content = $queryParams['content'];
		$contentIsHTML = sizeOf($content) == 1 && $content[0] == 'html';
		$contentParamString = urlencode(implode(',', $content));
		$style = $queryParams['style'];
		
		$entry = '<entry xmlns="' . Zotero_Atom::$nsAtom . '" xmlns:zapi="' . Zotero_Atom::$nsZoteroAPI . '"/>';
		$xml = new SimpleXMLElement($entry);
		
		$title = $item->getDisplayTitle(true);
		$title = $title ? $title : '[Untitled]';
		$xml->title = $title;
		
		$author = $xml->addChild('author');
		$createdByUserID = null;
		switch (Zotero_Libraries::getType($item->libraryID)) {
			case 'group':
				$createdByUserID = $item->createdByUserID;
				break;
		}
		if ($createdByUserID) {
			$author->name = Zotero_Users::getUsername($createdByUserID);
			$author->uri = Zotero_URI::getUserURI($createdByUserID);
		}
		else {
			$author->name = Zotero_Libraries::getName($item->libraryID);
			$author->uri = Zotero_URI::getLibraryURI($item->libraryID);
		}
		
		$id = Zotero_URI::getItemURI($item);
		/*if (!$contentIsHTML) {
			$id .= "?content=$content";
		}*/
		$xml->id = $id;
		
		$xml->published = Zotero_Date::sqlToISO8601($item->getField('dateAdded'));
		$xml->updated = Zotero_Date::sqlToISO8601($item->getField('dateModified'));
		
		$link = $xml->addChild("link");
		$link['rel'] = "self";
		$link['type'] = "application/atom+xml";
		$href = Zotero_Atom::getItemURI($item);
		if (!$contentIsHTML) {
			$href .= "?content=$contentParamString";
		}
		$link['href'] = $href;
		
		$parent = $item->getSource();
		if ($parent) {
			// TODO: handle group items?
			$parentItem = Zotero_Items::get($item->libraryID, $parent);
			$link = $xml->addChild("link");
			$link['rel'] = "up";
			$link['type'] = "application/atom+xml";
			$href = Zotero_Atom::getItemURI($parentItem);
			if (!$contentIsHTML) {
				$href .= "?content=$contentParamString";
			}
			$link['href'] = $href;
		}
		
		$link = $xml->addChild('link');
		$link['rel'] = 'alternate';
		$link['type'] = 'text/html';
		$link['href'] = Zotero_URI::getItemURI($item);
		
		// If appropriate permissions and the file is stored in ZFS, get file request link
		if ($permissions && $permissions->canAccess($item->libraryID, 'files')) {
			$details = Zotero_S3::getDownloadDetails($item);
			if ($details) {
				$link = $xml->addChild('link');
				$link['rel'] = 'enclosure';
				$type = $item->attachmentMIMEType;
				if ($type) {
					$link['type'] = $type;
				}
				$link['href'] = $details['url'];
				if (!empty($details['filename'])) {
					$link['title'] = $details['filename'];
				}
				if (!empty($details['size'])) {
					$link['length'] = $details['size'];
				}
			}
		}
		
		$xml->addChild('zapi:key', $item->key, Zotero_Atom::$nsZoteroAPI);
		$xml->addChild(
			'zapi:itemType',
			Zotero_ItemTypes::getName($item->itemTypeID),
			Zotero_Atom::$nsZoteroAPI
		);
		if ($item->isRegularItem()) {
			$val = $item->creatorSummary;
			if ($val !== '') {
				$xml->addChild(
					'zapi:creatorSummary',
					htmlspecialchars($val),
					Zotero_Atom::$nsZoteroAPI
				);
			}
			
			$val = substr($item->getField('date', true, true, true), 0, 4);
			if ($val !== '' && $val !== '0000') {
				$xml->addChild(
					'zapi:year',
					$val,
					Zotero_Atom::$nsZoteroAPI
				);
			}
		}
		if (!$parent && $item->isRegularItem()) {
			if ($permissions && !$permissions->canAccess($item->libraryID, 'notes')) {
				$numChildren = $item->numAttachments();
			}
			else {
				$numChildren = $item->numChildren();
			}
			
			$xml->addChild(
				'zapi:numChildren',
				$numChildren,
				Zotero_Atom::$nsZoteroAPI
			);
		}
		$xml->addChild(
			'zapi:numTags',
			$item->numTags(),
			Zotero_Atom::$nsZoteroAPI
		);
		
		$xml->content = '';
		
		//
		// DOM XML from here on out
		//
		
		$contentNode = dom_import_simplexml($xml->content);
		$domDoc = $contentNode->ownerDocument;
		$multiFormat = sizeOf($content) > 1;
		
		// Create a root XML document for multi-format responses
		if ($multiFormat) {
			$contentNode->setAttribute('type', 'application/xml');
			/*$multicontent = $domDoc->createElementNS(
				Zotero_Atom::$nsZoteroAPI, 'multicontent'
			);
			$contentNode->appendChild($multicontent);*/
		}
		
		foreach ($content as $type) {
			// Set the target to either the main <content>
			// or a <multicontent> <content>
			if (!$multiFormat) {
				$target = $contentNode;
			}
			else {
				$target = $domDoc->createElementNS(
					Zotero_Atom::$nsZoteroAPI, 'subcontent'
				);
				$contentNode->appendChild($target);
			}
			
			$target->setAttributeNS(
				Zotero_Atom::$nsZoteroAPI,
				"zapi:type",
				$type
			);
			
			if ($type == 'html') {
				if (!$multiFormat) {
					$target->setAttribute('type', 'xhtml');
				}
				$div = $domDoc->createElement('div');
				$div->setAttribute('xmlns', Zotero_Atom::$nsXHTML);
				$target->appendChild($div);
				$html = $item->toHTML(true);
				$subNode = dom_import_simplexml($html);
				$importedNode = $domDoc->importNode($subNode, true);
				$div->appendChild($importedNode);
			}
			else if ($type == 'citation') {
				if (!$multiFormat) {
					$target->setAttribute('type', 'xhtml');
				}
				$html = Zotero_Cite::getCitationFromCiteServer($item, $style);
				$html = new SimpleXMLElement($html);
				$html['xmlns'] = Zotero_Atom::$nsXHTML;
				$subNode = dom_import_simplexml($html);
				$importedNode = $domDoc->importNode($subNode, true);
				$target->appendChild($importedNode);
			}
			else if ($type == 'bib') {
				if (!$multiFormat) {
					$target->setAttribute('type', 'xhtml');
				}
				$html = Zotero_Cite::getBibliographyFromCitationServer(array($item), $style);
				$html = new SimpleXMLElement($html);
				$html['xmlns'] = Zotero_Atom::$nsXHTML;
				$subNode = dom_import_simplexml($html);
				$importedNode = $domDoc->importNode($subNode, true);
				$target->appendChild($importedNode);
			}
			else if ($type == 'json') {
				$target->setAttributeNS(
					Zotero_Atom::$nsZoteroAPI,
					"zapi:etag",
					$item->etag
				);
				$textNode = $domDoc->createTextNode($item->toJSON(false, $queryParams['pprint'], true));
				$target->appendChild($textNode);
			}
			else if ($type == 'csljson') {
				$arr = $item->toCSLItem();
				
				$mask = JSON_HEX_TAG|JSON_HEX_AMP;
				if ($queryParams['pprint']) {
					$json = Zotero_Utilities::json_encode_pretty($arr, $mask);
				}
				else {
					$json = json_encode($arr, $mask);
				}
				// Until JSON_UNESCAPED_SLASHES is available
				$json = str_replace('\\/', '/', $json);
				
				$textNode = $domDoc->createTextNode($json);
				$target->appendChild($textNode);
			}
			// Deprecated and not for public consumption
			else if ($type == 'full') {
				if (!$multiFormat) {
					$target->setAttribute('type', 'xhtml');
				}
				$fullXML = Zotero_Items::convertItemToXML($item, array(), $apiVersion);
				$fullXML->addAttribute("xmlns", Zotero_Atom::$nsZoteroTransfer);
				$subNode = dom_import_simplexml($fullXML);
				$importedNode = $domDoc->importNode($subNode, true);
				$target->appendChild($importedNode);
			}
			
			else if (in_array($type, Zotero_Translate::$exportFormats)) {
				$export = Zotero_Translate::getExportFromTranslationServer(array($item), $type);
				$target->setAttribute('type', $export['mimeType']);
				// Insert XML into document
				if (preg_match('/\+xml$/', $export['mimeType'])) {
					// Strip prolog
					$body = preg_replace('/^<\?xml.+\n/', "", $export['body']);
					$subNode = $domDoc->createDocumentFragment();
					$subNode->appendXML($body);
					$target->appendChild($subNode);
				}
				else {
					$textNode = $domDoc->createTextNode($export['body']);
					$target->appendChild($textNode);
				}
			}
		}
		
		return $xml;
	}
	
	
	/**
	 * Create new items from a decoded JSON object
	 */
	public static function addFromJSON($json, $libraryID, Zotero_Item $parentItem=null, $userID=null) {
		self::validateJSONItems($json, true);
		
		$keys = array();
		
		Zotero_DB::beginTransaction();
		
		// Mark library as updated
		$timestamp = Zotero_Libraries::updateTimestamps($libraryID);
		Zotero_DB::registerTransactionTimestamp($timestamp);
		
		foreach ($json->items as $jsonItem) {
			$item = new Zotero_Item;
			$item->libraryID = $libraryID;
			self::updateFromJSON($item, $jsonItem, true, $parentItem, $userID);
			$keys[] = $item->key;
		}
		
		Zotero_DB::commit();
		
		return $keys;
	}
	
	
	public static function updateFromJSON(Zotero_Item $item, $json, $isNew=false, Zotero_Item $parentItem=null, $userID=null) {
		self::validateJSONItem($json, $isNew, !is_null($parentItem));
		
		Zotero_DB::beginTransaction();
		
		// Mark library as updated
		if (!$isNew) {
			$timestamp = Zotero_Libraries::updateTimestamps($item->libraryID);
			Zotero_DB::registerTransactionTimestamp($timestamp);
		}
		
		$forceChange = false;
		$twoStage = false;
		
		// Set itemType first
		$item->setField("itemTypeID", Zotero_ItemTypes::getID($json->itemType));
		
		foreach ($json as $key=>$val) {
			switch ($key) {
				case 'itemType':
					continue;
				
				case 'deleted':
					continue;
				
				case 'creators':
					if (!$val && !$item->numCreators()) {
						continue 2;
					}
					
					$orderIndex = -1;
					
					foreach ($val as $orderIndex=>$newCreatorData) {
						if ((!isset($newCreatorData->name) || trim($newCreatorData->name) == "")
								&& (!isset($newCreatorData->firstName) || trim($newCreatorData->firstName) == "")
								&& (!isset($newCreatorData->lastName) || trim($newCreatorData->lastName) == "")) {
							// This should never happen, because of check in validateJSONItem()
							if (!$isNew) {
								throw new Exception("Nameless creator in update request");
							}
							// On item creation, ignore creators with empty names,
							// because that's in the item template that the API returns
							break;
						}
						
						// JSON uses 'name' and 'firstName'/'lastName',
						// so switch to just 'firstName'/'lastName'
						if (isset($newCreatorData->name)) {
							$newCreatorData->firstName = '';
							$newCreatorData->lastName = $newCreatorData->name;
							unset($newCreatorData->name);
							$newCreatorData->fieldMode = 1;
						}
						else {
							$newCreatorData->fieldMode = 0;
						}
						
						$newCreatorTypeID = Zotero_CreatorTypes::getID($newCreatorData->creatorType);
						
						// Same creator in this position
						$existingCreator = $item->getCreator($orderIndex);
						if ($existingCreator && $existingCreator['ref']->equals($newCreatorData)) {
							// Just change the creatorTypeID
							if ($existingCreator['creatorTypeID'] != $newCreatorTypeID) {
								$item->setCreator($orderIndex, $existingCreator['ref'], $newCreatorTypeID);
							}
							continue;
						}
						
						// Same creator in a different position, so use that
						$existingCreators = $item->getCreators();
						for ($i=0,$len=sizeOf($existingCreators); $i<$len; $i++) {
							if ($existingCreators[$i]['ref']->equals($newCreatorData)) {
								$item->setCreator($orderIndex, $existingCreators[$i]['ref'], $newCreatorTypeID);
								continue;
							}
						}
						
						// Make a fake creator to use for the data lookup
						$newCreator = new Zotero_Creator;
						$newCreator->libraryID = $item->libraryID;
						foreach ($newCreatorData as $key=>$val) {
							if ($key == 'creatorType') {
								continue;
							}
							$newCreator->$key = $val;
						}
						
						// Look for an equivalent creator in this library
						$candidates = Zotero_Creators::getCreatorsWithData($item->libraryID, $newCreator, true);
						if ($candidates) {
							$c = Zotero_Creators::get($item->libraryID, $candidates[0]);
							$item->setCreator($orderIndex, $c, $newCreatorTypeID);
							continue;
						}
						
						// None found, so make a new one
						$creatorID = $newCreator->save();
						$newCreator = Zotero_Creators::get($item->libraryID, $creatorID);
						$item->setCreator($orderIndex, $newCreator, $newCreatorTypeID);
					}
					
					// Remove all existing creators above the current index
					if (!$isNew && $indexes = array_keys($item->getCreators())) {
						$i = max($indexes);
						while ($i>$orderIndex) {
							$item->removeCreator($i);
							$i--;
						}
					}
					
					break;
				
				case 'tags':
					// If item isn't yet saved, add tags below
					if (!$item->id) {
						$twoStage = true;
						break;
					}
					
					if ($item->setTags($val)) {
						$forceChange = true;
					}
					break;
				
				case 'notes':
					if (!$val) {
						continue;
					}
					$twoStage = true;
					break;
				
				case 'note':
					$item->setNote($val);
					break;
				
				default:
					$item->setField($key, $val);
					break;
			}
		}
		
		if ($parentItem) {
			$item->setSource($parentItem->id);
		}
		
		$item->deleted = !empty($json->deleted);
		
		// For changes that don't register as changes internally, force a dateModified update
		if ($forceChange) {
			$item->setField('dateModified', Zotero_DB::getTransactionTimestamp());
		}
		$item->save($userID);
		
		// Additional steps that have to be performed on a saved object
		if ($twoStage) {
			foreach ($json as $key=>$val) {
				switch ($key) {
					case 'notes':
						if (!$val) {
							continue;
						}
						$noteItemTypeID = Zotero_ItemTypes::getID("note");
						
						// Save child notes
						foreach ($val as $note) {
							$childItem = new Zotero_Item;
							$childItem->libraryID = $item->libraryID;
							$childItem->itemTypeID = $noteItemTypeID;
							$childItem->setSource($item->id);
							$childItem->setNote($note->note);
							$childItem->save();
						}
						break;
					
					case 'tags':
						if ($item->setTags($val)) {
							$forceChange = true;
						}
						break;
				}
			}
			
			// For changes that don't register as changes internally, force a dateModified update
			if ($forceChange) {
				$item->setField('dateModified', Zotero_DB::getTransactionTimestamp());
			}
			$item->save($userID);
		}
		
		Zotero_DB::commit();
	}
	
	
	public static function validateJSONItem($json, $isNew=false, $isChild=false) {
		if (!is_object($json)) {
			throw new Exception('$json must be a decoded JSON object', Z_ERROR_INVALID_INPUT);
		}
		
		if (isset($json->items) && is_array($json->items)) {
			throw new Exception("An 'items' array is not valid for item updates", Z_ERROR_INVALID_INPUT);
		}
		
		if ($isNew) {
			$requiredProps = array('itemType');
		}
		else {
			$requiredProps = array('itemType', 'creators', 'tags');
		}
		
		foreach ($requiredProps as $prop) {
			if (!isset($json->$prop)) {
				throw new Exception("'$prop' property not provided", Z_ERROR_INVALID_INPUT);
			}
		}
		
		foreach ($json as $key=>$val) {
			switch ($key) {
				case 'itemType':
					if (!is_string($val)) {
						throw new Exception("'itemType' must be a string", Z_ERROR_INVALID_INPUT);
					}
					
					if ($isChild) {
						switch ($val) {
							case 'note':
							case 'attachment':
								break;
							
							default:
								throw new Exception("Child item must be note or attachment", Z_ERROR_INVALID_INPUT);
						}
					}
					
					if (!Zotero_ItemTypes::getID($val)) {
						throw new Exception("'$val' is not a valid itemType", Z_ERROR_INVALID_INPUT);
					}
					break;
					
				case 'tags':
					if (!is_array($val)) {
						throw new Exception("'tags' property must be an array", Z_ERROR_INVALID_INPUT);
					}
					
					foreach ($val as $tag) {
						$empty = true;
						
						foreach ($tag as $k=>$v) {
							switch ($k) {
								case 'tag':
									if (!is_scalar($v)) {
										throw new Exception("Invalid tag name", Z_ERROR_INVALID_INPUT);
									}
									break;
									
								case 'type':
									if (!is_numeric($v)) {
										throw new Exception("Invalid tag type '$v'", Z_ERROR_INVALID_INPUT);
									}
									break;
								
								default:
									throw new Exception("Invalid tag property '$k'", Z_ERROR_INVALID_INPUT);
							}
							
							$empty = false;
						}
						
						if ($empty) {
							throw new Exception("Tag object is empty", Z_ERROR_INVALID_INPUT);
						}
					}
					break;
					
				case 'creators':
					if (!is_array($val)) {
						throw new Exception("'creators' property must be an array", Z_ERROR_INVALID_INPUT);
					}
					
					foreach ($val as $creator) {
						$empty = true;
						
						if (!isset($creator->creatorType)) {
							throw new Exception("creator object must contain 'creatorType'", Z_ERROR_INVALID_INPUT);
						}
						
						if ((!isset($creator->name) || trim($creator->name) == "")
								&& (!isset($creator->firstName) || trim($creator->firstName) == "")
								&& (!isset($creator->lastName) || trim($creator->lastName) == "")) {
							// On item creation, ignore single nameless creator,
							// because that's in the item template that the API returns
							if (sizeOf($val) == 1 && $isNew) {
								continue;
							}
							else {
								throw new Exception("creator object must contain 'firstName'/'lastName' or 'name'", Z_ERROR_INVALID_INPUT);
							}
						}
						
						foreach ($creator as $k=>$v) {
							switch ($k) {
								case 'creatorType':
									$creatorTypeID = Zotero_CreatorTypes::getID($v);
									if (!$creatorTypeID) {
										throw new Exception("'$v' is not a valid creator type", Z_ERROR_INVALID_INPUT);
									}
									$itemTypeID = Zotero_ItemTypes::getID($json->itemType);
									if (!Zotero_CreatorTypes::isValidForItemType($creatorTypeID, $itemTypeID)) {
										throw new Exception("'$v' is not a valid creator type for item type '" . $json->itemType . "'", Z_ERROR_INVALID_INPUT);
									}
									break;
								
								case 'firstName':
									if (!isset($creator->lastName)) {
										throw new Exception("'lastName' creator field must be set if 'firstName' is set", Z_ERROR_INVALID_INPUT);
									}
									if (isset($creator->name)) {
										throw new Exception("'firstName' and 'name' creator fields are mutually exclusive", Z_ERROR_INVALID_INPUT);
									}
									break;
								
								case 'lastName':
									if (!isset($creator->firstName)) {
										throw new Exception("'firstName' creator field must be set if 'lastName' is set", Z_ERROR_INVALID_INPUT);
									}
									if (isset($creator->name)) {
										throw new Exception("'lastName' and 'name' creator fields are mutually exclusive", Z_ERROR_INVALID_INPUT);
									}
									break;
								
								case 'name':
									if (isset($creator->firstName)) {
										throw new Exception("'firstName' and 'name' creator fields are mutually exclusive", Z_ERROR_INVALID_INPUT);
									}
									if (isset($creator->lastName)) {
										throw new Exception("'lastName' and 'name' creator fields are mutually exclusive", Z_ERROR_INVALID_INPUT);
									}
									break;
								
								default:
									throw new Exception("Invalid creator property '$k'", Z_ERROR_INVALID_INPUT);
							}
							
							$empty = false;
						}
						
						if ($empty) {
							throw new Exception("Creator object is empty", Z_ERROR_INVALID_INPUT);
						}
					}
					break;
				
				case 'note':
					switch ($json->itemType) {
						case 'note':
						case 'attachment':
							break;
						
						default:
							throw new Exception("'note' property is valid only for note and attachment items", Z_ERROR_INVALID_INPUT);
					}
					break;
				
				case 'notes':
					if (!$isNew) {
						throw new Exception("'notes' property is valid only for new items", Z_ERROR_INVALID_INPUT);
					}
					
					if (!is_array($val)) {
						throw new Exception("'notes' property must be an array", Z_ERROR_INVALID_INPUT);
					}
					
					foreach ($val as $note) {
						if (isset($note->itemType) && $note->itemType != 'note') {
							throw new Exception("Child note must be of itemType 'note'", Z_ERROR_INVALID_INPUT);
						}
						if (!isset($note->note)) {
							throw new Exception("'note' property not provided for child note", Z_ERROR_INVALID_INPUT);
						}
					}
					break;
				
				case 'deleted':
					break;
				
				default:
					if (is_array($val)) {
						throw new Exception("Unexpected array for property '$key'", Z_ERROR_INVALID_INPUT);
					}
					
					if (!Zotero_ItemFields::getID($key)) {
						throw new Exception("'$key' is not a valid item field", Z_ERROR_INVALID_INPUT);
					}
					
					break;
			}
		}
	}
	
	
	public static function validateJSONItems($json) {
		if (!is_object($json)) {
			throw new Exception('$json must be a decoded JSON object', Z_ERROR_INVALID_INPUT);
		}
		
		foreach ($json as $key=>$val) {
			if ($key != 'items') {
				throw new Exception("Invalid property '$key'", Z_ERROR_INVALID_INPUT);
			}
			if (sizeOf($val) > Zotero_API::$maxWriteItems) {
				throw new Exception("Cannot add more than " . Zotero_API::$maxWriteItems . " items at a time", Z_ERROR_UPLOAD_TOO_LARGE);
			}
		}
	}
	
	
	private static function loadItems($libraryID, $itemIDs=array()) {
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		
		$sql = 'SELECT I.*,
				(SELECT COUNT(*) FROM itemNotes INo
					WHERE sourceItemID=I.itemID AND INo.itemID NOT IN
					(SELECT itemID FROM deletedItems)) AS numNotes,
				(SELECT COUNT(*) FROM itemAttachments IA
					WHERE sourceItemID=I.itemID AND IA.itemID NOT IN
					(SELECT itemID FROM deletedItems)) AS numAttachments	
			FROM items I WHERE 1';
		
		// TODO: optimize
		if ($itemIDs) {
			foreach ($itemIDs as $itemID) {
				if (!is_int($itemID)) {
					throw new Exception("Invalid itemID $itemID");
				}
			}
			$sql .= ' AND I.itemID IN ('
					. implode(',', array_fill(0, sizeOf($itemIDs), '?'))
					. ')';
		}
		
		$stmt = Zotero_DB::getStatement($sql, "loadItems_" . sizeOf($itemIDs), $shardID);
		$itemRows = Zotero_DB::queryFromStatement($stmt, $itemIDs);
		$loadedItemIDs = array();
		
		if ($itemRows) {
			foreach ($itemRows as $row) {
				if ($row['libraryID'] != $libraryID) {
					throw new Exception("Item $itemID isn't in library $libraryID", Z_ERROR_OBJECT_LIBRARY_MISMATCH);
				}
				
				$itemID = $row['itemID'];
				$loadedItemIDs[] = $itemID;
				
				// Item isn't loaded -- create new object and stuff in array
				if (!isset(self::$itemsByID[$itemID])) {
					$item = new Zotero_Item;
					$item->loadFromRow($row, true);
					self::$itemsByID[$itemID] = $item;
				}
				// Existing item -- reload in place
				else {
					self::$itemsByID[$itemID]->loadFromRow($row, true);
				}
			}
		}
		
		if (!$itemIDs) {
			// If loading all items, remove old items that no longer exist
			$ids = array_keys(self::$itemsByID);
			foreach ($ids as $id) {
				if (!in_array($id, $loadedItemIDs)) {
					throw new Exception("Unimplemented");
					//$this->unload($id);
				}
			}
			
			/*
			_cachedFields = ['itemID', 'itemTypeID', 'dateAdded', 'dateModified',
				'numNotes', 'numAttachments', 'numChildren'];
			*/
			//this._reloadCache = false;
		}
	}
	
	
	public static function getSortTitle($title) {
		if (!$title) {
			return '';
		}
		return mb_strcut(preg_replace('/^[[({\-"\'“‘ ]+(.*)[\])}\-"\'”’ ]*?$/Uu', '$1', $title), 0, Zotero_Notes::$MAX_TITLE_LENGTH);
	}
}
?>
