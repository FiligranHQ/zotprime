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
		'firstCreator', 'numNotes', 'numAttachments');
	private static $maxDataValueLength = 65535;
	
	private static $itemsByID = array();
	private static $dataValuesByHash = array();
	
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
	
	
	public static function getAllAdvanced($libraryID, $onlyTopLevel=false, $params=array(), $includeTrashed=false) {
		$results = array('items' => array(), 'total' => 0);
		
		$sql = "SELECT SQL_CALC_FOUND_ROWS A.itemID FROM items A ";
		
		if ($onlyTopLevel) {
			$sql .= "LEFT JOIN itemNotes B USING (itemID)
						LEFT JOIN itemAttachments C ON (C.itemID=A.itemID) ";
		}
		if (!$includeTrashed) {
			$sql .= " LEFT JOIN deletedItems D ON (D.itemID=A.itemID) ";
		}
		
		$sql .= "WHERE A.libraryID=? ";
		$sqlParams = array($libraryID);
		
		if ($onlyTopLevel) {
			$sql .= "AND B.sourceItemID IS NULL AND C.sourceItemID IS NULL ";
		}
		if (!$includeTrashed) {
			$sql .= " AND D.itemID IS NULL ";
		}
		
		if (!empty($params['fq'])) {
			if (!is_array($params['fq'])) {
				$params['fq'] = array($params['fq']);
			}
			foreach ($params['fq'] as $fq) {
				$facet = explode(":", $fq);
				if (sizeOf($facet) == 2 && preg_match('/-?Tag/', $facet[0])) {
					$tagIDs = Zotero_Tags::getIDs($libraryID, $facet[1]);
					if (!$tagIDs) {
						throw new Exception("Tag '{$facet[1]}' not found", Z_ERROR_TAG_NOT_FOUND);
					}
					
					$sql .= "AND A.itemID ";
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
				$sql .= " " . $params['sort'];
			}
			$sql .= ", ";
		}
		$sql .= "itemID " . (!empty($params['sort']) ? $params['sort'] : "ASC") . " ";
		
		if (!empty($params['limit'])) {
			$sql .= "LIMIT ?, ?";
			$sqlParams[] = $params['start'] ? $params['start'] : 0;
			$sqlParams[] = $params['limit'];
		}
		
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		$itemIDs = Zotero_DB::columnQuery($sql, $sqlParams, $shardID);
		
		if ($itemIDs) {
			$results['total'] = Zotero_DB::valueQuery("SELECT FOUND_ROWS()", false, $shardID);
			$results['items'] = Zotero_Items::get($libraryID, $itemIDs);
		}
		
		return $results;
	}
	
	
	public static function getAllAdvancedSolr($libraryID, $onlyTopLevel=false, $params=array(), $includeTrashed=false) {
		$results = array('items' => array(), 'total' => 0);
		
		$query = new SolrQuery();
		$query->addField("libraryID")->addField("key");
		
		$query->addFilterQuery("libraryID:$libraryID");
		
		$qParts = array();
		
		if ($onlyTopLevel) {
			$qParts[] = "-sourceItem:[* TO *]";
		}
		if (!$includeTrashed) {
			$qParts[] = "-deleted:1";
		}
		
		$query->setQuery(implode(' AND ', $qParts));
		
		$query->setStart(!empty($params['start']) ? $params['start'] : 0);
		if (!empty($params['limit'])) {
			$query->setRows($params['limit']);
		}
		
		if (!empty($params['order'])) {
			switch ($params['order']) {
				case 'title':
					$order = $params['order'] . "Sort";
					break;
					
				default:
					$order = $params['order'];
			}
			$query->addSortField(
				$order,
				!empty($params['sort']) && $params['sort'] == 'desc'
					? SolrQuery::ORDER_DESC : SolrQuery::ORDER_ASC
			);
		}
		
		// Run query
		$response = Z_Core::$Solr->client->query($query);
		$response = $response->getResponse();
		
		$results['total'] = $response['response']['numFound'];
		foreach ($response['response']['docs'] as $doc) {
			$results['items'][] = Zotero_Items::getByLibraryAndKey($doc['libraryID'], $doc['key']);
		}
		
		return $results;
		
		if (!empty($params['fq'])) {
			if (!is_array($params['fq'])) {
				$params['fq'] = array($params['fq']);
			}
			foreach ($params['fq'] as $fq) {
				$facet = explode(":", $fq);
				if (sizeOf($facet) == 2 && preg_match('/-?Tag/', $facet[0])) {
					$tagIDs = Zotero_Tags::getIDs($libraryID, $facet[1]);
					if (!$tagIDs) {
						throw new Exception("Tag '{$facet[1]}' not found", Z_ERROR_TAG_NOT_FOUND);
					}
					
					
					
					$sql .= "AND A.itemID ";
					// If first character is '-', negate
					$sql .= ($facet[0][0] == '-' ? 'NOT ' : '');
					$sql .= "IN (SELECT itemID FROM itemTags WHERE tagID IN (";
					$func = create_function('', "return '?';");
					$sql .= implode(',', array_map($func, $tagIDs)) . ")) ";
					$sqlParams = array_merge($sqlParams, $tagIDs);
				}
			}
		}
	}
	
	/*
	 * Generate SQL to retrieve creatorDataHashes for firstCreator field
	 *
	 * Format:
	 *
	 * 1:  'e48437e66f1e01311ba72d182b8a9a09'
	 * 2:  'e48437e66f1e01311ba72d182b8a9a09,a4c42d664aa99b73885cc271fdcaa37f'
	 * 3+: 'e48437e66f1e01311ba72d182b8a9a09,+'
	 *
	 * Why do we do this entirely in SQL? Because we're crazy. Crazy like foxes.
	 */
	public static function getFirstCreatorHashesSQL() {
		// TODO: memcache keyed with localizedAnd
		$masterDB = Z_CONFIG::$SHARD_MASTER_DB;
		
		/* This whole block is to get the firstCreator */
		$sql = "COALESCE(" .
			// First try for primary creator types
			"CASE (" .
				"SELECT COUNT(*) FROM itemCreators IC " .
				"LEFT JOIN $masterDB.itemTypeCreatorTypes ITCT " .
				"ON (IC.creatorTypeID=ITCT.creatorTypeID) " .
				"WHERE itemID=I.itemID AND primaryField=1 " .
				"AND ITCT.itemTypeID=I.itemTypeID" .
			") " .
			"WHEN 0 THEN NULL " .
			"WHEN 1 THEN (" .
				"SELECT creatorDataHash FROM itemCreators IC NATURAL JOIN creators " .
				"LEFT JOIN $masterDB.itemTypeCreatorTypes ITCT " .
				"ON (IC.creatorTypeID=ITCT.creatorTypeID) " .
				"WHERE itemID=I.itemID AND primaryField=1 " .
				"AND ITCT.itemTypeID=I.itemTypeID" .
			") " .
			"WHEN 2 THEN (" .
				"SELECT CONCAT(" .
				"(SELECT creatorDataHash FROM itemCreators IC NATURAL JOIN creators " .
				"LEFT JOIN $masterDB.itemTypeCreatorTypes ITCT " .
				"ON (IC.creatorTypeID=ITCT.creatorTypeID) " .
				"WHERE itemID=I.itemID AND primaryField=1 AND ITCT.itemTypeID=I.itemTypeID " .
				"ORDER BY orderIndex LIMIT 1)" .
				" , ',' , " .
				"(SELECT creatorDataHash FROM itemCreators IC NATURAL JOIN creators " .
				"LEFT JOIN $masterDB.itemTypeCreatorTypes ITCT " .
				"ON (IC.creatorTypeID=ITCT.creatorTypeID) " .
				"WHERE itemID=I.itemID AND primaryField=1 AND ITCT.itemTypeID=I.itemTypeID " .
				"ORDER BY orderIndex LIMIT 1,1))" .
			") " .
			"ELSE (" .
				"SELECT CONCAT(" .
				"(SELECT creatorDataHash FROM itemCreators IC NATURAL JOIN creators " .
				"LEFT JOIN $masterDB.itemTypeCreatorTypes ITCT " .
				"ON (IC.creatorTypeID=ITCT.creatorTypeID) " .
				"WHERE itemID=I.itemID AND primaryField=1 AND ITCT.itemTypeID=I.itemTypeID " .
				"ORDER BY orderIndex LIMIT 1)" .
				" , ',+' )" .
			") " .
			"END, " .
			
			// Then try editors
			"CASE (" .
				"SELECT COUNT(*) FROM itemCreators " .
				"NATURAL JOIN $masterDB.creatorTypes WHERE itemID=I.itemID AND creatorTypeID IN (3)" .
			") " .
			"WHEN 0 THEN NULL " .
			"WHEN 1 THEN (" .
				"SELECT creatorDataHash FROM itemCreators NATURAL JOIN creators " .
				"WHERE itemID=I.itemID AND creatorTypeID IN (3)" .
			") " .
			"WHEN 2 THEN (" .
				"SELECT CONCAT(" .
				"(SELECT creatorDataHash FROM itemCreators NATURAL JOIN creators WHERE itemID=I.itemID AND creatorTypeID IN (3) ORDER BY orderIndex LIMIT 1)" .
				" , ',' , " .
				"(SELECT creatorDataHash FROM itemCreators NATURAL JOIN creators WHERE itemID=I.itemID AND creatorTypeID IN (3) ORDER BY orderIndex LIMIT 1,1)) " .
			") " .
			"ELSE (" .
				"SELECT CONCAT(" .
				"(SELECT creatorDataHash FROM itemCreators NATURAL JOIN creators WHERE itemID=I.itemID AND creatorTypeID IN (3) ORDER BY orderIndex LIMIT 1)" .
				" , ',+' )" .
			") " .
			"END, " .
			
			// Then try contributors
			"CASE (" .
				"SELECT COUNT(*) FROM itemCreators " .
				"NATURAL JOIN $masterDB.creatorTypes WHERE itemID=I.itemID AND creatorTypeID IN (2)" .
			") " .
			"WHEN 0 THEN NULL " .
			"WHEN 1 THEN (" .
				"SELECT creatorDataHash FROM itemCreators NATURAL JOIN creators " .
				"WHERE itemID=I.itemID AND creatorTypeID IN (2)" .
			") " .
			"WHEN 2 THEN (" .
				"SELECT CONCAT(" .
				"(SELECT creatorDataHash FROM itemCreators NATURAL JOIN creators WHERE itemID=I.itemID AND creatorTypeID IN (2) ORDER BY orderIndex LIMIT 1)" .
				" , ',' , " .
				"(SELECT creatorDataHash FROM itemCreators NATURAL JOIN creators WHERE itemID=I.itemID AND creatorTypeID IN (2) ORDER BY orderIndex LIMIT 1,1)) " .
			") " .
			"ELSE (" .
				"SELECT CONCAT(" .
				"(SELECT creatorDataHash FROM itemCreators NATURAL JOIN creators WHERE itemID=I.itemID AND creatorTypeID IN (2) ORDER BY orderIndex LIMIT 1)" .
				" , ',+' )" .
			") " .
			"END" .
		") AS firstCreatorHashes";
		
		return $sql;
	}
	
	
	/**
	 * Convert hash string from getFirstCreatorHashesSQL() to firstCreator string
	 */
	public static function getFirstCreator($hashes) {
		$localizedAnd = " and ";
		$etAl = " et al.";
		
		if (!is_array($hashes)) {
			throw new Exception('$hashes is not an array');
		}
		
		$cacheKey = "firstCreator_" . md5(implode('_', $hashes));
		$firstCreator = Z_Core::$MC->get($cacheKey);
		if ($firstCreator) {
			return $firstCreator;
		}
		
		switch (sizeOf($hashes)) {
			case 1:
				$firstCreator = Z_Core::$Mongo->valueQuery("creatorData", $hashes[0], "lastName");
/*				if (!$firstCreator) {
					throw new Exception("Returned firstCreator is empty");
				}
*/				break;
			
			case 2:
				if (isset($hashes[1]) && $hashes[1] == "+") {
					$firstCreator = Z_Core::$Mongo->valueQuery("creatorData", $hashes[0], "lastName");
/*					if (!$firstCreator) {
						throw new Exception("Returned firstCreator is empty");
					}
*/					$firstCreator .= $etAl;
				}
				else {
					$names = Z_Core::$Mongo->find("creatorData", array("_id" => array('$in' => $hashes)), array("lastName"));
					
					$first = $names->getNext();
					$second = $names->getNext();
					
					$firstCreator = $first["_id"] == $hashes[0]
						? $first["lastName"] . $localizedAnd . $second["lastName"]
						: $second["lastName"] . $localizedAnd . $first["lastName"];
					
/*					if ($firstCreator == $localizedAnd) {
						throw new Exception("Returned firstCreator is empty");
					}
*/				}
				break;
			
			default:
				throw new Exception('$hashes must have 1 or 2 elements (' . sizeOf($hashes) . ')');
		}
		
		Z_Core::$MC->set($cacheKey, $firstCreator);
		
		return $firstCreator;
	}
	
	
	public static function cache(Zotero_Item $item) {
		if (isset($itemsByID[$item->id])) {
			Z_Core::debug("Item $item->id is already cached");
		}
		
		$itemsByID[$item->id] = $item;
	}
	
	
	public static function getDataValueHash($value, $create=false) {
		// For now, at least, simulate what MySQL used to throw
		if (mb_strlen($value) > 65536) {
			throw new Exception("Data too long for column 'value'");
		}
		
		$hash = self::getHash($value);
		
		if (!$create) {
			return $hash;
		}
		
		// Check local cache
		if (isset(self::$dataValuesByHash[$hash])) {
			return $hash;
		}
		
		// Check memcache
		$key = self::getDataValueCacheKey($hash);
		if (Z_Core::$MC->get($key)) {
			self::$dataValuesByHash[$hash] = $value;
			return $hash;
		}
		
		if (!Z_Core::$Mongo->valueQuery("itemDataValues", $hash, "_id")) {
			$doc = array(
				"_id" => $hash,
				"value" => $value
			);
			Z_Core::$Mongo->insertSafe("itemDataValues", $doc);
		}
		
		// Store in local cache and memcache
		self::$dataValuesByHash[$hash] = $value;
		Z_Core::$MC->set($key, $value);
		
		return $hash;
	}
	
	
	public static function getDataValue($hash) {
		// Check local cache
		if (isset(self::$dataValuesByHash[$hash])) {
			return self::$dataValuesByHash[$hash];
		}
		
		// Check memcache
		$key = self::getDataValueCacheKey($hash);
		$value = Z_Core::$MC->get($key);
		if ($value !== false) {
			return $value;
		}
		
		$value = Z_Core::$Mongo->valueQuery("itemDataValues", $hash, "value");
		if ($value === false) {
			return false;
		}
		
		// Store in local cache and memcache
		self::$dataValuesByHash[$hash] = $value;
		Z_Core::$MC->set($key, $value);
		
		return $value;
	}
	
	
	public static function bulkInsertDataValues($values) {
		$docs = array();
		
		foreach ($values as $value) {
			// Length check is done by Zotero_Items::getLongDataValueFromXML()
			// in Zotero_Sync::processUploadInternal()
			
			$hash = self::getHash($value);
			$key = self::getDataValueCacheKey($hash);
			if (Z_Core::$MC->get($key)) {
				self::$dataValuesByHash[$hash] = $value;
			}
			else {
				$docs[] = array(
					"_id" => $hash,
					"value" => $value
				);
			}
		}
		
		if (!$docs) {
			return;
		}
		
		// Insert into MongoDB
		Z_Core::$Mongo->batchInsertIgnoreSafe("itemDataValues", $docs);
		
		// Cache data values locally and in memcache
		foreach ($docs as $doc) {
			self::$dataValuesByHash[$doc["_id"]] = $doc["value"];
			$key = self::getDataValueCacheKey($doc["_id"]);
			Z_Core::$MC->add($key, $doc["value"]);
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
		
		// Note
		if ($item->isNote()) {
			$note = $item->getNote();
			
			// TEMP: Clean HTML before returning
			$c = HTMLPurifier_Config::createDefault();
			$c->set('HTML.Doctype', 'XHTML 1.0 Transitional');
			$c->set('Cache.SerializerPath', Z_ENV_TMP_PATH);
			$HTMLPurifier = new HTMLPurifier($c);
			$note = $HTMLPurifier->purify($note);
			$xml->addChild('note', htmlspecialchars($note));
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
				// TEMP: Clean HTML before returning
				$c = HTMLPurifier_Config::createDefault();
				$c->set('HTML.Doctype', 'XHTML 1.0 Transitional');
				$c->set('Cache.SerializerPath', Z_ENV_TMP_PATH);
				$HTMLPurifier = new HTMLPurifier($c);
				$note = $HTMLPurifier->purify($note);
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
		
		$id = Zotero_URI::getItemURI($item);
		/*if ($content != 'html') {
			$id .= "?content=$content";
		}*/
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
			$parentItem = Zotero_Items::get($item->libraryID, $parent);
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
		
		$xml->addChild('zapi:key', $item->key, Zotero_Atom::$nsZoteroAPI);
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
	
	
	private static function loadItems($libraryID, $itemIDs=array()) {
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		
		$sql = 'SELECT I.*, ' . self::getFirstCreatorHashesSQL() . ',
				(SELECT COUNT(*) FROM itemNotes INo
					WHERE sourceItemID=I.itemID AND INo.itemID NOT IN
					(SELECT itemID FROM deletedItems)) AS numNotes,
				(SELECT COUNT(*) FROM itemAttachments IA
					WHERE sourceItemID=I.itemID AND IA.itemID NOT IN
					(SELECT itemID FROM deletedItems)) AS numAttachments	
			FROM items I WHERE 1';
		$q = array();
		// TODO: optimize
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
		
		$stmt = Zotero_DB::getStatement($sql, "loadItems_" . sizeOf($q), $shardID);
		$itemRows = Zotero_DB::queryFromStatement($stmt, $itemIDs);
		$loadedItemIDs = array();
		
		if ($itemRows) {
			foreach ($itemRows as $row) {
				if ($row['libraryID'] != $libraryID) {
					throw new Exception("Item $itemID isn't in library $libraryID");
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
					throw new Exception("Unimplemented");
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
				'firstCreator', 'numNotes', 'numAttachments', 'numChildren'];
			*/
			//this._reloadCache = false;
		}
	}
	
	
	public static function getDataValueCacheKey($hash) {
		return 'itemDataValue_' . $hash;
	}
	
	private static function getHash($value) {
		return md5($value);
	}
}
?>
