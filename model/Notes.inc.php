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
	public static $MAX_TITLE_LENGTH = 79;
	public static $MAX_NOTE_LENGTH = 250000;
	
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
	 *
	 * input should be sanitized already
	 */
	public static function noteToTitle($text, $ignoreNewline=false) {
		if (!$text) {
			return '';
		}
		$max = self::$MAX_TITLE_LENGTH;
		
		// Get a reasonable beginning to work with
		$text = mb_substr($text, 0, $max * 5);
		
		// Clean and unencode
		$text = preg_replace("/<\/p>[\s]*<p>/", "</p>\n<p>", $text);
		$text = strip_tags($text);
		$text = html_entity_decode($text, ENT_COMPAT, 'UTF-8');
		
		$t = mb_strcut($text, 0, $max);
		if ($ignoreNewline) {
			$t = preg_replace('/\s+/', ' ', $t);
		}
		else {
			$ln = mb_strpos($t, "\n");
			if ($ln !== false && $ln < $max) {
				$t = mb_strcut($t, 0, $ln);
			}
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
