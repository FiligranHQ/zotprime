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

class MappingsController extends ApiController {
	/**
	 * JSON type/field data
	 */
	public function mappings() {
		if (!empty($_GET['locale']) && $_GET['locale'] != 'en-US') {
			$this->e400("Non-English locales are not yet supported");
		}
		
		$locale = empty($_GET['locale']) ? 'en-US' : $_GET['locale'];
		
		if ($this->subset == 'itemTypeFields') {
			if (empty($_GET['itemType'])) {
				$this->e400("'itemType' not provided");
			}
			
			$itemType = $_GET['itemType'];
			
			$itemTypeID = Zotero_ItemTypes::getID($itemType);
			if (!$itemTypeID) {
				$this->e400("Invalid item type '$itemType'");
			}
		}
		
		else if ($this->subset == 'itemTypeCreatorTypes') {
			if (empty($_GET['itemType'])) {
				$this->e400("'itemType' not provided");
			}
			
			$itemType = $_GET['itemType'];
			
			$itemTypeID = Zotero_ItemTypes::getID($itemType);
			if (!$itemTypeID) {
				$this->e400("Invalid item type '$itemType'");
			}
			
			// Notes and attachments don't have creators
			if ($itemType == 'note' || $itemType == 'attachment') {
				echo "[]";
				exit;
			}
		}
		
		// TODO: check If-Modified-Since and return 304 if not changed
		
		$cacheKey = $this->subset . "JSON";
		if (isset($itemTypeID)) {
			$cacheKey .= "_" . $itemTypeID;
		}
		$cacheKey .= '_' . $this->apiVersion;
		$ttl = 60;
		$json = Z_Core::$MC->get($cacheKey);
		if ($json) {
			header("Content-Type: application/json");
			echo $json;
			exit;
		}
		
		switch ($this->subset) {
			case 'itemTypes':
				$rows = Zotero_ItemTypes::getAll($locale);
				$propName = 'itemType';
				break;
			
			case 'itemTypeFields':
				$fieldIDs = Zotero_ItemFields::getItemTypeFields($itemTypeID);
				$rows = array();
				foreach ($fieldIDs as $fieldID) {
					$fieldName = Zotero_ItemFields::getName($fieldID);
					$rows[] = array(
						'id' => $fieldID,
						'name' => $fieldName,
						'localized' => Zotero_ItemFields::getLocalizedString(
							$itemTypeID, $fieldName, $locale
						)
					);
				}
				$propName = 'field';
				break;
			
			case 'itemFields':
				$rows = Zotero_ItemFields::getAll($locale);
				$propName = 'field';
				break;
			
			case 'itemTypeCreatorTypes':
				$rows = Zotero_CreatorTypes::getTypesForItemType($itemTypeID, $locale);
				$propName = 'creatorType';
				break;
			
			case 'creatorFields':
				$rows = Zotero_Creators::getLocalizedFieldNames();
				$propName = 'field';
				break;
		}
		
		$json = array();
		foreach ($rows as $row) {
			// Before v3, computerProgram's 'versionNumber' was just 'version'
			if ($this->apiVersion < 3
					&& ($this->subset == 'itemTypeFields'
						|| $this->subset == 'itemFields') && $row['id'] == 81) {
				$row['name'] = 'version';
			}
			$json[] = array(
				$propName => $row['name'],
				'localized' => $row['localized']
			);
		}
		
		header("Content-Type: application/json");
		$json = Zotero_Utilities::formatJSON($json);
		Z_Core::$MC->set($cacheKey, $json, $ttl);
		
		echo $json;
		exit;
	}
	
	
	public function newItem() {
		if (empty($_GET['itemType'])) {
			$this->e400("'itemType' not provided");
		}
		
		$itemType = $_GET['itemType'];
		if ($itemType == 'attachment') {
			if (empty($_GET['linkMode'])) {
				$this->e400("linkMode required for itemType=attachment");
			}
			
			$linkModeName = $_GET['linkMode'];
			
			try {
				$linkMode = Zotero_Attachments::linkModeNameToNumber($linkModeName);
			}
			catch (Exception $e) {
				$this->e400("Invalid linkMode '$linkModeName'");
			}
		}
		
		$itemTypeID = Zotero_ItemTypes::getID($itemType);
		if (!$itemTypeID) {
			$this->e400("Invalid item type '$itemType'");
		}
		
		// TODO: check If-Modified-Since and return 304 if not changed
		
		$cacheVersion = 1;
		$cacheKey = "newItemJSON"
			. "_" . $this->apiVersion
			. "_" . $itemTypeID
			. "_" . $cacheVersion;
		if ($itemType == 'attachment') {
			$cacheKey .= "_" . $linkMode;
		}
		$cacheKey .= '_' . $this->apiVersion;
		$ttl = 60;
		$json = Z_Core::$MC->get($cacheKey);
		if ($json) {
			header("Content-Type: application/json");
			echo $json;
			exit;
		}
		
		// Generate template
		
		$json = array(
			'itemType' => $itemType
		);
		if ($itemType == 'attachment') {
			$json['linkMode'] = $linkModeName;
		}
		
		$fieldIDs = Zotero_ItemFields::getItemTypeFields($itemTypeID);
		$first = true;
		foreach ($fieldIDs as $fieldID) {
			$fieldName = Zotero_ItemFields::getName($fieldID);
			
			// Before v3, computerProgram's 'versionNumber' was just 'version'
			if ($this->apiVersion < 3 && $fieldID == 81) {
				$fieldName = 'version';
			}
			
			if ($itemType == 'attachment' && $fieldName == 'url' && !preg_match('/_url$/', $linkModeName)) {
				continue;
			}
			
			$json[$fieldName] = "";
			
			if ($first && $itemType != 'note' && $itemType != 'attachment') {
				$creatorTypeID = Zotero_CreatorTypes::getPrimaryIDForType($itemTypeID);
				$creatorTypeName = Zotero_CreatorTypes::getName($creatorTypeID);
				$json['creators'] = array(
					array(
						'creatorType' => $creatorTypeName,
						'firstName' => '',
						'lastName' => ''
					)
				);
				$first = false;
			}
		}
		
		if ($itemType == 'note' || $itemType == 'attachment') {
			$json['note'] = '';
		}
		
		$json['tags'] = array();
		if ($this->apiVersion >= 2) {
			$json['collections'] = array();
			$json['relations'] = new stdClass;
		}
		
		if ($this->apiVersion == 1) {
			if ($itemType != 'note' && $itemType != 'attachment') {
				$json['attachments'] = array();
				$json['notes'] = array();
			}
		}
		
		if ($itemType == 'attachment') {
			$json['contentType'] = '';
			$json['charset'] = '';
			
			if ($linkModeName == 'linked_file') {
				$json['path'] = '';
			}
			
			if (preg_match('/^imported_/', $linkModeName)) {
				$json['filename'] = '';
				$json['md5'] = null;
				$json['mtime'] = null;
				//$json['zip'] = false;
			}
		}
		
		header("Content-Type: application/json");
		
		$json = Zotero_Utilities::formatJSON($json);
		Z_Core::$MC->set($cacheKey, $json, $ttl);
		
		echo $json;
		exit;
	}
}
