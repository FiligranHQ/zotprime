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

class Zotero_Date {
        // Regexes for multipart and SQL dates
        // Allow zeroes in multipart dates
        // TODO: Allow negative multipart in DB and here with \-?
	private static $multipartRE = "/^[0-9]{4}\-(0[0-9]|10|11|12)\-(0[0-9]|[1-2][0-9]|30|31) /";
	private static $_sqldateRE = "/^\-?[0-9]{4}\-(0[1-9]|10|11|12)\-(0[1-9]|[1-2][0-9]|30|31)$/";
	private static $sqldatetimeRE = "/^\-?[0-9]{4}\-(0[1-9]|10|11|12)\-(0[1-9]|[1-2][0-9]|30|31) ([0-1][0-9]|[2][0-3]):([0-5][0-9]):([0-5][0-9])$/";
	
	/**
	 * Tests if a string is a multipart date string
	 * e.g. '2006-11-03 November 3rd, 2006'
	 */
	public static function isMultipart($str) {
		return !!preg_match(self::$multipartRE, $str);
	}
	
	
	/**
	 * Returns the SQL part of a multipart date string
	 * (e.g. '2006-11-03 November 3rd, 2006' returns '2006-11-03')
	 */
	public static function multipartToSQL($multi) {
			if (!$multi) {
				return '';
			}
			
			if (!self::isMultipart($multi)) {
				return '0000-00-00';
			}
			
			return substr($multi, 0, 10);
	}
	
	
	/**
	* Returns the user part of a multipart date string
	* (e.g. '2006-11-03 November 3rd, 2006' returns 'November 3rd, 2006')
	*/
	public static function multipartToStr($multi) {
		if (!$multi) {
			return '';
		}
		
		if (!self::isMultipart($multi)) {
			return $multi;
		}
		
		return substr($multi, 11);
	}
	
	
	public static function sqlToISO8601($sqlDate) {
		$date = substr($sqlDate, 0, 10);
		$time = substr($sqlDate, 11);
		if (!$time) {
			$time = "00:00:00";
		}
		return $date . "T" . $time . "Z";
	}
}
?>
