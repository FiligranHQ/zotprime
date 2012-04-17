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
		require 'include/config.inc.php';
		foreach ($config as $k => $v) {
			self::$config[$k] = $v;
		}
	}
	
	public static function login() {
		self::loadConfig();
		
		$url = self::$config['syncURLPrefix'] . "login";
		$response = HTTP::post(
			$url,
			array(
				"version" => self::$config['apiVersion'],
				"username" => self::$config['username'],
				"password" => self::$config['password']
			)
		);
		self::checkResponse($response);
		$xml = new SimpleXMLElement($response->getBody());
		$sessionID = (string) $xml->sessionID;
		self::checkSessionID($sessionID);
		return $sessionID;
	}
	
	
	public static function logout($sessionID) {
		self::loadConfig();
		
		$url = self::$config['syncURLPrefix'] . "logout";
		$response = HTTP::post(
			$url,
			array(
				"version" => self::$config['apiVersion'],
				"sessionid" => $sessionID
			)
		);
		self::checkResponse($response);
		$xml = new SimpleXMLElement($response->getBody());
		if (!$xml->loggedout) {
			throw new Exception("Error logging out");
		}
	}
	
	
	public static function checkResponse($response) {
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
		
		if ($domdoc->firstChild->firstChild->tagName == "error") {
			if ($domdoc->firstChild->firstChild->getAttribute('code') == "INVALID_LOGIN") {
				throw new Exception("Invalid login");
			}
			
			throw new Exception($responseText);
		}
	}
	
	
	public static function getXMLFromResponse($response) {
		return new SimpleXMLElement($response->getBody());
	}
	
	
	private static function checkSessionID($sessionID) {
		if (!preg_match('/^[a-g0-9]{32}$/', $sessionID)) {
			throw new Exception("Invalid session id");
		}
	}

}
