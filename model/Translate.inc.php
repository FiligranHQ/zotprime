<?php
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2011 Center for History and New Media
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

class Zotero_Translate {
	public static $exportFormats = array(
		'bibtex',
		'bookmarks',
		'coins',
		'rdf_bibliontology',
		'rdf_dc',
		'rdf_zotero',
		'mods',
		'refer',
		'ris',
		'tei',
		'wikipedia'
	);
	
	public static function doExport($items, $format) {
		if (!in_array($format, self::$exportFormats)) {
			throw new Exception("Invalid export format");
		}
		
		$jsonItems = array();
		foreach ($items as $item) {
			$arr = $item->toJSON(true);
			$arr['uri'] = Zotero_URI::getItemURI($item);
			$jsonItems[] = $arr;
		}
		
		if (!$jsonItems) {
			return array(
				'body' => "",
				// Stripping the Content-Type header (header_remove, "Content-Type:")
				// in the API controller doesn't seem to be working, so send
				// text/plain instead
				'mimeType' => "text/plain"
			);
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
			
			if (!$response) {
				$response = "";
			}
			
			break;
		}
		
		if ($response === null) {
			throw new Exception("Error exporting items");
		}
		
		$export = array(
			'body' => $response,
			'mimeType' => $mimeType
		);
		
		return $export;
	}
	
	
	public static function doWeb($url, $sessionKey, $items=false) {
		$json = array(
			"url" => $url,
			"sessionid" => $sessionKey
		);
		
		if ($items) {
			$json['items'] = $items;
		}
		
		$json = json_encode($json);
		
		$servers = Z_CONFIG::$TRANSLATION_SERVERS;
		
		// Try servers in a random order
		//
		// TODO: send a 300 follow-up to the same node
		shuffle($servers);
		
		foreach ($servers as $server) {
			$serverURL = "http://$server/web";
			
			$start = microtime(true);
			
			$ch = curl_init($serverURL);
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
			
			if ($code != 200 && $code != 300) {
				// For explicit errors, trust translation server and bail
				if ($code == 500 && strpos($response, "An error occurred during translation") !== false) {
					error_log("Error translating $url");
					return 500;
				}
				else if ($code == 501) {
					error_log("No translators found for $url");
					return 501;
				}
				
				// If unknown error, log and try another server
				$response = null;
				Z_Core::logError("HTTP $code from translate server $server translating URL");
				Z_Core::logError($response);
				continue;
			}
			
			if (!$response) {
				$response = "";
			}
			
			break;
		}
		
		if ($response === null) {
			throw new Exception("Error from translation server");
		}
		
		$response = json_decode($response);
		
		$obj = new stdClass;
		if ($code == 300) {
			$obj->select = $response;
		}
		else {
			$obj->items = $response;
		}
		
		return $obj;
	}
}
?>
