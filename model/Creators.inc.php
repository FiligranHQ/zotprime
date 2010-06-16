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
		'firstName', 'lastName', 'fieldMode', 'birthYear'
	);
	private static $maxFirstNameLength = 255;
	private static $maxLastNameLength = 255;
	
	private static $dataIDsByHash = array();
	private static $dataByID = array();
	private static $primaryDataByCreatorID = array();
	private static $primaryDataByLibraryAndKey = array();
	
	public static function get($creatorID) {
		if (!empty(self::$objectCache[$creatorID])) {
			return self::$objectCache[$creatorID];
		}
		
		$sql = 'SELECT COUNT(*) FROM creators WHERE creatorID=?';
		$result = Zotero_DB::valueQuery($sql, $creatorID);
		
		if (!$result) {
			return false;
		}
		
		$creator = new Zotero_Creator;
		$creator->id = $creatorID;
		self::$objectCache[$creatorID] = $creator;
		return self::$objectCache[$creatorID];
	}
	
	
	/**
	 * @param	Zotero_Creator	$creator	Zotero Creator object
	 * @return	int|FALSE					creatorDataID
	 */
	public static function getDataID(Zotero_Creator $creator, $create=false) {
		if ($creator->firstName === ''  && $creator->lastName === '') {
			trigger_error("First or last name must be provided", E_USER_ERROR);
		}
		
		if (!is_int($creator->fieldMode)) {
			trigger_error("Field mode must be an integer", E_USER_ERROR);
		}
		
		$params = array($creator->firstName, $creator->lastName,
						$creator->fieldMode, $creator->birthYear);
		
		$hash = self::getHash($creator);
		$key = self::getCreatorDataIDCacheKey($hash);
		
		// Check local cache
		if (isset(self::$dataIDsByHash[$hash])) {
			return self::$dataIDsByHash[$hash];
		}
		
		// Check memcache
		$id = Z_Core::$MC->get($key);
		if ($id) {
			self::$dataIDsByHash[$hash] = $id;
			return $id;
		}
		
		Zotero_DB::beginTransaction();
		
		$sql = "SELECT creatorDataID FROM creatorData WHERE firstName=? AND lastName=?
				AND shortName='' AND fieldMode=? AND birthYear=?";
		$id = Zotero_DB::valueQuery($sql, $params);
		
		if (!$id && $create) {
			$id = Zotero_ID::get('creatorData');
			array_unshift($params, $id);
			
			try {
				$sql = "INSERT INTO creatorData SET creatorDataID=?,
						firstName=?, lastName=?, shortName='', fieldMode=?, birthYear=?";
				$stmt = Zotero_DB::getStatement($sql, true);
				$insertID = Zotero_DB::queryFromStatement($stmt, $params);
			}
			catch (Exception $e) {
				$msg = $e->getMessage();
				if (strpos($msg, "Data too long for column 'firstName'") !== false) {
					throw new Exception("=First name '" . mb_substr($params[1], 0, 50) . "…' too long");
				}
				if (strpos($msg, "Data too long for column 'lastName'") !== false) {
					throw new Exception("=Last name '" . mb_substr($params[2], 0, 50) . "…' too long");
				}
				throw ($e);
			}
			
			if (!$id) {
				$id = $insertID;
			}
		}
		
		Zotero_DB::commit();
		
		// Store in local cache and memcache
		if ($id) {
			self::$dataIDsByHash[$hash] = $id;
			Z_Core::$MC->set($key, $id);
		}
		
		return $id;
	}
	
	
	public static function getData($dataID) {
		if (!$dataID) {
			throw new Exception("Creator data id not provided");
		}
		
		if (isset(self::$dataByID[$dataID])) {
			return self::$dataByID[$dataID];
		}
		$key = self::getCreatorDataCacheKey($dataID);
		$row = Z_Core::$MC->get($key);
		if ($row) {
			return $row;
		}
		
		$sql = "SELECT * FROM creatorData WHERE creatorDataID=?";
		$stmt = Zotero_DB::getStatement($sql, true);
		$row = Zotero_DB::rowQueryFromStatement($stmt, $dataID);
		if (!$row) {
			return false;
		}
		unset($row['creatorDataID']);
		
		// Cache data
		self::$dataByID[$dataID] = $row;
		Z_Core::$MC->set($key, $row);
		
		return $row;
	}
	
	
	public static function getPrimaryDataByCreatorID($creatorID) {
		if (!is_numeric($creatorID)) {
			throw new Exception("Invalid creatorID '$creatorID'");
		}
		
		if (isset(self::$primaryDataByCreatorID[$creatorID])) {
			return self::$primaryDataByCreatorID[$creatorID];
		}
		
		$sql = self::getPrimaryDataSQL() . "creatorID=?";
		$stmt = Zotero_DB::getStatement($sql);
		$row = Zotero_DB::rowQueryFromStatement($stmt, $creatorID);
		self::cachePrimaryData($creatorID, $row);
		return $row;
	}
	
	
	public static function getPrimaryDataByLibraryAndKey($libraryID, $key) {
		if (!is_numeric($libraryID)) {
			throw new Exception("Invalid libraryID '$libraryID'");
		}
		if (!preg_match('/[A-Z0-9]{8}/', $key)) {
			throw new Exception("Invalid key '$key'");
		}
		
		// If primary creator data isn't cached for library, do so now
		if (!isset(self::$primaryDataByLibraryAndKey[$libraryID])) {
			self::cachePrimaryDataByLibrary($libraryID);
		}
		
		if (!isset(self::$primaryDataByLibraryAndKey[$libraryID][$key])) {
			return false;
		}
		
		return self::$primaryDataByLibraryAndKey[$libraryID][$key];
	}
	
	
	public static function cachePrimaryData($creatorID, $row) {
		if (!is_numeric($creatorID)) {
			throw new Exception("Invalid creatorID '$creatorID'");
		}
		
		if (!$row) {
			self::$primaryDataByCreatorID[$creatorID] = false;
		}
		
		$found = 0;
		$expected = 6; // number of values below
		
		foreach ($row as $key=>$val) {
			switch ($key) {
				case 'id':
				case 'libraryID':
				case 'key':
				case 'dateAdded':
				case 'dateModified':
				case 'creatorDataID':
					$found++;
					break;
				
				default:
					throw new Exception("Unknown primary data field '$key'");
			}
		}
		
		if ($found != $expected) {
			throw new Exception("$found primary data fields provided -- excepted $expected");
		}
		
		self::$primaryDataByCreatorID[$creatorID] = $row;
	}
	
	
	public static function bulkInsertDataValues($valueObjs) {
		$sets = array();
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
			$key = self::getCreatorDataIDCacheKey($hash);
			$id = Z_Core::$MC->get($key);
			if ($id) {
				self::$dataIDsByHash[$hash] = $id;
				continue;
			}
			
			$set = array();
			$set[0] = $obj->firstName;
			$set[1] = $obj->lastName;
			$set[2] = ""; // TEMP: shortName
			$set[3] = $obj->fieldMode;
			$set[4] = $obj->birthYear;
			$sets[] = $set;
		}
		
		if (!$sets) {
			return;
		}
		
		try {
			Zotero_DB::beginTransaction();
			$sql = "CREATE TEMPORARY TABLE tmpCreatorData (
						firstName VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
						lastName VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
						shortName VARCHAR(100) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
						fieldMode TINYINT(1) UNSIGNED NOT NULL,
						birthYear YEAR(4) NULL,
						INDEX (lastName))";
			Zotero_DB::query($sql);
			Zotero_DB::bulkInsert("INSERT INTO tmpCreatorData VALUES ", $sets, 150);
			$joinSQL = "FROM tmpCreatorData TCD JOIN creatorData CD ON (
						TCD.lastName=CD.lastName AND
						TCD.firstName=CD.firstName AND
						TCD.fieldMode=CD.fieldMode)";
			// Delete data rows that already exist
			Zotero_DB::query("DELETE TCD " . $joinSQL);
			$sql = "INSERT IGNORE INTO creatorData (firstName, lastName, shortName, fieldMode, birthYear)
						SELECT firstName, lastName, shortName, fieldMode, birthYear FROM tmpCreatorData";
			Zotero_DB::query($sql);
			$rows = Zotero_DB::query("SELECT CD.* " . $joinSQL);
			Zotero_DB::query("DROP TEMPORARY TABLE tmpCreatorData");
			Zotero_DB::commit();
		}
		catch (Exception $e) {
			Zotero_DB::rollback();
			throw ($e);
		}
		
		foreach ($rows as $row) {
			$id = $row['creatorDataID'];
			$hash = self::getHash($row);
			
			// Cache creator data id
			self::$dataIDsByHash[$hash] = $id;
			$key = self::getCreatorDataIDCacheKey($hash);
			Z_Core::$MC->add($key, $id);
			
			// Cache creator data
			self::$dataByID[$id] = $row;
			$key = self::getCreatorDataCacheKey($id);
			Z_Core::$MC->set($key, $row);
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
		$creatorObj = new Zotero_Creator;
		$creatorObj->libraryID = (int) $xml->getAttribute('libraryID');
		$creatorObj->key = $xml->getAttribute('key');
		$creatorObj->dateAdded = $xml->getAttribute('dateAdded');
		$creatorObj->dateModified = $xml->getAttribute('dateModified');
		
		$dataObj = self::convertXMLToDataValues($xml);
		foreach ($dataObj as $key => $val) {
			$creatorObj->$key = $val;
		}
		
		return $creatorObj;
	}
	
	/**
	 * Converts a SimpleXMLElement item to a Zotero_Item object
	 *
	 * @param	SimpleXMLElement	$xml		Item data as SimpleXML element
	 * @return	Zotero_Creator					Zotero creator object
	 */
/*	public static function convertXMLToCreator(SimpleXMLElement $xml) {
		$creatorObj = new Zotero_Creator;
		$creatorObj->libraryID = (int) $xml['libraryID'];
		$creatorObj->key = (string) $xml['key'];
		$creatorObj->dateAdded = (string) $xml['dateAdded'];
		$creatorObj->dateModified = (string) $xml['dateModified'];
		$creatorObj->fieldMode = (int) $xml->fieldMode;
		
		if ($xml->fieldMode == 1) {
			$creatorObj->firstName = '';
			$creatorObj->lastName = (string) $xml->name;
		}
		else {
			$creatorObj->firstName = (string) $xml->firstName;
			$creatorObj->lastName = (string) $xml->lastName;
		}
		
		$creatorObj->birthYear = (string) $xml->birthYear;
		
		return $creatorObj;
	}
*/	
	
	/**
	 * Converts a Zotero_Creator object to a SimpleXMLElement item
	 *
	 * @param	object				$item		Zotero_Creator object
	 * @return	SimpleXMLElement				Creator data as SimpleXML element
	 */
	public static function convertCreatorToXML(Zotero_Creator $creator) {
		$xml = new SimpleXMLElement('<creator/>');
		$xml['libraryID'] = $creator->libraryID;
		$xml['key'] = $creator->key;
		$xml['dateAdded'] = $creator->dateAdded;
		$xml['dateModified'] = $creator->dateModified;
		
		if ($creator->fieldMode == 1) {
			$n = $xml->addChild('name', htmlspecialchars($creator->lastName));
			$fm = $xml->addChild('fieldMode', 1);
		}
		else {
			$fn = $xml->addChild('firstName', htmlspecialchars($creator->firstName));
			$ln = $xml->addChild('lastName', htmlspecialchars($creator->lastName));
		}
		
		if ($creator->birthYear) {
			$xml->addChild('birthYear', $creator->birthYear);
		}
		
		return $xml;
	}
	
	
	public static function updateLinkedItems($creatorID, $dateModified) {
		Zotero_DB::beginTransaction();
		
		// TODO: add to notifier, if we have one
		//$sql = "SELECT itemID FROM itemCreators WHERE creatorID=?";
		//$changedItemIDs = Zotero_DB::columnQuery($sql, $creatorID);
		
		// This is very slow in MySQL 5.1.33 -- should be faster in MySQL 6
		/*
		$sql = "UPDATE items SET dateModified=?, serverDateModified=? WHERE itemID IN
				(SELECT itemID FROM itemCreators WHERE creatorID=?)";
		*/
		
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
	
	
	public static function getCreatorDataIDCacheKey($hash) {
		return 'creatorDataID_' . $hash;
	}
	
	public static function getCreatorDataCacheKey($id) {
		return 'creatorData_' . $id;
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
	
	
	private static function getPrimaryDataSQL() {
		return "SELECT creatorID AS id, libraryID, `key`, dateAdded, dateModified, creatorDataID
				FROM creators WHERE ";
	}
	
	private static function cachePrimaryDataByLibrary($libraryID) {
		self::$primaryDataByLibraryAndKey[$libraryID] = array();
		
		$sql = self::getPrimaryDataSQL() . "libraryID=?";
		$rows = Zotero_DB::query($sql, $libraryID);
		if (!$rows) {
			return;
		}
		
		foreach ($rows as $row) {
			self::$primaryDataByCreatorID[$row['id']] = $row;
			self::$primaryDataByLibraryAndKey[$libraryID][$row['key']] = self::$primaryDataByCreatorID[$row['id']];
		}
	}
}
?>
