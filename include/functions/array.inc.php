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

class Z_Array {
	public static function array2string($array, $html=false) {
		ob_start(); 
		$html ? var_dump($array) : print_r($array);
		return ob_get_clean();
	}
	
	/**
	* Removes empty keys from an array (preserving indexes)
	*
	* @param		array		$array
	* @return	array
	**/
	public static function array_remove_empty($array){
		return array_filter($array, create_function('$val', 'return $val===0 || $val==="0" || !empty($val);'));
	}
}
?>
