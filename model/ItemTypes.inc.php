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

class Zotero_ItemTypes {
	private static $typeIDs = array();
	private static $typeNames = array();
	private static $customTypeCheck = array();
	
	private static $localizedStrings = array(
		"note"					=> "Note",
		"attachment"			=> "Attachment",
		"book"					=> "Book",
		"bookSection"			=> "Book Section",
		"journalArticle" 		=> "Journal Article",
		"magazineArticle" 		=> "Magazine Article",
		"newspaperArticle"		=> "Newspaper Article",
		"thesis"		 		=> "Thesis",
		"letter" 				=> "Letter",
		"manuscript" 			=> "Manuscript",
		"interview" 			=> "Interview",
		"film" 					=> "Film",
		"artwork" 				=> "Artwork",
		"webpage" 				=> "Web Page",
		"report"				=> "Report",
		"bill"					=> "Bill",
		"case"					=> "Case",
		"hearing"				=> "Hearing",
		"patent"				=> "Patent",
		"statute"				=> "Statute",
		"email"					=> "E-mail",
		"map"					=> "Map",
		"blogPost"				=> "Blog Post",
		"instantMessage"		=> "Instant Message",
		"forumPost"				=> "Forum Post",
		"audioRecording"		=> "Audio Recording",
		"presentation"			=> "Presentation",
		"videoRecording"		=> "Video Recording",
		"tvBroadcast"			=> "TV Broadcast",
		"radioBroadcast"		=> "Radio Broadcast",
		"podcast"				=> "Podcast",
		"computerProgram"		=> "Computer Program",
		"conferencePaper"		=> "Conference Paper",
		"document"				=> "Document",
		"encyclopediaArticle"	=> "Encyclopedia Article",
		"dictionaryEntry"		=> "Dictionary Entry",
		"nsfReviewer"			=> "NSF Reviewer"
	);
	
	public static function getID($typeOrTypeID) {
		if (isset(self::$typeIDs[$typeOrTypeID])) {
			return self::$typeIDs[$typeOrTypeID];
		}
		
		$cacheKey = "itemTypeID_" . $typeOrTypeID;
		$typeID = Z_Core::$MC->get($cacheKey);
		if ($typeID) {
			// casts are temporary until memcached reload
			self::$typeIDs[$typeOrTypeID] = (int) $typeID;
			return (int) $typeID;
		}
		
		$sql = "(SELECT itemTypeID FROM itemTypes WHERE itemTypeID=?) UNION
				(SELECT itemTypeID FROM itemTypes WHERE itemTypeName=?) LIMIT 1";
		$typeID = Zotero_DB::valueQuery($sql, array($typeOrTypeID, $typeOrTypeID));
		
		self::$typeIDs[$typeOrTypeID] = $typeID ? (int) $typeID : false;
		Z_Core::$MC->set($cacheKey, (int) $typeID);
		
		return $typeID ? (int) $typeID : false;
	}
	
	
	public static function getName($typeOrTypeID) {
		if (isset(self::$typeNames[$typeOrTypeID])) {
			return self::$typeNames[$typeOrTypeID];
		}
		
		$cacheKey = "itemTypeName_" . $typeOrTypeID;
		$typeName = Z_Core::$MC->get($cacheKey);
		if ($typeName) {
			self::$typeNames[$typeOrTypeID] = $typeName;
			return $typeName;
		}
		
		$sql = "(SELECT itemTypeName FROM itemTypes WHERE itemTypeID=?) UNION
				(SELECT itemTypeName FROM itemTypes WHERE itemTypeName=?) LIMIT 1";
		$typeName = Zotero_DB::valueQuery($sql, array($typeOrTypeID, $typeOrTypeID));
		
		self::$typeNames[$typeOrTypeID] = $typeName;
		Z_Core::$MC->set($cacheKey, $typeName);
		
		return $typeName;
	}
	
	
	public static function getLocalizedString($typeOrTypeID, $locale='en-US') {
		if ($locale != 'en-US') {
			throw new Exception("Locale not yet supported");
		}
		
		$itemType = self::getName($typeOrTypeID);
		return self::$localizedStrings[$itemType];
	}
	
	
	public static function getAll($locale=false) {
		$sql = "SELECT itemTypeID AS id, itemTypeName AS name FROM itemTypes";
		// TEMP - skip nsfReviewer and attachment
		$sql .= " WHERE itemTypeID NOT IN (14,10001)";
		$rows = Zotero_DB::query($sql);
		
		// TODO: cache
		
		if (!$locale) {
			return $rows;
		}
		
		foreach ($rows as &$row) {
			$row['localized'] =  self::getLocalizedString($row['id'], $locale);
		}
		
		usort($rows, function ($a, $b) {
			return strcmp($a["localized"], $b["localized"]);
		});
		
		return $rows;
	}
	
	
	public static function getImageSrc($itemType, $linkMode=false, $mimeType=false) {
		$prefix = "/static/i/itemType/treeitem";
		
		if ($itemType == 'attachment') {
			if ($mimeType == 'application/pdf') {
				$itemType .= '-pdf';
			}
			else {
				switch ($linkMode) {
					case 0:
						$itemType .= '-file';
						break;
					
					case 1:
						$itemType .= '-file';
						break;
					
					case 2:
						$itemType .= '-snapshot';
						break;
					
					case 3:
						$itemType .= '-web-link';
						break;
				}
			}
		}
		
		// DEBUG: only have icons for some types so far
		switch ($itemType) {
			case 'attachment-file':
			case 'attachment-link':
			case 'attachment-snapshot':
			case 'attachment-web-link':
			case 'attachment-pdf':
			case 'artwork':
			case 'audioRecording':
			case 'blogPost':
			case 'book':
			case 'bookSection':
			case 'computerProgram':
			case 'conferencePaper':
			case 'email':
			case 'film':
			case 'forumPost':
			case 'interview':
			case 'journalArticle':
			case 'letter':
			case 'magazineArticle':
			case 'manuscript':
			case 'map':
			case 'newspaperArticle':
			case 'note':
			case 'podcast':
			case 'radioBroadcast':
			case 'report':
			case 'thesis':
			case 'tvBroadcast':
			case 'videoRecording':
			case 'webpage':
				return $prefix . '-' . $itemType . ".png";
		}
		
		return $prefix . ".png";
	}
	
	
	public static function isCustomType($itemTypeID) {
		if (isset(self::$customTypeCheck)) {
			return self::$customTypeCheck;
		}
		
		$sql = "SELECT custom FROM itemTypes WHERE itemTypeID=?";
		$isCustom = Zotero_DB::valueQuery($sql, $itemTypeID);
		if ($isCustom === false) {
			trigger_error("Invalid itemTypeID '$itemTypeID'", E_USER_ERROR);
		}
		
		self::$customTypesCheck[$itemTypeID] = !!$isCustom;
		
		return !!$isCustom;
	}
	
	
	public static function addCustomType($name) {
		if (self::getID($name)) {
			trigger_error("Item type '$name' already exists", E_USER_ERROR);
		}
		
		if (!preg_match('/^[a-z][^\s0-9]+$/', $name)) {
			trigger_error("Invalid item type name '$name'", E_USER_ERROR);
		}
		
		// TODO: make sure user hasn't added too many already
		
		trigger_error("Unimplemented", E_USER_ERROR);
		// TODO: add to cache
		
		Zotero_DB::beginTransaction();
		
		$sql = "SELECT NEXT_ID(itemTypeID) FROM itemTypes";
		$itemTypeID = Zotero_DB::valueQuery($sql);
		
		$sql = "INSERT INTO itemTypes (?, ?, ?)";
		Zotero_DB::query($sql, array($itemTypeID, $name, 1));
		
		Zotero_DB::commit();
		
		return $itemTypeID;
	}
}
?>
