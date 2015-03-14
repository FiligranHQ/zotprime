<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2012 Center for History and New Media
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

require_once __DIR__ . '/http.inc.php';

class API3 {
	private static $config;
	private static $nsZAPI;
	private static $apiVersion = false;
	private static $apiKey = false;
	
	public static function loadConfig() {
		require 'include/config.inc.php';
		foreach ($config as $k => $v) {
			self::$config[$k] = $v;
		}
		self::$nsZAPI = 'http://zotero.org/ns/api';
	}
	
	
	public static function useAPIVersion($apiVersion) {
		self::$apiVersion = $apiVersion;
	}
	
	
	public static function useAPIKey($key) {
		self::$apiKey = $key;
	}
	
	
	public static function createGroup($fields) {
		$xml = new \SimpleXMLElement('<group/>');
		$xml['owner'] = $fields['owner'];
		$xml['name'] = "Test Group " . uniqid();
		$xml['type'] = $fields['type'];
		$xml['libraryEditing'] = isset($fields['libraryEditing'])
			? $fields['libraryEditing']
			: 'members';
		$xml['libraryReading'] = isset($fields['libraryReading'])
			? $fields['libraryReading']
			: 'members';
		$xml['fileEditing'] = isset($fields['fileEditing'])
			? $fields['fileEditing']
			: 'none';
		$xml['description'] = "";
		$xml['url'] = "";
		$xml['hasImage'] = false;
		
		$response = self::superPost(
			"groups",
			$xml->asXML()
		);
		if ($response->getStatus() != 201) {
			echo $response->getBody();
			throw new Exception("Unexpected response code " . $response->getStatus());
		}
		$url = $response->getHeader('Location');
		preg_match('/[0-9]+$/', $url, $matches);
		return (int) $matches[0];
	}
	
	
	public static function deleteGroup($groupID) {
		$response = self::superDelete(
			"groups/$groupID"
		);
		if ($response->getStatus() != 204) {
			echo $response->getBody();
			throw new Exception("Unexpected response code " . $response->getStatus());
		}
	}
	
	
	//
	// Item modification methods
	//
	public function getItemTemplate($itemType) {
		$response = self::get("items/new?itemType=$itemType");
		return json_decode($response->getBody());
	}
	
	
	public function createItem($itemType, $data=array(), $context=null, $returnFormat='responseJSON') {
		$json = self::getItemTemplate($itemType);
		
		if ($data) {
			foreach ($data as $field => $val) {
				$json->$field = $val;
			}
		}
		
		$response = self::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode([$json]),
			array("Content-Type: application/json")
		);
		
		return self::handleCreateResponse('item', $response, $returnFormat, $context);
	}
	
	
	/**
	 * POST a JSON item object to the main test user's account
	 * and return the response
	 */
	public static function postItem($json) {
		return self::postItems(array($json));
	}
	
	
	/**
	 * POST a JSON items object to the main test user's account
	 * and return the response
	 */
	public static function postItems($json) {
		return self::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode($json),
			array("Content-Type: application/json")
		);
	}
	
	
	public function groupCreateItem($groupID, $itemType, $data=[], $context=null, $returnFormat='responseJSON') {
		$response = self::get("items/new?itemType=$itemType");
		$json = json_decode($response->getBody());
		
		if ($data) {
			foreach ($data as $field => $val) {
				$json->$field = $val;
			}
		}
		
		$response = self::groupPost(
			$groupID,
			"items?key=" . self::$config['apiKey'],
			json_encode([$json]),
			array("Content-Type: application/json")
		);
		return self::handleCreateResponse('item', $response, $returnFormat, $context, $groupID);
	}
	
	
	public function createAttachmentItem($linkMode, $data=[], $parentKey=false, $context=false, $returnFormat='responseJSON') {
		$response = self::get("items/new?itemType=attachment&linkMode=$linkMode");
		$json = json_decode($response->getBody());
		foreach ($data as $key => $val) {
			$json->{$key} = $val;
		}
		if ($parentKey) {
			$json->parentItem = $parentKey;
		}
		
		$response = self::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode([$json]),
			array("Content-Type: application/json")
		);
		
		return self::handleCreateResponse('item', $response, $returnFormat, $context); 
	}
	
	
	public function groupCreateAttachmentItem($groupID, $linkMode, $data=[], $parentKey=false, $context=false, $returnFormat='responseJSON') {
		$response = self::get("items/new?itemType=attachment&linkMode=$linkMode");
		$json = json_decode($response->getBody());
		foreach ($data as $key => $val) {
			$json->{$key} = $val;
		}
		if ($parentKey) {
			$json->parentItem = $parentKey;
		}
		
		$response = self::groupPost(
			$groupID,
			"items?key=" . self::$config['apiKey'],
			json_encode([$json]),
			array("Content-Type: application/json")
		);
		
		return self::handleCreateResponse('item', $response, $returnFormat, $context, $groupID);
	}
	
	
	public function createNoteItem($text="", $parentKey=false, $context=false, $returnFormat='responseJSON') {
		$response = self::get("items/new?itemType=note");
		$json = json_decode($response->getBody());
		$json->note = $text;
		if ($parentKey) {
			$json->parentItem = $parentKey;
		}
		
		$response = self::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode([$json]),
			array("Content-Type: application/json")
		);
		return self::handleCreateResponse('item', $response, $returnFormat, $context);
	}
	
	
	public function createCollection($name, $data=array(), $context=null, $returnFormat='responseJSON') {
		if (is_array($data)) {
			$parent = isset($data['parentCollection']) ? $data['parentCollection'] : false;
			$relations = isset($data['relations']) ? $data['relations'] : new stdClass;
		}
		else {
			$parent = $data ? $data : false;
			$relations = new stdClass;
		}
		
		$json = [
			[
				'name' => $name,
				'parentCollection' => $parent,
				'relations' => $relations
			]
		];
		
		$response = self::userPost(
			self::$config['userID'],
			"collections?key=" . self::$config['apiKey'],
			json_encode($json),
			array("Content-Type: application/json")
		);
		
		return self::handleCreateResponse('collection', $response, $returnFormat, $context);
	}
	
	
	public function createSearch($name, $conditions=array(), $context=null, $returnFormat='responseJSON') {
		if ($conditions == 'default') {
			$conditions = array(
				array(
					'condition' => 'title',
					'operator' => 'contains',
					'value' => 'test'
				)
			);
		}
		
		$json = [
			[
				'name' => $name,
				'conditions' => $conditions
			]
		];
		
		$response = self::userPost(
			self::$config['userID'],
			"searches?key=" . self::$config['apiKey'],
			json_encode($json),
			array("Content-Type: application/json")
		);
		
		return self::handleCreateResponse('search', $response, $returnFormat, $context);
	}
	
	
	public static function getLibraryVersion() {
		$response = self::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'] . "&format=keys&limit=1"
		);
		return $response->getHeader("Last-Modified-Version");
	}
	
	
	public static function getGroupLibraryVersion($groupID) {
		$response = self::groupGet(
			$groupID,
			"items?key=" . self::$config['apiKey'] . "&format=keys&limit=1"
		);
		return $response->getHeader("Last-Modified-Version");
	}
	
	
	public static function getItem($keys, $context=null, $format=false, $groupID=false) {
		return self::getObject('item', $keys, $context, $format, $groupID);
	}
	
	
	public static function getItemResponse($keys, $context=null, $format=false, $groupID=false) {
		return self::getObjectResponse('item', $keys, $format, $context, $groupID);
	}
	
	
	public static function getCollection($keys, $context=null, $format=false, $groupID=false) {
		return self::getObject('collection', $keys, $context, $format, $groupID);
	}
	
	public static function getCollectionResponse($keys, $context=null, $format=false, $groupID=false) {
		return self::getObjectResponse('collection', $keys, $context, $format, $groupID);
	}
	
	
	public static function getSearch($keys, $context=null, $format=false, $groupID=false) {
		return self::getObject('search', $keys, $context, $format, $groupID);
	}
	
	
	public static function getSearchResponse($keys, $context=null, $format=false, $groupID=false) {
		return self::getObjectResponse('search', $keys, $context, $format, $groupID);
	}
	
	
	// Atom
	public static function getItemXML($keys, $context=null) {
		return self::getObject('item', $keys, $context, 'atom');
	}
	
	
	public static function getCollectionXML($keys, $context=null) {
		return self::getObject('collection', $keys, $context, 'atom');
	}
	
	
	public static function getSearchXML($keys, $context=null) {
		return self::getObject('search', $keys, $context, 'atom');
	}
	
	
	public static function groupGetItemXML($groupID, $keys, $context=null) {
		if (is_scalar($keys)) {
			$keys = array($keys);
		}
		
		$response = self::groupGet(
			$groupID,
			"items?key=" . self::$config['apiKey']
				. "&itemKey=" . implode(',', $keys) . "&order=itemKeyList"
				. "&content=json"
		);
		if ($context) {
			$context->assert200($response);
		}
		return self::getXMLFromResponse($response);
	}
	
	
	public static function getXMLFromFirstSuccessItem($response) {
		$key = self::getFirstSuccessKeyFromResponse($response);
		self::getItemXML($key);
	}
	
	
	
	
	//
	// HTTP methods
	//
	public static function get($url, $headers=array(), $auth=false) {
		$url = self::$config['apiURLPrefix'] . $url;
		if (self::$apiVersion) {
			$headers[] = "Zotero-API-Version: " . self::$apiVersion;
		}
		if (!$auth && self::$apiKey) {
			$headers[] = "Authorization: Bearer " . self::$apiKey;
		}
		$response = HTTP::get($url, $headers, $auth);
		if (self::$config['verbose'] >= 2) {
			echo "\n\n" . $response->getBody() . "\n";
		}
		return $response;
	}
	
	public static function superGet($url, $headers=[]) {
		return self::get(
			$url,
			$headers,
			[
				"username" => self::$config['rootUsername'],
				"password" => self::$config['rootPassword']
			]
		);
	}
	
	public static function userGet($userID, $suffix, $headers=array(), $auth=false) {
		return self::get("users/$userID/$suffix", $headers, $auth);
	}
	
	public static function groupGet($groupID, $suffix, $headers=array(), $auth=false) {
		return self::get("groups/$groupID/$suffix", $headers, $auth);
	}
	
	public static function post($url, $data, $headers=array(), $auth=false) {
		$url = self::$config['apiURLPrefix'] . $url;
		if (self::$apiVersion) {
			$headers[] = "Zotero-API-Version: " . self::$apiVersion;
		}
		if (!$auth && self::$apiKey) {
			$headers[] = "Authorization: Bearer " . self::$apiKey;
		}
		$response = HTTP::post($url, $data, $headers, $auth);
		return $response;
	}
	
	public static function superPost($url, $data, $headers=[]) {
		return self::post(
			$url,
			$data,
			$headers,
			[
				"username" => self::$config['rootUsername'],
				"password" => self::$config['rootPassword']
			]
		);
	}
	
	public static function userPost($userID, $suffix, $data, $headers=array(), $auth=false) {
		return self::post("users/$userID/$suffix", $data, $headers, $auth);
	}
	
	public static function groupPost($groupID, $suffix, $data, $headers=array(), $auth=false) {
		return self::post("groups/$groupID/$suffix", $data, $headers, $auth);
	}
	
	public static function put($url, $data, $headers=array(), $auth=false) {
		$url = self::$config['apiURLPrefix'] . $url;
		if (self::$apiVersion) {
			$headers[] = "Zotero-API-Version: " . self::$apiVersion;
		}
		if (!$auth && self::$apiKey) {
			$headers[] = "Authorization: Bearer " . self::$apiKey;
		}
		$response = HTTP::put($url, $data, $headers, $auth);
		return $response;
	}
	
	public static function superPut($url, $data, $headers=[]) {
		return self::put(
			$url,
			$data,
			$headers,
			[
				"username" => self::$config['rootUsername'],
				"password" => self::$config['rootPassword']
			]
		);
	}
	
	public static function userPut($userID, $suffix, $data, $headers=array(), $auth=false) {
		return self::put("users/$userID/$suffix", $data, $headers, $auth);
	}
	
	public static function groupPut($groupID, $suffix, $data, $headers=array(), $auth=false) {
		return self::put("groups/$groupID/$suffix", $data, $headers, $auth);
	}
	
	public static function patch($url, $data, $headers=array(), $auth=false) {
		$url = self::$config['apiURLPrefix'] . $url;
		if (self::$apiVersion) {
			$headers[] = "Zotero-API-Version: " . self::$apiVersion;
		}
		if (!$auth && self::$apiKey) {
			$headers[] = "Authorization: Bearer " . self::$apiKey;
		}
		$response = HTTP::patch($url, $data, $headers, $auth);
		return $response;
	}
	
	public static function userPatch($userID, $suffix, $data, $headers=array()) {
		return self::patch("users/$userID/$suffix", $data, $headers);
	}
	
	public static function head($url, $headers=array(), $auth=false) {
		$url = self::$config['apiURLPrefix'] . $url;
		if (self::$apiVersion) {
			$headers[] = "Zotero-API-Version: " . self::$apiVersion;
		}
		if (!$auth && self::$apiKey) {
			$headers[] = "Authorization: Bearer " . self::$apiKey;
		}
		$response = HTTP::head($url, $headers, $auth);
		return $response;
	}
	
	public static function userHead($userID, $suffix, $headers=array(), $auth=false) {
		return self::head("users/$userID/$suffix", $headers, $auth);
	}
	
	public static function delete($url, $headers=array(), $auth=false) {
		$url = self::$config['apiURLPrefix'] . $url;
		if (self::$apiVersion) {
			$headers[] = "Zotero-API-Version: " . self::$apiVersion;
		}
		if (!$auth && self::$apiKey) {
			$headers[] = "Authorization: Bearer " . self::$apiKey;
		}
		$response = HTTP::delete($url, $headers, $auth);
		return $response;
	}
	
	public static function superDelete($url, $headers=[]) {
		return self::delete(
			$url,
			$headers,
			[
				"username" => self::$config['rootUsername'],
				"password" => self::$config['rootPassword']
			]
		);
	}
	
	public static function userDelete($userID, $suffix, $headers=array(), $auth=false) {
		return self::delete("users/$userID/$suffix", $headers, $auth);
	}
	
	public static function groupDelete($groupID, $suffix, $headers=array(), $auth=false) {
		return self::delete("groups/$groupID/$suffix", $headers, $auth);
	}
	
	
	public static function userClear($userID) {
		$response = self::userPost(
			$userID,
			"clear",
			"",
			array(),
			array(
				"username" => self::$config['rootUsername'],
				"password" => self::$config['rootPassword']
			)
		);
		if ($response->getStatus() != 204) {
			var_dump($response->getBody());
			throw new Exception("Error clearing user $userID");
		}
	}
	
	public static function groupClear($groupID) {
		$response = self::groupPost(
			$groupID,
			"clear",
			"",
			array(),
			array(
				"username" => self::$config['rootUsername'],
				"password" => self::$config['rootPassword']
			)
		);
		if ($response->getStatus() != 204) {
			var_dump($response->getBody());
			throw new Exception("Error clearing group $groupID");
		}
	}
	
	
	//
	// Response parsing
	//
	public static function getXMLFromResponse($response) {
		try {
			$xml = new SimpleXMLElement($response->getBody());
		}
		catch (Exception $e) {
			var_dump($response->getBody());
			throw $e;
		}
		$xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
		$xml->registerXPathNamespace('zapi', 'http://zotero.org/ns/api');
		return $xml;
	}
	
	
	public static function getJSONFromResponse($response, $asObject=false) {
		$json = json_decode($response->getBody(), !$asObject);
		if (is_null($json)) {
			var_dump($response->getBody());
			throw new Exception("JSON response could not be parsed");
		}
		return $json;
	}
	
	
	public static function getFirstSuccessKeyFromResponse($response) {
		$json = self::getJSONFromResponse($response);
		if (empty($json['success'])) {
			var_dump($response->getBody());
			throw new Exception("No success keys found in response");
		}
		return array_shift($json['success']);
	}
	
	
	public static function parseDataFromAtomEntry($entryXML) {
		$key = (string) array_shift($entryXML->xpath('//atom:entry/zapi:key'));
		$version = (string) array_shift($entryXML->xpath('//atom:entry/zapi:version'));
		$content = array_shift($entryXML->xpath('//atom:entry/atom:content'));
		if (is_null($content)) {
			var_dump($entryXML->asXML());
			throw new Exception("Atom response does not contain <content>");
		}
		
		// If 'content' contains XML, serialize all subnodes
		if ($content->count()) {
			$content = $content->asXML();
		}
		// Otherwise just get string content
		else {
			$content = (string) $content;
		}
		
		return array(
			"key" => $key,
			"version" => $version,
			"content" => $content
		);
	}
	
	
	public static function getContentFromResponse($response) {
		$xml = self::getXMLFromResponse($response);
		$data = self::parseDataFromAtomEntry($xml);
		return $data['content'];
	}
	
	
	public static function setKeyOption($userID, $key, $option, $val) {
		$response = self::get(
			"users/$userID/keys/$key",
			array(),
			array(
				"username" => self::$config['rootUsername'],
				"password" => self::$config['rootPassword']
			)
		);
		if ($response->getStatus() != 200) {
			var_dump($response->getBody());
			throw new Exception("GET returned " . $response->getStatus());
		}
		
		if (self::$apiVersion >= 3) {
			$json = self::getJSONFromResponse($response);
			
			switch ($option) {
			case 'libraryNotes':
				if (!isset($json['access']['user']) || $val == !empty($json['access']['user']['notes'])) {
					break;
				}
				$json['access']['user']['notes'] = $val;
				break;
				
			case 'libraryWrite':
				if (!isset($json['access']['user']) || $val == !empty($json['access']['user']['write'])) {
					break;
				}
				$json['access']['user']['write'] = $val;
				break;
			}
			
			$response = self::put(
				"users/" . self::$config['userID'] . "/keys/" . self::$config['apiKey'],
				json_encode($json),
				[],
				[
					"username" => self::$config['rootUsername'],
					"password" => self::$config['rootPassword']
				]
			);
		}
		else {
			try {
				$xml = new SimpleXMLElement($response->getBody());
			}
			catch (Exception $e) {
				var_dump($response->getBody());
				throw $e;
			}
			foreach ($xml->access as $access) {
				switch ($option) {
				case 'libraryNotes':
					if (!isset($access['library'])) {
						break;
					}
					$current = (int) $access['notes'];
					if ($current != $val) {
						$access['notes'] = (int) $val;
					}
					break;
				
				case 'libraryWrite':
					if (!isset($access['library'])) {
						continue;
					}
					$current = (int) $access['write'];
					if ($current != $val) {
						$access['write'] = (int) $val;
					}
					break;
				}
			}
			
			$response = self::put(
				"users/" . self::$config['userID'] . "/keys/" . self::$config['apiKey'],
				$xml->asXML(),
				array(),
				array(
					"username" => self::$config['rootUsername'],
					"password" => self::$config['rootPassword']
				)
			);
		}
		if ($response->getStatus() != 200) {
			var_dump($response->getBody());
			throw new Exception("PUT returned " . $response->getStatus());
		}
	}
	
	
	public static function getPluralObjectType($objectType) {
		if ($objectType == 'search') {
			return $objectType . "es";
		}
		return $objectType . "s";
	}
	
	
	private static function getObjectResponse($objectType, $keys, $context=null, $format=false, $groupID=false) {
		$objectTypePlural = self::getPluralObjectType($objectType);
		
		$single = is_string($keys);
		
		$url = "$objectTypePlural";
		if ($single) {
			$url .= "/$keys";
		}
		$url .= "?key=" . self::$config['apiKey'];
		if (!$single) {
			$url .= "&{$objectType}Key=" . implode(',', $keys) . "&order={$objectType}KeyList";
		}
		if ($format !== false) {
			$url .= "&format=" . $format;
			if ($format == 'atom') {
				$url .= '&content=json';
			}
		}
		if ($groupID) {
			$response = self::groupGet($groupID, $url);
		}
		else {
			$response = self::userGet(self::$config['userID'], $url);
		}
		if ($context) {
			$context->assert200($response);
		}
		return $response;
	}
	
	
	private static function getObject($objectType, $keys, $context=null, $format=false, $groupID=false) {
		$response = self::getObjectResponse($objectType, $keys, $context, $format, $groupID);
		$contentType = $response->getHeader('Content-Type');
		switch ($contentType) {
		case 'application/json':
			return self::getJSONFromResponse($response);
		
		case 'application/atom+xml':
			return self::getXMLFromResponse($response);
		
		default:
			var_dump($response->getBody());
			throw new Exception("Unknown content type '$contentType'");
		}
	}
	
	
	private function handleCreateResponse($objectType, $response, $returnFormat, $context=null, $groupID=false) {
		if ($context) {
			if (!preg_match('/APIv([0-9]+)/', get_class($context), $matches)) {
				throw new Exception("Unexpected namespace");
			}
			$apiVersion = (int) $matches[1];
		}
		
		$uctype = ucwords($objectType);
		
		if ($context) {
			$context->assert200($response);
		}
		
		if ($returnFormat == 'response') {
			return $response;
		}
		
		$json = self::getJSONFromResponse($response);
		
		if ($returnFormat != 'responseJSON' && sizeOf($json['success']) != 1) {
			var_dump($json);
			throw new Exception("$uctype creation failed");
		}
		
		if ($returnFormat == 'responseJSON') {
			return $json;
		}
		
		$key = array_shift($json['success']);
		
		if ($returnFormat == 'key') {
			return $key;
		}
		
		// returnFormat can be 'json', 'jsonResponse', 'atom', 'atomResponse', 'content', 'data'
		$asResponse = false;
		if (preg_match('/response$/i', $returnFormat)) {
			$returnFormat = substr($returnFormat, 0, -8);
			$asResponse = true;
		}
		$func = 'get' . $uctype . ($asResponse ? 'Response' : '');
		
		if (substr($returnFormat, 0, 4) == 'json') {
			$response = self::$func($key, $context, 'json', $groupID);
			if ($returnFormat == 'json' || $returnFormat == 'jsonResponse') {
				return $response;
			}
			if ($returnFormat == 'jsonData') {
				return $response['data'];
			}
		}
		
		// Request Atom
		$response = self::$func($key, $context, 'atom', $groupID);
		
		if ($returnFormat == 'atom' || $returnFormat == 'atomResponse') {
			return $response;
		}
		
		$xml = self::getXMLFromResponse($response);
		$data = self::parseDataFromAtomEntry($xml);
		
		if ($returnFormat == 'data') {
			return $data;
		}
		if ($returnFormat == 'content') {
			return $data['content'];
		}
		if ($returnFormat == 'atomJSON') {
			return json_decode($data['content'], true);
		}
		
		throw new Exception("Invalid result format '$returnFormat'");
	}
}

API3::loadConfig();
