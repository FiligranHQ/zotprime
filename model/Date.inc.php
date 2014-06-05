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
	private static $locale = 'en-US';
	
	private static function getString($key) {
		switch ($key) {
			case 'date.yesterday':
				return "yesterday";
			
			case 'date.today':
				return "today";
			
			case 'date.tomorrow':
				return "tomorrow";
			
			case 'date.daySuffixes':
				return "st, nd, rd, th";
		}
	}
	
	/*
	 * converts a string to an object containing:
	 *    day: integer form of the day
	 *    month: integer form of the month (indexed from 0, not 1)
	 *    year: 4 digit year (or, year + BC/AD/etc.)
	 *    part: anything that does not fall under any of the above categories
	 *          (e.g., "Summer," etc.)
	 */
	private static $slashRE = "/^(.*?)\b([0-9]{1,4})(?:([\-\/\.年])([0-9]{1,2}))?(?:([\-\/\.月])([0-9]{1,4}))?((?:\b|[^0-9]).*?)$/u";
	private static $yearRE = "/^(.*?)\b((?:circa |around |about |c\.? ?)?[0-9]{1,4}(?: ?B\.? ?C\.?(?: ?E\.?)?| ?C\.? ?E\.?| ?A\.? ?D\.?)|[0-9]{3,4})\b(.*?)$/iu";
	private static $monthRE = null;
	private static $dayRE = null;
	
	public static function strToDate($string) {
		// Parse 'yesterday'/'today'/'tomorrow'
		$lc = strtolower($string);
		if ($lc == 'yesterday' || $lc == self::getString('date.yesterday')) {
			$string = date("Y-m-d", strtotime('yesterday'));
		}
		else if ($lc == 'today' || $lc == self::getString('date.today')) {
			$string = date("Y-m-d");
		}
		else if ($lc == 'tomorrow' || $lc == self::getString('date.tomorrow')) {
			$string = date("Y-m-d", strtotime('tomorrow'));
		}
		
		$date = array();
		
		// skip empty things
		if (!$string) {
			return $date;
		}
		
		$string = preg_replace(
			array("/^\s+/", "/\s+$/", "/\s+/"),
			array("", "", " "),
			$string
		);
		
		// first, directly inspect the string
		preg_match(self::$slashRE, $string, $m);
		if ($m &&
				(empty($m[5]) || $m[3] == $m[5] || ($m[3] == "年" && $m[5] == "月")) && // require sane separators
				((!empty($m[2]) && !empty($m[4]) && !empty($m[6])) || (empty($m[1]) && empty($m[7])))) {	// require that either all parts are found,
																											// or else this is the entire date field
			// figure out date based on parts
			if (mb_strlen($m[2]) == 3 || mb_strlen($m[2]) == 4 || $m[3] == "年") {
				// ISO 8601 style date (big endian)
				$date['year'] = $m[2];
				$date['month'] = $m[4];
				$date['day'] = $m[6];
			}
			else {
				// local style date (middle or little endian)
				$date['year'] = $m[6];
				$country = substr(self::$locale, 3);
				if ($country == "US" ||	// The United States
				   $country == "FM" ||	// The Federated States of Micronesia
				   $country == "PW" ||	// Palau
				   $country == "PH") {	// The Philippines
					$date['month'] = $m[2];
					$date['day'] = $m[4];
				}
				else {
					$date['month'] = $m[4];
					$date['day'] = $m[2];
				}
			}
			
			if ($date['year']) $date['year'] = (int) $date['year'];
			if ($date['day']) $date['day'] = (int) $date['day'];
			if ($date['month']) {
				$date['month'] = (int) $date['month'];
				
				if ($date['month'] > 12) {
					// swap day and month
					$tmp = $date['day'];
					$date['day'] = $date['month'];
					$date['month'] = $tmp;
				}
			}
			
			if ((empty($date['month']) || $date['month'] <= 12) && (empty($date['day']) || $date['day'] <= 31)) {
				if (!empty($date['year']) && $date['year'] < 100) {	// for two digit years, determine proper
					$year = date('Y');
					$twoDigitYear = date('y');
					$century = $year - $twoDigitYear;
					
					if ($date['year'] <= $twoDigitYear) {
						// assume this date is from our century
						$date['year'] = $century + $date['year'];
					}
					else {
						// assume this date is from the previous century
						$date['year'] = $century - 100 + $date['year'];
					}
				}
				
				Z_Core::debug("DATE: retrieved with algorithms: " . json_encode($date));
				
				$date['part'] = $m[1] . $m[7];
			}
			else {
				// give up; we failed the sanity check
				Z_Core::debug("DATE: algorithms failed sanity check");
				$date = array("part" => $string);
			}
		}
		else {
			//Zotero.debug("DATE: could not apply algorithms");
			$date['part'] = $string;
		}
		
		// couldn't find something with the algorithms; use regexp
		// YEAR
		if (empty($date['year'])) {
			if (preg_match(self::$yearRE, $date['part'], $m)) {
				$date['year'] = $m[2];
				$date['part'] = $m[1] . $m[3];
				Z_Core::debug("DATE: got year (" . $date['year'] . ", " . $date['part'] . ")");
			}
		}
		
		// MONTH
		if (empty($date['month'])) {
			// compile month regular expression
			$months = array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul',
				'Aug', 'Sep', 'Oct', 'Nov', 'Dec');
			// If using a non-English bibliography locale, try those too
			if (self::$locale != 'en-US') {
				throw new Exception("Unimplemented");
				//$months = array_merge($months, .concat(Zotero.$date['month']s.short);
			}
			if (!self::$monthRE) {
				self::$monthRE = "/^(.*)\\b(" . implode("|", $months) . ")[^ ]*(?: (.*)$|$)/iu";
			}
			
			if (preg_match(self::$monthRE, $date['part'], $m)) {
				// Modulo 12 in case we have multiple languages
				$date['month'] = (array_search(ucwords(strtolower($m[2])), $months) % 12) + 1;
				$date['part'] = $m[1] . (isset($m[3]) ? $m[3] : '');
				Z_Core::debug("DATE: got month (" . $date['month'] . ", " . $date['part'] . ")");
			}
		}
		
		// DAY
		if (empty($date['day'])) {
			// compile day regular expression
			if(!self::$dayRE) {
				$daySuffixes = preg_replace("/, ?/", "|", self::getString("date.daySuffixes"));
				self::$dayRE = "/\\b([0-9]{1,2})(?:" . $daySuffixes . ")?\\b(.*)/iu";
			}
			
			if (preg_match(self::$dayRE, $date['part'], $m, PREG_OFFSET_CAPTURE)) {
				$day = (int) $m[1][0];
				// Sanity check
				if ($day <= 31) {
					$date['day'] = $day;
					if ($m[0][1] > 0) {
						$date['part'] = substr($date['part'], 0, $m[0][1]);
						if ($m[2][0]) {
							$date['part'] .= " " . $m[2][0];
						}
					}
					else {
						$date['part'] = $m[2][0];
					}
					
					Z_Core::debug("DATE: got day (" . $date['day'] . ", " . $date['part'] . ")");
				}
			}
		}
		
		// clean up date part
		if ($date['part']) {
			$date['part'] = preg_replace(
				array("/^[^A-Za-z0-9]+/", "/[^A-Za-z0-9]+$/"),
				"",
				$date['part']
			);
		}
		
		if ($date['part'] === "" || !isset($date['part'])) {
			unset($date['part']);
		}
		
		return $date;
	}
	
	
	// Regexes for multipart and SQL dates
	// Allow zeroes in multipart dates
	// TODO: Allow negative multipart in DB and here with \-?
	private static $multipartRE = "/^[0-9]{4}\-(0[0-9]|10|11|12)\-(0[0-9]|[1-2][0-9]|30|31) /";
	private static $sqldateRE = "/^\-?[0-9]{4}\-(0[1-9]|10|11|12)\-(0[1-9]|[1-2][0-9]|30|31)$/";
	private static $sqldatetimeRE = "/^\-?[0-9]{4}\-(0[1-9]|10|11|12)\-(0[1-9]|[1-2][0-9]|30|31) ([0-1][0-9]|[2][0-3]):([0-5][0-9]):([0-5][0-9])$/";
	
	/**
	 * Tests if a string is a multipart date string
	 * e.g. '2006-11-03 November 3rd, 2006'
	 */
	public static function isMultipart($str) {
		$isMultipart = !!preg_match(self::$multipartRE, $str);
		// Make sure the year actually appears after YYYY-MM-DD and this isn't just an SQL date
		if ($isMultipart) {
			 $isMultipart = strpos(substr($str, 11), substr($str, 0, 4)) !== false;
		}
		return $isMultipart;
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
	
	
	public static function strToMultipart($str){
		if (!$str) {
			return '';
		}
		
		$parts = self::strToDate($str);
		
		// FIXME: Until we have a better BCE date solution,
		// remove year value if not between 1 and 9999
		if (isset($parts['year'])) {
			if (!preg_match("/^[0-9]{1,4}$/", $parts['year'])) {
				unset($parts['year']);
			}
		}
		
		$multi = (!empty($parts['year']) ? str_pad($parts['year'], 4, '0', STR_PAD_LEFT) : '0000') . '-'
			. ((!empty($parts['month']) && $parts['month'] <= 12) ? str_pad($parts['month'], 2, '0', STR_PAD_LEFT) : '00') . '-'
			. ((!empty($parts['day']) && $parts['day'] <= 31) ? str_pad($parts['day'], 2, '0', STR_PAD_LEFT) : '00')
			. ' '
			. $str;
		return $multi;
	}
	
	
	public static function isSQLDate($str) {
		return !!preg_match(self::$sqldateRE, $str);
	}
	
	
	public static function isSQLDateTime($str) {
		return !!preg_match(self::$sqldatetimeRE, $str);
	}
	
	
	public static function isISO8601($str) {
		return !!DateTime::createFromFormat(DateTime::ISO8601, $str);
	}
	
	
	public static function sqlToISO8601($sqlDate) {
		$date = substr($sqlDate, 0, 10);
		// Replace '00' with '01' in month and day
		$date = str_replace("-00-", "-01-", $date);
		$date = str_replace("-00", "-01", $date);
		$time = substr($sqlDate, 11);
		if (!$time) {
			$time = "00:00:00";
		}
		return $date . "T" . $time . "Z";
	}
	
	
	public static function iso8601ToSQL($isoDate) {
		$date = DateTime::createFromFormat(DateTime::ISO8601, $isoDate);
		if (!$date) {
			throw new Exception("'$isoDate' is not an ISO 8601 date");
		}
		$date->setTimezone(new DateTimeZone('UTC'));
		return $date->format('Y-m-d H:i:s');
	}
}
?>
