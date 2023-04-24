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

class Z_String {
	/**
	* Convert a CamelCase string into underscore_case
	*
	* @param		string	$string
	* @return	string
	**/
	public static function camel2under($string){
		return strtolower(preg_replace(array('/([a-z])([A-Z])/'), array('$1_$2'), $string));
	}
	
	/**
	* Convert an underscore_case string to CamelCase string
	*
	* @param		string	$string
	* @return	string
	**/
	public static function under2camel($string){
		return str_replace(' ', '', ucwords(str_replace('_', ' ', $string)));
	}
}
?>
