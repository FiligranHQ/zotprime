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
	
	public static function indexItem(Zotero_Item $item, $content, $language='en') {
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
		$doc = array(
			'id' => $id,
			'libraryID' => $item->libraryID,
			'fulltext' => (string) $content,
			'language' => $language,
			// We don't seem to be able to search on _version, so we duplicate it here
			'version' => $version
		);
		$doc = new \Elastica\Document($id, $doc, self::$elasticSearchType);
		$doc->setVersion($version);
		$doc->setVersionType('external');
		$response = $type->addDocument($doc);
		if ($response->hasError()) {
			throw new Exception($response->getError());
		}
		$index->refresh();
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
			"version" => $data['version']
		);
		if (isset($data['language'])) {
			$itemData['language'] = $data['language'];
		}
		return $itemData;
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
	
	
	private static function getIndex() {
		return Z_Core::$Elastica->getIndex(Z_CONFIG::$SEARCH_INDEX);
	}
	
	
	private static function getType() {
		return new \Elastica\Type(self::getIndex(), self::$elasticSearchType);
	}
}
