<?
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

class Zotero_FullText {
	private static $metadata = array('indexedChars', 'totalChars', 'indexedPages', 'totalPages');
	
	public static function indexItem(Zotero_Item $item, $content, $stats=array()) {
		if (!$item->isAttachment() ||
				Zotero_Attachments::linkModeNumberToName($item->attachmentLinkMode) == 'LINKED_URL') {
			throw new Exception(
				"Full-text content can only be added for file attachments", Z_ERROR_INVALID_INPUT
			);
		}
		
		$sql = "REPLACE INTO fulltextContent (";
		$fields = [
			"libraryID",
			"`key`",
			"content",
			"version",
			"timestamp"
		];
		$libraryID = $item->libraryID;
		$params = [
			$libraryID,
			$item->key,
			$content,
			Zotero_Libraries::getUpdatedVersion($libraryID),
			Zotero_DB::transactionInProgress()
				? Zotero_DB::getTransactionTimestamp()
				: date("Y-m-d H:i:s")
		];
		if ($stats) {
			foreach (self::$metadata as $prop) {
				if (isset($stats[$prop])) {
					$fields[] = $prop;
					$params[] = (int) $stats[$prop];
				}
			}
		}
		$sql .= implode(", ", $fields) . ") VALUES ("
			. implode(', ', array_fill(0, sizeOf($params), '?')) . ")";
		Zotero_FullText_DB::query(
			$sql, $params, Zotero_Shards::getByLibraryID($libraryID)
		);
	}
	
	
	public static function getItemData(Zotero_Item $item) {
		$sql = "SELECT * FROM fulltextContent WHERE libraryID=? AND `key`=?";
		$data = Zotero_FullText_DB::rowQuery(
			$sql,
			[$item->libraryID, $item->key],
			Zotero_Shards::getByLibraryID($item->libraryID)
		);
		if (!$data) {
			return false;
		}
		
		$itemData = array(
			"content" => $data['content'],
			"version" => $data['version'],
		);
		foreach (self::$metadata as $prop) {
			$itemData[$prop] = isset($data[$prop]) ? $data[$prop] : 0;
		}
		return $itemData;
	}
	
	
	public static function getNewerInLibrary($libraryID, $version) {
		$sql = "SELECT `key`, version FROM fulltextContent WHERE libraryID=? AND version>?";
		$rows = Zotero_FullText_DB::query(
			$sql,
			[$libraryID, $version],
			Zotero_Shards::getByLibraryID($libraryID)
		);
		$versions = new stdClass;
		foreach ($rows as $row) {
			$versions->{$row['key']} = $row['version'];
		}
		return $versions;
	}
	
	
	public static function getNewerInLibraryByTime($libraryID, $timestamp, $keys=[]) {
		$sql = "(SELECT * FROM fulltextContent WHERE libraryID=? AND timestamp>=FROM_UNIXTIME(?))";
		$params = [$libraryID, $timestamp];
		if ($keys) {
			$sql .= " UNION "
			. "(SELECT * FROM fulltextContent WHERE libraryID=? AND `key` IN ("
			. implode(', ', array_fill(0, sizeOf($keys), '?')) . ")"
			. ")";
			$params = array_merge($params, [$libraryID], $keys);
		}
		return Zotero_FullText_DB::query(
			$sql, $params, Zotero_Shards::getByLibraryID($libraryID)
		);
	}
	
	
	/**
	 * @param {Integer} libraryID
	 * @param {String} searchText
	 * @return {Array<String>|Boolean} An array of item keys, or FALSE if no results
	 */
	public static function searchInLibrary($libraryID, $searchText) {
		$sql = "SELECT `key` FROM fulltextContent WHERE MATCH (content) AGAINST (?)";
		return Zotero_FullText_DB::columnQuery(
			$sql, '"' . $searchText . '"', Zotero_Shards::getByLibraryID($libraryID)
		);
	}
	
	
	public static function deleteItemContent(Zotero_Item $item) {
		$sql = "DELETE FROM fulltextContent WHERE libraryID=? AND `key`=?";
		return Zotero_FullText_DB::query(
			$sql,
			[$item->libraryID, $item->key],
			Zotero_Shards::getByLibraryID($item->libraryID)
		);
	}
	
	
	public static function deleteByLibrary($libraryID) {
		$sql = "DELETE FROM fulltextContent WHERE libraryID=?";
		return Zotero_FullText_DB::query(
			$sql, $libraryID, Zotero_Shards::getByLibraryID($libraryID)
		);
	}
	
	
	public static function indexFromXML(DOMElement $xml) {
		if ($xml->textContent === "") {
			error_log("Skipping empty full-text content for item "
				. $xml->getAttribute('libraryID') . "/" . $xml->getAttribute('key'));
			return;
		}
		$item = Zotero_Items::getByLibraryAndKey(
			$xml->getAttribute('libraryID'), $xml->getAttribute('key')
		);
		if (!$item) {
			throw new Exception("Item not found");
		}
		$stats = array();
		foreach (self::$metadata as $prop) {
			$val = $xml->getAttribute($prop);
			$stats[$prop] = $val;
		}
		self::indexItem($item, $xml->textContent, $stats);
	}
	
	
	/**
	 * @param {Array} $data  Item data from Elasticsearch
	 * @param {DOMDocument} $doc
	 * @param {Boolean} [$empty=false]  If true, don't include full-text content
	 */
	public static function itemDataToXML($data, DOMDocument $doc, $empty=false) {
		$xmlNode = $doc->createElement('fulltext');
		$xmlNode->setAttribute('libraryID', $data['libraryID']);
		$xmlNode->setAttribute('key', $data['key']);
		foreach (self::$metadata as $prop) {
			$xmlNode->setAttribute($prop, $data[$prop]);
		}
		$xmlNode->setAttribute('version', $data['version']);
		if (!$empty) {
			$xmlNode->appendChild($doc->createTextNode($data['content']));
		}
		return $xmlNode;
	}
}
