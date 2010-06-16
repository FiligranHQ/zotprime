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

class Zotero_Utilities {
	/**
	 * Generates random string of given length
	 *
	 * @param		int		$length
	 * @param		string	$case				'lower', 'upper' or 'mixed' (optional, default 'lower')
	 * @param		bool	$exclude_ambiguous	Include letters that are hard to distinguish visibly
	 *											(Optional, default false)
	 **/
	public static function randomString($length, $case='lower', $exclude_ambiguous=false) {
		// if you want extended ascii, then add the characters to the array
		$upper = array('A','B','C','D','E','F','G','H','I','J','K','L','M','N','P','Q','R','S','T','U','V','W','X','Y','Z');
		$lower = array('a','b','c','d','e','f','g','h','i','j','k','m','n','o','p','q','r','s','t','u','v','w','x','y','z');
		$numbers = array('2','3','4','5','6','7','8','9');
		
		switch ($case){
			case 'lower':
				$characters = array_merge($lower, $numbers);
				if (!$exclude_ambiguous){
					$characters = array_merge($characters, array('l','1','0'));
				}
				break;
			case 'mixed':
				$characters = array_merge($lower, $upper, $numbers);
				if (!$exclude_ambiguous){
					$characters = array_merge($characters, array('l','1','0','O'));
				}
				break;
			case 'upper':
				$characters = array_merge($upper, $numbers);
				if (!$exclude_ambiguous){
					$characters = array_merge($characters, array('1','0','O'));
				}
				break;
			default:
				$characters = array_merge($lower, $numbers);
				if (!$exclude_ambiguous){
					$characters = array_merge($characters, array('l','1','0'));
				}
				break;
		}
		
		$random_str = "";
		for ($i = 0; $i < $length; $i++) {
			$random_str .= $characters[array_rand($characters)];
		}
		return $random_str;
	}
	
	
	public static function isPosInt($val) {
		return preg_match('/^[0-9]+$/', $val);
	}
	
	
    /**
     * Generate url friendly slug from name
     *
     * @param string $input name to generate slug from
     * @return string
     */
    public static function slugify($input) {
        $slug = trim($input);
        $slug = strtolower($slug);
        $slug = preg_replace("/[^a-z0-9 ._-]/", "", $slug);
        $slug = str_replace(" ", "_", $slug);
        return $slug;
    }
}
?>
