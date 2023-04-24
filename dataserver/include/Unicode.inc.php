<?
class Z_Unicode {
	public static function charAt($str, $pos) {
		return mb_substr($str, $pos, 1);
	}
	
	public static function fromCharCode($str) {
		return self::unichr($str);
	}
	
	public static function charCodeAt($str, $pos) {
		return self::uniord(mb_substr($str, $pos, 1));
	}
	
	// From http://us2.php.net/manual/en/function.chr.php#88611
	public static function unichr($u) {
		return mb_convert_encoding('&#' . intval($u) . ';', 'UTF-8', 'HTML-ENTITIES');
	}
	
	// From http://us.php.net/manual/en/function.ord.php#42778
	public static function uniord($u) {
		$k = mb_convert_encoding($u, 'UCS-2LE', 'UTF-8');
		$k1 = ord(substr($k, 0, 1));
		$k2 = ord(substr($k, 1, 1));
		return $k2 * 256 + $k1;
	}
	
	
	//
	// Below functions ported from http://www.rishida.net/tools/conversion/
	//
	// GPL-licensed
	//
	public static function convertUTF82Char($str) {
		// converts to characters a sequence of space-separated hex numbers representing bytes in utf8
		// str: string, the sequence to be converted
		$outputString = "";
		$counter = 0;
		$n = 0;
		
		// remove leading and trailing spaces
		$str = preg_replace('/^\s+/', '', $str);
		$str = preg_replace('/\s+$/', '', $str);
		if (strlen($str) == 0) { return ""; }
		$str = preg_replace('/\s+/', ' ', $str);
		
		$listArray = explode(' ', $str);
		for ($i = 0; $i < sizeOf($listArray); $i++) {
			$b = intVal($listArray[$i], 16);  // alert('b:'+dec2hex(b));
			switch ($counter) {
			case 0:
				if (0 <= $b && $b <= 0x7F) {  // 0xxxxxxx
					$outputString .= self::dec2char($b);
				}
				else if (0xC0 <= $b && $b <= 0xDF) {  // 110xxxxx
					$counter = 1;
				$n = $b & 0x1F; }
				else if (0xE0 <= $b && $b <= 0xEF) {  // 1110xxxx
					$counter = 2;
					$n = $b & 0xF;
				}
				else if (0xF0 <= $b && $b <= 0xF7) {  // 11110xxx
					$counter = 3;
					$n = $b & 0x7;
				}
				else {
					throw new Exception('convertUTF82Char: error1 ' . dechex($b) . '!');
				}
				break;
			case 1:
				if ($b < 0x80 || $b > 0xBF) {
					throw new Exception('convertUTF82Char: error2 ' . dechex($b) . '!');
				}
				$counter--;
				$outputString .= self::dec2char(($n << 6) | ($b-0x80));
				$n = 0;
				break;
			case 2:
			case 3:
				if ($b < 0x80 || $b > 0xBF) {
					throw new Exception('convertUTF82Char: error3 ' . dechex($b) . '!');
				}
				$n = ($n << 6) | ($b-0x80);
				$counter--;
				break;
			}
		}
		return preg_replace('/ $/', '', $outputString);
	}
	
	
	public static function convertCharStr2CP($textString, $preserve, $pad, $type) {
		// converts a string of characters to code points, separated by space
		// textString: string, the string to convert
		// preserve: string enum [ascii, latin1], a set of characters to not convert
		// pad: boolean, if true, hex numbers lower than 1000 are padded with zeros
		// type: string enum[hex, dec, unicode, zerox], whether output should be in hex or dec or unicode U+ form
		$haut = 0;
		$n = 0;
		$CPString = '';
		$afterEscape = false;
		for ($i = 0; $i < mb_strlen($textString); $i++) {
			$b = Z_Unicode::charCodeAt($textString, $i);
			if ($b < 0 || $b > 0xFFFF) {
				throw new Exception('Error in convertChar2CP: byte out of range ' . dechex($b) . '!');
			}
			if ($haut != 0) {
				if (0xDC00 <= $b && $b <= 0xDFFF) { //alert('12345'.slice(-1).match(/[A-Fa-f0-9]/)+'<');
					//if ($CPString.slice(-1).match(/[A-Za-z0-9]/) != null) { $CPString += ' '; }
					if ($afterEscape) { $CPString .= ' '; }
					if (type == 'hex') {
						$CPString .= dechex(0x10000 . (($haut - 0xD800) << 10) . ($b - 0xDC00));
					}
					else if (type == 'unicode') {
						$CPString .= 'U+'+dechex(0x10000 . (($haut - 0xD800) << 10) . ($b - 0xDC00));
					}
					else if (type == 'zerox') {
						$CPString .= '0x'+dechex(0x10000 . (($haut - 0xD800) << 10) . ($b - 0xDC00));
					}
					else {
						$CPString .= 0x10000 . (($haut - 0xD800) << 10) . ($b - 0xDC00);
					}
					$haut = 0;
					continue;
				}
				else {
					throw new Exception('Error in convertChar2CP: surrogate out of range ' . dechex($haut) . '!');
				}
			}
			if (0xD800 <= $b && $b <= 0xDBFF) {
				$haut = $b;
			}
			else {
				if ($b <= 127 && $preserve == 'ascii') {
					$CPString .= Z_Unicode::charAt($textString, $i);
					$afterEscape = false;
				}
				else if ($b <= 255 && $preserve == 'latin1') {
					$CPString .= Z_Unicode::charAt($textString, $i);
					$afterEscape = false;
				}
				else {
					//if ($CPString.slice(-1).match(/[A-Za-z0-9]/) != null) { $CPString += ' '; }
					if ($afterEscape) { $CPString .= ' '; }
					if ($type == 'hex') {
						$cp = dechex($b);
						if ($pad) { while (strlen($cp) < 4) { $cp = '0' . $cp; } }
					}
					else if ($type == 'unicode') {
						$cp = dechex($b);
						if ($pad) { while (strlen($length) < 4) { $cp = '0' . $cp; } }
						$CPString .= 'U+';
					}
					else if ($type == 'zerox') {
						$cp = dechex($b);
						if ($pad) { while (strlen($cp) < 4) { $cp = '0' . $cp; } }
						$CPString .= '0x';
					}
					else {
						$cp = $b;
					}
					$CPString .= $cp;
					$afterEscape = true;
				}
			}
		}
		return strtoupper($CPString);
	}
	
	
	public static function convertCharStr2UTF8($str) {
		// Converts a string of characters to UTF-8 byte codes, separated by spaces
		// str: sequence of Unicode characters
		$highsurrogate = 0;
		$suppCP; // decimal code point value for a supp char
		$n = 0;
		$outputString = '';
		for ($i = 0; $i < mb_strlen($str); $i++) {
			$cc = self::charCodeAt($str, $i);
			if ($cc < 0 || $cc > 0xFFFF) {
				throw new Exception('Error in convertCharStr2UTF8: unexpected charCodeAt result, cc=' . $cc . '!');
			}
			if ($highsurrogate != 0) {
				if (0xDC00 <= $cc && $cc <= 0xDFFF) {
					$suppCP = 0x10000 + (($highsurrogate - 0xD800) << 10) + ($cc - 0xDC00);
					$outputString .= ' ' . self::dec2hex2(0xF0 | (($suppCP>>18) & 0x07)) . ' '
						. self::dec2hex2(0x80 | (($suppCP>>12) & 0x3F)) . ' '
						. self::dec2hex2(0x80 | (($suppCP>>6) & 0x3F)) . ' '
						. self::dec2hex2(0x80 | ($suppCP & 0x3F));
					$highsurrogate = 0;
					continue;
				}
				else {
					throw new Exception('Error in convertCharStr2UTF8: low surrogate expected, cc=' . $cc . '!');
					$highsurrogate = 0;
				}
			}
			if (0xD800 <= $cc && $cc <= 0xDBFF) { // high surrogate
				$highsurrogate = $cc;
			}
			else {
				if ($cc <= 0x7F) { $outputString .= ' ' . self::dec2hex2($cc); }
				else if ($cc <= 0x7FF) { $outputString .= ' ' . self::dec2hex2(0xC0 | (($cc>>6) & 0x1F)) . ' ' . self::dec2hex2(0x80 | ($cc & 0x3F)); } 
				else if ($cc <= 0xFFFF) { $outputString .= ' ' . self::dec2hex2(0xE0 | (($cc>>12) & 0x0F)) . ' ' . self::dec2hex2(0x80 | (($cc>>6) & 0x3F)) . ' ' . self::dec2hex2(0x80 | ($cc & 0x3F)); }
			}
		}
		return substr($outputString, 1);
	}
	
	
	private static function dec2char($n) {
		// converts a single string representing a decimal number to a character
		// note that no checking is performed to ensure that this is just a hex number, eg. no spaces etc
		// dec: string, the dec codepoint to be converted
		$result = '';
		if ($n <= 0xFFFF) {
			$result .= Z_Unicode::fromCharCode($n);
		}
		else if ($n <= 0x10FFFF) {
			$n -= 0x10000;
			$result .= Z_Unicode::fromCharCode(0xD800 | ($n >> 10)) . Z_Unicode::fromCharCode(0xDC00 | ($n & 0x3FF));
		}
		else {
			throw new Exception('dec2char error: Code point out of range: ' . dechex($n));
		}
		return $result;
	}
	
	
	public static function hex2char ($hex) {
		// converts a single hex number to a character
		// note that no checking is performed to ensure that this is just a hex number, eg. no spaces etc
		// hex: string, the hex codepoint to be converted
		$result = '';
		$n = intval($hex, 16);
		if ($n <= 0xFFFF) { $result .= self::fromCharCode($n); } 
		else if ($n <= 0x10FFFF) {
			$n -= 0x10000;
			$result .= self::fromCharCode(0xD800 | ($n >> 10)) . self::fromCharCode(0xDC00 | ($n & 0x3FF));
		} 
		else { throw new Exception('hex2char error: Code point out of range: ' . dexhex($n)); }
		return $result;
	}

	
	public static function dec2hex2($textString) {
		$hexequiv = array("0", "1", "2", "3", "4", "5", "6", "7", "8", "9", "A", "B", "C", "D", "E", "F");
		return $hexequiv[($textString >> 4) & 0xF] . $hexequiv[$textString & 0xF];
	}
}
