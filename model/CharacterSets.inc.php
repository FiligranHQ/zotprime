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

class Zotero_CharacterSets {
	private static $charsetIDs = array();
	private static $charsets = array();
	
	public static function getID($charsetOrCharsetID) {
		if (isset(self::$charsetIDs[$charsetOrCharsetID])) {
			return self::$charsetIDs[$charsetOrCharsetID];
		}
		
		$sql = "(SELECT charsetID FROM charsets WHERE charsetID=?) UNION
				(SELECT charsetID FROM charsets WHERE charset=?) LIMIT 1";
		$charsetID = Zotero_DB::valueQuery($sql, array($charsetOrCharsetID, $charsetOrCharsetID));
		
		self::$charsetIDs[$charsetOrCharsetID] = $charsetID;
		
		return $charsetID;
	}
	
	
	public static function getName($charsetOrCharsetID) {
		if (isset(self::$charsets[$charsetOrCharsetID])) {
			return self::$charsets[$charsetOrCharsetID];
		}
		
		$sql = "(SELECT charset FROM charsets WHERE charsetID=?) UNION
				(SELECT charset FROM charsets WHERE charset=?) LIMIT 1";
		$charset = Zotero_DB::valueQuery($sql, array($charsetOrCharsetID, $charsetOrCharsetID));
		
		self::$charsets[$charsetOrCharsetID] = $charset;
		
		return $charset;
	}
}
?>
