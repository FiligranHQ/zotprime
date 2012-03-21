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

class Sync {
	public static function clear() {
		require 'include/config.inc.php';
		
		// Get sync session
		$url = $config['syncURLPrefix'] . "login";
		$req = new HTTP_Request2($url);
		$req->setConfig('ssl_verify_peer', false);
		$req->setMethod(HTTP_Request2::METHOD_POST);
		$req->addPostParameter(
			array(
				"version" => $config['apiVersion'],
				"username" => $config['username'],
				"password" => $config['password']
			)
		);
		$response = $req->send();
		$xml = $response->getBody();
		$xml = new SimpleXMLElement($xml);
		$sessionID = (string) $xml->sessionID;
		
		// Clear account
		$url = $config['syncURLPrefix'] . "clear";
		$req = new HTTP_Request2($url);
		$req->setConfig('ssl_verify_peer', false);
		$req->setMethod(HTTP_Request2::METHOD_POST);
		$req->addPostParameter(
			array(
				"version" => $config['apiVersion'],
				"sessionid" => $sessionID
			)
		);
		$response = $req->send();
		$xml = $response->getBody();
		$xml = new SimpleXMLElement($xml);
		if (!$xml->cleared) {
			throw new Exception("Data not cleared");
		}
	}
}
