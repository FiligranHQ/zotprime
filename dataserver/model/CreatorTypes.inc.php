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

class Zotero_CreatorTypes {
	private static $typeIDs = array();
	private static $typeNames = array();
	private static $primaryIDCache = array();
	private static $creatorTypesByItemType = array();
	private static $isValidForItemType = array();
	
	private static $localizedStrings = array(
		"author"				=> "Author",
		"contributor"		=> "Contributor",
		"editor"				=> "Editor",
		"translator"			=> "Translator",
		"seriesEditor"		=> "Series Editor",
		"interviewee"		=> "Interview With",
		"interviewer"		=> "Interviewer",
		"director"			=> "Director",
		"scriptwriter"		=> "Scriptwriter",
		"producer"			=> "Producer",
		"castMember"			=> "Cast Member",
		"sponsor"			=> "Sponsor",
		"counsel"			=> "Counsel",
		"inventor"			=> "Inventor",
		"attorneyAgent"		=> "Attorney/Agent",
		"recipient"			=> "Recipient",
		"performer"			=> "Performer",
		"composer"			=> "Composer",
		"wordsBy"			=> "Words By",
		"cartographer"		=> "Cartographer",
		"programmer"			=> "Programmer",
		"reviewedAuthor"	=> "Reviewed Author",
		"artist"				=> "Artist",
		"commenter"			=> "Commenter",
		"presenter"			=> "Presenter",
		"guest"				=> "Guest",
		"podcaster"			=> "Podcaster",
		"reviewedAuthor"	=> "Reviewed Author",
		"cosponsor"			=> "Cosponsor",
		"bookAuthor"		=> "Book Author"
	);
	
	public static function getID($typeOrTypeID) {
		if (isset(self::$typeIDs[$typeOrTypeID])) {
			return self::$typeIDs[$typeOrTypeID];
		}
		
		$cacheKey = "creatorTypeID_" . $typeOrTypeID;
		$typeID = Z_Core::$MC->get($cacheKey);
		if ($typeID) {
			// casts are temporary until memcached reload
			self::$typeIDs[$typeOrTypeID] = (int) $typeID;
			return (int) $typeID;
		}
		
		$sql = "(SELECT creatorTypeID FROM creatorTypes WHERE creatorTypeID=?) UNION
				(SELECT creatorTypeID FROM creatorTypes WHERE creatorTypeName=?) LIMIT 1";
		$typeID = Zotero_DB::valueQuery($sql, array($typeOrTypeID, $typeOrTypeID));
		
		self::$typeIDs[$typeOrTypeID] = $typeID ? (int) $typeID : false;
		Z_Core::$MC->set($cacheKey, (int) $typeID);
		
		return (int) $typeID;
	}
	
	
	public static function getName($typeOrTypeID) {
		if (isset(self::$typeNames[$typeOrTypeID])) {
			return self::$typeNames[$typeOrTypeID];
		}
		
		$cacheKey = "creatorTypeName_" . $typeOrTypeID;
		$typeName = Z_Core::$MC->get($cacheKey);
		if ($typeName) {
			self::$typeNames[$typeOrTypeID] = $typeName;
			return $typeName;
		}
		
		$sql = "(SELECT creatorTypeName FROM creatorTypes WHERE creatorTypeID=?) UNION
				(SELECT creatorTypeName FROM creatorTypes WHERE creatorTypeName=?) LIMIT 1";
		$typeName = Zotero_DB::valueQuery($sql, array($typeOrTypeID, $typeOrTypeID));
		
		self::$typeNames[$typeOrTypeID] = $typeName;
		Z_Core::$MC->set($cacheKey, $typeName);
		
		return $typeName;
	}
	
	
	public static function getLocalizedString($typeOrTypeID, $locale='en-US') {
		if ($locale != 'en-US') {
			throw new Exception("Locale not yet supported");
		}
		$type = self::getName($typeOrTypeID);
		return self::$localizedStrings[$type];
	}
	
	
	public static function getTypesForItemType($itemTypeID, $locale=false) {
		if (isset(self::$creatorTypesByItemType[$itemTypeID])) {
			return self::$creatorTypesByItemType[$itemTypeID];
		}
		
		$sql = "SELECT creatorTypeID AS id, creatorTypeName AS name
			FROM itemTypeCreatorTypes NATURAL JOIN creatorTypes
			WHERE itemTypeID=? ORDER BY primaryField=1 DESC, name";
		$rows = Zotero_DB::query($sql, $itemTypeID);
		if (!$rows) {
			$rows = array();
		}
		
		if (!$locale) {
			self::$creatorTypesByItemType[$itemTypeID] = $rows;
			return $rows;
		}
		
		foreach ($rows as &$row) {
			$row['localized'] =  self::getLocalizedString($row['id'], $locale);
		}
		
		$primary = array_shift($rows);
		
		usort($rows, function ($a, $b) {
			return strcmp($a["localized"], $b["localized"]);
		});
		
		array_unshift($rows, $primary);
		
		self::$creatorTypesByItemType[$itemTypeID] = $rows;
		
		return $rows;
	}
	
	
	public static function isValidForItemType($creatorTypeID, $itemTypeID) {
		if (isset(self::$isValidForItemType[$itemTypeID][$creatorTypeID])) {
			return self::$isValidForItemType[$itemTypeID][$creatorTypeID];
		}
		
		$valid = false;
		$types = self::getTypesForItemType($itemTypeID);
		foreach ($types as $type) {
			if ($type['id'] == $creatorTypeID) {
				$valid = true;
				break;
			}
		}
		
		if (!isset(self::$isValidForItemType[$itemTypeID])) {
			self::$isValidForItemType[$itemTypeID] = array();
		}
		self::$isValidForItemType[$itemTypeID][$creatorTypeID] = $valid;
		return $valid;
	}
	
	
	public static function getPrimaryIDForType($itemTypeID) {
		// Check local cache
		if (isset(self::$primaryIDCache[$itemTypeID])) {
			return self::$primaryIDCache[$itemTypeID];
		}
		
		// Check memcached
		$cacheKey = "primaryCreatorTypeID_" . $itemTypeID;
		$creatorTypeID = Z_Core::$MC->get($cacheKey);
		if ($creatorTypeID) {
			self::$primaryIDCache[$itemTypeID] = $creatorTypeID;
			return $creatorTypeID;
		}

		
		$sql = "SELECT creatorTypeID FROM itemTypeCreatorTypes
			WHERE itemTypeID=? AND primaryField=1";
		$creatorTypeID = Zotero_DB::valueQuery($sql, $itemTypeID);
		
		// Store in local cache and memcached
		self::$primaryIDCache[$itemTypeID] = $creatorTypeID;
		Z_Core::$MC->set($cacheKey, $creatorTypeID);
		
		return $creatorTypeID;
	}
	
	
	public static function isCustomType($creatorTypeID) {
		$sql = "SELECT custom FROM creatorTypes WHERE creatorTypeID=?";
		$isCustom = Zotero_DB::valueQuery($sql, $creatorTypeID);
		if ($isCustom === false) {
			trigger_error("Invalid creatorTypeID '$creatorTypeID'", E_USER_ERROR);
		}
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
		
		Zotero_DB::beginTransaction();
		
		$sql = "SELECT NEXT_ID(creatorTypeID) FROM creatorTypes";
		$creatorTypeID = Zotero_DB::valueQuery($sql);
		
		$sql = "INSERT INTO creatorTypes (?, ?, ?)";
		Zotero_DB::query($sql, array($creatorTypeID, $name, 1));
		
		Zotero_DB::commit();
		
		return $creatorTypeID;
	}
}
?>
