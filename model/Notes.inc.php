<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2010 Center for History and New Media
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

class Zotero_Notes {
	public static $MAX_TITLE_LENGTH = 80;
	
	private static $noteCache = array();
	private static $hashCache = array();
	private static $hashCacheCachedUsers = array();
	
	
	public static function getCachedNote($itemID) {
		return isset(self::$noteCache[$itemID]) ? self::$noteCache[$itemID] : false;
	}
	
	public static function cacheNotes($itemIDs) {
		$sql = "SELECT itemID, note FROM itemNotes WHERE itemID IN (";
		foreach ($itemIDs as $itemID) {
			self::$noteCache[$itemID] = '';
			if (!is_int($itemID)) {
				throw new Exception("Invalid itemID $itemID");
			}
		}
		$sql .= join(',', $itemIDs) . ')';
		$notes = Zotero_DB::query($sql);
		if ($notes) {
			foreach ($notes as $row) {
				self::$noteCache[$row['itemID']] = $row['note'];
			}
		}
	}
	
	
	public static function updateNoteCache($itemID, $note) {
		self::$noteCache[$itemID] = $note;
	}
	
	
	public static function getHash($libraryID, $itemID) {
		if (!self::$hashCacheCachedUsers) {
			self::loadHashes($libraryID);
		}
		if (!empty(self::$hashCache[$itemID])) {
			return self::$hashCache[$itemID];
		}
		return false;
	}
	
	
	public static function updateHash($itemID, $value) {
		if ($value !== false) {
			self::$hashCache[$itemID] = $value;
		}
		else {
			unset(self::$hashCache[$itemID]);
		}
	}
	
	
	/**
	* Create a new item of type 'note' and add the note text to the itemNotes table
	*
	* Returns the itemID of the new note item
	**/
	/*
	public static function add($userID, $text, $sourceItemID) {
		Zotero_DB::beginTransaction();
		
		if ($sourceItemID) {
			$sourceItem = Zotero_Items::get($userID, $sourceItemID);
			if (!$sourceItem) {
				Zotero_DB::commit();
				trigger_error("Cannot set note source to invalid item $userID/$sourceItemID", E_USER_ERROR);
			}
			if (!$sourceItem->isRegularItem()) {
				Zotero_DB::commit();
				trigger_error("Cannot set note source to a note or attachment ($userID/$sourceItemID)", E_USER_ERROR);
			}
		}
		
		$note = new Zotero_Item($userID, false, 'note');
		$note->save();
		
		$title = $this->noteToTitle($text);
		
		$sql = "INSERT INTO itemNotes (userID, itemID, sourceItemID, note, title) VALUES (?,?,?,?,?)";
		$bindParams = array(
			$userID,
			$note->id,
			$sourceItemID ? $sourceItemID : null,
			$text ? $text : '',
			$title
		);
		Zotero_DB::query($sql, $bindParams);
		Zotero_DB::commit();
		
		// Switch to Zotero.Items version
		$note = Zotero_Items::get($userID, $note->id);
		//$note->updateNoteCache($text, title);
		
		// if (sourceItemID) {
		// 	var notifierData = {};
		// 	notifierData[sourceItem.id] = { old: sourceItem.toArray() };
		// 	sourceItem.incrementNoteCount();
		// 	Zotero.Notifier.trigger('modify', 'item', sourceItemID, notifierData);
		// }
		
		return $note->id;
	}
	*/
	
	
	/**
	* Return first line (or first MAX_LENGTH characters) of note content
	**/
	public static function noteToTitle($text) {
		if (!$text) {
			return '';
		}
		$max = self::$MAX_TITLE_LENGTH;
		
		$t = mb_substr($text, 0, $max);
		$ln = mb_strpos($t, "\n");
		if ($ln !== false && $ln < $max) {
			$t = mb_substr($t, 0, $ln);
		}
		return $t;
	}
	
	
	public static function loadHashes($libraryID) {
		$sql = "SELECT itemID, MD5(note) AS hash FROM itemNotes JOIN items USING (itemID) WHERE libraryID=?";
		$hashes = Zotero_DB::query($sql, $libraryID);
		if (!$hashes) {
			return;
		}
		foreach ($hashes as $hash) {
			self::$hashCache[$hash['itemID']] = $hash['hash'];
		}
		self::$hashCacheCachedUsers[$libraryID] = true;
	}
}
?>
