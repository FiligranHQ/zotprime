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

class Zotero_ID {
	/*
	* Gets an unused primary key id for a DB table
	*/             
	public static function get($table) {
		switch ($table) {
			case 'collections':
			case 'creators':
			case 'items':
			case 'relations':
			case 'savedSearches':
			case 'tags':
				return self::getNext($table);
				
			default:
				trigger_error("Unsupported table '$table'", E_USER_ERROR);
		}
	}
	
	
	public static function getKey() {
		return Zotero_Utilities::randomString(8, 'key', true);
	}
	
	
	public static function getBigInt() {
		return rand(1, 2147483647);
	}
	
	
	/*              
	* Get MAX(id) + 1 from ids databases
	*/                     
	private static function getNext($table) {
		$sql = "REPLACE INTO $table (stub) VALUES ('a')";
		if (Z_Core::probability(2)) {
			try {
				Zotero_ID_DB_1::query($sql);
				$id = Zotero_ID_DB_1::valueQuery("SELECT LAST_INSERT_ID()");
			}
			catch (Exception $e) {
				Zotero_ID_DB_2::query($sql);
				$id = Zotero_ID_DB_2::valueQuery("SELECT LAST_INSERT_ID()");
			}
		}
		else {
			try {
				Zotero_ID_DB_2::query($sql);
				$id = Zotero_ID_DB_2::valueQuery("SELECT LAST_INSERT_ID()");
			}
			catch (Exception $e) {
				Zotero_ID_DB_1::query($sql);
				$id = Zotero_ID_DB_1::valueQuery("SELECT LAST_INSERT_ID()");
			}
		}
		
		if (!$id || !is_int($id)) {
			throw new Exception("Invalid id $id");
		}
		
		return $id;
	}
}
?>
