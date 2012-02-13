<?
class Zotero_Translate {
	public static $exportFormats = array(
		'bibtex',
		'bookmarks',
		'rdf_bibliontology',
		'rdf_dc',
		'rdf_zotero',
		'mods',
		'refer',
		'ris',
		'wikipedia'
	);
	
	public static function getExportFromTranslationServer($items, $format) {
		if (!in_array($format, self::$exportFormats)) {
			throw new Exception("Invalid export format");
		}
		
		$jsonItems = array();
		foreach ($items as $item) {
			$arr = $item->toJSON(true);
			$arr['uri'] = Zotero_URI::getItemURI($item);
			$jsonItems[] = $arr;
		}
		
		$json = json_encode($jsonItems);
		
		$servers = Z_CONFIG::$TRANSLATION_SERVERS;
		
		// Try servers in a random order
		shuffle($servers);
		
		foreach ($servers as $server) {
			$url = "http://$server/export?format=$format";
			
			$start = microtime(true);
			
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array("Expect:", "Content-Type: application/json"));
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 4);
			curl_setopt($ch, CURLOPT_HEADER, 0); // do not return HTTP headers
			curl_setopt($ch, CURLOPT_RETURNTRANSFER , 1);
			$response = curl_exec($ch);
			
			$time = microtime(true) - $start;
			
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$mimeType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
			
			if ($code != 200) {
				$response = null;
				Z_Core::logError("HTTP $code from translate server $server exporting items");
				Z_Core::logError($response);
				continue;
			}
			
			// If no response, try another server
			if (!$response) {
				continue;
			}
			
			break;
		}
		
		if (!$response) {
			throw new Exception("Error exporting items");
		}
		
		$export = array(
			'body' => $response,
			'mimeType' => $mimeType
		);
		
		return $export;
	}
}
?>
