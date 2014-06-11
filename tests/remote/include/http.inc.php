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
	
	private function loadConfig() {
		require 'include/config.inc.php';
		foreach ($config as $k => $v) {
			self::$config[$k] = $v;
		}
	}
	
	
	public function get($url, $headers=array(), $auth=false) {
		self::loadConfig();
		$req = new HTTP_Request2($url);
		$req->setHeader($headers);
		if ($auth) {
			$req->setAuth($auth['username'], $auth['password']);
		}
		$req->setConfig('ssl_verify_peer', false);
		if (self::$config['verbose'] >= 1) {
			echo "\nGET $url\n";
		}
		$response = $req->send();
		if (self::$config['verbose'] >= 2) {
			echo "\n\n" . $response->getBody() . "\n";
		}
		return $response;
	}
	
	public function post($url, $data, $headers=array(), $auth=false) {
		$req = new HTTP_Request2($url);
		$req->setMethod(HTTP_Request2::METHOD_POST);
		$req->setHeader($headers);
		if ($auth) {
			$req->setAuth($auth['username'], $auth['password']);
		}
		$req->setConfig('ssl_verify_peer', false);
		if (is_array($data)) {
			$req->addPostParameter($data);
		}
		else {
			$req->setBody($data);
		}
		if (self::$config['verbose'] >= 1) {
			echo "\nPOST $url\n";
		}
		$response = $req->send();
		return $response;
	}
	
	public function put($url, $data, $headers=array(), $auth=false) {
		$req = new HTTP_Request2($url);
		$req->setMethod(HTTP_Request2::METHOD_PUT);
		$req->setHeader($headers);
		if ($auth) {
			$req->setAuth($auth['username'], $auth['password']);
		}
		$req->setConfig('ssl_verify_peer', false);
		$req->setBody($data);
		if (self::$config['verbose'] >= 1) {
			echo "\nPUT $url\n";
		}
		$response = $req->send();
		return $response;
	}
	
	public function patch($url, $data, $headers=array()) {
		$req = new HTTP_Request2($url);
		$req->setMethod("PATCH");
		$req->setHeader($headers);
		$req->setConfig('ssl_verify_peer', false);
		$req->setBody($data);
		if (self::$config['verbose'] >= 1) {
			echo "\nPATCH $url\n";
		}
		$response = $req->send();
		return $response;
	}
	
	public function head($url, $headers=array(), $auth=false) {
		$req = new HTTP_Request2($url);
		$req->setMethod(HTTP_Request2::METHOD_HEAD);
		$req->setHeader($headers);
		if ($auth) {
			$req->setAuth($auth['username'], $auth['password']);
		}
		$req->setConfig('ssl_verify_peer', false);
		if (self::$config['verbose'] >= 1) {
			echo "\nHEAD $url\n";
		}
		$response = $req->send();
		return $response;
	}
	
	
	public function options($url, $headers=[]) {
		$req = new HTTP_Request2($url);
		$req->setMethod(HTTP_Request2::METHOD_OPTIONS);
		$req->setHeader($headers);
		$req->setConfig('ssl_verify_peer', false);
		if (self::$config['verbose'] >= 1) {
			echo "\nOPTIONS $url\n";
		}
		$response = $req->send();
		return $response;
	}
	
	public function delete($url, $headers=array(), $auth=false) {
		$req = new HTTP_Request2($url);
		$req->setMethod(HTTP_Request2::METHOD_DELETE);
		$req->setHeader($headers);
		if ($auth) {
			$req->setAuth($auth['username'], $auth['password']);
		}
		$req->setConfig('ssl_verify_peer', false);
		if (self::$config['verbose'] >= 1) {
			echo "\nDELETE $url\n";
		}
		$response = $req->send();
		return $response;
	}
}
