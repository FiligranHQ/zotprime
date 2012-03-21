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

class API {
	private static $config;
	private static $nsZAPI;
	
	private function loadConfig() {
		require 'include/config.inc.php';
		foreach ($config as $k => $v) {
			self::$config[$k] = $v;
		}
		self::$nsZAPI = 'http://zotero.org/ns/api';
	}
	
	
	public function get($url, $headers=array(), $auth=false) {
		self::loadConfig();
		$url = self::$config['apiURLPrefix'] . $url;
		$response = HTTP::get($url, $headers, $auth);
		if (self::$config['verbose']) {
			echo "\n\n" . $response->getBody() . "\n";
		}
		return $response;
	}
	
	public function userGet($userID, $suffix, $headers=array(), $auth=false) {
		return self::get("users/$userID/$suffix", $headers, $auth);
	}
	
	public function post($url, $data, $headers=array(), $auth=false) {
		self::loadConfig();
		$url = self::$config['apiURLPrefix'] . $url;
		$response = HTTP::post($url, $data, $headers, $auth);
		return $response;
	}
	
	public function userPost($userID, $suffix, $data, $headers=array(), $auth=false) {
		return self::post("users/$userID/$suffix", $data, $headers, $auth);
	}
	
	public function put($url, $data, $headers=array(), $auth=false) {
		self::loadConfig();
		$url = self::$config['apiURLPrefix'] . $url;
		$response = HTTP::put($url, $data, $headers, $auth);
		return $response;
	}
	
	public function userPut($userID, $suffix, $data, $headers=array(), $auth=false) {
		return self::put("users/$userID/$suffix", $data, $headers, $auth);
	}
	
	public function patch($url, $data, $headers=array(), $auth=false) {
		self::loadConfig();
		$url = self::$config['apiURLPrefix'] . $url;
		$response = HTTP::patch($url, $data, $headers, $auth);
		return $response;
	}
	
	public function userPatch($userID, $suffix, $data, $headers=array()) {
		return self::patch("users/$userID/$suffix", $data, $headers);
	}
	
	public function head($url, $headers=array(), $auth=false) {
		self::loadConfig();
		$url = self::$config['apiURLPrefix'] . $url;
		$response = HTTP::head($url, $headers, $auth);
		return $response;
	}
	
	public function userHead($userID, $suffix, $headers=array(), $auth=false) {
		return self::head("users/$userID/$suffix", $headers, $auth);
	}
	
	public function delete($url, $headers=array(), $auth=false) {
		self::loadConfig();
		$url = self::$config['apiURLPrefix'] . $url;
		$response = HTTP::delete($url, $headers, $auth);
		return $response;
	}
	
	public function userDelete($userID, $suffix, $headers=array(), $auth=false) {
		return self::delete("users/$userID/$suffix", $headers, $auth);
	}
	
	
	public static function getXMLFromResponse($response) {
		$xml = new SimpleXMLElement($response->getBody());
		$xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
		$xml->registerXPathNamespace('zapi', 'http://zotero.org/ns/api');
		return $xml;
	}
	
	
	public static function parseDataFromItemEntry($itemEntryXML) {
		$key = (string) array_shift($itemEntryXML->xpath('//atom:entry/zapi:key'));
		$etag = (string) array_shift($itemEntryXML->xpath('//atom:entry/atom:content/@zapi:etag'));
		$content = (string) array_shift($itemEntryXML->xpath('//atom:entry/atom:content'));
		
		return array(
			"key" => $key,
			"etag" => $etag,
			"content" => $content
		);
	}
	
	
	/**
	* Strips updated and published field values
	*/
	public function simpleXMLStripAtomDates(SimpleXMLElement $xml) {
		$namespaces = $xml->getNamespaces(true);
		$xml->registerXPathNamespace("default", $namespaces[""]);
		$updated = $xml->xpath("//default:updated|//default:published");
		foreach ($updated as $node) {
			$node[0] = "";
		}
	}
}
