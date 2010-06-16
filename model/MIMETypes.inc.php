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

class Zotero_MIMETypes {
	private static $typeIDs = array();
	private static $typeNames = array();
	
	public static function getID($typeOrTypeID, $createNonExistent) {
		if (isset(self::$typeIDs[$typeOrTypeID])) {
			return self::$typeIDs[$typeOrTypeID];
		}
		
		$sql = "(SELECT mimeTypeID FROM mimeTypes WHERE mimeTypeID=?) UNION
				(SELECT mimeTypeID FROM mimeTypes WHERE mimeType=?) LIMIT 1";
		$typeID = Zotero_DB::valueQuery($sql, array($typeOrTypeID, $typeOrTypeID));
		
		if (!$typeID && preg_match("'.+/.+'", $typeOrTypeID) && $createNonExistent) {
			$typeID = Zotero_ID::get('mimeTypes');
			$sql = "INSERT INTO mimeTypes VALUES (?,?)";
			$insertID = Zotero_DB::query($sql, array($typeID, $typeOrTypeID));
			if (!$typeID) {
				$typeID = $insertID;
			}
		}
		
		self::$typeIDs[$typeOrTypeID] = $typeID;
		
		return $typeID;
	}
	
	
	public static function getName($typeOrTypeID) {
		if (isset(self::$typeNames[$typeOrTypeID])) {
			return self::$typeNames[$typeOrTypeID];
		}
		
		$sql = "(SELECT mimeType FROM mimeTypes WHERE mimeTypeID=?) UNION
				(SELECT mimeType FROM mimeTypes WHERE mimeType=?) LIMIT 1";
		$typeName = Zotero_DB::valueQuery($sql, array($typeOrTypeID, $typeOrTypeID));
		
		self::$typeNames[$typeOrTypeID] = $typeName;
		
		return $typeName;
	}
}
?>
