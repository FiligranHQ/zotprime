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
	
	
	public static function getCachedNote($libraryID, $itemID) {
		if (!$libraryID) {
			throw new Exception("Library ID not provided");
		}
		if (!$itemID) {
			throw new Exception("Item ID not provided");
		}
		return isset(self::$noteCache[$libraryID][$itemID]) ? self::$noteCache[$libraryID][$itemID] : false;
	}
	
	public static function cacheNotes($libraryID, $itemIDs) {
		if (!$libraryID) {
			throw new Exception("Library ID not provided");
		}
		if (!$itemIDs) {
			throw new Exception("Item IDs not provided");
		}
		
		if (isset(self::$noteCache[$libraryID])) {
			// Clear all notes before getting new ones
			foreach ($itemIDs as $itemID) {
				self::$noteCache[$libraryID][$row['itemID']] = '';
			}
		}
		else {
			self::$noteCache[$libraryID] = array();
		}
		
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		
		Zotero_DB::beginTransaction();
		
		$sql = "CREATE TEMPORARY TABLE tmpNoteCacheIDs (itemID int(10) unsigned NOT NULL, PRIMARY KEY (itemID))";
		Zotero_DB::query($sql, false, $shardID);
		
		Zotero_DB::bulkInsert("INSERT IGNORE INTO tmpNoteCacheIDs VALUES ", $itemIDs, 100, false, $shardID);
		
		$sql = "SELECT itemID, note FROM itemNotes JOIN tmpNoteCacheIDs USING (itemID)";
		$notes = Zotero_DB::query($sql, false, $shardID);
		if ($notes) {
			foreach ($notes as $row) {
				self::$noteCache[$libraryID][$row['itemID']] = $row['note'];
			}
		}
		
		Zotero_DB::query("DROP TEMPORARY TABLE tmpNoteCacheIDs", false, $shardID);
		
		Zotero_DB::commit();
	}
	
	
	public static function updateNoteCache($libraryID, $itemID, $note) {
		if (!$libraryID) {
			throw new Exception("Library ID not provided");
		}
		if (!$itemID) {
			throw new Exception("Item ID not provided");
		}
		if (!isset(self::$noteCache[$libraryID])) {
			self::$noteCache[$libraryID] = array();
		}
		self::$noteCache[$libraryID][$itemID] = $note;
	}
	
	
	public static function getHash($libraryID, $itemID) {
		if (!isset(self::$hashCache[$libraryID])) {
			self::loadHashes($libraryID);
		}
		if (!empty(self::$hashCache[$libraryID][$itemID])) {
			return self::$hashCache[$libraryID][$itemID];
		}
		return false;
	}
	
	
	public static function updateHash($libraryID, $itemID, $value) {
		if (!isset(self::$hashCache[$libraryID])) {
			self::$hashCache[$libraryID] = array();
		}
		
		if ($value) {
			self::$hashCache[$libraryID][$itemID] = $value;
		}
		else {
			unset(self::$hashCache[$libraryID][$itemID]);
		}
	}
	
	
	public static function sanitize($text) {
		return $GLOBALS['HTMLPurifier']->purify($text);
	}
	
	
	/**
	 * Return first line (or first MAX_LENGTH characters) of note content
	 */
	public static function noteToTitle($text) {
		if (!$text) {
			return '';
		}
		$max = self::$MAX_TITLE_LENGTH;
		
		// Get a reasonable beginning to work with
		$text = mb_substr($text, 0, $max * 5);
		
		// Clean and unencode
		$text = self::sanitize($text);
		$text = preg_replace("/<\/p>[\s]*<p>/", "</p>\n<p>", $text);
		$text = strip_tags($text);
		$text = html_entity_decode($text);
		
		$t = mb_substr($text, 0, $max);
		$ln = mb_strpos($t, "\n");
		if ($ln !== false && $ln < $max) {
			$t = mb_substr($t, 0, $ln);
		}
		return $t;
	}
	
	
	public static function loadHashes($libraryID) {
		$sql = "SELECT itemID, hash FROM itemNotes JOIN items USING (itemID) WHERE libraryID=?";
		$hashes = Zotero_DB::query($sql, $libraryID, Zotero_Shards::getByLibraryID($libraryID));
		if (!$hashes) {
			return;
		}
		
		if (!isset(self::$hashCache[$libraryID])) {
			self::$hashCache[$libraryID] = array();
		}
		
		foreach ($hashes as $hash) {
			if ($hash['hash']) {
				self::$hashCache[$libraryID][$hash['itemID']] = $hash['hash'];
			}
		}
	}
}
?>
