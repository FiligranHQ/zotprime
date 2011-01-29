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

class Zotero_Creators extends Zotero_DataObjects {
	protected static $ZDO_object = 'creator';
	
	private static $fields = array(
		'firstName', 'lastName', 'fieldMode'
	);
	private static $maxFirstNameLength = 255;
	private static $maxLastNameLength = 255;
	
	private static $creatorsByID = array();
	private static $dataByHash = array();
	private static $primaryDataByCreatorID = array();
	private static $primaryDataByLibraryAndKey = array();
	
	
	public static function get($libraryID, $creatorID, $skipCheck=false) {
		if (!$libraryID) {
			throw new Exception("Library ID not set");
		}
		
		if (!$creatorID) {
			throw new Exception("Creator ID not set");
		}
		
		if (!empty(self::$creatorsByID[$creatorID])) {
			return self::$creatorsByID[$creatorID];
		}
		
		if (!$skipCheck) {
			$sql = 'SELECT COUNT(*) FROM creators WHERE creatorID=?';
			$result = Zotero_DB::valueQuery($sql, $creatorID, Zotero_Shards::getByLibraryID($libraryID));
			if (!$result) {
				return false;
			}
		}
		
		$creator = new Zotero_Creator;
		$creator->libraryID = $libraryID;
		$creator->id = $creatorID;
		
		self::$creatorsByID[$creatorID] = $creator;
		return self::$creatorsByID[$creatorID];
	}

	
	
	/**
	 * @param	Zotero_Creator	$creator	Zotero Creator object
	 */
	public static function getDataHash(Zotero_Creator $creator, $create=false) {
		if ($creator->firstName === ''  && $creator->lastName === '') {
			trigger_error("First or last name must be provided", E_USER_ERROR);
		}
		
		if (!is_int($creator->fieldMode)) {
			trigger_error("Field mode must be an integer", E_USER_ERROR);
		}
		
		// For now, at least, simulate what MySQL used to throw
		if (mb_strlen($creator->firstName) > 255) {
			throw new Exception("=First name '" . mb_substr($creator->firstName, 0, 50) . "…' too long");
		}
		if (mb_strlen($creator->lastName) > 255) {
			throw new Exception("=Last name '" . mb_substr($creator->lastName, 0, 50) . "…' too long");
		}
		
		$hash = self::getHash($creator);
		$key = self::getCreatorDataCacheKey($hash);
		
		// Check local cache
		if (isset(self::$dataByHash[$hash])) {
			return $hash;
		}
		
		// Check memcache
		$data = Z_Core::$MC->get($key);
		if ($data) {
			self::$dataByHash[$hash] = $data;
			return $hash;
		}
		
		$doc = Z_Core::$Mongo->findOne("creatorData", $hash);
		if (!$doc) {
			$doc = array(
				"_id" => $hash,
				"firstName" => $creator->firstName,
				"lastName" => $creator->lastName,
				"fieldMode" => $creator->fieldMode
			);
			Z_Core::$Mongo->insertSafe("creatorData", $doc);
		}
		
		// Store in local cache and memcache
		unset($doc['_id']);
		self::$dataByHash[$hash] = $doc;
		Z_Core::$MC->set($key, $doc);
		
		return $hash;
	}
	
	
	public static function getData($hash) {
		if (!$hash) {
			throw new Exception("Creator data hash not provided");
		}
		
		if (isset(self::$dataByHash[$hash])) {
			return self::$dataByHash[$hash];
		}
		
		$key = self::getCreatorDataCacheKey($hash);
		$data = Z_Core::$MC->get($key);
		if ($data) {
			return $data;
		}
		
		$data = Z_Core::$Mongo->findOne("creatorData", $hash);
		if (!$data) {
			return false;
		}
		
		// Cache data
		unset($data["_id"]);
		self::$dataByHash[$hash] = $data;
		Z_Core::$MC->set($key, $data);
		
		return $data;
	}
	
	
	public static function getCreatorsWithData($libraryID, $hash, $sortByItemCountDesc=false) {
		$sql = "SELECT creatorID FROM creators ";
		if ($sortByItemCountDesc) {
			$sql .= "LEFT JOIN itemCreators USING (creatorID) ";
		}
		$sql .= "WHERE libraryID=? AND creatorDataHash=?";
		if ($sortByItemCountDesc) {
			$sql .= " ORDER BY IFNULL(COUNT(*), 0) DESC";
		}
		$ids = Zotero_DB::columnQuery($sql, array($libraryID, $hash), Zotero_Shards::getByLibraryID($libraryID));
		return $ids;
	}
	
	
	public static function getPrimaryDataSQL() {
		return "SELECT creatorID AS id, libraryID, `key`, dateAdded, dateModified, creatorDataHash
				FROM creators WHERE ";
	}
	
	
	public static function bulkInsertDataValues($valueObjs) {
		$docs = array();
		foreach ($valueObjs as $obj) {
			if (mb_strlen($obj->firstName) > 255) {
				throw new Exception("=First name '" . mb_substr($obj->firstName, 0, 50) . "…' too long");
			}
			if (mb_strlen($obj->lastName) > 255) {
				if ($obj->fieldMode == 1) {
					throw new Exception("=Last name '" . mb_substr($obj->lastName, 0, 50) . "…' too long");
				}
				else {
					throw new Exception("=Name '" . mb_substr($obj->lastName, 0, 50) . "…' too long");
				}
			}
			
			$hash = self::getHash($obj);
			
			if (isset(self::$dataByHash[$hash])) {
				continue;
			}
			
			$key = self::getCreatorDataCacheKey($hash);
			$data = Z_Core::$MC->get($key);
			if ($data) {
				self::$dataByHash[$hash] = $data;
				continue;
			}
			
			$doc = array(
				"_id" => $hash,
				"firstName" => $obj->firstName,
				"lastName" => $obj->lastName,
				"fieldMode" => $obj->fieldMode
			);
			$docs[] = $doc;
		}
		
		if (!$docs) {
			return;
		}
		
		// Insert into MongoDB
		Z_Core::$Mongo->batchInsertIgnoreSafe("creatorData", $docs);
		
		// Cache data values locally and in memcache
		foreach ($docs as &$doc) {
			$hash = $doc["_id"];
			unset($doc["_id"]);
			self::$dataByHash[$hash] = $doc;
			$key = self::getCreatorDataCacheKey($hash);
			Z_Core::$MC->add($key, $doc);
		}
	}
	
	
	public static function getDataValuesFromXML(DOMDocument $doc) {
		$xpath = new DOMXPath($doc);
		$nodes = $xpath->evaluate('//creators/creator');
		$objs = array();
		foreach ($nodes as $n) {
			$objs[] = self::convertXMLToDataValues($n);
		}
		return $objs;
	}
	
	
	public static function getLongDataValueFromXML(DOMDocument $doc) {
		$xpath = new DOMXPath($doc);
		$names = $xpath->evaluate(
			'//creators/creator[string-length(name) > ' . self::$maxLastNameLength . ']/name '
			. '| //creators/creator[string-length(firstName) > ' . self::$maxFirstNameLength . ']/firstName '
			. '| //creators/creator[string-length(lastName) > ' . self::$maxLastNameLength . ']/lastName '
		);
		return $names->length ? $names->item(0) : false;
	}
	
	
	/**
	 * Converts a SimpleXMLElement item to a Zotero_Item object
	 *
	 * @param	DOMElement			$xml		Item data as DOMElement
	 * @return	Zotero_Creator					Zotero creator object
	 */
	public static function convertXMLToCreator(DOMElement $xml) {
		$libraryID = (int) $xml->getAttribute('libraryID');
		$creatorObj = self::getByLibraryAndKey($libraryID, $xml->getAttribute('key'));
		// Not an existing item, so create
		if (!$creatorObj) {
			$creatorObj = new Zotero_Creator;
			$creatorObj->libraryID = $libraryID;
			$creatorObj->key = $xml->getAttribute('key');
		}
		$creatorObj->dateAdded = $xml->getAttribute('dateAdded');
		$creatorObj->dateModified = $xml->getAttribute('dateModified');
		
		$dataObj = self::convertXMLToDataValues($xml);
		foreach ($dataObj as $key => $val) {
			$creatorObj->$key = $val;
		}
		
		return $creatorObj;
	}
	
	
	/**
	 * Converts a Zotero_Creator object to a DOMElement
	 *
	 * @param	object				$item		Zotero_Creator object
	 * @return	SimpleXMLElement				Creator data as DOMElement element
	 */
	public static function convertCreatorToXML(Zotero_Creator $creator, DOMDocument $doc) {
		$xmlCreator = $doc->createElement('creator');
		
		$xmlCreator->setAttributeNode(new DOMAttr('libraryID', $creator->libraryID));
		$xmlCreator->setAttributeNode(new DOMAttr('key', $creator->key));
		$xmlCreator->setAttributeNode(new DOMAttr('dateAdded', $creator->dateAdded));
		$xmlCreator->setAttributeNode(new DOMAttr('dateModified', $creator->dateModified));
		
		if ($creator->fieldMode == 1) {
			$xmlCreator->appendChild(new DOMElement('name', htmlspecialchars($creator->lastName)));
			$xmlCreator->appendChild(new DOMElement('fieldMode', 1));
		}
		else {
			$xmlCreator->appendChild(new DOMElement('firstName', htmlspecialchars($creator->firstName)));
			$xmlCreator->appendChild(new DOMElement('lastName', htmlspecialchars($creator->lastName)));
		}
		
		if ($creator->birthYear) {
			$xmlCreator->appendChild(new DOMElement('birthYear', $creator->birthYear));
		}
		
		return $xmlCreator;
	}
	
	
/*
	public static function updateLinkedItems($creatorID, $dateModified) {
		Zotero_DB::beginTransaction();
		
		// TODO: add to notifier, if we have one
		//$sql = "SELECT itemID FROM itemCreators WHERE creatorID=?";
		//$changedItemIDs = Zotero_DB::columnQuery($sql, $creatorID);
		
		// This is very slow in MySQL 5.1.33 -- should be faster in MySQL 6
		//$sql = "UPDATE items SET dateModified=?, serverDateModified=? WHERE itemID IN
		//		(SELECT itemID FROM itemCreators WHERE creatorID=?)";
		
		$sql = "UPDATE items JOIN itemCreators USING (itemID) SET items.dateModified=?,
					items.serverDateModified=?, serverDateModifiedMS=? WHERE creatorID=?";
		$timestamp = Zotero_DB::getTransactionTimestamp();
		$timestampMS = Zotero_DB::getTransactionTimestampMS();
		Zotero_DB::query(
			$sql,
			array($dateModified, $timestamp, $timestampMS, $creatorID)
		);
		Zotero_DB::commit();
	}
*/	
	
	public static function cache(Zotero_Creator $creator) {
		if (isset($creatorsByID[$creator->id])) {
			error_log("Creator $creator->id is already cached");
		}
		
		$creatorsByID[$creator->id] = $creator;
	}
	
	
	public static function getLocalizedFieldNames($locale='en-US') {
		if ($locale != 'en-US') {
			throw new Exception("Locale not yet supported");
		}
		
		$fields = array('firstName', 'lastName', 'name');
		$rows = array();
		foreach ($fields as $field) {
			$rows[] = array('name' => $field);
		}
		
		foreach ($rows as &$row) {
			switch ($row['name']) {
				case 'firstName':
					$row['localized'] = 'First';
					break;
				
				case 'lastName':
					$row['localized'] = 'Last';
					break;
				
				case 'name':
					$row['localized'] = 'Name';
					break;
			}
		}
		
		return $rows;
	}
	
	
	public static function purge() {
		trigger_error("Unimplemented", E_USER_ERROR);
	}
	
	
	private static function convertXMLToDataValues(DOMElement $xml) {
		$dataObj = new stdClass;
		
		$fieldMode = $xml->getElementsByTagName('fieldMode')->item(0);
		$fieldMode = $fieldMode ? (int) $fieldMode->nodeValue : 0;
		$dataObj->fieldMode = $fieldMode;
		
		if ($fieldMode == 1) {
			$dataObj->firstName = '';
			$dataObj->lastName = $xml->getElementsByTagName('name')->item(0)->nodeValue;
		}
		else {
			$dataObj->firstName = $xml->getElementsByTagName('firstName')->item(0)->nodeValue;
			$dataObj->lastName = $xml->getElementsByTagName('lastName')->item(0)->nodeValue;
		}
		
		$birthYear = $xml->getElementsByTagName('birthYear')->item(0);
		$dataObj->birthYear = $birthYear ? $birthYear->nodeValue : null;
		
		return $dataObj;
	}
	
	
	public static function getCreatorDataCacheKey($hash) {
		return 'creatorData_' . $hash;
	}
	
	
	private static function getHash($fields) {
		$hashFields = array();
		foreach (self::$fields as $field) {
			// Array
			if (is_array($fields)) {
				$val = $fields[$field];
			}
			// Object
			else {
				$val = $fields->$field;
			}
			$hashFields[] = is_null($val) ? "" : $val;
		}
		return md5(join('_', $hashFields));
	}
}
?>
