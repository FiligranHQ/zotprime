<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2012 Center for History and New Media
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

require_once 'APITests.inc.php';
require_once 'include/api.inc.php';

class ParamsTests extends APITests {
	private static $collectionKeys = array();
	private static $itemKeys = array();
	private static $searchKeys = array();
	
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testFormatKeys() {
		//
		// Collections
		//
		for ($i=0; $i<5; $i++) {
			self::$collectionKeys[] = API::createCollection("Test", false, null, 'key');
		}
		
		//
		// Items
		//
		for ($i=0; $i<5; $i++) {
			self::$itemKeys[] = API::createItem("book", false, null, 'key');
		}
		self::$itemKeys[] = API::createAttachmentItem("imported_file", [], false, null, 'key');
		
		//
		// Searches
		//
		for ($i=0; $i<5; $i++) {
			self::$searchKeys[] = API::createSearch("Test", 'default', null, 'key');
		}
		
		$this->_testFormatKeys('collection');
		$this->_testFormatKeys('item');
		$this->_testFormatKeys('search');
		
		$this->_testFormatKeysSorted('collection');
		$this->_testFormatKeysSorted('item');
		$this->_testFormatKeysSorted('search');
	}
	
	
	private function _testFormatKeys($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		$keysVar = $objectType . "Keys";
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&format=keys"
		);
		$this->assert200($response);
		
		$keys = explode("\n", trim($response->getBody()));
		sort($keys);
		$this->assertEmpty(
			array_merge(
				array_diff(self::$$keysVar, $keys), array_diff($keys, self::$$keysVar)
			)
		);
	}
	
	
	private function _testFormatKeysSorted($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		$keysVar = $objectType . "Keys";
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&format=keys&order=title"
		);
		$this->assert200($response);
		
		$keys = explode("\n", trim($response->getBody()));
		sort($keys);
		$this->assertEmpty(
			array_merge(
				array_diff(self::$$keysVar, $keys), array_diff($keys, self::$$keysVar)
			)
		);
	}
	
	
	public function testObjectKeyParameter() {
		$this->_testObjectKeyParameter('collection');
		$this->_testObjectKeyParameter('item');
		$this->_testObjectKeyParameter('search');
	}
	
	
	private function _testObjectKeyParameter($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		$xmlArray = array();
		
		switch ($objectType) {
		case 'collection':
			$xmlArray[] = API::createCollection("Name", false, $this);
			$xmlArray[] = API::createCollection("Name", false, $this);
			break;
		
		case 'item':
			$xmlArray[] = API::createItem("book", false, $this);
			$xmlArray[] = API::createItem("book", false, $this);
			break;
		
		case 'search':
			$xmlArray[] = API::createSearch(
				"Name",
				array(
					array(
						"condition" => "title",
						"operator" => "contains",
						"value" => "test"
					)
				),
				$this
			);
			$xmlArray[] = API::createSearch(
				"Name",
				array(
					array(
						"condition" => "title",
						"operator" => "contains",
						"value" => "test"
					)
				),
				$this
			);
			break;
		}
		
		$keys = array();
		foreach ($xmlArray as $xml) {
			$data = API::parseDataFromAtomEntry($xml);
			$keys[] = $data['key'];
		}
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&content=json&{$objectType}Key={$keys[0]}"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$this->assertEquals($keys[0], $data['key']);
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&content=json&{$objectType}Key={$keys[0]},{$keys[1]}&order={$objectType}KeyList"
		);
		$this->assert200($response);
		$this->assertNumResults(2, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$key = (string) array_shift($xpath);
		$this->assertEquals($keys[0], $key);
		$key = (string) array_shift($xpath);
		$this->assertEquals($keys[1], $key);
	}
	
	
	public function testCollectionQuickSearch() {
		$title1 = "Test Title";
		$title2 = "Another Title";
		
		$keys = [];
		$keys[] = API::createCollection($title1, [], $this, 'key');
		$keys[] = API::createCollection($title2, [], $this, 'key');
		
		// Search by title
		$response = API::userGet(
			self::$config['userID'],
			"collections?key=" . self::$config['apiKey'] . "&content=json&q=another"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$key = (string) array_shift($xpath);
		$this->assertEquals($keys[1], $key);
		
		// No results
		$response = API::userGet(
			self::$config['userID'],
			"collections?key=" . self::$config['apiKey'] . "&content=json&q=nothing"
		);
		$this->assert200($response);
		$this->assertNumResults(0, $response);
	}
	
	
	public function testItemQuickSearch() {
		$title1 = "Test Title";
		$title2 = "Another Title";
		$year2 = "2013";
		
		$keys = [];
		$keys[] = API::createItem("book", [
			'title' => $title1
		], $this, 'key');
		$keys[] = API::createItem("journalArticle", [
			'title' => $title2,
			'date' => "November 25, $year2"
		], $this, 'key');
		
		// Search by title
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'] . "&content=json&q=" . urlencode($title1)
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$key = (string) array_shift($xpath);
		$this->assertEquals($keys[0], $key);
		
		// TODO: Search by creator
		
		// Search by year
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'] . "&content=json&q=$year2"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$key = (string) array_shift($xpath);
		$this->assertEquals($keys[1], $key);
		
		// Search by year + 1
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'] . "&content=json&q=" . ($year2 + 1)
		);
		$this->assert200($response);
		$this->assertNumResults(0, $response);
	}
	
	
	public function testItemQuickSearchOrderByDate() {
		$title1 = "Test Title";
		$title2 = "Another Title";
		
		$keys = [];
		$keys[] = API::createItem("book", [
			'title' => $title1,
			'date' => "February 12, 2013"
		], $this, 'key');
		$keys[] = API::createItem("journalArticle", [
			'title' => $title2,
			'date' => "November 25, 2012"
		], $this, 'key');
		
		// Search for one by title
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'] . "&content=json&q=" . urlencode($title1)
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$key = (string) array_shift($xpath);
		$this->assertEquals($keys[0], $key);
		
		// Search by both by title, date asc
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'] . "&content=json&q=title&order=date&sort=asc"
		);
		$this->assert200($response);
		$this->assertNumResults(2, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$key = (string) array_shift($xpath);
		$this->assertEquals($keys[1], $key);
		$key = (string) array_shift($xpath);
		$this->assertEquals($keys[0], $key);
		
		// Search by both by title, date desc
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'] . "&content=json&q=title&order=date&sort=desc"
		);
		$this->assert200($response);
		$this->assertNumResults(2, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$key = (string) array_shift($xpath);
		$this->assertEquals($keys[0], $key);
		$key = (string) array_shift($xpath);
		$this->assertEquals($keys[1], $key);
	}
}
