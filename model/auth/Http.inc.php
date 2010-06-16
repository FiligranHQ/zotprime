<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2010 Center for History and New Media
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

class Zotero_AuthenticationPlugin_Http implements Zotero_AuthenticationPlugin {
	public static function authenticate($data) {
		if (Z_ENV_DEV_SITE) {
			throw new Exception("External auth requests disabled on dev site");
		}
		else if (empty(Z_CONFIG::$AUTH_URI)) {
			throw new Exception("Authorization URI not set");
		}
		else {
			$url = Z_CONFIG::$AUTH_URI;
		}
		
		$username = urlencode($data['username']);
		$password = urlencode($data['password']);
		$postdata = "username=$username&password=$password";
		$userID = @self::do_post_request($url, $postdata);
		
		if (!is_numeric($userID) || $userID <= 0) {
			return false;
		}
		$userID = (int) $userID;
		
		$isEmailAddress = strpos($data['username'], '@') !== false;
		
		Zotero_DB::beginTransaction();
		
		$dbUsername = Zotero_Users::getUsername($userID, true);
		
		if ($dbUsername || Zotero_Users::exists($userID)) {
			// If login is username and doesn't match existing
			if (!$isEmailAddress && $dbUsername != $data['username']) {
				Zotero_Users::update($userID, $data['username']);
			}
			// Otherwise just update last sync time
			else {
				Zotero_Users::update($userID);
			}
		}
		else {
			if (!$isEmailAddress) {
				Zotero_Users::add($userID, $data['username']);
			}
			else {
				Zotero_Users::add($userID);
			}
			Zotero_Users::update($userID);
		}
		
		Zotero_DB::commit();
		
		return $userID;
	}
	
	
	// From http://www.netevil.org/blog/2006/nov/http-post-from-php-without-curl
	function do_post_request($url, $data, $optional_headers=null) {
		$params = array('http' => array(
			'method' => 'POST',
			'content' => $data
		));
		if ($optional_headers !== null) {
			$params['http']['header'] = $optional_headers;
		}
		$ctx = stream_context_create($params);
		$fp = @fopen($url, 'rb', false, $ctx);
		if (!$fp) {
			throw new Exception("Problem with $url, $php_errormsg");
		}
		$response = @stream_get_contents($fp);
		if ($response === false) {
			throw new Exception("Problem reading data from $url, $php_errormsg");
		}
		return $response;
	}
}
?>
