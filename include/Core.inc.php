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

class Z_Core {
	public static $Mongo = null; // Mongo
	public static $MC = null; // Memcached
	public static $Solr = null; // Solr
	public static $debug = false;
	
	public static function debug($str, $level=false) {
		if (self::$debug) {
			error_log($str);
		}
		//Z_Log::log(Z_CONFIG::$LOG_TARGET_DEFAULT, $str);
	}
	
	
	public static function isCommandLine() {
		return !isset($_SERVER['SERVER_NAME']);
	}
	
	public static function logError($summary, $message=false) {
		Z_Log::log(Z_CONFIG::$LOG_TARGET_DEFAULT, $summary);
	}
	
	public static function isPosInt($val) {
		return preg_match('/^[0-9]+$/', $val);
	}
	
	public static function getBacktrace() {
		ob_start();
		debug_print_backtrace();
		return ob_get_clean();
	}
	
	public static function exitClean() {
		include('footer.inc.php');
		exit;
	}
	
	/**
	* Return true according to a given probability
	*
	* @param	int		$x		Will return true every $x times on average
	* @return	bool			On average, TRUE every $x times the function is called
	**/
	public static function probability($x) {
		return rand(1,$x) == rand(1,$x);
	}
}
?>
