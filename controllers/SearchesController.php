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

class SearchesController extends ApiController {
	public function searches() {
		if ($this->apiVersion < 2) {
			$this->e404();
		}
		
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
		
		$results = array();
		
		// Single search
		if ($this->singleObject) {
			$this->allowMethods(['HEAD', 'GET', 'PUT', 'PATCH', 'DELETE']);
			
			$search = Zotero_Searches::getByLibraryAndKey($this->objectLibraryID, $this->objectKey);
			error_log("SEARCH");
			error_log(json_encode($search));
			
			if ($this->isWriteMethod()) {
				$search = $this->handleObjectWrite('search', $search ? $search : null);
				$this->e204();
			}
			
			if (!$search) {
				$this->e404("Search not found");
			}
			
			$this->libraryVersion = $search->version;
			
			if ($this->method == 'HEAD') {
				$this->end();
			}
			
			// Display search
			switch ($this->queryParams['format']) {
			case 'atom':
				$this->responseXML = $search->toAtom($this->queryParams);
				break;
			
			case 'json':
				$json = $search->toResponseJSON($this->queryParams, $this->permissions);
				echo Zotero_Utilities::formatJSON($json);
				break;
			
			default:
				throw new Exception("Unexpected format '" . $this->queryParams['format'] . "'");
			}
		}
		// Multiple searches
		else {
			$this->allowMethods(['HEAD', 'GET', 'POST', 'DELETE']);
			
			$this->libraryVersion = Zotero_Libraries::getUpdatedVersion($this->objectLibraryID);
			
			// Create a search
			if ($this->method == 'POST') {
				$this->queryParams['format'] = 'writereport';
				
				$obj = $this->jsonDecode($this->body);
				$results = Zotero_Searches::updateMultipleFromJSON(
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
			}
			// Delete searches
			else if ($this->method == 'DELETE') {
				Zotero_DB::beginTransaction();
				foreach ($this->queryParams['searchKey'] as $searchKey) {
					Zotero_Searches::delete($this->objectLibraryID, $searchKey);
				}
				Zotero_DB::commit();
				$this->e204();
			}
			// Display searches
			else {
				$title = "Searches";
				$results = Zotero_Searches::search($this->objectLibraryID, $this->queryParams);
			}
			
			$options = [
				'action' => $this->action,
				'uri' => $this->uri,
				'results' => $results,
				'requestParams' => $this->queryParams,
				'permissions' => $this->permissions,
				'head' => $this->method == 'HEAD'
			];
			switch ($this->queryParams['format']) {
			case 'atom':
				$this->responseXML = Zotero_API::multiResponse(array_merge($options, [
					'title' => $this->getFeedNamePrefix($this->objectLibraryID) . $title
				]));
				break;
			
			case 'json':
			case 'keys':
			case 'versions':
			case 'writereport':
				Zotero_API::multiResponse($options);
				break;
			
			default:
				throw new Exception("Unexpected format '" . $this->queryParams['format'] . "'");
			}
		}
		
		$this->end();
	}
}
