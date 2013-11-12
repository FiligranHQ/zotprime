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
	private static $elasticsearchType = "item_fulltext";
	public static $metadata = array('indexedChars', 'totalChars', 'indexedPages', 'totalPages');
	
	public static function indexItem(Zotero_Item $item, $content, $stats=array()) {
		if (!$item->isAttachment()) {
			throw new Exception(
				"Full-text content can only be added for attachments", Z_ERROR_INVALID_INPUT
			);
		}
		
		Zotero_FullText_DB::beginTransaction();
		
		$libraryID = $item->libraryID;
		$key = $item->key;
		$version = Zotero_Libraries::getUpdatedVersion($item->libraryID);
		$timestamp = Zotero_DB::transactionInProgress()
				? Zotero_DB::getTransactionTimestamp()
				: date("Y-m-d H:i:s");
		
		// Add to MySQL
		$sql = "REPLACE INTO fulltextContent (";
		$fields = ["libraryID", "`key`", "content", "version", "timestamp"];
		$params = [$libraryID, $key, $content, $version, $timestamp];
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
		Zotero_FullText_DB::query($sql, $params, Zotero_Shards::getByLibraryID($libraryID));
		
		// Add to Elasticsearch
		try {
			$type = self::getType();
			
			$id = $libraryID . "/" . $key;
			$doc = [
				'id' => $id,
				'libraryID' => $libraryID,
				'content' => (string) $content,
				// We don't seem to be able to search on _version, so we duplicate it here
				'version' => $version,
				// Add "T" between date and time for Elasticsearch
				'timestamp' => str_replace(" ", "T", $timestamp)
			];
			if ($stats) {
				foreach (self::$metadata as $prop) {
					if (isset($stats[$prop])) {
						$doc[$prop] = (int) $stats[$prop];
					}
				}
			}
			$doc = new \Elastica\Document($id, $doc, self::$elasticsearchType);
			$doc->setVersion($version);
			$doc->setVersionType('external');
			$response = $type->addDocument($doc);
			if ($response->hasError()) {
				throw new Exception($response->getError());
			}
		}
		// TEMP
		catch (Exception $e) {
			error_log("WARNING: " . $e);
		}
		
		Zotero_FullText_DB::commit();
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
		// TEMP: For now, strip double-quotes and make everything a phrase search
		$searchText = str_replace('"', '', $searchText);
		
		$index = self::getIndex();
		$type = self::getType();
		
		$libraryFilter = new \Elastica\Filter\Term();
		$libraryFilter->setTerm("libraryID", $libraryID);
		
		$matchQuery = new \Elastica\Query\Match();
		$matchQuery->setFieldQuery('content', $searchText);
		$matchQuery->setFieldType('content', 'phrase');
		
		$matchQuery = new \Elastica\Query\Filtered($matchQuery, $libraryFilter);
		$resultSet = $type->search($matchQuery);
		if ($resultSet->getResponse()->hasError()) {
			throw new Exception($resultSet->getResponse()->getError());
		}
		$results = $resultSet->getResults();
		$keys = array();
		foreach ($results as $result) {
			$keys[] = explode("/", $result->getId())[1];
		}
		return $keys;
	}
	
	
	public static function deleteItemContent(Zotero_Item $item) {
		$libraryID = $item->libraryID;
		$key = $item->key;
		
		Zotero_FullText_DB::beginTransaction();
		
		// Delete from MySQL
		$sql = "DELETE FROM fulltextContent WHERE libraryID=? AND `key`=?";
		return Zotero_FullText_DB::query(
			$sql,
			[$libraryID, $key],
			Zotero_Shards::getByLibraryID($libraryID)
		);
		
		// Delete from Elasticsearch
		try {
			$index = self::getIndex();
			$type = self::getType();
			
			try {
				$response = $type->deleteById($libraryID . "/" . $key);
			}
			catch (Elastica\Exception\NotFoundException $e) {
				// Ignore if not found
			}
			catch (Exception $e) {
				throw $e;
			}
			if ($response->hasError()) {
				throw new Exception($response->getError());
			}
		}
		// TEMP
		catch (Exception $e) {
			error_log("WARNING: " . $e);
		}
		
		Zotero_FullText_DB::commit();
	}
	
	
	public static function deleteByLibrary($libraryID) {
		Zotero_FullText_DB::beginTransaction();
		
		$sql = "DELETE FROM fulltextContent WHERE libraryID=?";
		Zotero_FullText_DB::query(
			$sql, $libraryID, Zotero_Shards::getByLibraryID($libraryID)
		);
		
		// Delete from Elasticsearch
		try {
			$type = self::getType();
			
			$libraryQuery = new \Elastica\Query\Term();
			$libraryQuery->setTerm("libraryID", $libraryID);
			$query = new \Elastica\Query($libraryQuery);
			$response = $type->deleteByQuery($query);
			if ($response->hasError()) {
				throw new Exception($response->getError());
			}
		}
		// TEMP
		catch (Exception $e) {
			error_log("WARNING: " . $e);
		}
		
		Zotero_FullText_DB::commit();
	}
	
	
	public static function indexFromXML(DOMElement $xml, $userID) {
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
		if (!Zotero_Libraries::userCanEdit($item->libraryID, $userID)) {
			error_log("Skipping full-text content from user $userID for uneditable item "
				. $xml->getAttribute('libraryID') . "/" . $xml->getAttribute('key'));
			return;
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
	
	private static function getIndex() {
		return Z_Core::$Elastica->getIndex(self::$elasticsearchType . "_index");
	}
	
	
	private static function getType() {
		return new \Elastica\Type(self::getIndex(), self::$elasticsearchType);
	}
}
