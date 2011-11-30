<?
//
// Ported from http://www.webtoolkit.info/javascript-base64.html
//
class Z_Base64 {
	// private property
	private static $keyStr = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=";
	
	// public method for encoding
	public static function encode($input) {
		$output = "";
		$chr1 = $chr2 = $chr3 = $enc1 = $enc2 = $enc3 = $enc4 = "";
		$i = 0;
		
		$input = self::utf8_encode($input);
		
		while ($i < mb_strlen($input)) {
			$chr1 = Z_Unicode::charCodeAt($input, $i++);
			$chr2 = Z_Unicode::charCodeAt($input, $i++);
			$chr3 = Z_Unicode::charCodeAt($input, $i++);
			
			$enc1 = $chr1 >> 2;
			$enc2 = (($chr1 & 3) << 4) | ($chr2 >> 4);
			$enc3 = (($chr2 & 15) << 2) | ($chr3 >> 6);
			$enc4 = $chr3 & 63;
			
			if (is_nan($chr2)) {
				$enc3 = $enc4 = 64;
			} else if (is_nan($chr3)) {
				$enc4 = 64;
			}
			
			$output = $output .
				Z_Unicode::charAt(self::$keyStr, $enc1) . Z_Unicode::charAt(self::$keyStr, $enc2) .
				Z_Unicode::charAt(self::$keyStr, $enc3) . Z_Unicode::charAt(self::$keyStr, $enc4);
			
		}
		
		return $output;
	}
	
	// public method for decoding
	public static function decode($input) {
		$output = "";
		$chr1 = $chr2 = $chr3 = $enc1 = $enc2 = $enc3 = $enc4 = "";
		$i = 0;
		
		$input = preg_replace('/[^A-Za-z0-9\+\/\=]/', "", $input);
		
		while ($i < mb_strlen($input)) {
			$enc1 = strpos(self::$keyStr, $input[$i++]);
			$enc2 = strpos(self::$keyStr, $input[$i++]);
			$enc3 = strpos(self::$keyStr, $input[$i++]);
			$enc4 = strpos(self::$keyStr, $input[$i++]);
			
			$chr1 = ($enc1 << 2) | ($enc2 >> 4);
			$chr2 = (($enc2 & 15) << 4) | ($enc3 >> 2);
			$chr3 = (($enc3 & 3) << 6) | $enc4;
			
			$output = $output . Z_Unicode::unichr($chr1);
			
			if ($enc3 != 64) {
				$output = $output . Z_Unicode::unichr($chr2);
			}
			if ($enc4 != 64) {
				$output = $output . Z_Unicode::unichr($chr3);
			}
		}
		
		$output = self::utf8_decode($output);
		
		return $output;
	}
	
	// private method for UTF-8 encoding
	private static function utf8_encode($string) {
		$string = preg_replace('/\r\n/', "\n", $string);
		$utftext = "";
		
		for ($n = 0; $n < strlen($string); $n++) {
			
			$c = Z_Unicode::charCodeAt($string, $n);
			
			if ($c < 128) {
				$utftext .= Z_Unicode::fromCharCode($c);
			}
			else if(($c > 127) && ($c < 2048)) {
				$utftext .= Z_Unicode::fromCharCode(($c >> 6) | 192);
				$utftext .= Z_Unicode::fromCharCode(($c & 63) | 128);
			}
			else {
				$utftext .= Z_Unicode::fromCharCode(($c >> 12) | 224);
				$utftext .= Z_Unicode::fromCharCode((($c >> 6) & 63) | 128);
				$utftext .= Z_Unicode::fromCharCode(($c & 63) | 128);
			}
			
		}
		
		return $utftext;
	}
	
	// private method for UTF-8 decoding
	private static function utf8_decode($utftext) {
		$string = "";
		$i = 0;
		$c = $c1 = $c2 = 0;
		
		while ( $i < mb_strlen($utftext) ) {
			
			$c = Z_Unicode::charCodeAt($utftext, $i);
			
			if ($c < 128) {
				$string .= Z_Unicode::fromCharCode($c);
				$i++;
			}
			else if(($c > 191) && ($c < 224)) {
				$c2 = Z_Unicode::charCodeAt($utftext, $i+1);
				$string .= Z_Unicode::fromCharCode((($c & 31) << 6) | ($c2 & 63));
				$i += 2;
			}
			else {
				$c2 = Z_Unicode::charCodeAt($utftext, $i+1);
				$c3 = Z_Unicode::charCodeAt($utftext, $i+2);
				$string .= Z_Unicode::fromCharCode((($c & 15) << 12) | (($c2 & 63) << 6) | ($c3 & 63));
				$i += 3;
			}
		}
		
		return $string;
	}
}
