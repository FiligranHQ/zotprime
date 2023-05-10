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

// Todo: move S3 code outside of transactions
class Zotero_FullText {
	private static $minFileSizeStandardIA = 75 * 1024;
	private static $elasticsearchType = "item_fulltext";
	public static $metadata = ['indexedChars', 'totalChars', 'indexedPages', 'totalPages'];
	
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
		$fields = ["itemID", "libraryID", "version", "timestamp"];
		$params = [$item->id, $libraryID, $version, $timestamp];
		$sql .= implode(", ", $fields) . ") VALUES ("
			. implode(', ', array_fill(0, sizeOf($params), '?')) . ")";
		Zotero_DB::query($sql, $params, Zotero_Shards::getByLibraryID($libraryID));
		
		// Add to S3
		$json = [
			'libraryID' => $libraryID,
			'key' => $key,
			'version' => $version,
			'content' => (string) $data->content,
			'timestamp' => str_replace(" ", "T", $timestamp)
		];
		
		foreach (self::$metadata as $prop) {
			if (isset($data->$prop)) {
				$json[$prop] = (int)$data->$prop;
			}
		}
		
		$json = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$json = gzencode($json);
		
		$s3Client = Z_Core::$AWS->createS3();
		$start = microtime(true);
		$s3Client->putObject([
			'Bucket' => Z_CONFIG::$S3_BUCKET_FULLTEXT,
			'Key' => $libraryID . "/" . $key,
			'Body' => $json,
			'ContentType' => 'application/gzip',
			'StorageClass' => strlen($json) < self::$minFileSizeStandardIA ? 'STANDARD' : 'STANDARD_IA'
		]);
		StatsD::timing("s3.fulltext.put", (microtime(true) - $start) * 1000);
		
		Zotero_DB::commit();
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
						"Item $libraryID/$jsonObject->key not found in library",
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
				Z_Core::debug($e->getMessage());

				Zotero_DB::rollback();
				
				// If item key given, include that
				$resultKey = isset($jsonObject->key) ? $jsonObject->key : '';
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
	 * Get item full-text data from S3 by libraryID and key
	 *
	 * @param {$libraryID}
	 * @param {$key}
	 * @return {object|null}
	 */
	public static function getItemData($libraryID, $key) {
		$s3Client = Z_Core::$AWS->createS3();
		
		try {
			$start = microtime(true);
			$result = $s3Client->getObject([
				'Bucket' => Z_CONFIG::$S3_BUCKET_FULLTEXT,
				'Key' => $libraryID . "/" . $key
			]);
			StatsD::timing("s3.fulltext.get", (microtime(true) - $start) * 1000);
		}
		catch (Aws\S3\Exception\S3Exception $e) {
			if ($e->getAwsErrorCode() == 'NoSuchKey') {
				return null;
			}
			throw $e;
		}
		
		$json = $result['Body'];
		
		if ($result['ContentType'] == 'application/gzip') {
			$json = gzdecode($json);
		}
		
		$json = json_decode($json);
		
		$itemData = [
			"libraryID" => $libraryID,
			"key" => $key,
			"content" => $json->content,
			"version" => $json->version
		];
		if (isset($json->language)) {
			$itemData['language'] = $json->language;
		}
		foreach (self::$metadata as $prop) {
			$itemData[$prop] = isset($json->$prop) ? $json->$prop : 0;
		}
		return $itemData;
	}
	
	/**
	 * @return {Object} An object with item keys for keys and full-text content versions for values
	 */
	public static function getNewerInLibrary($libraryID, $version) {
		$sql = "SELECT `key`, IFT.version FROM itemFulltext IFT JOIN items I USING (itemID) "
			. "WHERE IFT.libraryID=? AND IFT.version>?";
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
	 * @param {Integer} libraryID
	 * @param {String} searchText
	 * @return {Array<String>|null} An array of item keys, or null if no results
	 */
	public static function searchInLibrary($libraryID, $searchText) {
		$params = [
			'index' => self::$elasticsearchType . "_index",
			'type' => self::$elasticsearchType,
			'routing' => $libraryID,
			'size' => 150,
			"body" => [
				'query' => [
					'bool' => [
						'must' => [
							'match_phrase_prefix' => [
								'content' => $searchText
							]
						],
						'filter' => [
							'term' => [
								'libraryID' => $libraryID
							]
						]
					]
				]
			]
		];
		
		$start = microtime(true);
		$resp = Z_Core::$ES->search($params);
		StatsD::timing("elasticsearch.client.item_fulltext.search", (microtime(true) - $start) * 1000);
		
		$results = $resp['hits']['hits'];
		
		$keys = [];
		foreach ($results as $result) {
			$keys[] = explode("/", $result['_id'])[1];
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
		
		// Delete from S3
		$s3Client = Z_Core::$AWS->createS3();
		$start = microtime(true);
		$s3Client->deleteObject([
			'Bucket' => Z_CONFIG::$S3_BUCKET_FULLTEXT,
			'Key' => $libraryID . '/' . $key
		]);
		StatsD::timing("s3.fulltext.delete", (microtime(true) - $start) * 1000);
		
		Zotero_DB::commit();
	}
	
	public static function deleteByLibrary($libraryID) {
		Zotero_DB::beginTransaction();
		
		// Delete from MySQL
		self::deleteByLibraryMySQL($libraryID);
		
		// Delete from S3
		$s3Client = Z_Core::$AWS->createS3();
		$start = microtime(true);
		// Potentially slow, because internally it lists objects and then deletes by batches of 1000
		$s3Client->deleteMatchingObjects(Z_CONFIG::$S3_BUCKET_FULLTEXT, $libraryID . '/');
		StatsD::timing("s3.fulltext.bulk_delete", (microtime(true) - $start) * 1000);
		
		Zotero_DB::commit();
	}
	
	public static function deleteByLibraryMySQL($libraryID) {
		$sql = "DELETE FROM itemFulltext WHERE libraryID=?";
		Zotero_DB::query(
			$sql, $libraryID, Zotero_Shards::getByLibraryID($libraryID)
		);
	}
}
