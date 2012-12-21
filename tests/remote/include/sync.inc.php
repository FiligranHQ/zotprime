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
	}
	
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
	
	
	public static function updated($sessionID, $lastsync=1) {
		return self::req($sessionID, "updated", array("lastsync" => $lastsync));
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
		return new SimpleXMLElement($response->getBody());
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
