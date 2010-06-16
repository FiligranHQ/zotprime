<?
class IPAddress {
	private static $ip_private_list = array("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "127.0.0.1/32");
	
	// Determine if an IP address is in a net.
	// e.g. 120.120.120.120 in 120.120.0.0/16
	//
	// From http://php.net/getenv
	public static function isIPInNet($ip,$net,$mask) {
		$lnet=ip2long($net);
		$lip=ip2long($ip);
		$binnet=str_pad( decbin($lnet),32,"0",STR_PAD_LEFT );
		$firstpart=substr($binnet,0,$mask);
		$binip=str_pad( decbin($lip),32,"0",STR_PAD_LEFT );
		$firstip=substr($binip,0,$mask);
		
		return(strcmp($firstpart,$firstip)==0);
	}
	
	// This function checks if an IP address is in an array of nets (ip and mask)
	//
	// From http://php.net/getenv
	public static function isIPInNetArray($theip, $thearray) {
		$exit_c = false;
		foreach ($thearray as $subnet) {
			list($net, $mask) = mb_split("/", $subnet);
			if (self::isIPInNet($theip, $net, $mask)){
				$exit_c = true;
				break;
			}
		}
		return($exit_c);
	}
	
	// Building the IP array with the HTTP_X_FORWARDED_FOR and REMOTE_ADDR HTTP vars.
	// With this function we get an array where first are the IP's listed in
	// HTTP_X_FORWARDED_FOR and the last ip is the REMOTE_ADDR.
	// This is inspired (copied and modified) in the function from daniel_dll.
	//
	// From http://php.net/getenv
	public static function GetIPArray() {
		$cad="";
		
		if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$cad = $_SERVER['HTTP_X_FORWARDED_FOR'];
		}
		
		if (!empty($_SERVER['REMOTE_ADDR'])) {
			$cad = $cad . ",". $_SERVER['REMOTE_ADDR'];
		}
		
		$arr = explode(',', $cad);
		
		return $arr;
	}
	
	// We check each IP in the array and return the first that is not a private IP
	//
	// From http://php.net/getenv
	public static function getIP() {
		global $ip_private_list;
		$ip = '';
		$ip_array = self::getIPArray();
		
		foreach ( $ip_array as $ip_s ) {
			
			if ( $ip_s != "" && $ip_s != 'unknown' && !self::isIPInNetArray($ip_s, self::$ip_private_list)) {
				$ip = $ip_s;
				break;
			}
		}
		
		return trim($ip);
	}
}
?>
