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
		$totalResults = 0;
		
		//
		// Single item
		//
		if ($this->singleObject) {
			if ($this->fileMode) {
				if ($this->fileView) {
					$this->allowMethods(array('GET', 'HEAD', 'POST'));
				}
				else {
					$this->allowMethods(array('GET', 'PUT', 'POST', 'HEAD', 'PATCH'));
				}
			}
			else {
				$this->allowMethods(array('GET', 'PUT', 'PATCH', 'DELETE'));
			}
			
			$item = Zotero_Items::getByLibraryAndKey($this->objectLibraryID, $this->objectKey);
			if (!$item) {
				// Possibly temporary workaround to block unnecessary full syncs
				if ($this->fileMode && $this->method == 'POST') {
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
				
				$this->e404("Item does not exist");
			}
			
			if ($item->isNote() && !$this->permissions->canAccess($this->objectLibraryID, 'notes')) {
				$this->e403();
			}
			
			// Make sure URL libraryID matches item libraryID
			if ($this->objectLibraryID != $item->libraryID) {
				$this->e404("Item does not exist");
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
			
			if ($this->isWriteMethod()) {
				$objectTimestampChecked =
					$this->checkObjectIfUnmodifiedSinceVersion(
						$item, $this->method == 'DELETE'
				);
				
				$this->libraryVersion = Zotero_Libraries::getUpdatedVersion($this->objectLibraryID);
				
				// Update item
				if ($this->method == 'PUT' || $this->method == 'PATCH') {
					if ($this->queryParams['apiVersion'] < 2) {
						$this->allowMethods(array('PUT'));
					}
					
					$changed = Zotero_Items::updateFromJSON(
						$item,
						$this->jsonDecode($this->body),
						null,
						$this->queryParams,
						$this->userID,
						$objectTimestampChecked ? 0 : 2,
						$this->method == 'PATCH'
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
					
					if ($this->queryParams['apiVersion'] < 2) {
						$this->queryParams['format'] = 'atom';
						$this->queryParams['content'] = array('json');
					}
				}
				// Delete item
				else if ($this->method == 'DELETE') {
					Zotero_Items::delete($this->objectLibraryID, $this->objectKey);
					
					try {
						Zotero_Processors::notifyProcessors('index');
					}
					catch (Exception $e) {
						Z_Core::logError($e);
					}
				}
				else {
					throw new Exception("Unexpected method $this->method");
				}
				
				if ($this->queryParams['apiVersion'] >= 2 || $this->method == 'DELETE') {
					$this->e204();
				}
			}
			
			$this->libraryVersion = $item->itemVersion;
			
			// Display item
			switch ($this->queryParams['format']) {
				case 'atom':
					$this->responseXML = Zotero_Items::convertItemToAtom(
						$item, $this->queryParams, $this->permissions
					);
					break;
				
				case 'bib':
					echo Zotero_Cite::getBibliographyFromCitationServer(array($item), $this->queryParams);
					exit;
				
				case 'csljson':
					$json = Zotero_Cite::getJSONFromItems(array($item), true);
					if ($this->queryParams['pprint']) {
						header("Content-Type: text/plain");
						$json = Zotero_Utilities::json_encode_pretty($json);
					}
					else {
						header("Content-Type: application/vnd.citationstyles.csl+json");
						$json = json_encode($json);
					}
					echo $json;
					exit;
				
				default:
					$export = Zotero_Translate::doExport(array($item), $this->queryParams['format']);
					if ($this->queryParams['pprint']) {
						header("Content-Type: text/plain");
					}
					else {
						header("Content-Type: " . $export['mimeType']);
					}
					echo $export['body'];
					exit;
			}
		}
		
		//
		// Multiple items
		//
		else {
			$this->allowMethods(array('GET', 'POST', 'DELETE'));
			
			$this->libraryVersion = Zotero_Libraries::getUpdatedVersion($this->objectLibraryID);
			
			$includeTrashed = false;
			
			if ($this->scopeObject) {
				$this->allowMethods(array('GET', 'POST'));
				
				switch ($this->scopeObject) {
					case 'collections':
						$collection = Zotero_Collections::getByLibraryAndKey($this->objectLibraryID, $this->scopeObjectKey);
						if (!$collection) {
							// If old collectionID, redirect
							if ($this->method == 'GET' && Zotero_Utilities::isPosInt($this->scopeObjectKey)) {
								$collection = Zotero_Collections::get($this->objectLibraryID, $this->scopeObjectKey);
								if ($collection) {
									$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
									$base = Zotero_API::getCollectionURI($collection);
									$this->redirect($base . "/items" . $qs, 301);
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
						
						$title = "Items in Collection ‘" . $collection->name . "’";
						$itemIDs = $collection->getChildItems();
						break;
					
					case 'tags':
						if ($this->queryParams['apiVersion'] >= 2) {
							$this->e404();
						}
						
						$this->allowMethods(array('GET'));
						
						$tagIDs = Zotero_Tags::getIDs($this->objectLibraryID, $this->scopeObjectName);
						if (!$tagIDs) {
							$this->e404("Tag not found");
						}
						
						$title = '';
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
				else if ($this->subset == 'trash') {
					$this->allowMethods(array('GET'));
					
					$title = "Deleted Items";
					$itemIDs = Zotero_Items::getDeleted($this->objectLibraryID, true);
					$includeTrashed = true;
				}
				else if ($this->subset == 'children') {
					$item = Zotero_Items::getByLibraryAndKey($this->objectLibraryID, $this->objectKey);
					if (!$item) {
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
						if ($this->queryParams['apiVersion'] >= 2) {
							$this->allowMethods(array('GET'));
						}
						
						Zotero_DB::beginTransaction();
						
						$obj = $this->jsonDecode($this->body);
						$results = Zotero_Items::updateMultipleFromJSON(
							$obj,
							$this->objectLibraryID,
							$this->queryParams,
							$this->userID,
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
						if (isset($obj->url)) {
							$response = Zotero_Items::addFromURL($obj, $this->objectLibraryID, $this->userID, $this->getTranslationToken());
							if ($response instanceof stdClass) {
								header("Content-Type: application/json");
								echo json_encode($response->select);
								$this->e300();
							}
							else if (is_int($response)) {
								switch ($response) {
									case 501:
										$this->e501("No translators found for URL");
										break;
									
									default:
										$this->e500("Error translating URL");
								}
							}
							else {
								$keys = $response;
							}
							
							if (!$keys) {
								throw new Exception("No items added");
							}
						}
						else {
							if ($this->queryParams['apiVersion'] < 2) {
								Zotero_DB::beginTransaction();
							}
							
							$results = Zotero_Items::updateMultipleFromJSON(
								$obj,
								$this->objectLibraryID,
								$this->queryParams,
								$this->userID,
								$libraryTimestampChecked ? 0 : 1,
								null
							);
							
							if ($this->queryParams['apiVersion'] < 2) {
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
						$itemKeys = explode(',', $this->queryParams['itemKey']);
						foreach ($itemKeys as $itemKey) {
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
			
			if ($this->queryParams['format'] == 'bib') {
				if (($itemIDs ? sizeOf($itemIDs) : $results['total']) > Zotero_API::$maxBibliographyItems) {
					$this->e413("Cannot generate bibliography with more than " . Zotero_API::$maxBibliographyItems . " items");
				}
			}
			
			if ($itemIDs || $itemKeys) {
				if ($itemIDs) {
					$this->queryParams['itemIDs'] = $itemIDs;
				}
				if ($itemKeys) {
					$this->queryParams['itemKey'] = implode(',', $itemKeys);
				}
				$results = Zotero_Items::search(
					$this->objectLibraryID,
					false,
					$this->queryParams,
					$includeTrashed,
					$this->permissions
				);
			}
			
			if ($results && isset($results['results'])) {
				 $totalResults = $results['total'];
				 $results = $results['results'];
			}
			
			switch ($this->queryParams['format']) {
				case 'atom':
					$t = microtime(true);
					$this->responseXML = Zotero_Atom::createAtomFeed(
						$this->getFeedNamePrefix($this->objectLibraryID) . $title,
						$this->uri,
						$results,
						$totalResults,
						$this->queryParams,
						$this->permissions
					);
					StatsD::timing("api.items.multiple.createAtomFeed." . implode("-", $this->queryParams['content']), (microtime(true) - $t) * 1000);
					break;
				
				case 'bib':
					echo Zotero_Cite::getBibliographyFromCitationServer($results, $this->queryParams);
					break;
				
				case 'csljson':
					if ($this->queryParams['pprint']) {
						header("Content-Type: text/plain");
					}
					else {
						header("Content-Type: application/vnd.citationstyles.csl+json");
					}
					$json = Zotero_Cite::getJSONFromItems($results, true);
					echo Zotero_Utilities::formatJSON($json, $this->queryParams['pprint']);
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
					$export = Zotero_Translate::doExport($results, $this->queryParams['format']);
					if ($this->queryParams['pprint']) {
						header("Content-Type: text/plain");
					}
					else {
						header("Content-Type: " . $export['mimeType']);
					}
					echo $export['body'];
			}
		}
		
		$this->end();
	}
	
	
	/**
	 * Handle S3 request
	 *
	 * Permission-checking provided by items()
	 */
	private function _handleFileRequest($item) {
		if (!$this->permissions->canAccess($this->objectLibraryID, 'files')) {
			$this->e403();
		}
		
		$this->allowMethods(array('HEAD', 'GET', 'POST', 'PATCH'));
		
		if (!$item->isAttachment()) {
			$this->e400("Item is not an attachment");
		}
		
		// File info for client sync
		//
		// Use of HEAD method is deprecated after 2.0.8/2.1b1 due to
		// compatibility problems with proxies and security software
		if ($this->method == 'HEAD' || ($this->method == 'GET' && $this->fileMode == 'info')) {
			$info = Zotero_S3::getLocalFileItemInfo($item);
			if (!$info) {
				$this->e404();
			}
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
		}
		
		// File download/viewing
		//
		// TEMP: allow POST for snapshot viewing until using session auth
		else if ($this->method == 'GET' || ($this->method == 'POST' && $this->fileView)) {
			if ($this->fileView) {
				$info = Zotero_S3::getLocalFileItemInfo($item);
				if (!$info) {
					$this->e404();
				}
				// For zip files, redirect to files domain
				if ($info['zip']) {
					$url = Zotero_Attachments::getTemporaryURL($item, !empty($_GET['int']));
					if (!$url) {
						$this->e500();
					}
					$this->redirect($url);
					exit;
				}
			}
			
			// For single files, redirect to S3
			$url = Zotero_S3::getDownloadURL($item, 60);
			if (!$url) {
				$this->e404();
			}
			Zotero_S3::logDownload(
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
			
			// If not the client, require If-Match or If-None-Match
			if (!$this->httpAuth) {
				if (empty($_SERVER['HTTP_IF_MATCH']) && empty($_SERVER['HTTP_IF_NONE_MATCH'])) {
					$this->e428("If-Match/If-None-Match header not provided");
				}
				
				if (!empty($_SERVER['HTTP_IF_MATCH'])) {
					if (!preg_match('/^"?([a-f0-9]{32})"?$/', $_SERVER['HTTP_IF_MATCH'], $matches)) {
						$this->e400("Invalid ETag in If-Match header");
					}
					
					if (!$item->attachmentStorageHash) {
						$info = Zotero_S3::getLocalFileItemInfo($item);
						$this->e412("ETag set but file does not exist");
					}
					
					if ($item->attachmentStorageHash != $matches[1]) {
						$this->e412("ETag does not match current version of file");
					}
				}
				else {
					if ($_SERVER['HTTP_IF_NONE_MATCH'] != "*") {
						$this->e400("Invalid value for If-None-Match header");
					}
					
					if ($item->attachmentStorageHash) {
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
				$info->hash = $_REQUEST['md5'];
				if (!preg_match('/[abcdefg0-9]{32}/', $info->hash)) {
					$this->e400('Invalid MD5 hash');
				}
				
				if (empty($_REQUEST['mtime'])) {
					$this->e400('File modification time not provided');
				}
				$info->mtime = $_REQUEST['mtime'];
				
				if (!isset($_REQUEST['filename']) || $_REQUEST['filename'] === "") {
					$this->e400('File name not provided');
				}
				$info->filename = $_REQUEST['filename'];
				
				if (!isset($_REQUEST['filesize'])) {
					$this->e400('File size not provided');
				}
				$info->size = $_REQUEST['filesize'];
				if (!is_numeric($info->size)) {
					$this->e400("Invalid file size");
				}
				
				$info->contentType = isset($_REQUEST['contentType']) ? $_REQUEST['contentType'] : "";
				if (!preg_match("/^[a-zA-Z0-9\-\/]+$/", $info->contentType)) {
					$info->contentType = "";
				}
				
				$info->charset = isset($_REQUEST['charset']) ? $_REQUEST['charset'] : "";
				if (!preg_match("/^[a-zA-Z0-9\-]+$/", $info->charset)) {
					$info->charset = "";
				}
				
				$contentTypeHeader = $info->contentType . (($info->contentType && $info->charset) ? "; charset=" . $info->charset : "");
				
				$info->zip = !empty($_REQUEST['zip']);
				
				// Reject file if it would put account over quota
				if ($group) {
					$quota = Zotero_S3::getEffectiveUserQuota($group->ownerUserID);
					$usage = Zotero_S3::getUserUsage($group->ownerUserID);
				}
				else {
					$quota = Zotero_S3::getEffectiveUserQuota($this->objectUserID);
					$usage = Zotero_S3::getUserUsage($this->objectUserID);
				}
				$total = $usage['total'];
				$fileSizeMB = round($info->size / 1024 / 1024, 1);
				if ($total + $fileSizeMB > $quota) {
					$this->e413("File would exceed quota ($total + $fileSizeMB > $quota)");
				}
				
				Zotero_DB::query("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
				Zotero_DB::beginTransaction();
				
				// See if file exists with this filename
				$localInfo = Zotero_S3::getLocalFileInfo($info);
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
					$oldStorageFileID = Zotero_S3::getFileByHash($info->hash, $info->zip);
					if ($oldStorageFileID) {
						// Verify file size
						$localInfo = Zotero_S3::getFileInfoByID($oldStorageFileID);
						if ($localInfo['size'] != $info->size) {
							throw new Exception(
								"Specified file size incorrect for duplicated file "
								. $info->hash . "/" . $info->filename
								. " ({$localInfo['size']} != {$info->size})"
							);
						}
						
						// Create new file on S3 with new name
						$storageFileID = Zotero_S3::duplicateFile(
							$oldStorageFileID,
							$info->filename,
							$info->zip,
							$contentTypeHeader
						);
						if (!$storageFileID) {
							$this->e500("File duplication failed");
						}
					}
				}
				
				// If we already have a file, add/update storageFileItems row and stop
				if (!empty($storageFileID)) {
					Zotero_S3::updateFileItemInfo($item, $storageFileID, $info, $this->httpAuth);
					Zotero_DB::commit();
					
					if ($this->httpAuth) {
						header('Content-Type: application/xml');
						echo "<exists/>";
					}
					else {
						header('Content-Type: application/json');
						echo json_encode(array('exists' => 1));
					}
					exit;
				}
				
				Zotero_DB::commit();
				
				// Add request to upload queue
				$uploadKey = Zotero_S3::queueUpload($this->userID, $info);
				// User over queue limit
				if (!$uploadKey) {
					header('Retry-After: ' . Zotero_S3::$uploadQueueTimeout);
					if ($this->httpAuth) {
						$this->e413("Too many queued uploads");
					}
					else {
						$this->e429("Too many queued uploads");
					}
				}
				
				// Output XML for client requests (which use HTTP Auth)
				if ($this->httpAuth) {
					$params = Zotero_S3::generateUploadPOSTParams($item, $info, true);
					
					header('Content-Type: application/xml');
					$xml = new SimpleXMLElement('<upload/>');
					$xml->url = Zotero_S3::getUploadBaseURL();
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
							"url" => Zotero_S3::getUploadBaseURL(),
							"params" => array()
						);
						foreach (Zotero_S3::generateUploadPOSTParams($item, $info) as $key=>$val) {
							$params['params'][$key] = $val;
						}
					}
					else {
						$params = Zotero_S3::getUploadPOSTData($item, $info);
					}
					
					$params['uploadKey'] = $uploadKey;
					
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
				
				$info = Zotero_S3::getUploadInfo($uploadKey);
				if (!$info) {
					$this->e400("Upload key not found");
				}
				
				// Partial upload
				if ($this->method == 'PATCH') {
					if (empty($_REQUEST['algorithm'])) {
						throw new Exception("Algorithm not specified", Z_ERROR_INVALID_INPUT);
					}
					
					$storageFileID = Zotero_S3::patchFile($item, $info, $_REQUEST['algorithm'], $this->body);
				}
				// Full upload
				else {
					$remoteInfo = Zotero_S3::getRemoteFileInfo($info);
					if (!$remoteInfo) {
						error_log("Remote file {$info->hash}/{$info->filename} not found");
						$this->e400("Remote file not found");
					}
					if ($remoteInfo['size'] != $info->size) {
						error_log("Uploaded file size does not match ({$remoteInfo['size']} != {$info->size}) for file {$info->hash}/{$info->filename}");
					}
				}
				
				// Set an automatic shared lock in getLocalFileInfo() to prevent
				// two simultaneous transactions from adding a file
				Zotero_DB::query("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
				Zotero_DB::beginTransaction();
				
				if (!isset($storageFileID)) {
					// Check if file already exists, which can happen if two identical
					// files are uploaded simultaneously
					$fileInfo = Zotero_S3::getLocalFileInfo($info);
					if ($fileInfo) {
						$storageFileID = $fileInfo['storageFileID'];
					}
					// If file doesn't exist, add it
					else {
						$storageFileID = Zotero_S3::addFile($info);
					}
				}
				Zotero_S3::updateFileItemInfo($item, $storageFileID, $info);
				
				Zotero_S3::logUpload($this->userID, $item, $uploadKey, IPAddress::getIP());
				
				Zotero_DB::commit();
				
				header("HTTP/1.1 204 No Content");
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
				
				$info = Zotero_S3::getUploadInfo($uploadKey);
				if (!$info) {
					$this->e400("Upload key not found");
				}
				
				$remoteInfo = Zotero_S3::getRemoteFileInfo($info);
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
				$fileInfo = Zotero_S3::getLocalFileInfo($info);
				if ($fileInfo) {
					$storageFileID = $fileInfo['storageFileID'];
				}
				else {
					$storageFileID = Zotero_S3::addFile($info);
				}
				
				Zotero_S3::updateFileItemInfo($item, $storageFileID, $info, true);
				
				Zotero_S3::logUpload($this->userID, $item, $uploadKey, IPAddress::getIP());
				
				Zotero_DB::commit();
				
				header("HTTP/1.1 204 No Content");
				exit;
			}
		}
		exit;
	}
}
