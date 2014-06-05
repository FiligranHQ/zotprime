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

class DeletedController extends ApiController {
	public function deleted() {
		if ($this->apiVersion < 2) {
			$this->e404();
		}
		
		$this->allowMethods(array('GET'));
		
		if (!$this->permissions->canAccess($this->objectLibraryID)) {
			$this->e403();
		}
		
		$this->libraryVersion = Zotero_Libraries::getUpdatedVersion($this->objectLibraryID);
		
		// TEMP: sync transition
		if (isset($this->queryParams['sincetime'])) {
			$deleted = array(
				"collections" => Zotero_Collections::getDeleteLogKeys(
					$this->objectLibraryID, $this->queryParams['sincetime'], true
				),
				"items" => Zotero_Items::getDeleteLogKeys(
					$this->objectLibraryID, $this->queryParams['sincetime'], true
				),
				"searches" => Zotero_Searches::getDeleteLogKeys(
					$this->objectLibraryID, $this->queryParams['sincetime'], true
				),
				"tags" => Zotero_Tags::getDeleteLogKeys(
					$this->objectLibraryID, $this->queryParams['sincetime'], true
				),
				"settings" => Zotero_Settings::getDeleteLogKeys(
					$this->objectLibraryID, $this->queryParams['sincetime'], true
				),
			);
			header("Content-Type: application/json");
			echo Zotero_Utilities::formatJSON($deleted);
			$this->end();
		}
		
		if (!isset($this->queryParams['since'])) {
			$this->e400("'since' parameter must be provided");
		}
		
		$deleted = array(
			"collections" => Zotero_Collections::getDeleteLogKeys(
				$this->objectLibraryID, $this->queryParams['since']
			),
			"items" => Zotero_Items::getDeleteLogKeys(
					$this->objectLibraryID, $this->queryParams['since']
			),
			"searches" => Zotero_Searches::getDeleteLogKeys(
					$this->objectLibraryID, $this->queryParams['since']
			),
			"tags" => Zotero_Tags::getDeleteLogKeys(
				$this->objectLibraryID, $this->queryParams['since']
			),
			"settings" => Zotero_Settings::getDeleteLogKeys(
					$this->objectLibraryID, $this->queryParams['since']
			)
		);
		
		header("Content-Type: application/json");
		echo Zotero_Utilities::formatJSON($deleted);
		$this->end();
	}
}

