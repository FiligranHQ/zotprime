<?
class Zotero_Attachments {
	private static $cacheTime = 60; // seconds to cache extracted ZIP files
	
	private static $linkModes = array(
		0 => "IMPORTED_FILE",
		1 => "IMPORTED_URL",
		2 => "LINKED_FILE",
		3 => "LINKED_URL"
	);
	
	public static function linkModeNumberToName($number) {
		if (!isset(self::$linkModes[$number])) {
			throw new Exception("Invalid link mode '" . $number . "'");
		}
		return self::$linkModes[$number];
	}
	
	
	public static function linkModeNameToNumber($name, $caseInsensitive=false) {
		if ($caseInsensitive) {
			$name = strtoupper($name);
		}
		$number = array_search($name, self::$linkModes);
		if ($number === false) {
			throw new Exception("Invalid link mode name '" . $name . "'");
		}
		return $number;
	}
	
	
	/**
	 * Download ZIP file from S3, extract it, and return a temporary URL
	 * pointing to the main file
	 */
	public static function getTemporaryURL(Zotero_Item $item, $localOnly=false) {
		$extURLPrefix = Z_CONFIG::$ATTACHMENT_SERVER_URL;
		if ($extURLPrefix[strlen($extURLPrefix) - 1] != "/") {
			$extURLPrefix .= "/";
		}
		
		$info = Zotero_S3::getLocalFileItemInfo($item);
		$storageFileID = $info['storageFileID'];
		$filename = $info['filename'];
		$mtime = $info['mtime'];
		if (!$info['zip']) {
			throw new Exception("Not a zip attachment");
		}
		$realFilename = preg_replace("/^storage:/", "", $item->attachmentPath);
		$realFilename = self::decodeRelativeDescriptorString($realFilename);
		$realFilename = urlencode($realFilename);
		
		$docroot = Z_CONFIG::$ATTACHMENT_SERVER_DOCROOT;
		
		//Z_Core::$debug = true;
		
		// Check memcached to see if file is already extracted
		$key = "attachmentServerString_" . $storageFileID . "_" . $mtime;
		if ($randomStr = Z_Core::$MC->get($key)) {
			Z_Core::debug("Got attachment path '$randomStr/$realFilename' from memcached");
			return $extURLPrefix . "$randomStr/$realFilename";
		}
		
		$localAddr = gethostbyname(gethostname());
		
		// See if this is an attachment host
		$index = false;
		$skipHost = false;
		for ($i = 0, $len = sizeOf(Z_CONFIG::$ATTACHMENT_SERVER_HOSTS); $i < $len; $i++) {
			$hostAddr = gethostbyname(Z_CONFIG::$ATTACHMENT_SERVER_HOSTS[$i]);
			if ($hostAddr == $localAddr) {
				// Make a HEAD request on the local static port to make sure
				// this host is actually functional
				$url = "http://" . Z_CONFIG::$ATTACHMENT_SERVER_HOSTS[$i]
					. ":" . Z_CONFIG::$ATTACHMENT_SERVER_STATIC_PORT . "/";
				Z_Core::debug("Making HEAD request to $url");
				$ch = curl_init($url);
				curl_setopt($ch, CURLOPT_NOBODY, 1);
				curl_setopt($ch, CURLOPT_HTTPHEADER, array("Expect:"));
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
				curl_setopt($ch, CURLOPT_TIMEOUT, 2);
				curl_setopt($ch, CURLOPT_HEADER, 0); // do not return HTTP headers
				curl_setopt($ch, CURLOPT_RETURNTRANSFER , 1);
				$response = curl_exec($ch);
				$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				
				if ($code != 200) {
					$skipHost = Z_CONFIG::$ATTACHMENT_SERVER_HOSTS[$i];
					if ($code == 0) {
						Z_Core::logError("Error connecting to local attachments server");
					}
					else {
						Z_Core::logError("Local attachments server returned $code");
					}
					break;
				}
				
				$index = $i + 1;
				break;
			}
		}
		
		// If not, make an internal root request to trigger the extraction on
		// one of them and retrieve the temporary URL
		if ($index === false) {
			// Prevent redirect madness if target server doesn't think it's an
			// attachment server
			if ($localOnly) {
				throw new Exception("Internal attachments request hit a non-attachment server");
			}
			
			$prefix = 'http://' . Z_CONFIG::$API_SUPER_USERNAME .
							":" . Z_CONFIG::$API_SUPER_PASSWORD . "@";
			$path = Zotero_API::getItemURI($item) . "/file/view?int=1";
			$path = preg_replace('/^[^:]+:\/\/[^\/]+/', '', $path);
			$context = stream_context_create(array(
				'http' => array(
					'follow_location' => 0
				)
			));
			$url = false;
			$hosts = Z_CONFIG::$ATTACHMENT_SERVER_HOSTS;
			// Try in random order
			shuffle($hosts);
			foreach ($hosts as $host) {
				// Don't try the local host again if we know it's not working
				if ($host == $skipHost) {
					continue;
				}
				$intURL = $prefix . $host . ":" . Z_CONFIG::$ATTACHMENT_SERVER_DYNAMIC_PORT . $path;
				Z_Core::debug("Making GET request to $host");
				if (file_get_contents($intURL, false, $context) !== false) {
					foreach ($http_response_header as $header) {
						if (preg_match('/^Location:\s*(.+)$/', $header, $matches)) {
							if (strpos($matches[1], $extURLPrefix) !== 0) {
								throw new Exception(
									"Redirect location '" . $matches[1] . "'"
									. " does not begin with $extURLPrefix"
								);
							}
							return $matches[1];
						}
					}
				}
			}
			return false;
		}
		
		// If this is an attachment host, do the extraction inline
		// and generate a random number with an embedded host id.
		//
		// The reverse proxy routes incoming file requests to the proper hosts
		// using the embedded id.
		//
		// A cron job deletes old attachment directories
		$randomStr = rand(1000000, 2147483647);
		// Seventh number is the host id
		$randomStr = substr($randomStr, 0, 6) . $index . substr($randomStr, 6);
		
		// Download and extract file
		$dir = $docroot . $randomStr . "/";
		$tmpDir = $dir . "ztmp/";
		if (!mkdir($tmpDir, 0777, true)) {
			throw new Exception("Unable to create directory '$tmpDir'");
		}
		Z_Core::debug("Downloading attachment to $dir");
		$response = Zotero_S3::downloadFile($info, $tmpDir);
		
		$success = self::extractZip($tmpDir . $info['filename'], $dir);
		
		unlink($tmpDir . $info['filename']);
		rmdir($tmpDir);
		
		if (!$success) {
			return false;
		}
		
		Z_Core::$MC->set($key, $randomStr, self::$cacheTime);
		
		return $extURLPrefix . "$randomStr/" . $realFilename;
	}
	
	
	// Filenames are in Mozilla's getRelativeDescriptor() format
	public static function decodeRelativeDescriptorString($str) {
		$str = Z_Unicode::convertCharStr2CP($str, false, true, 'hex');
		$str = Z_Unicode::convertUTF82Char($str);
		if (function_exists('normalizer_normalize')) {
			$str = normalizer_normalize($str);
		}
		return $str;
	}
	
	
	public static function encodeRelativeDescriptorString($str) {
		if (function_exists('normalizer_normalize')) {
			$str = normalizer_normalize($str);
		}
		
		$str = Z_Unicode::convertCharStr2UTF8($str);
		// convertNumbers2Char($str, 'hex')
		$str = preg_replace_callback(
			"/([A-Fa-f0-9]{2})/",
			function($matches) {
				return Z_Unicode::hex2char($matches[0]);
			},
			str_replace(" ", "", $str)
		);
		
		return $str;
	}
	
	
	private static function extractZip($file, $destDir) {
		$za = new ZipArchive();
		$za->open($file);
		
		$entries = array();
		
		for ($i = 0, $max = $za->numFiles; $i < $max; $i++) {
			$stat = $za->statIndex($i);
			// Skip files not at the top level
			if ($stat['name'] != basename($stat['name'])) {
				continue;
			}
			// Skip dot files or ztmp (which we use as temp dir)
			if ($stat['name'][0] == '.' || $stat['name'] == 'ztmp') {
				continue;
			}
			if (preg_match("/%ZB64$/", $stat['name'])) {
				$filename = Z_Base64::decode(substr($stat['name'], 0, -5));
				$filename = self::decodeRelativeDescriptorString($filename);
				$za->renameIndex($i, $filename);
			}
			else {
				$filename = $stat['name'];
			}
			
			$entries[] = $filename;
		}
		
		$success = $za->extractTo($destDir, $entries);
		
		$za->close();
		
		if (!$success) {
			Z_Core::logError($za->getStatusString());
		}
		
		return $success;
	}
}
