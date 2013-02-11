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

class FullTextController extends ApiController {
	public function __construct($controllerName, $action, $params) {
		parent::__construct($controllerName, $action, $params);
		
		// Only available on testing site for now
		if (!Z_ENV_TESTING_SITE) {
			$this->e404();
		}
	}
	
	
	public function fulltext() {
		$this->allowMethods(array('GET'));
		
		if (!isset($_GET['newer'])) {
			$this->e400("'newer' not provided");
		}
		
		$newer = Zotero_FullText::getNewerInLibrary(
			$this->objectLibraryID, $this->queryParams['newer']
		);
		
		if ($this->queryParams['pprint']) {
			header("Content-Type: text/plain");
		}
		else {
			header("Content-Type: application/json");
		}
		echo Zotero_Utilities::formatJSON($newer, $this->queryParams['pprint']);
		$this->end();
	}
	
	
	public function itemContent() {
		$this->allowMethods(array('GET', 'PUT', 'DELETE'));
		
		// Check for general library access
		if (!$this->permissions->canAccess($this->objectLibraryID)) {
			$this->e403();
		}
		
		if (!$this->singleObject) {
			$this->e404();
		}
		
		if ($this->isWriteMethod()) {
			// Check for library write access
			if (!$this->permissions->canWrite($this->objectLibraryID)) {
				$this->e403("Write access denied");
			}
			
			Zotero_Libraries::updateVersionAndTimestamp($this->objectLibraryID);
		}
		
		$item = Zotero_Items::getByLibraryAndKey($this->objectLibraryID, $this->objectKey);
		if (!$item) {
			$this->e404();
		}
		
		// If no access to the note, don't show that it exists
		if ($item->isNote() && !$this->permissions->canAccess($this->objectLibraryID, 'notes')) {
			$this->e404();
		}
		
		if (!$item->isAttachment() || Zotero_Attachments::linkModeNumberToName(
				$item->attachmentLinkMode) == 'LINKED_URL') {
			$this->e404();
		}
		
		if ($this->isWriteMethod()) {
			$this->libraryVersion = Zotero_Libraries::getUpdatedVersion($this->objectLibraryID);
			
			if ($this->method == 'PUT') {
				$this->requireContentType("text/plain");
				
				Zotero_FullText::indexItem($item, $this->body);
			}
			else if ($this->method == 'DELETE') {
				$deleted = Zotero_FullText::deleteItemContent($item);
				if (!$deleted) {
					$this->e404();
				}
				$this->e204();
			}
			else {
				$this->e501();
			}
		}
		else {
			$data = Zotero_FullText::getItemData($item);
			if (!$data) {
				$this->e404();
			}
			$this->libraryVersion = $data['version'];
			header("Content-Type: text/plain; charset=UTF-8");
			echo $data['content'];
		}
		
		$this->end();
	}
}
