<?php
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2013 Center for History and New Media
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

require('ApiController.php');

class KeysController extends ApiController {
	public function keys() {
		$userID = $this->objectUserID;
		$key = $this->objectName;
		
		if ($this->method == 'GET') {
			// Single key
			if ($key) {
				$keyObj = Zotero_Keys::getByKey($key);
				if (!$keyObj || $keyObj->userID != $this->objectUserID) {
					$this->e404("Key not found");
				}
				
				$this->responseXML = $keyObj->toXML();
				
				// If not super-user, don't include name or recent IP addresses
				if (!$this->permissions->isSuper()) {
					unset($this->responseXML['dateAdded']);
					unset($this->responseXML['lastUsed']);
					unset($this->responseXML->name);
					unset($this->responseXML->recentIPs);
				}
			}
			
			// All of the user's keys
			else {
				if (!$this->permissions->isSuper()) {
					$this->e403();
				}
				
				$keyObjs = Zotero_Keys::getUserKeys($userID);
				$xml = new SimpleXMLElement('<keys/>');
				$domXML = dom_import_simplexml($xml);
				
				if ($keyObjs) {
					foreach ($keyObjs as $keyObj) {
						$keyXML = $keyObj->toXML();
						$domKeyXML = dom_import_simplexml($keyXML);
						$node = $domXML->ownerDocument->importNode($domKeyXML, true);
						$domXML->appendChild($node);
					}
				}
				
				$this->responseXML = $xml;
			}
		}
		
		else {
			// Require super-user for modifications
			if (!$this->permissions->isSuper()) {
				$this->e403();
			}
			
			if ($this->method == 'POST') {
				if ($key) {
					$this->e400("POST requests cannot end with a key (did you mean PUT?)");
				}
				
				try {
					$key = @new SimpleXMLElement($this->body);
				}
				catch (Exception $e) {
					$this->e400("$this->method data is not valid XML");
				}
				
				if ((string) $key['key']) {
					$this->e400("POST requests cannot contain a key in '" . $this->body . "'");
				}
				
				$fields = $this->getFieldsFromKeyXML($key);
				
				Zotero_DB::beginTransaction();
				
				try {
					$keyObj = new Zotero_Key;
					$keyObj->userID = $userID;
					foreach ($fields as $field=>$val) {
						if ($field == 'access') {
							foreach ($val as $access) {
								$this->setKeyPermissions($keyObj, $access);
							}
						}
						else {
							$keyObj->$field = $val;
						}
					}
					$keyObj->save();
				}
				catch (Exception $e) {
					if ($e->getCode() == Z_ERROR_KEY_NAME_TOO_LONG) {
						$this->e400($e->getMessage());
					}
					$this->handleException($e);
				}
				
				$this->responseXML = $keyObj->toXML();
				
				Zotero_DB::commit();
				
				$url = Zotero_API::getKeyURI($keyObj);
				$this->responseCode = 201;
				header("Location: " . $url, false, 201);
				$this->end();
			}
			
			if ($this->method == 'PUT') {
				if (!$key) {
					$this->e400("PUT requests must end with a key (did you mean POST?)");
				}
				
				try {
					$keyXML = @new SimpleXMLElement($this->body);
				}
				catch (Exception $e) {
					$this->e400("$this->method data is not valid XML");
				}
				
				$fields = $this->getFieldsFromKeyXML($keyXML);
				
				// Key attribute is optional, but, if it's there, make sure it matches
				if (isset($fields['key']) && $fields['key'] != $key) {
					$this->e400("Key '{$fields['key']}' does not match key '$key' from URI");
				}
				
				Zotero_DB::beginTransaction();
				
				try {
					$keyObj = Zotero_Keys::getByKey($key);
					if (!$keyObj) {
						$this->e404("Key '$key' does not exist");
					}
					foreach ($fields as $field=>$val) {
						if ($field == 'access') {
							foreach ($val as $access) {
								$this->setKeyPermissions($keyObj, $access);
							}
						}
						else {
							$keyObj->$field = $val;
						}
					}
					$keyObj->save();
				}
				catch (Exception $e) {
					if ($e->getCode() == Z_ERROR_KEY_NAME_TOO_LONG) {
						$this->e400($e->getMessage());
					}
					$this->handleException($e);
				}
				
				$this->responseXML = $keyObj->toXML();
				
				Zotero_DB::commit();
			}
			
			if ($this->method == 'DELETE') {
				if (!$key) {
					$this->e400("DELETE requests must end with a key");
				}
				
				Zotero_DB::beginTransaction();
				
				$keyObj = Zotero_Keys::getByKey($key);
				if (!$keyObj) {
					$this->e404("Key '$key' does not exist");
				}
				$keyObj->erase();
				Zotero_DB::commit();
				
				header("HTTP/1.1 204 No Content");
				exit;
			}
		}
		
		header('Content-Type: application/xml');
		$xmlstr = $this->responseXML->asXML();
		
		$doc = new DOMDocument('1.0');
		
		$doc->loadXML($xmlstr);
		$doc->formatOutput = true;
		echo $doc->saveXML();
		exit;
	}
	
	
	protected function getFieldsFromKeyXML(SimpleXMLElement $xml) {
		$fields = array();
		$fields['name'] = (string) $xml->name;
		$fields['access'] = array();
		foreach ($xml->access as $access) {
			$a = array();
			if (isset($access['group'])) {
				$a['group'] = $access['group'] == 'all' ? 0 : (int) $access['group'];
			}
			else {
				$a['library'] = (int) $access['library'];
				$a['notes'] = (int) $access['notes'];
			}
			$a['write'] = isset($access['write']) ? (bool) (int) $access['write'] : false;
			$fields['access'][] = $a;
		}
		return $fields;
	}
	
	
	protected function setKeyPermissions($keyObj, $accessElement) {
		foreach ($accessElement as $accessField=>$accessVal) {
			// 'write' is handled below
			if ($accessField == 'write') {
				continue;
			}
			
			// Group library access (<access group="23456"/>)
			if ($accessField == 'group') {
				// Grant access to all groups
				if ($accessVal === 0) {
					$keyObj->setPermission(0, 'group', true);
					$keyObj->setPermission(0, 'write', $accessElement['write']);
				}
				else {
					$group = Zotero_Groups::get($accessVal);
					if (!$group) {
						$this->e400("Group not found");
					}
					if (!$group->hasUser($this->objectUserID)) {
						$this->e400("User $this->id is not a member of group $group->id");
					}
					$keyObj->setPermission($group->libraryID, 'library', true);
					$keyObj->setPermission($group->libraryID, 'write', $accessElement['write']);
				}
			}
			// Personal library access (<access library="1" notes="0"/>)
			else {
				$libraryID = Zotero_Users::getLibraryIDFromUserID($this->objectUserID);
				$keyObj->setPermission($libraryID, $accessField, $accessVal);
				$keyObj->setPermission($libraryID, 'write', $accessElement['write']);
			}
		}
	}
}
