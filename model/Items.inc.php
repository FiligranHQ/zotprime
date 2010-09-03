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
	
	private static $items = array();
	
	public static $primaryFields = array('itemID', 'libraryID', 'key', 'itemTypeID',
		'dateAdded', 'dateModified', 'serverDateModified',
		'firstCreator', 'numNotes', 'numAttachments');
	private static $maxDataValueLength = 65535;
	
	public static $dataValueIDCache = array();
	
	public static $dataValueIDsByHash = array();
	public static $dataValuesByID = array();
	
	public static function get($itemIDs) {
		$numArgs = func_num_args();
		if ($numArgs>1) {
			throw new Exception("Constructor takes only one parameter");
		}
		
		if (!$itemIDs) {
			throw new Exception("itemIDs is not provided");
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
			if (!isset(self::$items[$itemID])) {
				array_push($toLoad, $itemID);
			}
		}
		
		if ($toLoad) {
			self::loadItems($toLoad);
		}
		
		$loaded = array();
		
		foreach ($itemIDs as $itemID) {
			if (!isset(self::$items[$itemID])) {
				Z_Core::debug("Item $itemID doesn't exist");
				continue;
			}
			$loaded[] = self::$items[$itemID];
		}
		
		if ($single) {
			return !empty($loaded) ? $loaded[0] : false;
		}
		
		return $loaded;
	}
	
	
	public static function exist($itemIDs) {
		$sql = "SELECT itemID FROM items WHERE itemID IN ("
			. implode(', ', array_fill(0, sizeOf($itemIDs), '?')) . ")";
		$exist = Zotero_DB::columnQuery($sql, $itemIDs);
		return $exist ? $exist : array();
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
		$ids = Zotero_DB::columnQuery($sql, $libraryID);
		if (!$ids) {
			return array();
		}
		if ($asIDs) {
			return $ids;
		}
		return self::get($ids);
	}
	
	
	public static function getAllAdvanced($libraryID, $onlyTopLevel=false, $params, $includeTrashed=false) {
		$results = array('items' => array(), 'total' => 0);
		
		$sql = "SELECT SQL_CALC_FOUND_ROWS A.itemID FROM items A ";
		
		if ($onlyTopLevel) {
			$sql .= "LEFT JOIN itemNotes B USING (itemID)
						LEFT JOIN itemAttachments C ON (C.itemID=A.itemID) ";
		}
		$sql .= "WHERE A.libraryID=? ";
		$sqlParams = array($libraryID);
		
		if ($onlyTopLevel) {
			$sql .= "AND B.sourceItemID IS NULL AND C.sourceItemID IS NULL ";
		}
		if (!$includeTrashed) {
			$sql .= " AND A.itemID NOT IN (SELECT itemID FROM deletedItems) ";
		}
		
		if (!empty($params['fq'])) {
			if (!is_array($params['fq'])) {
				$params['fq'] = array($params['fq']);
			}
			foreach ($params['fq'] as $fq) {
				$facet = split(":", $fq);
				if (sizeOf($facet) == 2 && preg_match('/-?Tag/', $facet[0])) {
					$tagIDs = Zotero_Tags::getIDs($libraryID, $facet[1]);
					if (!$tagIDs) {
						throw new Exception("Tag '{$facet[1]}' not found", Z_ERROR_TAG_NOT_FOUND);
					}
					
					$sql .= "AND itemID ";
					// If first character is '-', negate
					$sql .= ($facet[0][0] == '-' ? 'NOT ' : '');
					$sql .= "IN (SELECT itemID FROM itemTags WHERE tagID IN (";
					$func = create_function('', "return '?';");
					$sql .= implode(',', array_map($func, $tagIDs)) . ")) ";
					$sqlParams = array_merge($sqlParams, $tagIDs);
				}
			}
		}
		
		$sql .= "ORDER BY ";
		if (!empty($params['order'])) {
			$sql .= $params['order'];
			if (!empty($params['sort'])) {
				$sql .= " " . $params['sort'] . ", ";
			}
		}
		$sql .= "itemID " . $params['sort'] . " ";
		
		if (!empty($params['limit'])) {
			$sql .= "LIMIT ?, ?";
			$sqlParams[] = $params['start'] ? $params['start'] : 0;
			$sqlParams[] = $params['limit'];
		}
		$itemIDs = Zotero_DB::columnQuery($sql, $sqlParams);
		
		if ($itemIDs) {
			$results['total'] = Zotero_DB::valueQuery("SELECT FOUND_ROWS()");
			$results['items'] = Zotero_Items::get($itemIDs);
		}
		
		return $results;
	}
	
	/*
	 * Generate SQL to retrieve firstCreator field
	 *
	 * Why do we do this entirely in SQL? Because we're crazy. Crazy like foxes.
	 */
	public static function getFirstCreatorSQL() {
		// TODO: memcache keyed with localizedAnd
		
		/* This whole block is to get the firstCreator */
		$localizedAnd = 'and';
		$sql = "COALESCE(" .
			// First try for primary creator types
			"CASE (" .
				"SELECT COUNT(*) FROM itemCreators IC " .
				"LEFT JOIN itemTypeCreatorTypes ITCT " .
				"ON (IC.creatorTypeID=ITCT.creatorTypeID) " .
				"WHERE itemID=I.itemID AND primaryField=1 " .
				"AND ITCT.itemTypeID=I.itemTypeID" .
			") " .
			"WHEN 0 THEN NULL " .
			"WHEN 1 THEN (" .
				"SELECT lastName FROM itemCreators IC NATURAL JOIN creators " .
				"NATURAL JOIN creatorData " .
				"LEFT JOIN itemTypeCreatorTypes ITCT " .
				"ON (IC.creatorTypeID=ITCT.creatorTypeID) " .
				"WHERE itemID=I.itemID AND primaryField=1 " .
				"AND ITCT.itemTypeID=I.itemTypeID" .
			") " .
			"WHEN 2 THEN (" .
				"SELECT CONCAT(" .
				"(SELECT lastName FROM itemCreators IC NATURAL JOIN creators " .
				"NATURAL JOIN creatorData " .
				"LEFT JOIN itemTypeCreatorTypes ITCT " .
				"ON (IC.creatorTypeID=ITCT.creatorTypeID) " .
				"WHERE itemID=I.itemID AND primaryField=1 AND ITCT.itemTypeID=I.itemTypeID " .
				"ORDER BY orderIndex LIMIT 1)" .
				" , ' " . $localizedAnd . " ' , " .
				"(SELECT lastName FROM itemCreators IC NATURAL JOIN creators " .
				"NATURAL JOIN creatorData " .
				"LEFT JOIN itemTypeCreatorTypes ITCT " .
				"ON (IC.creatorTypeID=ITCT.creatorTypeID) " .
				"WHERE itemID=I.itemID AND primaryField=1 AND ITCT.itemTypeID=I.itemTypeID " .
				"ORDER BY orderIndex LIMIT 1,1))" .
			") " .
			"ELSE (" .
				"SELECT CONCAT(" .
				"(SELECT lastName FROM itemCreators IC NATURAL JOIN creators " .
				"NATURAL JOIN creatorData " .
				"LEFT JOIN itemTypeCreatorTypes ITCT " .
				"ON (IC.creatorTypeID=ITCT.creatorTypeID) " .
				"WHERE itemID=I.itemID AND primaryField=1 AND ITCT.itemTypeID=I.itemTypeID " .
				"ORDER BY orderIndex LIMIT 1)" .
				" , ' et al.' )" .
			") " .
			"END, " .
			
			// Then try editors
			"CASE (" .
				"SELECT COUNT(*) FROM itemCreators " .
				"NATURAL JOIN creatorTypes WHERE itemID=I.itemID AND creatorTypeID IN (3)" .
			") " .
			"WHEN 0 THEN NULL " .
			"WHEN 1 THEN (" .
				"SELECT lastName FROM itemCreators NATURAL JOIN creators " .
				"NATURAL JOIN creatorData " .
				"WHERE itemID=I.itemID AND creatorTypeID IN (3)" .
			") " .
			"WHEN 2 THEN (" .
				"SELECT CONCAT(" .
				"(SELECT lastName FROM itemCreators NATURAL JOIN creators NATURAL JOIN creatorData WHERE itemID=I.itemID AND creatorTypeID IN (3) ORDER BY orderIndex LIMIT 1)" .
				" , ' " . $localizedAnd . " ' , " .
				"(SELECT lastName FROM itemCreators NATURAL JOIN creators NATURAL JOIN creatorData WHERE itemID=I.itemID AND creatorTypeID IN (3) ORDER BY orderIndex LIMIT 1,1)) " .
			") " .
			"ELSE (" .
				"SELECT CONCAT(" .
				"(SELECT lastName FROM itemCreators NATURAL JOIN creators NATURAL JOIN creatorData WHERE itemID=I.itemID AND creatorTypeID IN (3) ORDER BY orderIndex LIMIT 1)" .
				" , ' et al.' )" .
			") " .
			"END, " .
			
			// Then try contributors
			"CASE (" .
				"SELECT COUNT(*) FROM itemCreators " .
				"NATURAL JOIN creatorTypes WHERE itemID=I.itemID AND creatorTypeID IN (2)" .
			") " .
			"WHEN 0 THEN NULL " .
			"WHEN 1 THEN (" .
				"SELECT lastName FROM itemCreators NATURAL JOIN creators " .
				"NATURAL JOIN creatorData " .
				"WHERE itemID=I.itemID AND creatorTypeID IN (2)" .
			") " .
			"WHEN 2 THEN (" .
				"SELECT CONCAT(" .
				"(SELECT lastName FROM itemCreators NATURAL JOIN creators NATURAL JOIN creatorData WHERE itemID=I.itemID AND creatorTypeID IN (2) ORDER BY orderIndex LIMIT 1)" .
				" , ' " . $localizedAnd . " ' , " .
				"(SELECT lastName FROM itemCreators NATURAL JOIN creators NATURAL JOIN creatorData WHERE itemID=I.itemID AND creatorTypeID IN (2) ORDER BY orderIndex LIMIT 1,1)) " .
			") " .
			"ELSE (" .
				"SELECT CONCAT(" .
				"(SELECT lastName FROM itemCreators NATURAL JOIN creators NATURAL JOIN creatorData WHERE itemID=I.itemID AND creatorTypeID IN (2) ORDER BY orderIndex LIMIT 1)" .
				" , ' et al.' )" .
			") " .
			"END" .
		") AS firstCreator";
		
		return $sql;
	}
	
	
	public static function getDataValueID($value, $create=false) {
		if ($value == '') {
			throw new Exception("Data value cannot be empty");
		}
		
		$hash = self::getHash($value);
		$key = self::getDataValueIDCacheKey($value, $hash);
		
		// Check local cache
		if (isset(self::$dataValueIDsByHash[$hash])) {
			return self::$dataValueIDsByHash[$hash];
		}
		
		// Check memcache
		$id = Z_Core::$MC->get($key);
		
		if ($id) {
			self::$dataValueIDsByHash[$hash] = $id;
			return $id;
		}
		
		Zotero_DB::beginTransaction();
		
		$sql = "SELECT itemDataValueID FROM itemDataValues WHERE value=?";
		$id = Zotero_DB::valueQuery($sql, $value);
		
		if (!$id && $create) {
			$id = Zotero_ID::get('itemDataValues');
			
			$sql = "INSERT INTO itemDataValues VALUES (?,?)";
			$stmt = Zotero_DB::getStatement($sql, "getDataValueID_insert");
			$insertID = Zotero_DB::queryFromStatement($stmt, array($id, $value));
			if (!$id) {
				$id = $insertID;
			}
		}
		
		Zotero_DB::commit();
		
		// Store in local cache and memcache
		if ($id) {
			// Cache data value id
			self::$dataValueIDsByHash[$hash] = $id;
			Z_Core::$MC->set($key, $id);
			
			// Cache data value
			self::$dataValuesByID[$id] = $value;
			$key = self::getDataValueCacheKey($id);
			Z_Core::$MC->add($key, $value);
		}
		
		return $id;
	}
	
	
	public static function bulkInsertDataValues($values) {
		$insertValues = array();
		
		foreach ($values as $value) {
			$hash = self::getHash($value);
			$key = self::getDataValueIDCacheKey($value, $hash);
			$id = Z_Core::$MC->get($key);
			if ($id) {
				self::$dataValueIDsByHash[$hash] = $id;
				self::$dataValuesByID[$id] = $value;
			}
			else {
				$insertValues[] = $value;
			}
		}
		
		if (!$insertValues) {
			return;
		}
		
		try {
			Zotero_DB::beginTransaction();
			Zotero_DB::query("CREATE TEMPORARY TABLE IF NOT EXISTS tmpItemDataValues (value TEXT CHARACTER SET utf8 COLLATE utf8_bin NOT NULL, KEY value (value(50)))");
			Zotero_DB::bulkInsert("INSERT IGNORE INTO tmpItemDataValues VALUES ", $insertValues, 50);
			$joinSQL = "FROM tmpItemDataValues TIDV JOIN itemDataValues IDV USING (value)";
			// Delete data rows that already exist
			Zotero_DB::query("DELETE TIDV " . $joinSQL);
			Zotero_DB::query("INSERT IGNORE INTO itemDataValues SELECT NULL, value FROM tmpItemDataValues");
			$rows = Zotero_DB::query("SELECT IDV.* " . $joinSQL);
			Zotero_DB::query("DROP TEMPORARY TABLE tmpItemDataValues");
			Zotero_DB::commit();
		}
		catch (Exception $e) {
			Zotero_DB::rollback();
			throw ($e);
		}
		
		foreach ($rows as $row) {
			$value = $row['value'];
			$id = $row['itemDataValueID'];
			$hash = self::getHash($value);
			
			// Cache data value id
			self::$dataValueIDsByHash[$hash] = $id;
			$key = self::getDataValueIDCacheKey($value, $hash);
			Z_Core::$MC->set($key, $id);
			
			// Cache data value
			self::$dataValuesByID[$id] = $value;
			$key = self::getDataValueCacheKey($id);
			Z_Core::$MC->add($key, $value);
		}
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
	 * Converts a SimpleXMLElement item to a Zotero_Item object
	 *
	 * @param	SimpleXMLElement	$xml		Item data as SimpleXML element
	 * @return	Zotero_Item					Zotero item object
	 */
/*	public static function convertXMLToItem(SimpleXMLElement $xml) {
		// Get item type id, adding custom type if necessary
		$itemTypeName = (string) $xml['itemType'];
		$itemTypeID = Zotero_ItemTypes::getID($itemTypeName);
		if (!$itemTypeID) {
			$itemTypeID = Zotero_ItemTypes::addCustomType($itemTypeName);
		}
		
		// Primary fields
		$itemObj = new Zotero_Item;
		$libraryID = (int) $xml['libraryID'];
		$itemObj->setField('libraryID', $libraryID);
		$itemObj->setField('key', (string) $xml['key'], false, true);
		$itemObj->setField('itemTypeID', $itemTypeID, false, true);
		$itemObj->setField('dateAdded', (string) $xml['dateAdded'], false, true);
		$itemObj->setField('dateModified', (string) $xml['dateModified'], false, true);
		
		// Item data
		$setFields = array();
		foreach ($xml->field as $field) {
			// TODO: add custom fields
			
			$fieldName = (string) $field['name'];
			$itemObj->setField($fieldName, (string) $field, false, true);
			$setFields[$fieldName] = true;
		}
		$previousFields = $itemObj->getUsedFields(true);
		
		foreach ($previousFields as $field) {
			if (!isset($setFields[$field])) {
				$itemObj->setField($field, false, false, true);
			}
		}
		
		$deleted = (string) $xml['deleted'];
		$itemObj->deleted = ($deleted == 'true' || $deleted == '1');
		
		// Creators
		$i = 0;
		foreach ($xml->creator as $creator) {
			// TODO: add custom creator types
			
			$pos = (int) $creator['index'];
			if ($pos != $i) {
				throw new Exception("No creator in position $i");
			}
			
			$key = (string) $creator['key'];
			$creatorObj = Zotero_Creators::getByLibraryAndKey($libraryID, $key);
			// If creator doesn't exist locally (e.g., if it was deleted locally
			// and appears in a new/modified item remotely), get it from within
			// the item's creator block, where a copy should be provided
			if (!$creatorObj) {
				if (!$creator->creator) {
					throw new Exception("Data for missing local creator $key not provided", Z_ERROR_CREATOR_NOT_FOUND);
				}
				$creatorObj = Zotero_Creators::convertXMLToCreator($creator->creator, $libraryID);
				if ($creatorObj->key != $key) {
					throw new Exception("Creator key " . $creatorObj->key .
						" does not match item creator key $key");
				}
			}
			$creatorTypeID = Zotero_CreatorTypes::getID((string) $creator['creatorType']);
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
			$sourceItemKey = (string) $xml['sourceItem'];
			// Workaround for bug in 2.0b6.2
			if ($sourceItemKey == "undefined") {
				$sourceItemKey = false;
			}
			$itemObj->setSource($sourceItemKey ? $sourceItemKey : false);
			$itemObj->setNote((string) $xml->note);
		}
		
		// Attachment metadata
		if ($itemTypeName == 'attachment') {
			$itemObj->attachmentLinkMode = (int) $xml['linkMode'];
			$itemObj->attachmentMIMEType = (string) $xml['mimeType'];
			$itemObj->attachmentCharset = (string) $xml['charset'];
			$storageModTime = (int) $xml['storageModTime'];
			$itemObj->attachmentStorageModTime = $storageModTime ? $storageModTime : null;
			$storageHash = (string) $xml['storageHash'];
			$itemObj->attachmentStorageHash = $storageHash ? $storageHash : null;
			$itemObj->attachmentPath = (string) $xml->path;
		}
		
		$related = (string) $xml->related;
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
*/	
	
	
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
		$itemObj = new Zotero_Item;
		$libraryID = (int) $xml->getAttribute('libraryID');
		$itemObj->setField('libraryID', $libraryID);
		$itemObj->setField('key', $xml->getAttribute('key'), false, true);
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
				throw new Exception("No creator in position $i");
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
	 * @param	SimpleXMLElement	$xmlElement
	 * @return	array
	 */
/*	public static function removeMissingRelatedItems(SimpleXMLElement $xmlElement) {
		$missing = array();
		if (!empty($xmlElement->related)) {
			$relKeys = explode(' ', $xmlElement->related);
			$exist = array();
			$missing = array();
			foreach ($relKeys as $key) {
				$item = Zotero_Items::getByLibraryAndKey((int) $xmlElement['libraryID'], $key);
				if ($item) {
					$exist[] = $key;
				}
				else {
					$missing[] = $key;
				}
			}
			$xmlElement->related = implode(' ', $exist);
		}
		return $missing;
	}
*/	
	
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
				case 'firstCreator':
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
				$sourceItem = Zotero_Items::get($sourceItemID);
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
		
		// Note
		if ($item->isNote()) {
			$xml->addChild('note', htmlspecialchars($item->getNote()));
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
			
			$note = $item->getNote();
			if ($note) {
				$xml->addChild('note', htmlspecialchars($note));
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
					$creatorXML = Zotero_Creators::convertCreatorToXML($creator['ref'], $cNode->ownerDocument);
					$cNode = dom_import_simplexml($c);
					$cNode->appendChild($creatorXML);
				}
			}
		}
		
		// Related items
		$related = $item->relatedItems;
		if ($related) {
			$xml->addChild('related', implode(' ', $related));
		}
		
		// Related items
		$related = $item->relatedItems;
		if ($related) {
			$related = Zotero_Items::get($related);
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
	public static function convertItemToAtom(Zotero_Item $item, $content='none', $apiVersion=null) {
		$entry = '<entry xmlns="' . Zotero_Atom::$nsAtom . '" xmlns:zapi="' . Zotero_Atom::$nsZoteroAPI . '"/>';
		$xml = new SimpleXMLElement($entry);
		
		$title = $item->getDisplayTitle(true);
		$title = $title ? $title : '[Untitled]';
		// Strip HTML from note titles
		if ($item->isNote()) {
			// Clean and strip HTML, giving us an HTML-encoded plaintext string
			$title = strip_tags($GLOBALS['HTMLPurifier']->purify($title));
			// Unencode plaintext string
			$title = html_entity_decode($title);
		}
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
		
		$id = Zotero_URI::getItemURI($item, true);
		if ($content != 'html') {
			$id .= "?content=$content";
		}
		$xml->id = $id;
		
		$xml->published = Zotero_Date::sqlToISO8601($item->getField('dateAdded'));
		$xml->updated = Zotero_Date::sqlToISO8601($item->getField('dateModified'));
		
		$link = $xml->addChild("link");
		$link['rel'] = "self";
		$link['type'] = "application/atom+xml";
		$href = Zotero_Atom::getItemURI($item);
		if ($content != 'html') {
			$href .= "?content=$content";
		}
		$link['href'] = $href;
		
		$parent = $item->getSource();
		if ($parent) {
			// TODO: handle group items?
			$parentItem = Zotero_Items::get($parent);
			$link = $xml->addChild("link");
			$link['rel'] = "up";
			$link['type'] = "application/atom+xml";
			$href = Zotero_Atom::getItemURI($parentItem);
			if ($content != 'html') {
				$href .= "?content=$content";
			}
			$link['href'] = $href;
		}
		
		$link = $xml->addChild('link');
		$link['rel'] = 'alternate';
		$link['type'] = 'text/html';
		$link['href'] = Zotero_URI::getItemURI($item);
		
		// If stored in ZFS, get file request link
		$details = Zotero_S3::getDownloadDetails($item);
		if ($details) {
			$link = $xml->addChild('link');
			$link['rel'] = 'enclosure';
			$type = $item->attachmentMIMEType;
			if ($type) {
				$link['type'] = $type;
			}
			$link['href'] = $details['url'];
			$link['title'] = $details['filename'];
			$link['length'] = $details['size'];
		}
		
		$xml->addChild('zapi:itemID', $item->id, Zotero_Atom::$nsZoteroAPI);
		$xml->addChild(
			'zapi:itemType',
			Zotero_ItemTypes::getName($item->itemTypeID),
			Zotero_Atom::$nsZoteroAPI
		);
		if ($item->isRegularItem()) {
			$xml->addChild(
				'zapi:creatorSummary',
				htmlspecialchars($item->firstCreator),
				Zotero_Atom::$nsZoteroAPI
			);
		}
		if (!$parent && $item->isRegularItem()) {
			$xml->addChild(
				'zapi:numChildren',
				$item->numChildren(),
				Zotero_Atom::$nsZoteroAPI
			);
		}
		$xml->addChild(
			'zapi:numTags',
			$item->numTags(),
			Zotero_Atom::$nsZoteroAPI
		);
		
		if ($content == 'html') {
			$xml->content['type'] = 'xhtml';
			$html = Zotero_Helpers::renderItemsMetadataTable($item, true);
			$xml->content->div = '';
			$xml->content->div['xmlns'] = Zotero_Atom::$nsXHTML;
			$fNode = dom_import_simplexml($xml->content->div);
			$subNode = dom_import_simplexml($html);
			$importedNode = $fNode->ownerDocument->importNode($subNode, true);
			$fNode->appendChild($importedNode);
		}
		// Not for public consumption
		else if ($content == 'full') {
			$xml->content['type'] = 'application/xml';
			$fullXML = Zotero_Items::convertItemToXML($item, array(), $apiVersion);
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
	
	
	private static function loadItems($itemIDs=array()) {
		$sql = 'SELECT I.*, ' . self::getFirstCreatorSQL() . ',
				(SELECT COUNT(*) FROM itemNotes INo
					WHERE sourceItemID=I.itemID AND INo.itemID NOT IN
					(SELECT itemID FROM deletedItems)) AS numNotes,
				(SELECT COUNT(*) FROM itemAttachments IA
					WHERE sourceItemID=I.itemID AND IA.itemID NOT IN
					(SELECT itemID FROM deletedItems)) AS numAttachments	
			FROM items I WHERE 1';
		$q = array();
		if ($itemIDs) {
			foreach ($itemIDs as $itemID) {
				if (!is_int($itemID)) {
					throw new Exception("Invalid itemID $itemID");
				}
			}
			$sql .= ' AND I.itemID IN (';
			foreach ($itemIDs as $itemID) {
				$q[] = '?';
			}
			$sql .= join(',', $q) . ')';
		}
		
		$stmt = Zotero_DB::getStatement($sql, "loadItems_" . sizeOf($q));
		$itemRows = Zotero_DB::queryFromStatement($stmt, $itemIDs);
		$loadedItemIDs = array();
		
		if ($itemRows) {
			foreach ($itemRows as $row) {
				$itemID = $row['itemID'];
				$loadedItemIDs[] = $itemID;
				
				// Item isn't loaded -- create new object and stuff in array
				if (!isset(self::$items[$itemID])) {
					$item = new Zotero_Item;
					$item->loadFromRow($row, true);
					self::$items[$itemID] = $item;
				}
				// Existing item -- reload in place
				else {
					throw new Exception("Unimplemented");
				}
			}
		}
		
		if (!$itemIDs) {
			// If loading all items, remove old items that no longer exist
			$ids = array_keys(self::$items);
			foreach ($ids as $id) {
				if (!in_array($id, $loadedItemIDs)) {
					throw new Exception("Unimplemented");
					//$this->unload($id);
				}
			}
			
			/*
			_cachedFields = ['itemID', 'itemTypeID', 'dateAdded', 'dateModified',
				'firstCreator', 'numNotes', 'numAttachments', 'numChildren'];
			*/
			//this._reloadCache = false;
		}
	}
	
	
	public static function getDataValueIDCacheKey($name, $hash=false) {
		return 'itemDataValueID_' . ($hash ? $hash : self::getHash($name));
	}
	
	public static function getDataValueCacheKey($id) {
		return 'itemDataValue_' . $id;
	}
	
	private static function getHash($value) {
		return md5($value);
	}
}
?>
