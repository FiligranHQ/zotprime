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

class Zotero_ItemFields {
	private static $customFieldCheck = array();
	
	// Caches
	private static $fieldIDCache = array();
	private static $fieldNameCache = array();
	private static $itemTypeFieldsCache = array();
	private static $itemTypeBaseFieldIDCache = array();
	private static $isValidForTypeCache = array();
	
	private static $localizedFields = array(
			"itemType"			=> "Type",
			"title"        		=> "Title",
			"dateAdded"    		=> "Date Added",
			"dateModified" 		=> "Modified",
			"source"       		=> "Source",
			"notes"				=> "Notes",
			"tags"				=> "Tags",
			"attachments"		=> "Attachments",
			"related"			=> "Related",
			"url"				=> "URL",
			"rights"       		=> "Rights",
			"series"	    		=> "Series",
			"volume"       		=> "Volume",
			"issue"	       		=> "Issue",
			"edition"      		=> "Edition",
			"place"        		=> "Place",
			"publisher"    		=> "Publisher",
			"pages"        		=> "Pages",
			"ISBN"         		=> "ISBN",
			"publicationTitle"	=> "Publication",
			"ISSN"         		=> "ISSN",
			"date"					=> "Date",
			"section"				=> "Section",
			"callNumber"			=> "Call Number",
			"archiveLocation"		=> "Loc. in Archive",
			"distributor"			=> "Distributor",
			"extra"				=> "Extra",
			"journalAbbreviation"	=> "Journal Abbr",
			"DOI"					=> "DOI",
			"accessDate"			=> "Accessed",
			"seriesTitle"    		=> "Series Title",
			"seriesText"    		=> "Series Text",
			"seriesNumber"   		=> "Series Number",
			"institution"			=> "Institution",
			"reportType"			=> "Report Type",
			"code"					=> "Code",
			"session"				=> "Session",
			"legislativeBody"		=> "Legislative Body",
			"history"				=> "History",
			"reporter"				=> "Reporter",
			"court"				=> "Court",
			"numberOfVolumes"		=> "# of Volumes",
			"committee"			=> "Committee",
			"assignee"				=> "Assignee",
			"patentNumber"			=> "Patent Number",
			"priorityNumbers"		=> "Priority Numbers",
			"issueDate"			=> "Issue Date",
			"references"			=> "References",
			"legalStatus"			=> "Legal Status",
			"codeNumber"			=> "Code Number",
			"artworkMedium"			=> "Medium",
			"number"				=> "Number",
			"artworkSize"			=> "Artwork Size",
			"libraryCatalog"		=> "Library Catalog",
			"repository"			=> "Repository",
			"videoRecordingFormat"	=> "Format",
			"interviewMedium"		=> "Medium",
			"letterType"			=> "Type",
			"manuscriptType"		=> "Type",
			"mapType"				=> "Type",
			"scale"				=> "Scale",
			"thesisType"			=> "Type",
			"websiteType"			=> "Website Type",
			"audioRecordingFormat"	=> "Format",
			"label"				=> "Label",
			"presentationType"	=> "Type",
			"meetingName"			=> "Meeting Name",
			"studio"				=> "Studio",
			"runningTime"			=> "Running Time",
			"network"				=> "Network",
			"postType"				=> "Post Type",
			"audioFileType"		=> "File Type",
			"version"				=> "Version",
			"system"				=> "System",
			"company"				=> "Company",
			"conferenceName"		=> "Conference Name",
			"encyclopediaTitle"		=> "Encyclopedia Title",
			"dictionaryTitle"		=> "Dictionary Title",
			"language"				=> "Language",
			"programmingLanguage"	=> "Language",
			"university"			=> "University",
			"abstractNote"			=> "Abstract",
			"websiteTitle"			=> "Website Title",
			"reportNumber"			=> "Report Number",
			"billNumber"			=> "Bill Number",
			"codeVolume"			=> "Code Volume",
			"codePages"				=> "Code Pages",
			"dateDecided"			=> "Date Decided",
			"reporterVolume"		=> "Reporter Volume",
			"firstPage"				=> "First Page",
			"documentNumber"		=> "Document Number",
			"dateEnacted"			=> "Date Enacted",
			"publicLawNumber"		=> "Public Law Number",
			"country"				=> "Country",
			"applicationNumber"		=> "Application Number",
			"forumTitle"			=> "Forum/Listserv Title",
			"episodeNumber"			=> "Episode Number",
			"blogTitle"				=> "Blog Title",
			"medium"				=> "Medium",
			"caseName"				=> "Case Name",
			"nameOfAct"				=> "Name of Act",
			"subject"				=> "Subject",
			"proceedingsTitle"		=> "Proceedings Title",
			"bookTitle"				=> "Book Title",
			"shortTitle"			=> "Short Title",
			"docketNumber"			=> "Docket Number",
			"numPages"				=> "# of Pages",
			"programTitle"			=> "Program Title",
			"issuingAuthority"		=> "Issuing Authority",
			"filingDate"			=> "Filing Date",
			"genre"					=> "Genre",
			"archive"				=> "Archive"
		);

	
	public static function getID($fieldOrFieldID) {
		// TODO: batch load
		
		if (isset(self::$fieldIDCache[$fieldOrFieldID])) {
			return self::$fieldIDCache[$fieldOrFieldID];
		}
		
		$cacheKey = "itemFieldID_" . $fieldOrFieldID;
		$fieldID = Z_Core::$MC->get($cacheKey);
		if ($fieldID) {
			self::$fieldIDCache[$fieldOrFieldID] = $fieldID;
			return $fieldID;
		}
		
		$sql = "(SELECT fieldID FROM fields WHERE fieldID=?) UNION
				(SELECT fieldID FROM fields WHERE fieldName=?) LIMIT 1";
		$fieldID = Zotero_DB::valueQuery($sql, array($fieldOrFieldID, $fieldOrFieldID));
		
		self::$fieldIDCache[$fieldOrFieldID] = $fieldID;
		Z_Core::$MC->set($cacheKey, $fieldID);
		
		return $fieldID;
	}
	
	
	public static function getName($fieldOrFieldID) {
		if (isset(self::$fieldNameCache[$fieldOrFieldID])) {
			return self::$fieldNameCache[$fieldOrFieldID];
		}
		
		$cacheKey = "itemFieldName_" . $fieldOrFieldID;
		$fieldName = Z_Core::$MC->get($cacheKey);
		if ($fieldName) {
			self::$fieldNameCache[$fieldOrFieldID] = $fieldName;
			return $fieldName;
		}
		
		$sql = "(SELECT fieldName FROM fields WHERE fieldID=?) UNION
				(SELECT fieldName FROM fields WHERE fieldName=?) LIMIT 1";
		$fieldName = Zotero_DB::valueQuery($sql, array($fieldOrFieldID, $fieldOrFieldID));
		
		self::$fieldNameCache[$fieldOrFieldID] = $fieldName;
		Z_Core::$MC->set($cacheKey, $fieldName);
		
		return $fieldName;
	}
	
	
	public static function getLocalizedString($itemType, $field) {
		// Fields in the items table are special cases
		switch ($field) {
			case 'dateAdded':
			case 'dateModified':
			case 'itemType':
				$fieldName = $field;
				break;
			
			default:
				// unused currently
				//var typeName = Zotero.ItemTypes.getName(itemType);
				$fieldName = self::getName($field);
		}
		
		// TODO: different labels for different item types
		
		return self::$localizedFields[$fieldName];
	}
	
	
	public static function getAll($includeLocalized=false) {
		$sql = "SELECT DISTINCT fieldID AS id, fieldName AS name
				FROM fields JOIN itemTypeFields USING (fieldID)";
		// TEMP - skip nsfReviewer fields
		$sql .= " WHERE fieldID < 10000";
		$rows = Zotero_DB::query($sql);
		
		// TODO: cache
		
		if (!$includeLocalized) {
			return $rows;
		}
		
		foreach ($rows as &$row) {
			$row['localized'] =  self::getLocalizedString(false, $row['id']);
		}
		
		usort($rows, function ($a, $b) {
			return strcmp($a["localized"], $b["localized"]);
		});
		
		return $rows;
	}
	
	
	/**
	 * Validates field content
	 *
	 * @param	string		$field		Field name
	 * @param	mixed		$value
	 * @return	bool
	 */
	public static function validate($field, $value) {
		if (empty($field)) {
			throw new Exception("Field not provided");
		}
		
		switch ($field) {
			case 'itemTypeID':
				return is_integer($value);
		}
		
		return true;
	}
	
	
	public static function isValidForType($fieldID, $itemTypeID) {
		// Check local cache
		if (isset(self::$isValidForTypeCache[$itemTypeID][$fieldID])) {
			return self::$isValidForTypeCache[$itemTypeID][$fieldID];
		}
		
		// Check memcached
		$cacheKey = "isValidForType_" . $itemTypeID . "_" . $fieldID;
		$valid = Z_Core::$MC->get($cacheKey);
		if ($valid !== false) {
			if (!isset(self::$isValidForTypeCache[$itemTypeID])) {
				self::$isValidForTypeCache[$itemTypeID] = array();
			}
			self::$isValidForTypeCache[$itemTypeID] = !!$valid;
			return $valid;
		}
		
		if (!self::getID($fieldID)) {
			throw new Exception("Invalid fieldID '$fieldID'");
		}
		
		if (!Zotero_ItemTypes::getID($itemTypeID)) {
			throw new Exception("Invalid item type id '$itemTypeID'");
		}
		
		$sql = "SELECT COUNT(*) FROM itemTypeFields WHERE itemTypeID=? AND fieldID=?";
		$valid = !!Zotero_DB::valueQuery($sql, array($itemTypeID, $fieldID));
		
		// Store in local cache and memcached
		if (!isset(self::$isValidForTypeCache[$itemTypeID])) {
			self::$isValidForTypeCache[$itemTypeID] = array();
		}
		self::$isValidForTypeCache[$itemTypeID] = $valid;
		Z_Core::$MC->get($cacheKey, $valid ? true : 0);
		
		return $valid;
	}
	
	
	public static function getItemTypeFields($itemTypeID) {
		if (isset(self::$itemTypeFieldsCache[$itemTypeID])) {
			return self::$itemTypeFieldsCache[$itemTypeID];
		}
		
		$cacheKey = "itemTypeFields_" . $itemTypeID;
		$fields = Z_Core::$MC->get($cacheKey);
		if ($fields !== false) {
			self::$itemTypeFieldsCache[$itemTypeID] = $fields;
			return $fields;
		}
		
		if (!Zotero_ItemTypes::getID($itemTypeID)) {
			throw new Exception("Invalid item type id '$itemTypeID'");
		}
		
		$sql = 'SELECT fieldID FROM itemTypeFields WHERE itemTypeID=? ORDER BY orderIndex';
		$fields = Zotero_DB::columnQuery($sql, $itemTypeID);
		if (!$fields) {
			$fields = array();
		}
		
		self::$itemTypeFieldsCache[$itemTypeID] = $fields;
		Z_Core::$MC->set($cacheKey, $fields);
		
		return $fields;
	}
	
	
	public static function isBaseField($field) {
		$fieldID = self::getID($field);
		if (!$fieldID) {
			throw new Exception("Invalid field '$field'");
		}
		
		$sql = "SELECT COUNT(*) FROM baseFieldMappings WHERE baseFieldID=?";
		return !!Zotero_DB::valueQuery($sql, $fieldID);
	}
	
	
	public static function isFieldOfBase($field, $baseField) {
		$fieldID = self::getID($field);
		if (!$fieldID) {
			throw new Exception("Invalid field '$field'");
		}
		
		$baseFieldID = self::getID($baseField);
		if (!$baseFieldID) {
			throw new Exception("Invalid field '$baseField' for base field");
		}
		
		if ($fieldID == $baseFieldID) {
			return true;
		}
		
		$typeFields = self::getTypeFieldsFromBase($baseFieldID);
		return in_array($fieldID, $typeFields);
	}
	
	
	public static function getBaseMappedFields() {
		$sql = "SELECT DISTINCT fieldID FROM baseFieldMappings";
		return Zotero_DB::columnQuery($sql);
	}
	
	
	/*
	 * Returns the fieldID of a type-specific field for a given base field
	 * 		or false if none
	 *
	 * Examples:
	 *
	 * 'audioRecording' and 'publisher' returns label's fieldID
	 * 'book' and 'publisher' returns publisher's fieldID
	 * 'audioRecording' and 'number' returns false
	 *
	 * Accepts names or ids
	 */
	public static function getFieldIDFromTypeAndBase($itemType, $baseField) {
		// Check local cache
		if (isset(self::$itemTypeBaseFieldIDCache[$itemType][$baseField])) {
			return self::$itemTypeBaseFieldIDCache[$itemType][$baseField];
		}
		
		// Check memcached
		$cacheKey = "itemTypeBaseFieldID_" . $itemType . "_" . $baseField;
		$fieldID = Z_Core::$MC->get($cacheKey);
		if ($fieldID !== false) {
			if (!isset(self::$itemTypeBaseFieldIDCache[$itemType])) {
				self::$itemTypeBaseFieldIDCache[$itemType] = array();
			}
			// FALSE is stored as 0
			if ($fieldID == 0) {
				$fieldID = false;
			}
			self::$itemTypeBaseFieldIDCache[$itemType][$baseField] = $fieldID;
			return $fieldID;
		}
		
		$itemTypeID = Zotero_ItemTypes::getID($itemType);
		if (!$itemTypeID) {
			throw new Exception("Invalid item type '$itemType'");
		}
		
		$baseFieldID = self::getID($baseField);
		if (!$baseFieldID) {
			throw new Exception("Invalid field '$baseField' for base field");
		}
		
		$sql = "SELECT fieldID FROM baseFieldMappings
					WHERE itemTypeID=? AND baseFieldID=?
				UNION
				SELECT baseFieldID FROM baseFieldMappings
					WHERE itemTypeID=? AND baseFieldID=?";
		$fieldID =  Zotero_DB::valueQuery(
			$sql,
			array($itemTypeID, $baseFieldID, $itemTypeID, $baseFieldID)
		);
		
		// Store in local cache
		if (!isset(self::$itemTypeBaseFieldIDCache[$itemType])) {
			self::$itemTypeBaseFieldIDCache[$itemType] = array();
		}
		self::$itemTypeBaseFieldIDCache[$itemType][$baseField] = $fieldID;
		// Store in memcached (and store FALSE as 0)
		Z_Core::$MC->set($cacheKey, $fieldID ? $fieldID : 0);
		
		return $fieldID;
	}
	
	
	/*
	 * Returns the fieldID of the base field for a given type-specific field
	 * 		or false if none
	 *
	 * Examples:
	 *
	 * 'audioRecording' and 'label' returns publisher's fieldID
	 * 'book' and 'publisher' returns publisher's fieldID
	 * 'audioRecording' and 'runningTime' returns false
	 *
	 * Accepts names or ids
	 */
	public static function getBaseIDFromTypeAndField($itemType, $typeField) {
		$itemTypeID = Zotero_ItemTypes::getID($itemType);
		if (!$itemTypeID) {
			throw new Exception("Invalid item type '$itemType'");
		}
		
		$typeFieldID = self::getID($typeField);
		if (!$typeFieldID) {
			throw new Exception("Invalid field '$typeField'");
		}
		
		if (!self::isValidForType($typeFieldID, $itemTypeID)) {
			throw new Exception("'$typeField' is not a valid field for '$itemType'");
		}
		
		// If typeField is already a base field, just return that
		if (self::isBaseField($typeFieldID)) {
			return $typeFieldID;
		}
		
		return Zotero_DB::valueQuery("SELECT baseFieldID FROM baseFieldMappings
			WHERE itemTypeID=? AND fieldID=?", array($itemTypeID, $typeFieldID));
	}
	
	
	/*
	 * Returns an array of fieldIDs associated with a given base field
	 *
	 * e.g. 'publisher' returns fieldIDs for [university, studio, label, network]
	 */
	public static function getTypeFieldsFromBase($baseField, $asNames=false) {
		$baseFieldID = self::getID($baseField);
		if (!$baseFieldID) {
			throw new Exception("Invalid base field '$baseField'");
		}
		
		if ($asNames) {
			$sql = "SELECT fieldName FROM fields WHERE fieldID IN (
				SELECT fieldID FROM baseFieldMappings
				WHERE baseFieldID=?)";
			return Zotero_DB::columnQuery($sql, $baseFieldID);
		}
		
		// TEMP
		if ($baseFieldID==14) {
			return array(96,52,100,10008);
		}
		
		$sql = "SELECT DISTINCT fieldID FROM baseFieldMappings WHERE baseFieldID=?";
		return Zotero_DB::columnQuery($sql, $baseFieldID);
	}
	
	
	public static function isCustomField($fieldID) {
		if (isset(self::$customFieldCheck)) {
			return self::$customFieldCheck;
		}
		
		$sql = "SELECT custom FROM fields WHERE fieldID=?";
		$isCustom = Zotero_DB::valueQuery($sql, $fieldID);
		if ($isCustom === false) {
			throw new Exception("Invalid fieldID '$fieldID'");
		}
		
		self::$customFieldCheck[$fieldID] = !!$isCustom;
		
		return !!$isCustom;
	}
	
	
	public static function addCustomField($name) {
		if (self::getID($name)) {
			trigger_error("Field '$name' already exists", E_USER_ERROR);
		}
		
		if (!preg_match('/^[a-z][^\s0-9]+$/', $name)) {
			trigger_error("Invalid field name '$name'", E_USER_ERROR);
		}
		
		// TODO: make sure user hasn't added too many already
		
		trigger_error("Unimplemented", E_USER_ERROR);
		// TODO: add to cache
		
		Zotero_DB::beginTransaction();
		
		$sql = "SELECT NEXT_ID(fieldID) FROM fields";
		$fieldID = Zotero_DB::valueQuery($sql);
		
		$sql = "INSERT INTO fields (?, ?, ?)";
		Zotero_DB::query($sql, array($fieldID, $name, 1));
		
		Zotero_DB::commit();
		
		return $fieldID;
	}
}
?>
