<?
/*
    Add license block if adding additional code
*/

class Zotero_URL {
	/**
	 * By Evan K on http://us.php.net/manual/en/function.parse-str.php
	 */
	public static function proper_parse_str($str) {
		if (!$str) {
			return array();
		}
		$arr = array();
		
		$pairs = explode('&', $str);
		
		foreach ($pairs as $i) {
			list($name, $value) = explode('=', $i, 2);
			
			if (!$value && $value !== '0') {
				Z_Core::logError($str);
			}
			
			// Added by Dan S.
			$value = urldecode($value);
			
			// if name already exists
			if (isset($arr[$name])) {
				// stick multiple values into an array
				if (is_array($arr[$name])) {
					$arr[$name][] = $value;
				}
				else {
					$arr[$name] = array($arr[$name], $value);
				}
			}
			// otherwise, simply stick it in a scalar
			else {
				$arr[$name] = $value;
			}
		}
		return $arr;
	}
}
