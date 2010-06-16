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
			// Always use auto-increment
			// TODO: purge these sometimes?
			case 'creatorData':
			case 'itemDataValues':
			case 'mimeTypes':
			case 'tags':
			case 'creators':
			case 'collections':
			case 'items':
			case 'relations':
			case 'savedSearches':
			case 'tags':
			case 'tagData':
				return null;
			
			// Non-autoincrement tables
			//case :
				//return self::getNext($table);
				
			default:
				trigger_error("Unsupported table '$table'", E_USER_ERROR);
		}
	}
	
	
	public static function getBigInt() {
		return rand(1, 2147483647);
	}
	
	
	/*              
	* Get MAX(id) + 1 from table
	*/                     
	private function getNext($table) {
		$column = self::getTableColumn($table);
		$where = self::getWhere($table);
		$sql = "SELECT NEXT_ID($column) FROM $table" . $where;
		return Zotero_DB::valueQuery($sql);
	}
	
	
	private function getTableColumn($table) {
		switch ($table) {
			case 'creatorData':
				return 'creatorDataID';
			
			case 'itemDataValues':
				return 'itemDataValueID';
				
			case 'savedSearches':
				return 'savedSearchID';
				
			default:
				return substr($table, 0, -1) . 'ID';
       }
	}
	
	
	private function getWhere($table) {
		$where = ' WHERE ';
		
		switch ($table) {
			case 'items':
			case 'creators':
				break;
				
			default:
				trigger_error("Invalid table '$table'", E_USER_ERROR);
		}
		
		return $where;
	}
}
?>
