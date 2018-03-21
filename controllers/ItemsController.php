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

class ItemsController extends ApiController {
	public function items() {
		if ($this->isWriteMethod()) {
			// Check for library write access
			if (!$this->permissions->canWrite($this->objectLibraryID)) {
				$this->e403("Write access denied");
			}
			
			// Make sure library hasn't been modified
			if (!$this->singleObject) {
				$libraryTimestampChecked = $this->checkLibraryIfUnmodifiedSinceVersion();
			}
			
			// We don't update the library version in file mode, because currently
			// to avoid conflicts in the client the timestamp can't change
			// when the client updates file metadata
			if (!$this->fileMode) {
				Zotero_Libraries::updateVersionAndTimestamp($this->objectLibraryID);
			}
		}
		
		$itemIDs = array();
		$itemKeys = array();
		$results = array();
		$title = "";
		
		if ($this->objectGlobalItemID) {
			$id = $this->objectGlobalItemID;
			$libraryItems = Zotero_GlobalItems::getGlobalItemLibraryItems($id);
			if (!$libraryItems) {
				$this->e404();
			}
			// TODO: Improve pagination
			// Pagination isn't reliable here, because we
			// don't know if library and key exist and if we have permissions
			// to access it, until we actually query specific library and key.
			// Empty object placeholders should be returned where item
			// retrieval fails, or otherwise all items before 'start' must be fetched
			//$start = $this->queryParams['start'];
			$start = 0;
			$limit = $this->queryParams['limit'];
			
			// Group items by libraryID to later query all library's items at once
			$groupedLibraryItems = [];
			for ($i = 0, $len = sizeOf($libraryItems); $i < $len; $i++) {
				list($libraryID, $key) = $libraryItems[$i];
				$groupedLibraryItems[$libraryID][] = $key;
			}
			
			$allResults = ['results' => [], 'total' => 0];
			foreach ($groupedLibraryItems as $libraryID => $keys) {
				if (!$this->permissions->canAccess($libraryID)) {
					continue;
				}
				
				$remaining = $limit - sizeOf($allResults['results']);
				if (!$remaining) {
					// If not adding more items, add approximate total based on number of items from
					// libraryItems array. These might not all exist if they've been deleted recently,
					// but we don't want to keep searching for all items after reaching the limit.
					$allResults['total'] += sizeOf($keys);
					continue;
				}
				
				// Do not pass $this->queryParams directly to prevent
				// other query parameters from influencing Zotero_Items::search
				$params = [
					'format' => $this->queryParams['format'],
					'itemKey' => $keys
				];
				$results = Zotero_Items::search(
					$libraryID,
					false,
					$params
				);
				$allResults['results'] = array_merge(
					$allResults['results'],
					array_slice($results['results'], 0, $remaining)
				);
				$allResults['total'] += $results['total'];
			}
			$this->generateMultiResponse($allResults);
			$this->end();
		}
		
		//
		// Single item
		//
		if ($this->singleObject) {
			if ($this->fileMode) {
				if ($this->fileView) {
					$this->allowMethods(array('HEAD', 'GET', 'POST'));
				}
				else {
					$this->allowMethods(array('HEAD', 'GET', 'PUT', 'POST', 'PATCH'));
				}
			}
			else {
				$this->allowMethods(array('HEAD', 'GET', 'PUT', 'PATCH', 'DELETE'));
			}
			
			if (!Zotero_ID::isValidKey($this->objectKey)) {
				$this->e404();
			}
			
			$item = Zotero_Items::getByLibraryAndKey($this->objectLibraryID, $this->objectKey);
			if ($item) {
				// If no access to the item, don't show that it exists
				if (!$this->permissions->canAccessObject($item)) {
					$this->e404();
				}
				
				// Don't show an item in publications that doesn't belong there, even if user has
				// access to it
				if ($this->publications
						&& ((!$this->legacyPublications && !$item->inPublications)
							|| $item->deleted)) {
					$this->e404();
				}
				
				// Make sure URL libraryID matches item libraryID
				if ($this->objectLibraryID != $item->libraryID) {
					$this->e404();
				}
				
				// File access mode
				if ($this->fileMode) {
					$this->_handleFileRequest($item);
				}
				
				if ($this->scopeObject) {
					switch ($this->scopeObject) {
						// Remove item from collection
					case 'collections':
						$this->allowMethods(array('DELETE'));
						
						$collection = Zotero_Collections::getByLibraryAndKey($this->objectLibraryID, $this->scopeObjectKey);
						if (!$collection) {
							$this->e404("Collection not found");
						}
						
						if (!$collection->hasItem($item->id)) {
							$this->e404("Item not found in collection");
						}
						
						$collection->removeItem($item->id);
						$this->e204();
						
					default:
						$this->e400();
					}
				}
			}
			else {
				if ($this->isWriteMethod() && $this->fileMode) {
					$this->e404();
				}
				
				// Possibly temporary workaround to block unnecessary full syncs
				if ($this->fileMode && $this->httpAuth && $this->method == 'POST') {
					// If > 2 requests for missing file, trigger a full sync via 404
					$cacheKey = "apiMissingFile_"
						. $this->objectLibraryID . "_"
						. $this->objectKey;
					$set = Z_Core::$MC->get($cacheKey);
					if (!$set) {
						Z_Core::$MC->set($cacheKey, 1, 86400);
					}
					else if ($set < 2) {
						Z_Core::$MC->increment($cacheKey);
					}
					else {
						Z_Core::$MC->delete($cacheKey);
						$this->e404("A file sync error occurred. Please sync again.");
					}
					$this->e500("A file sync error occurred. Please sync again.");
				}
			}
			
			if ($this->isWriteMethod()) {
				$item = $this->handleObjectWrite('item', $item ? $item : null);
				
				if ($this->apiVersion < 2
						&& ($this->method == 'PUT' || $this->method == 'PATCH')) {
					$this->queryParams['format'] = 'atom';
					$this->queryParams['content'] = ['json'];
				}
			}
			
			if (!$item) {
				$this->e404("Item does not exist");
			}
			
			$this->libraryVersion = $item->version;
			
			if ($this->method == 'HEAD') {
				$this->end();
			}
			
			// Display item
			switch ($this->queryParams['format']) {
				case 'atom':
					$this->responseXML = Zotero_Items::convertItemToAtom(
						$item, $this->queryParams, $this->permissions
					);
					break;
				
				case 'bib':
					echo Zotero_Cite::getBibliographyFromCitationServer(array($item), $this->queryParams);
					break;
				
				case 'csljson':
					// TODO: Use in APIv4
					//$json = Zotero_Cite::getJSONFromItems([$item], true)['items'][0];
					$json = Zotero_Cite::getJSONFromItems(array($item), true);
					echo Zotero_Utilities::formatJSON($json);
					break;
				
				case 'json':
					$json = $item->toResponseJSON($this->queryParams, $this->permissions);
					echo Zotero_Utilities::formatJSON($json);
					break;
				
				default:
					$export = Zotero_Translate::doExport([$item], $this->queryParams);
					$this->queryParams['format'] = null;
					header("Content-Type: " . $export['mimeType']);
					echo $export['body'];
					break;
			}
		}
		
		//
		// Multiple items
		//
		else {
			$this->allowMethods(array('HEAD', 'GET', 'POST', 'DELETE'));
			
			// Check for general library access
			if (!$this->publications && !$this->permissions->canAccess($this->objectLibraryID)) {
				$this->e403();
			}
			
			if ($this->publications) {
				// Disabled until it actually works
				/*// Include ETag in My Publications (or, in the future, public collections)
				$this->etag = Zotero_Publications::getETag($this->objectUserID);
				
				// Return 304 if ETag matches
				if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] == $this->etag) {
					$this->e304();
				}*/
				
				// TEMP: Remove after integrated publications upgrade
				$this->libraryVersion = Zotero_Libraries::getUpdatedVersion($this->objectLibraryID);
			}
			// Last-Modified-Version otherwise
			else {
				$this->libraryVersion = Zotero_Libraries::getUpdatedVersion($this->objectLibraryID);
			}
			
			$includeTrashed = $this->queryParams['includeTrashed'];
			
			if ($this->scopeObject) {
				$this->allowMethods(array('GET', 'POST'));
				
				switch ($this->scopeObject) {
					case 'collections':
						// TEMP
						if (Zotero_ID::isValidKey($this->scopeObjectKey)) {
							$collection = Zotero_Collections::getByLibraryAndKey($this->objectLibraryID, $this->scopeObjectKey);
						}
						else {
							$collection = false;
						}
						if (!$collection) {
							// If old collectionID, redirect
							if ($this->method == 'GET' && Zotero_Utilities::isPosInt($this->scopeObjectKey)) {
								$collection = Zotero_Collections::get($this->objectLibraryID, $this->scopeObjectKey);
								if ($collection) {
									$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
									$base = Zotero_API::getCollectionURI($collection);
									$suffix = $this->subset == 'top' ? '/top' : '';
									$this->redirect($base . "/items$suffix" . $qs, 301);
								}
							}
							
							$this->e404("Collection not found");
						}
						
						// Add items to collection
						if ($this->method == 'POST') {
							$itemKeys = explode(' ', $this->body);
							$itemIDs = array();
							foreach ($itemKeys as $key) {
								try {
									$item = Zotero_Items::getByLibraryAndKey($this->objectLibraryID, $key);
								}
								catch (Exception $e) {
									if ($e->getCode() == Z_ERROR_OBJECT_LIBRARY_MISMATCH) {
										$item = false;
									}
									else {
										throw ($e);
									}
								}
								
								if (!$item) {
									throw new Exception("Item '$key' not found in library", Z_ERROR_INVALID_INPUT);
								}
								
								if ($item->getSource()) {
									throw new Exception("Child items cannot be added to collections directly", Z_ERROR_INVALID_INPUT);
								}
								$itemIDs[] = $item->id;
							}
							$collection->addItems($itemIDs);
							
							$this->e204();
						}
						
						if ($this->subset == 'top' || $this->apiVersion < 2) {
							$title = "Top-Level Items in Collection ‘" . $collection->name . "’";
							$itemIDs = $collection->getItems();
						}
						else {
							$title = "Items in Collection ‘" . $collection->name . "’";
							$itemIDs = $collection->getItems(true);
						}
						break;
					
					case 'tags':
						if ($this->apiVersion >= 2) {
							$this->e404();
						}
						
						$this->allowMethods(array('GET'));
						
						$tagIDs = Zotero_Tags::getIDs($this->objectLibraryID, $this->scopeObjectName);
						if (!$tagIDs) {
							$this->e404("Tag not found");
						}
						
						foreach ($tagIDs as $tagID) {
							$tag = new Zotero_Tag;
							$tag->libraryID = $this->objectLibraryID;
							$tag->id = $tagID;
							// Use a real tag name, in case case differs
							if (!$title) {
								$title = "Items of Tag ‘" . $tag->name . "’";
							}
							$itemKeys = array_merge($itemKeys, $tag->getLinkedItems(true));
						}
						$itemKeys = array_unique($itemKeys);
						
						break;
					
					default:
						$this->e404();
				}
			}
			else {
				// Top-level items
				if ($this->subset == 'top') {
					$this->allowMethods(array('GET'));
					
					$title = "Top-Level Items";
					$results = Zotero_Items::search(
						$this->objectLibraryID,
						true,
						$this->queryParams,
						$includeTrashed,
						$this->permissions
					);
				}
				// Deleted items
				else if ($this->subset == 'trash') {
					$this->allowMethods(array('GET'));
					
					$title = "Deleted Items";
					$this->queryParams['trashedItemsOnly'] = true;
					$includeTrashed = true;
					$results = Zotero_Items::search(
						$this->objectLibraryID,
						false,
						$this->queryParams,
						$includeTrashed,
						$this->permissions
					);
				}
				else if ($this->subset == 'children') {
					$item = Zotero_Items::getByLibraryAndKey($this->objectLibraryID, $this->objectKey);
					if (!$item) {
						$this->e404("Item not found");
					}
					
					// Don't show child items in publications mode of an item not in publications
					if ($this->publications && !$item->inPublications) {
						$this->e404("Item not found");
					}
					
					if ($item->isAttachment()) {
						$this->e400("/children cannot be called on attachment items");
					}
					if ($item->isNote()) {
						$this->e400("/children cannot be called on note items");
					}
					if ($item->getSource()) {
						$this->e400("/children cannot be called on child items");
					}
					
					// Create new child items
					if ($this->method == 'POST') {
						if ($this->apiVersion >= 2) {
							$this->allowMethods(array('GET'));
						}
						
						Zotero_DB::beginTransaction();
						
						$obj = $this->jsonDecode($this->body);
						$results = Zotero_Items::updateMultipleFromJSON(
							$obj,
							$this->queryParams,
							$this->objectLibraryID,
							$this->userID,
							$this->permissions,
							$libraryTimestampChecked ? 0 : 1,
							$item
						);
						
						Zotero_DB::commit();
						
						if ($cacheKey = $this->getWriteTokenCacheKey()) {
							Z_Core::$MC->set($cacheKey, true, $this->writeTokenCacheTime);
						}
						
						$uri = Zotero_API::getItemsURI($this->objectLibraryID);
						$keys = array_merge(
							get_object_vars($results['success']),
							get_object_vars($results['unchanged'])
						);
						$queryString = "itemKey="
							. urlencode(implode(",", $keys))
							. "&format=atom&content=json&order=itemKeyList&sort=asc";
						if ($this->apiKey) {
							$queryString .= "&key=" . $this->apiKey;
						}
						$uri .= "?" . $queryString;
						$this->queryParams = Zotero_API::parseQueryParams($queryString, $this->action, false);
						$this->responseCode = 201;
						
						$title = "Items";
						$results = Zotero_Items::search(
							$this->objectLibraryID,
							false,
							$this->queryParams,
							$includeTrashed,
							$this->permissions
						);
					}
					// Display items
					else {
						$title = "Child Items of ‘" . $item->getDisplayTitle() . "’";
						$notes = $item->getNotes();
						$attachments = $item->getAttachments();
						$itemIDs = array_merge($notes, $attachments);
					}
				}
				// All items
				else {
					// Create new items
					if ($this->method == 'POST') {
						$this->queryParams['format'] = 'writereport';
						
						$obj = $this->jsonDecode($this->body);
						
						// Server-side translation
						if (isset($obj->url)) {
							if ($this->apiVersion == 1) {
								Zotero_DB::beginTransaction();
							}
							
							$token = $this->getTranslationToken($obj);
							
							$results = Zotero_Items::addFromURL(
								$obj,
								$this->queryParams,
								$this->objectLibraryID,
								$this->userID,
								$this->permissions,
								$token
							);
							
							if ($this->apiVersion == 1) {
								Zotero_DB::commit();
							}
							// Multiple choices
							if ($results instanceof stdClass) {
								$this->queryParams['format'] = null;
								header("Content-Type: application/json");
								if ($this->queryParams['v'] >= 2) {
									echo Zotero_Utilities::formatJSON([
										'url' => $obj->url,
										'token' => $token,
										'items' => $results->select
									]);
								}
								else {
									echo Zotero_Utilities::formatJSON($results->select);
								}
								$this->e300();
							}
							// Error from translation server
							else if (is_int($results)) {
								switch ($results) {
									case 501:
										$this->e501("No translators found for URL");
										break;
									
									default:
										$this->e500("Error translating URL");
								}
							}
							// In v1, return data for saved items
							else if ($this->apiVersion == 1) {
								$uri = Zotero_API::getItemsURI($this->objectLibraryID);
								$keys = array_merge(
									get_object_vars($results['success']),
									get_object_vars($results['unchanged'])
								);
								$queryString = "itemKey="
									. urlencode(implode(",", $keys))
									. "&format=atom&content=json&order=itemKeyList&sort=asc";
								if ($this->apiKey) {
									$queryString .= "&key=" . $this->apiKey;
								}
								$uri .= "?" . $queryString;
								$this->queryParams = Zotero_API::parseQueryParams($queryString, $this->action, false);
								$this->responseCode = 201;
								
								$title = "Items";
								$results = Zotero_Items::search(
									$this->objectLibraryID,
									false,
									$this->queryParams,
									$includeTrashed,
									$this->permissions
								);
							}
							// Otherwise return write status report
						}
						// Uploaded items
						else {
							if ($this->apiVersion < 2) {
								Zotero_DB::beginTransaction();
							}
							
							$results = Zotero_Items::updateMultipleFromJSON(
								$obj,
								$this->queryParams,
								$this->objectLibraryID,
								$this->userID,
								$this->permissions,
								$libraryTimestampChecked ? 0 : 1,
								null
							);
							
							if ($this->apiVersion < 2) {
								Zotero_DB::commit();
								
								$uri = Zotero_API::getItemsURI($this->objectLibraryID);
								$keys = array_merge(
									get_object_vars($results['success']),
									get_object_vars($results['unchanged'])
								);
								$queryString = "itemKey="
									. urlencode(implode(",", $keys))
									. "&format=atom&content=json&order=itemKeyList&sort=asc";
								if ($this->apiKey) {
									$queryString .= "&key=" . $this->apiKey;
								}
								$uri .= "?" . $queryString;
								$this->queryParams = Zotero_API::parseQueryParams($queryString, $this->action, false);
								$this->responseCode = 201;
								
								$title = "Items";
								$results = Zotero_Items::search(
									$this->objectLibraryID,
									false,
									$this->queryParams,
									$includeTrashed,
									$this->permissions
								);
							}
						}
						
						if ($cacheKey = $this->getWriteTokenCacheKey()) {
							Z_Core::$MC->set($cacheKey, true, $this->writeTokenCacheTime);
						}
					}
					// Delete items
					else if ($this->method == 'DELETE') {
						Zotero_DB::beginTransaction();
						foreach ($this->queryParams['itemKey'] as $itemKey) {
							Zotero_Items::delete($this->objectLibraryID, $itemKey);
						}
						Zotero_DB::commit();
						$this->e204();
					}
					// Display items
					else {
						$title = "Items";
						$results = Zotero_Items::search(
							$this->objectLibraryID,
							false,
							$this->queryParams,
							$includeTrashed,
							$this->permissions
						);
					}
				}
			}
			
			if ($itemIDs || $itemKeys) {
				if ($itemIDs) {
					$this->queryParams['itemIDs'] = $itemIDs;
				}
				if ($itemKeys) {
					$this->queryParams['itemKey'] = $itemKeys;
				}
				$results = Zotero_Items::search(
					$this->objectLibraryID,
					false,
					$this->queryParams,
					$includeTrashed,
					$this->permissions
				);
			}
			
			if ($this->queryParams['format'] == 'bib') {
				$maxBibItems = Zotero_API::MAX_BIBLIOGRAPHY_ITEMS;
				if ($results['total'] > $maxBibItems) {
					$this->e413("Cannot generate bibliography with more than $maxBibItems items");
				}
			}
			
			$this->generateMultiResponse($results, $title);
		}
		
		$this->end();
	}
	
	
	private function generateMultiResponse($results, $title='') {
		$options = [
			'action' => $this->action,
			'uri' => $this->uri,
			'results' => $results,
			'requestParams' => $this->queryParams,
			'permissions' => $this->permissions,
			'head' => $this->method == 'HEAD'
		];
		$format = $this->queryParams['format'];
		
		switch ($format) {
			case 'atom':
				$this->responseXML = Zotero_API::multiResponse(
					array_merge(
						$options,
						[
							'title' => $this->getFeedNamePrefix($this->objectLibraryID) . $title
						]
					)
				);
				break;
			
			case 'bib':
				if ($this->method == 'HEAD') {
					break;
				}
				if (isset($results['results'])) {
					echo Zotero_Cite::getBibliographyFromCitationServer($results['results'], $this->queryParams);
				}
				break;
			
			case 'csljson':
			case 'json':
			case 'keys':
			case 'versions':
			case 'writereport':
				Zotero_API::multiResponse($options);
				break;
			
			default:
				if (Zotero_Translate::isExportFormat($format)) {
					Zotero_API::multiResponse($options);
					$this->queryParams['format'] = null;
				}
				else {
					throw new Exception("Unexpected format '$format'");
				}
		}
	}
	
	
	/**
	 * Handle S3 request
	 *
	 * Permission-checking provided by items()
	 */
	private function _handleFileRequest($item) {
		if (!$this->permissions->canAccess($this->objectLibraryID, 'files')
				// Check access on specific item, for My Publications files
				&& !$this->permissions->canAccessObject($item)) {
			$this->e403();
		}
		
		$this->allowMethods(array('HEAD', 'GET', 'POST', 'PATCH'));
		
		if (!$item->isAttachment()) {
			$this->e400("Item is not an attachment");
		}
		
		// File info for 4.0 client sync
		//
		// Use of HEAD method was discontinued after 2.0.8/2.1b1 due to
		// compatibility problems with proxies and security software
		if ($this->method == 'GET' && $this->fileMode == 'info') {
			$info = Zotero_Storage::getLocalFileItemInfo($item);
			if (!$info) {
				$this->e404();
			}
			StatsD::increment("storage.info", 1);
			/*
			header("Last-Modified: " . gmdate('r', $info['uploaded']));
			header("Content-Type: " . $info['type']);
			*/
			header("Content-Length: " . $info['size']);
			header("ETag: " . $info['hash']);
			header("X-Zotero-Filename: " . $info['filename']);
			header("X-Zotero-Modification-Time: " . $info['mtime']);
			header("X-Zotero-Compressed: " . ($info['zip'] ? 'Yes' : 'No'));
			header_remove("X-Powered-By");
			$this->end();
		}
		
		// File viewing/download
		//
		// TEMP: allow POST for snapshot viewing until using session auth
		else if ($this->method == 'GET') {
			$info = Zotero_Storage::getLocalFileItemInfo($item);
			if (!$info) {
				$this->e404();
			}
			
			// File viewing
			if ($this->fileView) {
				$url = Zotero_Attachments::getTemporaryURL($item, !empty($_GET['int']));
				if (!$url) {
					$this->e500();
				}
				StatsD::increment("storage.view", 1);
				$this->redirect($url);
				exit;
			}
			
			// File download
			$url = Zotero_Storage::getDownloadURL($item, 60);
			if (!$url) {
				$this->e404();
			}
			
			// Provide some headers to let 5.0 client skip download
			header("Zotero-File-Modification-Time: {$info['mtime']}");
			header("Zotero-File-MD5: {$info['hash']}");
			header("Zotero-File-Size: {$info['size']}");
			header("Zotero-File-Compressed: " . ($info['zip'] ? 'Yes' : 'No'));
			
			StatsD::increment("storage.download", 1);
			Zotero_Storage::logDownload(
				$item,
				// TODO: support anonymous download if necessary
				$this->userID,
				IPAddress::getIP()
			);
			$this->redirect($url);
			exit;
		}
		
		else if ($this->method == 'POST' || $this->method == 'PATCH') {
			if (!$item->isImportedAttachment()) {
				$this->e400("Cannot upload file for linked file/URL attachment item");
			}
			
			$libraryID = $item->libraryID;
			$type = Zotero_Libraries::getType($libraryID);
			if ($type == 'group') {
				$groupID = Zotero_Groups::getGroupIDFromLibraryID($libraryID);
				$group = Zotero_Groups::get($groupID);
				if (!$group->userCanEditFiles($this->userID)) {
					$this->e403("You do not have file editing access");
				}
			}
			else {
				$group = null;
			}
			
			// If not the 4.0 client, require If-Match or If-None-Match
			if (!$this->httpAuth) {
				if (empty($_SERVER['HTTP_IF_MATCH']) && empty($_SERVER['HTTP_IF_NONE_MATCH'])) {
					$this->e428("If-Match/If-None-Match header not provided");
				}
				
				if (!empty($_SERVER['HTTP_IF_MATCH'])) {
					if (!preg_match('/^"?([a-f0-9]{32})"?$/', $_SERVER['HTTP_IF_MATCH'], $matches)) {
						$this->e400("Invalid ETag in If-Match header");
					}
					
					if (!$item->attachmentStorageHash) {
						$this->e412("If-Match set but file does not exist");
					}
					
					if ($item->attachmentStorageHash != $matches[1]) {
						$this->libraryVersion = $item->version;
						$this->libraryVersionOnFailure = true;
						$this->e412("ETag does not match current version of file");
					}
				}
				else {
					if ($_SERVER['HTTP_IF_NONE_MATCH'] != "*") {
						$this->e400("Invalid value for If-None-Match header");
					}
					
					if ($item->attachmentStorageHash) {
						$this->libraryVersion = $item->version;
						$this->libraryVersionOnFailure = true;
						$this->e412("If-None-Match: * set but file exists");
					}
				}
			}
			
			//
			// Upload authorization
			//
			if (!isset($_POST['update']) && !isset($_REQUEST['upload'])) {
				$info = new Zotero_StorageFileInfo;
				
				// Validate upload metadata
				if (empty($_REQUEST['md5'])) {
					$this->e400('MD5 hash not provided');
				}
				if (!preg_match('/[abcdefg0-9]{32}/', $_REQUEST['md5'])) {
					$this->e400('Invalid MD5 hash');
				}
				if (!isset($_REQUEST['filename']) || $_REQUEST['filename'] === "") {
					$this->e400('Filename not provided');
				}
				
				// Multi-file upload
				//
				// For ZIP files, the filename and hash of the ZIP file are different from those
				// of the main file. We use the former for S3, and we store the latter in the
				// upload log to set the attachment metadata with them on file registration.
				if (!empty($_REQUEST['zipMD5'])) {
					if (!preg_match('/[abcdefg0-9]{32}/', $_REQUEST['zipMD5'])) {
						$this->e400('Invalid ZIP MD5 hash');
					}
					if (empty($_REQUEST['zipFilename'])) {
						$this->e400('ZIP filename not provided');
					}
					$info->zip = true;
					$info->hash = $_REQUEST['zipMD5'];
					$info->filename = $_REQUEST['zipFilename'];
					$info->itemFilename = $_REQUEST['filename'];
					$info->itemHash = $_REQUEST['md5'];
				}
				else if (!empty($_REQUEST['zipFilename'])) {
					$this->e400('ZIP MD5 hash not provided');
				}
				// Single-file upload
				else {
					$info->zip = !empty($_REQUEST['zip']);
					
					$info->filename = $_REQUEST['filename'];
					$info->hash = $_REQUEST['md5'];
				}
				
				if (empty($_REQUEST['mtime'])) {
					$this->e400('File modification time not provided');
				}
				$info->mtime = $_REQUEST['mtime'];
				
				if (!isset($_REQUEST['filesize'])) {
					$this->e400('File size not provided');
				}
				$info->size = $_REQUEST['filesize'];
				if (!is_numeric($info->size)) {
					$this->e400("Invalid file size");
				}
				// TEMP: Until the client supports multi-part upload
				if ($info->size > 5000000000) {
					$this->e400("Files above 5 GB are not currently supported");
				}
				
				$info->contentType = isset($_REQUEST['contentType']) ? $_REQUEST['contentType'] : null;
				if (!preg_match("/^[a-zA-Z0-9\-\/]+$/", $info->contentType)) {
					$info->contentType = null;
				}
				
				$info->charset = isset($_REQUEST['charset']) ? $_REQUEST['charset'] : null;
				if (!preg_match("/^[a-zA-Z0-9\-]+$/", $info->charset)) {
					$info->charset = null;
				}
				
				$contentTypeHeader = $info->contentType . (($info->contentType && $info->charset) ? "; charset=" . $info->charset : "");
				
				// Reject file if it would put account over quota
				if ($group) {
					$quota = Zotero_Storage::getEffectiveUserQuota($group->ownerUserID);
					$usage = Zotero_Storage::getUserUsage($group->ownerUserID);
				}
				else {
					$quota = Zotero_Storage::getEffectiveUserQuota($this->objectUserID);
					$usage = Zotero_Storage::getUserUsage($this->objectUserID);
				}
				$total = $usage['total'];
				$fileSizeMB = round($info->size / 1024 / 1024, 1);
				if ($total + $fileSizeMB > $quota) {
					StatsD::increment("storage.upload.quota", 1);
					$this->e413("File would exceed quota ($total + $fileSizeMB > $quota)");
				}
				
				Zotero_DB::query("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
				Zotero_DB::beginTransaction();
				
				// See if file exists with this filename
				$localInfo = Zotero_Storage::getLocalFileInfo($info);
				if ($localInfo) {
					$storageFileID = $localInfo['storageFileID'];
					
					// Verify file size
					if ($localInfo['size'] != $info->size) {
						throw new Exception(
							"Specified file size incorrect for existing file "
								. $info->hash . "/" . $info->filename
								. " ({$localInfo['size']} != {$info->size})"
						);
					}
				}
				// If not found, see if there's a copy with a different name
				else {
					$oldStorageFileID = Zotero_Storage::getFileByHash($info->hash, $info->zip);
					if ($oldStorageFileID) {
						// Verify file size
						$localInfo = Zotero_Storage::getFileInfoByID($oldStorageFileID);
						if ($localInfo['size'] != $info->size) {
							throw new Exception(
								"Specified file size incorrect for duplicated file "
								. $info->hash . "/" . $info->filename
								. " ({$localInfo['size']} != {$info->size})"
							);
						}
						
						// Create new file on S3 with new name
						$storageFileID = Zotero_Storage::duplicateFile(
							$oldStorageFileID,
							$info->filename,
							$info->zip,
							$contentTypeHeader
						);
					}
				}
				
				// If we already have a file, add/update storageFileItems row and stop
				if (!empty($storageFileID)) {
					Zotero_Storage::updateFileItemInfo($item, $storageFileID, $info, $this->httpAuth);
					Zotero_DB::commit();
					
					StatsD::increment("storage.upload.existing", 1);
					
					if ($this->httpAuth) {
						$this->queryParams['format'] = null;
						header('Content-Type: application/xml');
						echo "<exists/>";
					}
					else {
						$this->queryParams['format'] = null;
						header('Content-Type: application/json');
						$this->libraryVersion = $item->version;
						echo json_encode(array('exists' => 1));
					}
					$this->end();
				}
				
				Zotero_DB::commit();
				
				// Add request to upload queue
				$uploadKey = Zotero_Storage::queueUpload($this->userID, $info);
				// User over queue limit
				if (!$uploadKey) {
					header('Retry-After: ' . Zotero_Storage::$uploadQueueTimeout);
					if ($this->httpAuth) {
						$this->e413("Too many queued uploads");
					}
					else {
						$this->e429("Too many queued uploads");
					}
				}
				
				StatsD::increment("storage.upload.new", 1);
				
				// Output XML for client requests (which use HTTP Auth)
				if ($this->httpAuth) {
					$params = Zotero_Storage::generateUploadPOSTParams($item, $info, true);
					
					$this->queryParams['format'] = null;
					header('Content-Type: application/xml');
					$xml = new SimpleXMLElement('<upload/>');
					$xml->url = Zotero_Storage::getUploadBaseURL();
					$xml->key = $uploadKey;
					foreach ($params as $key=>$val) {
						$xml->params->$key = $val;
					}
					echo $xml->asXML();
				}
				// Output JSON for API requests
				else {
					if (!empty($_REQUEST['params']) && $_REQUEST['params'] == "1") {
						$params = array(
							"url" => Zotero_Storage::getUploadBaseURL(),
							"params" => array()
						);
						foreach (Zotero_Storage::generateUploadPOSTParams($item, $info) as $key=>$val) {
							$params['params'][$key] = $val;
						}
					}
					else {
						$params = Zotero_Storage::getUploadPOSTData($item, $info);
					}
					
					$params['uploadKey'] = $uploadKey;
					
					$this->queryParams['format'] = null;
					header('Content-Type: application/json');
					echo json_encode($params);
				}
				exit;
			}
			
			//
			// API partial upload and post-upload file registration
			//
			if (isset($_REQUEST['upload'])) {
				$uploadKey = $_REQUEST['upload'];
				
				if (!$uploadKey) {
					$this->e400("Upload key not provided");
				}
				
				$info = Zotero_Storage::getUploadInfo($uploadKey);
				if (!$info) {
					$this->e400("Upload key not found");
				}
				
				// Partial upload
				if ($this->method == 'PATCH') {
					if (empty($_REQUEST['algorithm'])) {
						throw new Exception("Algorithm not specified", Z_ERROR_INVALID_INPUT);
					}
					
					$storageFileID = Zotero_Storage::patchFile($item, $info, $_REQUEST['algorithm'], $this->body);
				}
				// Full upload
				else {
					$remoteInfo = Zotero_Storage::getRemoteFileInfo($info);
					if (!$remoteInfo) {
						error_log("Remote file {$info->hash}/{$info->filename} not found");
						$this->e400("Remote file not found");
					}
					if ($remoteInfo->size != $info->size) {
						error_log("Uploaded file size does not match "
							. "({$remoteInfo->size} != {$info->size}) "
							. "for file {$info->hash}/{$info->filename}");
					}
				}
				
				// Set an automatic shared lock in getLocalFileInfo() to prevent
				// two simultaneous transactions from adding a file
				Zotero_DB::query("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
				Zotero_DB::beginTransaction();
				
				if (!isset($storageFileID)) {
					// Check if file already exists, which can happen if two identical
					// files are uploaded simultaneously
					$fileInfo = Zotero_Storage::getLocalFileInfo($info);
					if ($fileInfo) {
						$storageFileID = $fileInfo['storageFileID'];
					}
					// If file doesn't exist, add it
					else {
						$storageFileID = Zotero_Storage::addFile($info);
					}
				}
				Zotero_Storage::updateFileItemInfo($item, $storageFileID, $info);
				
				Zotero_Storage::logUpload($this->userID, $item, $uploadKey, IPAddress::getIP());
				
				Zotero_DB::commit();
				
				header("HTTP/1.1 204 No Content");
				header("Last-Modified-Version: " . $item->version);
				exit;
			}
			
			
			//
			// Client post-upload file registration
			//
			if (isset($_POST['update'])) {
				$this->allowMethods(array('POST'));
				
				if (empty($_POST['mtime'])) {
					throw new Exception('File modification time not provided');
				}
				
				$uploadKey = $_POST['update'];
				
				$info = Zotero_Storage::getUploadInfo($uploadKey);
				if (!$info) {
					$this->e400("Upload key not found");
				}
				
				$remoteInfo = Zotero_Storage::getRemoteFileInfo($info);
				if (!$remoteInfo) {
					$this->e400("Remote file not found");
				}
				if (!isset($info->size)) {
					throw new Exception("Size information not available");
				}
				
				$info->mtime = $_POST['mtime'];
				
				// Set an automatic shared lock in getLocalFileInfo() to prevent
				// two simultaneous transactions from adding a file
				Zotero_DB::query("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
				Zotero_DB::beginTransaction();
				
				// Check if file already exists, which can happen if two identical
				// files are uploaded simultaneously
				$fileInfo = Zotero_Storage::getLocalFileInfo($info);
				if ($fileInfo) {
					$storageFileID = $fileInfo['storageFileID'];
				}
				else {
					$storageFileID = Zotero_Storage::addFile($info);
				}
				
				Zotero_Storage::updateFileItemInfo($item, $storageFileID, $info, true);
				
				Zotero_Storage::logUpload($this->userID, $item, $uploadKey, IPAddress::getIP());
				
				Zotero_DB::commit();
				
				header("HTTP/1.1 204 No Content");
				exit;
			}
			
			throw new Exception("Invalid request", Z_ERROR_INVALID_INPUT);
		}
		exit;
	}
	
	
	
	/**
	 * Get a token to pass to the translation server to retain state for multi-item saves
	 */
	protected function getTranslationToken($obj) {
		$allowExplicitToken = $this->queryParams['v'] >= 2 || ($this->queryParams['v'] == 1 && Z_ENV_TESTING_SITE);
		
		if ($allowExplicitToken && isset($obj->token)) {
			if (!isset($obj->items)) {
				throw new Exception("'token' is valid only for item selection requests", Z_ERROR_INVALID_INPUT);
			}
			return $obj->token;
		}
		
		// Bookmarklet uses cookie auth with v1
		if ($this->queryParams['v'] == 1 && $this->cookieAuth) {
			return md5($this->userID . $_GET['session']);
		}
		if (!$allowExplicitToken) {
			return false;
		}
		if (isset($obj->items)) {
			throw new Exception("Token not provided with selected items", Z_ERROR_INVALID_INPUT);
		}
		return md5($this->userID . $_SERVER['REMOTE_ADDR'] . uniqid());
	}
}
