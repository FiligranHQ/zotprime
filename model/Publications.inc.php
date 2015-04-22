<?php
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2015 Center for History and New Media
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

class Zotero_Publications {
	// Currently unused
	private static $rights = [
		'CC-BY-4.0',
		'CC-BY-ND-4.0',
		'CC-BY-NC-SA-4.0',
		'CC-BY-SA-4.0',
		'CC-BY-NC-4.0',
		'CC-BY-NC-ND-4.0',
		'CC0'
	];
	
	public static function add($userID) {
		Z_Core::debug("Creating publications library for user $userID");
		
		Zotero_DB::beginTransaction();
		
		// Use same shard as user library
		$shardID = Zotero_Shards::getByUserID($userID);
		$libraryID = Zotero_Libraries::add('publications', $shardID);
		
		$sql = "INSERT INTO userPublications (userID, libraryID) VALUES (?, ?)";
		Zotero_DB::query($sql, [$userID, $libraryID]);
		
		Zotero_DB::commit();
		
		return $libraryID;
	}
	
	
	public static function validateJSONItem($json) {
		// No deleted items
		if (!empty($json->deleted)) {
			throw new Exception("Items in publications libraries cannot be marked as deleted",
				Z_ERROR_INVALID_INPUT);
		}
		
		// No top-level attachments or notes
		if (($json->itemType == 'note' || $json->itemType == 'attachment') && empty($json->parentItem)) {
			throw new Exception("Top-level notes and attachments cannot be added to publications libraries",
				Z_ERROR_INVALID_INPUT);
		}
		
		if ($json->itemType == 'attachment' && $json->linkMode == 'linked_file') {
			throw new Exception("Linked-file attachments cannot be added to publications libraries",
				Z_ERROR_INVALID_INPUT);
		}
	}
}
