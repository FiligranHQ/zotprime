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

class StorageController extends ApiController {
	//
	// Storage-related
	//
	
	public function laststoragesync() {
		if (!$this->httpAuth && !$this->permissions->isSuper()) {
			$this->e403();
		}
		
		$this->allowMethods(array('GET', 'POST'));
		
		if ($this->method == 'POST') {
			//Zotero_Users::setLastStorageSync($this->userID);
		}
		
		// Deprecated after 3.0, which used auth=1
		if ($this->queryParams['apiVersion'] < 2 || !empty($_GET['auth'])) {
			$lastSync = Zotero_Users::getLastStorageSync($this->objectUserID);
		}
		else {
			$lastSync = Zotero_Libraries::getLastStorageSync($this->objectLibraryID);
		}
		if (!$lastSync) {
			$this->e404();
		}
		
		echo $lastSync;
		exit;
	}
	
	
	public function removestoragefiles() {
		if (!$this->permissions->isSuper() && !$this->httpAuth) {
			$this->e403();
		}
		
		$this->allowMethods(array('POST'));
		$sql = "DELETE SFI FROM storageFileItems SFI JOIN items USING (itemID) WHERE libraryID=?";
		Zotero_DB::query($sql, $this->objectLibraryID, Zotero_Shards::getByLibraryID($this->objectLibraryID));
		header("HTTP/1.1 204 No Content");
		exit;
	}
	
	
	public function storageadmin() {
		if (!$this->permissions->isSuper()) {
			$this->e404();
		}
		
		$this->allowMethods(array('GET', 'POST'));
		
		Zotero_DB::beginTransaction();
		
		if ($this->method == 'POST') {
			if (!isset($_POST['quota'])) {
				$this->e400("Quota not provided");
			}
			if (!isset($_POST['expiration'])) {
				$this->e400("Expiration not provided");
			}
			if (!is_numeric($_POST['quota']) || $_POST['quota'] < 0) {
				$this->e400("Invalid quota");
			}
			if (!is_numeric($_POST['expiration'])) {
				$this->e400("Invalid expiration");
			}
			$halfHourAgo = strtotime("-30 minutes");
			if ($_POST['expiration'] != 0 && $_POST['expiration'] < $halfHourAgo) {
				$this->e400("Expiration is in the past");
			}
			
			try {
				Zotero_Storage::setUserValues($this->objectUserID, $_POST['quota'], $_POST['expiration']);
			}
			catch (Exception $e) {
				if ($e->getCode() == Z_ERROR_GROUP_QUOTA_SET_BELOW_USAGE) {
					$this->e409("Cannot set quota below current usage");
				}
				$this->handleException($e);
			}
		}
		
		// GET request
		$xml = new SimpleXMLElement('<storage/>');
		$quota = Zotero_Storage::getEffectiveUserQuota($this->objectUserID);
		$xml->quota = $quota;
		$instQuota = Zotero_Storage::getInstitutionalUserQuota($this->objectUserID);
		// If personal quota is in effect
		if (!$instQuota || $quota > $instQuota) {
			$values = Zotero_Storage::getUserValues($this->objectUserID);
			if ($values) {
				$xml->expiration = (int) $values['expiration'];
			}
		}
		$usage = Zotero_Storage::getUserUsage($this->objectUserID);
		$xml->usage->total = $usage['total'];
		$xml->usage->library = $usage['library'];
		
		foreach ($usage['groups'] as $group) {
			if (!isset($group['id'])) {
				throw new Exception("Group id isn't set");
			}
			if (!isset($group['usage'])) {
				throw new Exception("Group usage isn't set");
			}
			$xmlGroup = $xml->usage->addChild('group', $group['usage']);
			$xmlGroup['id'] = $group['id'];
		}
		
		Zotero_DB::commit();
		
		header('application/xml');
		echo $xml->asXML();
		exit;
	}
	
	
	public function storagetransferbucket() {
		// DISABLED
		$this->e404();
		
		if (!$this->permissions->isSuper()) {
			$this->e404();
		}
		
		$this->allowMethods(array('POST'));
		
		Zotero_Storage::transferBucket('zoterofilestorage', 'zoterofilestoragetest');
		exit;
	}
}
