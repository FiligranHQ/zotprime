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

class CollectionsController extends ApiController {
	public function collections() {
		// Check for general library access
		if (!$this->permissions->canAccess($this->objectLibraryID)) {
			$this->e403();
		}
		
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
		
		$collectionIDs = array();
		$collectionKeys = array();
		$results = array();
		$totalResults = 0;
		
		// Single collection
		if ($this->singleObject) {
			$this->allowMethods(array('GET', 'PUT', 'DELETE'));
			
			// TEMP
			if (!Zotero_ID::isValidKey($this->objectKey)) {
				$this->e404();
			}
			
			$collection = Zotero_Collections::getByLibraryAndKey($this->objectLibraryID, $this->objectKey);
			if (!$collection) {
				$this->e404("Collection not found");
			}
			
			if ($this->method == 'PUT' || $this->method == 'DELETE') {
				$objectTimestampChecked =
					$this->checkObjectIfUnmodifiedSinceVersion(
						$collection, $this->method == 'DELETE'
				);
				
				$this->libraryVersion = Zotero_Libraries::getUpdatedVersion($this->objectLibraryID);
				
				// Update collection
				if ($this->method == 'PUT') {
					$obj = $this->jsonDecode($this->body);
					$changed = Zotero_Collections::updateFromJSON(
						$collection,
						$obj,
						$this->queryParams,
						$this->userID,
						$objectTimestampChecked ? 0 : 2
					);
					
					// If not updated, return the original library version
					if (!$changed) {
						$this->libraryVersion = Zotero_Libraries::getOriginalVersion(
							$this->objectLibraryID
						);
					}
					
					if ($cacheKey = $this->getWriteTokenCacheKey()) {
						Z_Core::$MC->set($cacheKey, true, $this->writeTokenCacheTime);
					}
				}
				
				// Delete collection
				else if ($this->method == 'DELETE') {
					Zotero_Collections::delete($this->objectLibraryID, $this->objectKey);
				}
				else {
					throw new Exception("Unexpected method $this->method");
				}
				
				if ($this->queryParams['apiVersion'] >= 2 || $this->method == 'DELETE') {
					$this->e204();
				}
			}
			
			$this->libraryVersion = $collection->version;
			$this->responseXML = Zotero_Collections::convertCollectionToAtom(
				$collection, $this->queryParams
			);
		}
		// Multiple collections
		else {
			$this->allowMethods(array('GET', 'POST', 'DELETE'));
			
			$this->libraryVersion = Zotero_Libraries::getUpdatedVersion($this->objectLibraryID);
			
			if ($this->scopeObject) {
				$this->allowMethods(array('GET'));
				
				switch ($this->scopeObject) {
					case 'collections':
						$collection = Zotero_Collections::getByLibraryAndKey($this->objectLibraryID, $this->scopeObjectKey);
						if (!$collection) {
							$this->e404("Collection not found");
						}
						$title = "Child Collections of ‘$collection->name'’";
						$collectionIDs = $collection->getChildCollections();
						break;
					
					default:
						throw new Exception("Invalid collections scope object '$this->scopeObject'");
				}
			}
			else {
				// Top-level items
				if ($this->subset == 'top') {
					$this->allowMethods(array('GET'));
					
					$title = "Top-Level Collections";
					$results = Zotero_Collections::search($this->objectLibraryID, true, $this->queryParams);
				}
				else {
					// Create a collection
					if ($this->method == 'POST') {
						$this->queryParams['format'] = 'writereport';
						
						$obj = $this->jsonDecode($this->body);
						$results = Zotero_Collections::updateMultipleFromJSON(
							$obj,
							$this->objectLibraryID,
							$this->queryParams,
							$this->userID,
							$libraryTimestampChecked ? 0 : 1,
							null
						);
						
						if ($cacheKey = $this->getWriteTokenCacheKey()) {
							Z_Core::$MC->set($cacheKey, true, $this->writeTokenCacheTime);
						}
						
						if ($this->queryParams['apiVersion'] < 2) {
							$uri = Zotero_API::getCollectionsURI($this->objectLibraryID);
							$keys = array_merge(
								get_object_vars($results['success']),
								get_object_vars($results['unchanged'])
							);
							$queryString = "collectionKey="
									. urlencode(implode(",", $keys))
									. "&format=atom&content=json&order=collectionKeyList&sort=asc";
							if ($this->apiKey) {
									$queryString .= "&key=" . $this->apiKey;
							}
							$uri .= "?" . $queryString;
							
							$this->queryParams = Zotero_API::parseQueryParams($queryString, $this->action, true);
							
							$title = "Collections";
							$results = Zotero_Collections::search($this->objectLibraryID, false, $this->queryParams);
						}
					}
					// Delete collections
					else if ($this->method == 'DELETE') {
						Zotero_DB::beginTransaction();
						$collectionKeys = explode(',', $this->queryParams['collectionKey']);
						foreach ($collectionKeys as $collectionKey) {
							Zotero_Collections::delete($this->objectLibraryID, $collectionKey);
						}
						Zotero_DB::commit();
						$this->e204();
					}
					// Display collections
					else {
						$title = "Collections";
						$results = Zotero_Collections::search($this->objectLibraryID, false, $this->queryParams);
					}
				}
			}
			
			if ($collectionIDs) {
				$this->queryParams['collectionIDs'] = $collectionIDs;
				$results = Zotero_Collections::search($this->objectLibraryID, false, $this->queryParams);
			}
			
			if ($results && isset($results['results'])) {
				$totalResults = $results['total'];
				$results = $results['results'];
			}
				
			switch ($this->queryParams['format']) {
			case 'atom':
				$this->responseXML = Zotero_Atom::createAtomFeed(
					$this->getFeedNamePrefix($this->objectLibraryID) . $title,
					$this->uri,
					$results,
					$totalResults,
					$this->queryParams,
					$this->permissions
				);
				break;
			
			case 'keys':
				header("Content-Type: text/plain");
				echo implode("\n", $results) . "\n";
				break;
			
			case 'versions':
			case 'writereport':
				if ($this->queryParams['pprint']) {
					header("Content-Type: text/plain");
				}
				else {
					header("Content-Type: application/json");
				}
				echo Zotero_Utilities::formatJSON($results, $this->queryParams['pprint']);
				break;
			
			default:
				throw new Exception("Invalid 'format' value '"
					. $this->queryParams['format'] . "'", Z_ERROR_INVALID_INPUT);
			}
		}
		
		$this->end();
	}
}
