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

require_once 'include/http.inc.php';
require_once '../../model/Utilities.inc.php';

class Sync {
	private static $config;
	
	private static function loadConfig() {
		if (self::$config) {
			return;
		}
		require 'include/config.inc.php';
		foreach ($config as $k => $v) {
			self::$config[$k] = $v;
		}
		
		date_default_timezone_set('UTC');
	}
	
	
	public static function createItem($sessionID, $libraryID, $itemType, $data=array(), $context) {
		$xml = Sync::updated($sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		$key = Zotero_Utilities::randomString(8, 'key', true);
		$dateAdded = date( 'Y-m-d H:i:s', time() - 1);
		$dateModified = date( 'Y-m-d H:i:s', time());
		
		$xmlstr = '<data version="9">'
			. '<items>'
			. '<item libraryID="' . $libraryID . '" '
				. 'itemType="' . $itemType . '" '
				. 'dateAdded="' . $dateAdded . '" '
				. 'dateModified="' . $dateModified . '" '
				. 'key="' . $key . '">';
		if ($data) {
			$relatedstr = "";
			foreach ($data as $key => $val) {
				$xmlstr .= '<field name="' . $key . '">' . $val . '</field>';
				if ($key == 'related') {
					$relatedstr .= "<related>$val</related>";
				}
			}
			$xmlstr .= $relatedstr;
		}
		$xmlstr .= '</item>'
			. '</items>'
			. '</data>';
		$response = Sync::upload($sessionID, $updateKey, $xmlstr);
		Sync::waitForUpload($sessionID, $response, $context);
		
		return $key;
	}
	
	
	public static function deleteItem($sessionID, $libraryID, $itemKey, $context=null) {
		$xml = Sync::updated($sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		$xmlstr = '<data version="9">'
			. '<deleted>'
			. '<items>'
			. '<item libraryID="' . self::$config['libraryID']
				. '" key="' . $itemKey . '"/>'
			. '</items>'
			. '</deleted>'
			. '</data>';
		$response = Sync::upload($sessionID, $updateKey, $xmlstr);
		Sync::waitForUpload($sessionID, $response, $context);
	}
	
	
	public static function createCollection($sessionID, $libraryID, $name, $parent, $context) {
		$xml = Sync::updated($sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		$key = Zotero_Utilities::randomString(8, 'key', true);
		$dateAdded = date( 'Y-m-d H:i:s', time() - 1);
		$dateModified = date( 'Y-m-d H:i:s', time());
		
		$xmlstr = '<data version="9">'
			. '<collections>'
			. '<collection libraryID="' . $libraryID . '" '
				. 'name="' . $name . '" ';
		if ($parent) {
			$xmlstr .= 'parent="' . $name . '" ';
		}
		$xmlstr .= 'dateAdded="' . $dateAdded . '" '
				. 'dateModified="' . $dateModified . '" '
				. 'key="' . $key . '"/>'
			. '</collections>'
			. '</data>';
		$response = Sync::upload($sessionID, $updateKey, $xmlstr);
		Sync::waitForUpload($sessionID, $response, $context);
		
		return $key;
	}
	
	
	public static function createSearch($sessionID, $libraryID, $name, $conditions, $context) {
		if ($conditions == 'default') {
			$conditions = array(
				array(
					'condition' => 'title',
					'operator' => 'contains',
					'value' => 'test'
				)
			);
		}
		
		$xml = Sync::updated($sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		$key = Zotero_Utilities::randomString(8, 'key', true);
		$dateAdded = date( 'Y-m-d H:i:s', time() - 1);
		$dateModified = date( 'Y-m-d H:i:s', time());
		
		$xmlstr = '<data version="9">'
			. '<searches>'
			. '<search libraryID="' . $libraryID . '" '
				. 'name="' . $name . '" '
				. 'dateAdded="' . $dateAdded . '" '
				. 'dateModified="' . $dateModified . '" '
				. 'key="' . $key . '">';
		$i = 1;
		foreach ($conditions as $condition) {
			$xmlstr .= '<condition id="' . $i . '" '
				. 'condition="' . $condition['condition'] . '" '
				. 'operator="' . $condition['operator'] . '" '
				. 'value="' . $condition['value'] . '"/>';
			$i++;
		}
		$xmlstr .= '</search>'
			. '</searches>'
			. '</data>';
		$response = Sync::upload($sessionID, $updateKey, $xmlstr);
		Sync::waitForUpload($sessionID, $response, $context);
		
		return $key;
	}
	
	
	//
	// Sync operations
	//
	public static function login($credentials=false) {
		self::loadConfig();
		
		if (!$credentials) {
			$credentials['username'] = self::$config['username'];
			$credentials['password'] = self::$config['password'];
		}
		
		$url = self::$config['syncURLPrefix'] . "login";
		$response = HTTP::post(
			$url,
			array(
				"version" => self::$config['syncVersion'],
				"username" => $credentials['username'],
				"password" => $credentials['password']
			)
		);
		self::checkResponse($response);
		$xml = new SimpleXMLElement($response->getBody());
		$sessionID = (string) $xml->sessionID;
		self::checkSessionID($sessionID);
		return $sessionID;
	}
	
	
	public static function updated($sessionID, $lastsync=1, $allowError=false, $allowQueued=false) {
		$response = self::req($sessionID, "updated", array("lastsync" => $lastsync));
		$xml = Sync::getXMLFromResponse($response);
		
		if (isset($xml->updated) || (isset($xml->error) && $allowError)
				|| (isset($xml->locked) && $allowQueued)) {
			return $xml;
		}
		
		if (!isset($xml->locked)) {
			var_dump($xml->asXML());
			throw new Exception("Not locked");
		}
		
		$max = 5;
		do {
			$wait = (int) $xml->locked['wait'];
			sleep($wait / 1000);
			
			$xml = Sync::updated($sessionID, $lastsync, $allowError, true);
			
			$max--;
		}
		while (isset($xml->locked) && $max > 0);
		
		if (!$max) {
			throw new Exception("Download did not finish after $max attempts");
		}
		
		if (!$allowError && !isset($xml->updated)) {
			var_dump($xml->asXML());
			throw new Exception("<updated> not found");
		}
		
		return $xml;
	}
	
	
	public static function upload($sessionID, $updateKey, $data, $allowError=false) {
		return self::req(
			$sessionID,
			"upload",
			array(
				"updateKey" => $updateKey,
				"data" => $data,
			),
			true,
			$allowError
		);
	}
	
	
	public static function uploadstatus($sessionID, $allowError=false) {
		return self::req($sessionID, "uploadstatus", false, false, true);
	}
	
	
	public static function waitForUpload($sessionID, $response, $context, $allowError=false) {
		$xml = Sync::getXMLFromResponse($response);
		
		if (isset($xml->uploaded) || (isset($xml->error) && $allowError))  {
			return $xml;
		}
		
		$context->assertTrue(isset($xml->queued));
		
		$max = 5;
		do {
			$wait = (int) $xml->queued['wait'];
			sleep($wait / 1000);
			
			$response = Sync::uploadStatus($sessionID, $allowError);
			$xml = Sync::getXMLFromResponse($response);
			
			$max--;
		}
		while (isset($xml->queued) && $max > 0);
		
		if (!$max) {
			$context->fail("Upload did not finish after $max attempts");
		}
		
		if (!$allowError) {
			$context->assertTrue(isset($xml->uploaded));
		}
		
		return $xml;
	}
	
	
	public static function logout($sessionID) {
		self::loadConfig();
		
		$url = self::$config['syncURLPrefix'] . "logout";
		$response = HTTP::post(
			$url,
			array(
				"version" => self::$config['syncVersion'],
				"sessionid" => $sessionID
			)
		);
		self::checkResponse($response);
		$xml = new SimpleXMLElement($response->getBody());
		if (!$xml->loggedout) {
			throw new Exception("Error logging out");
		}
	}
	
	
	public static function checkResponse($response, $allowError=false) {
		$responseText = $response->getBody();
		
		if (empty($responseText)) {
			throw new Exception("Response is empty");
		}
		
		$domdoc = new DOMDocument;
		try {
			$domdoc->loadXML($responseText);
		}
		catch (Exception $e) {
			var_dump($responseText);
			throw ($e);
		}
		if ($domdoc->firstChild->tagName != "response") {
			throw new Exception("Invalid XML output: " . $responseText);
		}
		
		if (!$allowError && $domdoc->firstChild->firstChild->tagName == "error") {
			if ($domdoc->firstChild->firstChild->getAttribute('code') == "INVALID_LOGIN") {
				throw new Exception("Invalid login");
			}
			
			throw new Exception($responseText);
		}
	}
	
	
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

	
	
	private static function req($sessionID, $path, $params=array(), $gzip=false, $allowError=false) {
		self::loadConfig();
		
		$url = self::$config['syncURLPrefix'] . $path;
		
		$params = array_merge(
			array(
				"sessionid" => $sessionID,
				"version" => self::$config['syncVersion']
			),
			$params ? $params : array()
		);
		
		if ($gzip) {
			$data = "";
			foreach ($params as $key => $val) {
				$data .= $key . "=" . urlencode($val) . "&";
			}
			$data = gzdeflate(substr($data, 0, -1));
			$headers = array(
				"Content-Type: application/octet-stream",
				"Content-Encoding: gzip"
			);
		}
		else {
			$data = $params;
			$headers = array();
		}
		
		$response = HTTP::post($url, $data, $headers);
		self::checkResponse($response, $allowError);
		return $response;
	}
	
	
	private static function checkSessionID($sessionID) {
		if (!preg_match('/^[a-g0-9]{32}$/', $sessionID)) {
			throw new Exception("Invalid session id");
		}
	}

}
