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
	
	public static function indexItem(Zotero_Item $item, $data) {
		if (!$item->isAttachment()) {
			throw new Exception(
				"Full-text content can only be added for attachments", Z_ERROR_INVALID_INPUT
			);
		}
		
		Zotero_DB::beginTransaction();
		
		$libraryID = $item->libraryID;
		$key = $item->key;
		$version = Zotero_Libraries::getUpdatedVersion($item->libraryID);
		$timestamp = Zotero_DB::transactionInProgress()
				? Zotero_DB::getTransactionTimestamp()
				: date("Y-m-d H:i:s");
		
		// Add to MySQL for syncing, since Elasticsearch doesn't refresh immediately
		$sql = "REPLACE INTO itemFulltext (";
		$fields = ["itemID", "version", "timestamp"];
		$params = [$item->id, $version, $timestamp];
		$sql .= implode(", ", $fields) . ") VALUES ("
			. implode(', ', array_fill(0, sizeOf($params), '?')) . ")";
		Zotero_DB::query($sql, $params, Zotero_Shards::getByLibraryID($libraryID));
		
		// Add to Elasticsearch
		self::indexItemInElasticsearch($libraryID, $key, $version, $timestamp, $data);
		
		Zotero_DB::commit();
	}
	
	private static function indexItemInElasticsearch($libraryID, $key, $version, $timestamp, $data) {
		$type = self::getWriteType();
		
		$id = $libraryID . "/" . $key;
		$doc = [
			'id' => $id,
			'libraryID' => $libraryID,
			'content' => (string) $data->content,
			// We don't seem to be able to search on _version, so we duplicate it here
			'version' => $version,
			// Add "T" between date and time for Elasticsearch
			'timestamp' => str_replace(" ", "T", $timestamp)
		];
		foreach (self::$metadata as $prop) {
			if (isset($data->$prop)) {
				$doc[$prop] = (int) $data->$prop;
			}
		}
		$start = microtime(true);
		$doc = new \Elastica\Document($id, $doc, self::$elasticsearchType);
		$doc->setVersion($version);
		$doc->setVersionType('external');
		try {
			$response = $type->addDocument($doc);
		}
		catch (Exception $e) {
			$msg = $e->getMessage();
			if (preg_match('/version conflict, current \[([0-9]+)\], provided \[([0-9]+)\]/', $msg, $matches)) {
				if ($matches[1] == $matches[2]) {
					error_log("WARNING: " . $msg);
					return;
				}
			}
			throw $e;
		}
		StatsD::timing("elasticsearch.client.item_fulltext.add", (microtime(true) - $start) * 1000);
		if ($response->hasError()) {
			$msg = $response->getError();
			if (preg_match('/version conflict, current \[([0-9]+)\], provided \[([0-9]+)\]/', $msg, $matches)) {
				if ($matches[1] == $matches[2]) {
					error_log("WARNING: " . $msg);
					return;
				}
			}
			throw new Exception($response->getError());
		}
	}
	
	
	public static function updateMultipleFromJSON($json, $requestParams, $libraryID, $userID,
			Zotero_Permissions $permissions) {
		self::validateMultiObjectJSON($json);
		
		$results = new Zotero_Results($requestParams);
		
		if (Zotero_DB::transactionInProgress()) {
			throw new Exception(
				"Transaction cannot be open when starting multi-object update"
			);
		}
		
		$i = 0;
		foreach ($json as $index => $jsonObject) {
			Zotero_DB::beginTransaction();
			
			try {
				if (!is_object($jsonObject)) {
					throw new Exception(
						"Invalid value for index $index in uploaded data; expected JSON full-text object",
						Z_ERROR_INVALID_INPUT
					);
				}
				
				if (!isset($jsonObject->key)) {
					throw new Exception("Item key not provided", Z_ERROR_INVALID_INPUT);
				}
				
				$item = Zotero_Items::getByLibraryAndKey($libraryID, $jsonObject->key);
				// This shouldn't happen, since the request uses a library version
				if (!$item) {
					throw new Exception(
						"Item $jsonObject->key not found in library",
						Z_ERROR_ITEM_NOT_FOUND
					);
				}
				self::indexItem($item, $jsonObject);
				Zotero_DB::commit();
				$obj = [
					'key' => $jsonObject->key
				];
				$results->addSuccessful($i, $obj);
			}
			catch (Exception $e) {
				Zotero_DB::rollback();
				
				// If item key given, include that
				$resultKey = isset($jsonObject->key) ? $jsonObject->$key : '';
				$results->addFailure($i, $resultKey, $e);
			}
			$i++;
		}
		
		return $results->generateReport();
	}
	
	
	private static function validateMultiObjectJSON($json) {
		if (!is_array($json)) {
			throw new Exception('Uploaded data must be a JSON array', Z_ERROR_INVALID_INPUT);
		}
		if (sizeOf($json) > Zotero_API::$maxWriteFullText) {
			throw new Exception("Cannot add full text for more than " . Zotero_API::$maxWriteFullText
				. " items at a time", Z_ERROR_UPLOAD_TOO_LARGE);
		}
	}
	
	
	/**
	 * Get item full-text data from Elasticsearch by libraryID and key
	 *
	 * Since the request to Elasticsearch is by id, it will return results even if the document
	 * hasn't yet been indexed
	 */
	public static function getItemData($libraryID, $key) {
		$index = self::getReadIndex();
		$type = self::getReadType();
		$id = $libraryID . "/" . $key;
		
		try {
			$document = $type->getDocument($id, [
				'routing' => $libraryID
			]);
		}
		catch (\Elastica\Exception\NotFoundException $e) {
			return false;
		}
		
		$esData = $document->getData();
		$itemData = array(
			"content" => $esData['content'],
			"version" => $esData['version'],
		);
		if (isset($esData['language'])) {
			$itemData['language'] = $esData['language'];
		}
		foreach (self::$metadata as $prop) {
			$itemData[$prop] = isset($esData[$prop]) ? $esData[$prop] : 0;
		}
		return $itemData;
	}
	
	
	/**
	 * @return {Object} An object with item keys for keys and full-text content versions for values
	 */
	public static function getNewerInLibrary($libraryID, $version) {
		$sql = "SELECT `key`, IFT.version FROM itemFulltext IFT JOIN items USING (itemID) "
			. "WHERE libraryID=? AND IFT.version>?";
		$rows = Zotero_DB::query(
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
	
	
	/**
	 * Used by classic sync
	 *
	 * @return {Array} Array of arrays of item data
	 */
	public static function getNewerInLibraryByTime($libraryID, $timestamp, $keys=[]) {
		$sql = "(SELECT libraryID, `key` FROM itemFulltext JOIN items USING (itemID) "
			. "WHERE libraryID=? AND timestamp>=FROM_UNIXTIME(?))";
		$params = [$libraryID, $timestamp];
		if ($keys) {
			$sql .= " UNION "
			. "(SELECT libraryID, `key` FROM itemFulltext JOIN items USING (itemID) "
				. "WHERE libraryID=? AND `key` IN ("
			. implode(', ', array_fill(0, sizeOf($keys), '?')) . ")"
			. ")";
			$params = array_merge($params, [$libraryID], $keys);
		}
		$rows = Zotero_DB::query(
			$sql, $params, Zotero_Shards::getByLibraryID($libraryID)
		);
		if (!$rows) {
			return [];
		}
		
		$maxChars = 1000000;
		
		$index = self::getReadIndex();
		$type = self::getReadType();
		$first = true;
		$chars = 0;
		$data = [];
		
		while ($batchRows = array_splice($rows, 0, 100)) {
			// Make a raw query, since Elastica doesn't yet support mget
			$json = [
				"docs" => []
			];
			foreach ($batchRows as $row) {
				$json['docs'][] = [
					"_id" => $row['libraryID'] . "/" . $row['key'],
					"_routing" => $row['libraryID']
				];
			}
			$path = $index->getName() . '/' . $type->getName() . '/_mget';
			$response = Z_Core::$Elastica->request($path, \Elastica\Request::GET, json_encode($json));
			if ($response->hasError()) {
				throw new Exception($response->getError());
			}
			$responseData = $response->getData();
			if (!isset($responseData['docs'])) {
				throw new Exception("Invalid response from mget");
			}
			if (sizeOf($responseData['docs']) != sizeOf($batchRows)) {
				throw new Exception("MySQL and Elasticsearch do not match "
					. "(" . sizeOf($responseData['docs']) . ", " . sizeOf($batchRows) . ")");
			}
			
			foreach ($responseData['docs'] as $doc) {
				list($libraryID, $key) = explode("/", $doc['_id']);
				// This shouldn't happen
				if (empty($doc["found"])) {
					error_log("WARNING: Item {$doc['_id']} not found in Elasticsearch");
					continue;
				}
				$source = $doc['_source'];
				if (!$source) {
					throw new Exception("_source not found in Elasticsearch for item {$doc['_id']}");
				}
				
				$data[$key] = [
					"libraryID" => $libraryID,
					"key" => $key,
					"version" => $source['version']
				];
				
				// If the current item would put us over max characters,
				// leave it empty, unless it's the first one
				$currentChars = strlen($source['content']);
				if (!$first && (($chars + $currentChars) > $maxChars)) {
					$data[$key]['empty'] = true;
				}
				else {
					$data[$key]['content'] = $source['content'];
					$data[$key]['empty'] = false;
					$chars += $currentChars;
				}
				$first = false;
				
				foreach (self::$metadata as $prop) {
					if (isset($source[$prop])) {
						$data[$key][$prop] = (int) $source[$prop];
					}
				}
			}
		}
		return $data;
	}
	
	
	/**
	 * @param {Integer} libraryID
	 * @param {String} searchText
	 * @return {Array<String>|Boolean} An array of item keys, or FALSE if no results
	 */
	public static function searchInLibrary($libraryID, $searchText) {
		// TEMP: For now, strip double-quotes and make everything a phrase search
		$searchText = str_replace('"', '', $searchText);
		
		$type = self::getReadType();
		
		$libraryFilter = new \Elastica\Filter\Term();
		$libraryFilter->setTerm("libraryID", $libraryID);
		
		$matchQuery = new \Elastica\Query\Match();
		$matchQuery->setFieldQuery('content', $searchText);
		$matchQuery->setFieldType('content', 'phrase');
		
		$matchQuery = new \Elastica\Query\Filtered($matchQuery, $libraryFilter);
		$start = microtime(true);
		$resultSet = $type->search($matchQuery, [
			'routing' => $libraryID
		]);
		StatsD::timing("elasticsearch.client.item_fulltext.search", (microtime(true) - $start) * 1000);
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
		
		Zotero_DB::beginTransaction();
		
		// Delete from MySQL
		$sql = "DELETE FROM itemFulltext WHERE itemID=?";
		Zotero_DB::query(
			$sql,
			[$item->id],
			Zotero_Shards::getByLibraryID($libraryID)
		);
		
		self::deleteItemContentFromElasticsearch($libraryID, $key);
		
		Zotero_DB::commit();
	}
	
	
	public static function deleteItemContentFromElasticsearch($libraryID, $key) {
		// Delete from Elasticsearch
		$type = self::getWriteType();
		
		try {
			$start = microtime(true);
			$response = $type->deleteById($libraryID . "/" . $key, [
				'routing' => $libraryID
			]);
			StatsD::timing("elasticsearch.client.item_fulltext.delete_item", (microtime(true) - $start) * 1000);
		}
		catch (Elastica\Exception\NotFoundException $e) {
			// Ignore if not found
		}
		catch (Exception $e) {
			throw $e;
		}
		if (isset($response) && $response->hasError()) {
			throw new Exception($response->getError());
		}
	}
	
	
	public static function deleteByLibrary($libraryID) {
		Zotero_DB::beginTransaction();
		
		// Delete from MySQL
		self::deleteByLibraryMySQL($libraryID);
		
		// Delete from Elasticsearch
		$type = self::getWriteType();
		
		$libraryQuery = new \Elastica\Query\Term();
		$libraryQuery->setTerm("libraryID", $libraryID);
		$query = new \Elastica\Query($libraryQuery);
		$start = microtime(true);
		$response = $type->deleteByQuery($query);
		StatsD::timing("elasticsearch.client.item_fulltext.delete_library", (microtime(true) - $start) * 1000);
		if ($response->hasError()) {
			throw new Exception($response->getError());
		}
		
		Zotero_DB::commit();
	}
	
	
	public static function deleteByLibraryMySQL($libraryID) {
		$sql = "DELETE IFT FROM itemFulltext IFT JOIN items USING (itemID) WHERE libraryID=?";
		Zotero_DB::query(
			$sql, $libraryID, Zotero_Shards::getByLibraryID($libraryID)
		);
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
			error_log("Item " . $xml->getAttribute('libraryID') . "/" . $xml->getAttribute('key')
				. " not found during full-text indexing");
			return;
		}
		if (!Zotero_Libraries::userCanEdit($item->libraryID, $userID)) {
			error_log("Skipping full-text content from user $userID for uneditable item "
				. $xml->getAttribute('libraryID') . "/" . $xml->getAttribute('key'));
			return;
		}
		$data = new stdClass;
		$data->content = $xml->textContent;
		foreach (self::$metadata as $prop) {
			$data->$prop = $xml->getAttribute($prop);
		}
		self::indexItem($item, $data);
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
			$xmlNode->setAttribute($prop, isset($data[$prop]) ? $data[$prop] : 0);
		}
		$xmlNode->setAttribute('version', $data['version']);
		if (!$empty) {
			$xmlNode->appendChild($doc->createTextNode($data['content']));
		}
		return $xmlNode;
	}
	
	
	private static function getReadIndex() {
		return Z_Core::$Elastica->getIndex(self::$elasticsearchType . "_index_read");
	}
	
	
	private static function getWriteIndex() {
		return Z_Core::$Elastica->getIndex(self::$elasticsearchType . "_index_write");
	}
	
	
	private static function getReadType() {
		return new \Elastica\Type(self::getReadIndex(), self::$elasticsearchType);
	}
	
	
	private static function getWriteType() {
		return new \Elastica\Type(self::getWriteIndex(), self::$elasticsearchType);
	}
}
