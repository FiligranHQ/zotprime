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

class SettingsController extends ApiController {
	public function settings() {
		if ($this->apiVersion < 2) {
			$this->e404();
		}
		
		// Check for general library access
		if (!$this->permissions->canAccess($this->objectLibraryID)) {
			$this->e403();
		}
		
		Zotero_DB::beginTransaction();
		
		if ($this->isWriteMethod()) {
			// Check for library write access
			if (!$this->permissions->canWrite($this->objectLibraryID)) {
				$this->e403("Write access denied");
			}
			
			// Make sure library hasn't been modified
			if (!$this->singleObject) {
				$libraryTimestampChecked = $this->checkLibraryIfUnmodifiedSinceVersion();
			}
			
			Zotero_Libraries::updateVersionAndTimestamp($this->objectLibraryID);
		}
		
		// Single setting
		if ($this->singleObject) {
			$this->allowMethods(array('GET', 'PUT', 'DELETE'));
			
			$setting = Zotero_Settings::getByLibraryAndKey($this->objectLibraryID, $this->objectKey);
			if (!$setting) {
				if ($this->method == 'PUT') {
					$setting = new Zotero_Setting;
					$setting->libraryID = $this->objectLibraryID;
					$setting->name = $this->objectKey;
				}
				else {
					$this->e404("Setting not found");
				}
			}
			
			if ($this->isWriteMethod()) {
				if (!empty($this->body)) {
					$json = $this->jsonDecode($this->body);
				}
				$objectVersionValidated = $this->checkSingleObjectWriteVersion(
					'setting', $setting, $json
				);
				
				$this->libraryVersion = Zotero_Libraries::getUpdatedVersion($this->objectLibraryID);
				
				// Update setting
				if ($this->method == 'PUT') {
					$changed = Zotero_Settings::updateFromJSON(
						$setting,
						$json,
						$this->queryParams,
						$this->userID,
						$objectVersionValidated ? 0 : 2
					);
					
					// If not updated, return the original library version
					if (!$changed) {
						$this->libraryVersion = Zotero_Libraries::getOriginalVersion(
							$this->objectLibraryID
						);
						
						Zotero_DB::rollback();
						$this->e204();
					}
				}
				
				// Delete setting
				else if ($this->method == 'DELETE') {
					Zotero_Settings::delete($this->objectLibraryID, $this->objectKey);
				}
				else {
					throw new Exception("Unexpected method $this->method");
				}
				
				$this->responseCode = 204;
			}
			else {
				$this->libraryVersion = $setting->version;
				$json = $setting->toJSON(true, $this->queryParams);
				echo Zotero_Utilities::formatJSON($json);
			}
		}
		// Multiple settings
		else {
			$this->allowMethods(array('GET', 'POST'));
			
			$this->libraryVersion = Zotero_Libraries::getUpdatedVersion($this->objectLibraryID);
			
			// Create a setting
			if ($this->method == 'POST') {
				$obj = $this->jsonDecode($this->body);
				$changed = Zotero_Settings::updateMultipleFromJSON(
					$obj,
					$this->queryParams,
					$this->objectLibraryID,
					$this->userID,
					$this->permissions,
					$libraryTimestampChecked ? 0 : 1,
					null
				);
				
				// If not updated, return the original library version
				if (!$changed) {
					$this->libraryVersion = Zotero_Libraries::getOriginalVersion(
						$this->objectLibraryID
					);
				}
				
				$this->responseCode = 204;
			}
			// Display all settings
			else {
				$settings = Zotero_Settings::search($this->objectLibraryID, $this->queryParams);
				
				$json = new stdClass;
				foreach ($settings as $setting) {
					$json->{$setting->name} = $setting->toJSON(true, $this->queryParams);
				}
				
				echo Zotero_Utilities::formatJSON($json);
			}
		}
		
		Zotero_DB::commit();
		
		$this->end();
	}
}
