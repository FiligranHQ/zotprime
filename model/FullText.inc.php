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
	private static $elasticSearchType = "item_fulltext";
	private static $metadata = array('indexedChars', 'totalChars', 'indexedPages', 'totalPages');
	
	public static function indexItem(Zotero_Item $item, $content, $language='en', $stats=array()) {
		$index = self::getIndex();
		$type = self::getType();
		
		if (!$item->isAttachment() ||
				Zotero_Attachments::linkModeNumberToName($item->attachmentLinkMode) == 'LINKED_URL') {
			throw new Exception(
				"Full-text content can only be added for file attachments", Z_ERROR_INVALID_INPUT
			);
		}
		
		$id = $item->libraryID . "/" . $item->key;
		$version = Zotero_Libraries::getUpdatedVersion($item->libraryID);
		$timestamp = Zotero_DB::transactionInProgress()
			? Zotero_DB::getTransactionTimestamp() : date("Y-m-d H:i:s");
		$doc = array(
			'id' => $id,
			'libraryID' => $item->libraryID,
			'fulltext' => (string) $content,
			'language' => $language,
			// We don't seem to be able to search on _version, so we duplicate it here
			'version' => $version,
			// Add "T" between date and time for Elasticsearch
			'timestamp' => str_replace(" ", "T", $timestamp)
		);
		if ($stats) {
			foreach (self::$metadata as $prop) {
				if (isset($stats[$prop])) {
					$doc[$prop] = (int) $stats[$prop];
				}
			}
		}
		$doc = new \Elastica\Document($id, $doc, self::$elasticSearchType);
		$doc->setVersion($version);
		$doc->setVersionType('external');
		$doc->setRefresh(true);
		$response = $type->addDocument($doc);
		if ($response->hasError()) {
			throw new Exception($response->getError());
		}
	}
	
	
	public static function getItemData(Zotero_Item $item) {
		$index = self::getIndex();
		$type = self::getType();
		
		$id = $item->libraryID . "/" . $item->key;
		try {
			$document = $type->getDocument($id);
			
		}
		catch (\Elastica\Exception\NotFoundException $e) {
			return false;
		}
		
		$data = $document->getData();
		
		$itemData = array(
			"content" => $data['fulltext'],
			"version" => $data['version'],
		);
		if (isset($data['language'])) {
			$itemData['language'] = $data['language'];
		}
		foreach (self::$metadata as $prop) {
			$itemData[$prop] = isset($data[$prop]) ? $data[$prop] : 0;
		}
		return $itemData;
	}
	
	
	public static function getNewerInLibrary($libraryID, $version) {
		$index = self::getIndex();
		$type = self::getType();
		
		$libraryFilter = new \Elastica\Filter\Term();
		$libraryFilter->setTerm("libraryID", $libraryID);
		$versionFilter = new \Elastica\Filter\NumericRange(
			'version', array('gt' => $version)
		);
		
		$andFilter = new \Elastica\Filter\BoolAnd();
		$andFilter->addFilter($libraryFilter);
		$andFilter->addFilter($versionFilter);
		
		$query = new \Elastica\Query();
		$query->setFilter($andFilter);
		$query->setFields(array('version'));
		$resultSet = $type->search($query);
		if ($resultSet->getResponse()->hasError()) {
			throw new Exception($resultSet->getResponse()->getError());
		}
		$results = $resultSet->getResults();
		$keys = array();
		foreach ($results as $result) {
			list($libraryID, $key) = explode("/", $result->getId());
			$keys[$key] = $result->version;
		}
		return $keys;
	}
	
	
	public static function getNewerInLibraryByTime($libraryID, $timestamp, $keys=[]) {
		$index = self::getIndex();
		$type = self::getType();
		
		$libraryFilter = new \Elastica\Filter\Term();
		$libraryFilter->setTerm("libraryID", $libraryID);
		
		$timeFilter = new \Elastica\Filter\Range(
			// Add "T" between date and time for Elasticsearch
			'timestamp', array('gte' => str_replace(" ", "T", date("Y-m-d H:i:s", $timestamp)))
		);
		
		if ($keys) {
			$keysFilter = new \Elastica\Filter\Ids();
			$keysFilter->setIds(array_map(function ($key) use ($libraryID) {
				return $libraryID . "/" . $key;
			}, $keys));
			
			$secondFilter = new \Elastica\Filter\BoolOr();
			$secondFilter->addFilter($timeFilter);
			$secondFilter->addFilter($keysFilter);
		}
		else {
			$secondFilter = $timeFilter;
		}
		
		$andFilter = new \Elastica\Filter\BoolAnd();
		$andFilter->addFilter($libraryFilter);
		$andFilter->addFilter($secondFilter);
		
		$query = new \Elastica\Query();
		$query->setFilter($andFilter);
		//error_log(json_encode($query->toArray()));
		$resultSet = $type->search($query);
		if ($resultSet->getResponse()->hasError()) {
			throw new Exception($resultSet->getResponse()->getError());
		}
		$results = $resultSet->getResults();
		$data = array();
		foreach ($results as $result) {
			$data[] = $result->getData();
		}
		//error_log(json_encode($data));
		return $data;
	}
	
	
	public static function deleteItemContent(Zotero_Item $item) {
		$index = self::getIndex();
		$type = self::getType();
		
		try {
			$response = $type->deleteById($item->libraryID . "/" . $item->key);
		}
		catch (Elastica\Exception\NotFoundException $e) {
			return false;
		}
		catch (Exception $e) {
			throw $e;
		}
		if ($response->hasError()) {
			throw new Exception($response->getError());
		}
		return true;
	}
	
	
	public static function deleteByLibrary($libraryID) {
		$index = self::getIndex();
		$type = self::getType();
		
		$libraryQuery = new \Elastica\Query\Term();
		$libraryQuery->setTerm("libraryID", $libraryID);
		$query = new \Elastica\Query($libraryQuery);
		$response = $type->deleteByQuery($query);
		
		if ($response->hasError()) {
			throw new Exception($response->getError());
		}
		return true;
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
		self::indexItem($item, $xml->textContent, false, $stats);
	}
	
	
	/**
	 * @param {Array} $data  Item data from Elasticsearch
	 * @param {DOMDocument} $doc
	 * @param {Boolean} [$empty=false]  If true, don't include full-text content
	 */
	public static function itemDataToXML($data, DOMDocument $doc, $empty=false) {
		list($libraryID, $key) = explode("/", $data['id']);
		$xmlNode = $doc->createElement('fulltext');
		$xmlNode->setAttribute('libraryID', $libraryID);
		$xmlNode->setAttribute('key', $key);
		foreach (self::$metadata as $prop) {
			$xmlNode->setAttribute($prop, $data[$prop]);
		}
		$xmlNode->setAttribute('version', $data['version']);
		if (!$empty) {
			$xmlNode->appendChild($doc->createTextNode($data['fulltext']));
		}
		return $xmlNode;
	}
	
	
	private static function getIndex() {
		return Z_Core::$Elastica->getIndex(self::$elasticSearchType . "_index");
	}
	
	
	private static function getType() {
		return new \Elastica\Type(self::getIndex(), self::$elasticSearchType);
	}
}
