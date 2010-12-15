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
	private static $fieldIDs = array();
	private static $fieldNames = array();
	private static $customFieldCheck = array();
	private static $itemTypeFields = array();
	
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
		
		if (isset(self::$fieldIDs[$fieldOrFieldID])) {
			return self::$fieldIDs[$fieldOrFieldID];
		}
		
		$cacheKey = "itemFieldID_" . $fieldOrFieldID;
		$fieldID = Z_Core::$MC->get($cacheKey);
		if ($fieldID) {
			self::$fieldIDs[$fieldOrFieldID] = $fieldID;
			return $fieldID;
		}
		
		$sql = "(SELECT fieldID FROM fields WHERE fieldID=?) UNION 
				(SELECT fieldID FROM fields WHERE fieldName=?) LIMIT 1";
		$fieldID = Zotero_DB::valueQuery($sql, array($fieldOrFieldID, $fieldOrFieldID));
		
		self::$fieldIDs[$fieldOrFieldID] = $fieldID;
		Z_Core::$MC->set($cacheKey, $fieldID);
		
		return $fieldID;
	}
	
	
	public static function getName($fieldOrFieldID) {
		// TODO: batch load
		
		if (isset(self::$fieldNames[$fieldOrFieldID])) {
			return self::$fieldNames[$fieldOrFieldID];
		}
		
		$cacheKey = "itemFieldName_" . $fieldOrFieldID;
		$fieldName = Z_Core::$MC->get($cacheKey);
		if ($fieldName) {
			self::$fieldNames[$fieldOrFieldID] = $fieldName;
			return $fieldName;
		}
		
		$sql = "(SELECT fieldName FROM fields WHERE fieldID=?) UNION 
				(SELECT fieldName FROM fields WHERE fieldName=?) LIMIT 1";
		$fieldName = Zotero_DB::valueQuery($sql, array($fieldOrFieldID, $fieldOrFieldID));
		
		self::$fieldNames[$fieldOrFieldID] = $fieldName;
		Z_Core::$MC->set($cacheKey, $fieldName);
		
		return $fieldName;
	}
	
	
	public static function getLocalizedString($itemType, $field) {
		// unused currently
		//var typeName = Zotero.ItemTypes.getName(itemType);
		$fieldName = self::getName($field);
		
		// Fields in the items table are special cases
		switch ($field) {
			case 'dateAdded':
			case 'dateModified':
			case 'itemType':
				$fieldName = $field;
		}
		
		// TODO: different labels for different item types
		
		return self::$localizedFields[$fieldName];
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
			trigger_error("Field not provided", E_USER_ERROR);
		}
		
		switch ($field) {
			case 'itemTypeID':
				return is_integer($value);
		}
		
		return true;
	}
	
	
	public static function isValidForType($fieldID, $itemTypeID) {
		if (!self::getID($fieldID)) {
			trigger_error("Invalid fieldID '$fieldID'", E_USER_ERROR);
		}
		
		if (!Zotero_ItemTypes::getID($itemTypeID)) {
			trigger_error("Invalid item type id '$itemTypeID'", E_USER_ERROR);
		}
		
		$sql = "SELECT COUNT(*) FROM itemTypeFields WHERE
			itemTypeID=? AND fieldID=?";
		return !!Zotero_DB::valueQuery($sql, array($itemTypeID, $fieldID));
	}
	
	
	public static function getItemTypeFields($itemTypeID) {
		if (isset(self::$itemTypeFields[$itemTypeID])) {
			return self::$itemTypeFields[$itemTypeID];
		}
		
		if (!Zotero_ItemTypes::getID($itemTypeID)) {
			trigger_error("Invalid item type id '$itemTypeID'", E_USER_ERROR);
		}
		
		$sql = 'SELECT fieldID FROM itemTypeFields
					WHERE itemTypeID=? ORDER BY orderIndex';
		$fields = Zotero_DB::columnQuery($sql, $itemTypeID);
		
		$fields = $fields ? $fields : array();
		self::$itemTypeFields[$itemTypeID] = $fields;
		return $fields;
	}
	
	
	public static function isBaseField($field) {
		$fieldID = self::getID($field);
		if (!$fieldID) {
			trigger_error("Invalid field '$field'", E_USER_ERROR);
		}
		
		$sql = "SELECT COUNT(*) FROM baseFieldMappings WHERE baseFieldID=?";
		return !!Zotero_DB::valueQuery($sql, $fieldID);
	}
	
	
	public static function isFieldOfBase($field, $baseField) {
		$fieldID = self::getID($field);
		if (!$fieldID) {
			trigger_error("Invalid field '$field'", E_USER_ERROR);
		}
		
		$baseFieldID = self::getID($baseField);
		if (!$baseFieldID) {
			trigger_error("Invalid field '$baseField' for base field", E_USER_ERROR);
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
		$itemTypeID = Zotero_ItemTypes::getID($itemType);
		if (!$itemTypeID) {
			trigger_error("Invalid item type '$itemType'", E_USER_ERROR);
		}
		
		$baseFieldID = self::getID($baseField);
		if (!$baseFieldID) {
			trigger_error("Invalid field '$baseField' for base field", E_USER_ERROR);
		}
		
		$sql = "SELECT fieldID FROM baseFieldMappings
					WHERE itemTypeID=? AND baseFieldID=?
				UNION
				SELECT baseFieldID FROM baseFieldMappings
					WHERE itemTypeID=? AND baseFieldID=?";
		return Zotero_DB::valueQuery($sql,
			array($itemTypeID, $baseFieldID, $itemTypeID, $baseFieldID));
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
			trigger_error("Invalid item type '$itemType'", E_USER_ERROR);
		}
		
		$typeFieldID = self::getID($typeField);
		if (!$typeFieldID) {
			trigger_error("Invalid field '$typeField'", E_USER_ERROR);
		}
		
		if (!self::isValidForType($typeFieldID, $itemTypeID)) {
			trigger_error("'$typeField' is not a valid field for '$itemType'", E_USER_ERROR);
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
			trigger_error("Invalid base field '$baseField'", E_USER_ERROR);
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
			trigger_error("Invalid fieldID '$fieldID'", E_USER_ERROR);
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
