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

require_once 'HTTP/Request2.php';

class HTTP {
	private static $config;
	
	private static function loadConfig() {
		require 'include/config.inc.php';
		foreach ($config as $k => $v) {
			self::$config[$k] = $v;
		}
	}
	
	
	private static function getRequest($url, $headers, $auth) {
		$req = new HTTP_Request2($url);
		$req->setHeader($headers);
		if ($auth) {
			$req->setAuth($auth['username'], $auth['password']);
		}
		$req->setHeader("Expect:");
		$req->setConfig([
			'ssl_verify_peer' => false,
			'ssl_verify_host' => false
		]);
		
		return $req;
	}
	
	private static function sendRequest($req) {
		return $req->send();
	}
	
	
	public static function get($url, $headers=array(), $auth=false) {
		self::loadConfig();
		$req = self::getRequest($url, $headers, $auth);
		if (self::$config['verbose'] >= 1) {
			echo "\nGET $url\n";
		}
		$response = self::sendRequest($req);
		if (self::$config['verbose'] >= 2) {
			echo "\n\n" . $response->getBody() . "\n";
		}
		return $response;
	}
	
	public static function post($url, $data, $headers=array(), $auth=false) {
		$req = self::getRequest($url, $headers, $auth);
		$req->setMethod(HTTP_Request2::METHOD_POST);
		if (is_array($data)) {
			$req->addPostParameter($data);
		}
		else {
			$req->setBody($data);
		}
		if (self::$config['verbose'] >= 1) {
			echo "\nPOST $url\n";
		}
		$response = self::sendRequest($req);
		return $response;
	}
	
	public static function put($url, $data, $headers=array(), $auth=false) {
		$req = self::getRequest($url, $headers, $auth);
		$req->setMethod(HTTP_Request2::METHOD_PUT);
		$req->setBody($data);
		if (self::$config['verbose'] >= 1) {
			echo "\nPUT $url\n";
		}
		$response = self::sendRequest($req);
		return $response;
	}
	
	public static function patch($url, $data, $headers=array(), $auth=false) {
		$req = self::getRequest($url, $headers, $auth);
		$req->setMethod("PATCH");
		$req->setBody($data);
		if (self::$config['verbose'] >= 1) {
			echo "\nPATCH $url\n";
		}
		$response = self::sendRequest($req);
		return $response;
	}
	
	public static function head($url, $headers=array(), $auth=false) {
		$req = self::getRequest($url, $headers, $auth);
		$req->setMethod(HTTP_Request2::METHOD_HEAD);
		if (self::$config['verbose'] >= 1) {
			echo "\nHEAD $url\n";
		}
		$response = self::sendRequest($req);
		return $response;
	}
	
	
	public static function options($url, $headers=[], $auth=false) {
		$req = self::getRequest($url, $headers, $auth);
		$req->setMethod(HTTP_Request2::METHOD_OPTIONS);
		if (self::$config['verbose'] >= 1) {
			echo "\nOPTIONS $url\n";
		}
		$response = self::sendRequest($req);
		return $response;
	}
	
	public static function delete($url, $headers=array(), $auth=false) {
		$req = self::getRequest($url, $headers, $auth);
		$req->setMethod(HTTP_Request2::METHOD_DELETE);
		if (self::$config['verbose'] >= 1) {
			echo "\nDELETE $url\n";
		}
		$response = self::sendRequest($req);
		return $response;
	}
}
