<?php
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2017 Center for History and New Media
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

class Zotero_GlobalItems {
	const endpointTimeout = 3;
	
	public static function getGlobalItems($params) {
		$requestURL = Z_CONFIG::$GLOBAL_ITEMS_URL;
		if ($requestURL[strlen($requestURL) - 1] != "/") {
			$requestURL .= "/";
		}
		$requestURL .= 'global/items';
		
		// If a single-object query
		if (!empty($params['id'])) {
			$requestURL .= '/' . rawurlencode($params['id']);
		}
		// If a multi-object query
		else {
			if (!empty($params['q'])) {
				$requestURL .= '?q=' . rawurlencode($params['q']);
			}
			else if (!empty($params['doi'])) {
				$requestURL .= '?doi=' . rawurlencode($params['doi']);
			}
			else if (!empty($params['isbn'])) {
				$requestURL .= '?isbn=' . rawurlencode($params['isbn']);
			}
			else {
				throw new Exception("Missing query parameter");
			}
			
			if (!empty($params['start'])) {
				$requestURL .= '&start=' . $params['start'];
			}
			
			if (!empty($params['limit'])) {
				$requestURL .= '&limit=' . $params['limit'];
			}
		}
		
		$start = microtime(true);
		
		$ch = curl_init($requestURL);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, self::endpointTimeout);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		// Allow an invalid ssl certificate (Todo: remove)
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		$response = curl_exec($ch);
		
		$time = microtime(true) - $start;
		StatsD::timing("api.globalitems", $time * 1000);
		
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		
		// If a single item request
		if ($code == 404 && !empty($params['id'])) {
			return false;
		}
		
		if ($code != 200) {
			throw new Exception($code . " from global items server "
				. "[URL: '$requestURL'] [RESPONSE: '$response']");
		}
		
		$headerSize = strpos($response, "\r\n\r\n") + 4;
		$body = substr($response, $headerSize);
		$headerLines = explode("\r\n", trim(substr($response, 0, $headerSize)));
		unset($headerLines[0]);
		$headers = [];
		foreach ($headerLines as $headerLine) {
			list($key, $val) = explode(':', $headerLine, 2);
			$headers[strtolower($key)] = trim($val);
		}
		
		$json = json_decode($body, true);
		return [
			'totalResults' => $headers['total-results'],
			'data' => $json
		];
	}
	
	public static function getGlobalItemLibraryItems($id) {
		$params = [
			'id' => $id
		];
		$result = self::getGlobalItems($params);
		if (!$result) return false;
		$libraryItems = $result['data']['libraryItems'];
		$parsedLibraryItems = [];
		for ($i = 0, $len = sizeOf($libraryItems); $i < $len; $i++) {
			list($libraryID, $key) = explode('/', $libraryItems[$i]);
			$parsedLibraryItems[] = [$libraryID, $key];
		}
		return $parsedLibraryItems;
	}
	
	public static function getGlobalItemDatesAdded($id) {
		$params = [
			'id' => $id
		];
		$result = self::getGlobalItems($params);
		if (!$result) return false;
		return $result['data']['meta']['datesAdded'];
	}
}
