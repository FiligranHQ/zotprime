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

namespace APIv3;
use API3 as API;
require_once 'APITests.inc.php';
require_once 'include/api3.inc.php';

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
	
	
	public function setUp() {
		parent::setUp();
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
			"$objectTypePlural?format=keys"
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
			"$objectTypePlural?format=keys&order=title"
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
		
		$jsonArray = array();
		
		switch ($objectType) {
		case 'collection':
			$jsonArray[] = API::createCollection("Name", false, $this, 'jsonData');
			$jsonArray[] = API::createCollection("Name", false, $this, 'jsonData');
			break;
		
		case 'item':
			$jsonArray[] = API::createItem("book", false, $this, 'jsonData');
			$jsonArray[] = API::createItem("book", false, $this, 'jsonData');
			break;
		
		case 'search':
			$jsonArray[] = API::createSearch(
				"Name",
				array(
					array(
						"condition" => "title",
						"operator" => "contains",
						"value" => "test"
					)
				),
				$this,
				'jsonData'
			);
			$jsonArray[] = API::createSearch(
				"Name",
				array(
					array(
						"condition" => "title",
						"operator" => "contains",
						"value" => "test"
					)
				),
				$this,
				'jsonData'
			);
			break;
		}
		
		$keys = [];
		foreach ($jsonArray as $json) {
			$keys[] = $json['key'];
		}
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?{$objectType}Key={$keys[0]}"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$this->assertTotalResults(1, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($keys[0], $json[0]['key']);
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?{$objectType}Key={$keys[0]},{$keys[1]}&order={$objectType}KeyList"
		);
		$this->assert200($response);
		$this->assertNumResults(2, $response);
		$this->assertTotalResults(2, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($keys[0], $json[0]['key']);
		$this->assertEquals($keys[1], $json[1]['key']);
	}
	
	
	public function testPagination() {
		self::_testPagination('collection');
		self::_testPagination('group');
		self::_testPagination('item');
		self::_testPagination('search');
		self::_testPagination('tag');
	}
	
	
	private function _testPagination($objectType) {
		API::userClear(self::$config['userID']);
		
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		$limit = 2;
		$totalResults = 5;
		$formats = ['json', 'atom', 'keys'];
		
		switch ($objectType) {
		case 'collection':                                                                                         
			for ($i=0; $i<$totalResults; $i++) {
				API::createCollection("Test", false, $this, 'key');
			}
			break;
		
		case 'item':
			//
			// Items
			//
			for ($i=0; $i<$totalResults; $i++) {
				API::createItem("book", false, $this, 'key');
			}
			break;
		
		//
		// Searches
		//
		case 'search':
			for ($i=0; $i<$totalResults; $i++) {
				API::createSearch("Test", 'default', $this, 'key');
			}
			break;
		
		case 'tag':
			API::createItem("book", [
				'tags' => [
					'a',
					'b'
				]
			], $this);
			API::createItem("book", [
				'tags' => [
					'c',
					'd',
					'e'
				]
			], $this);
			$formats = array_filter($formats, function ($val) { return !in_array($val, ['keys']); });
			break;
		
		case 'group':
			// Change if the config changes
			$limit = 1;
			$totalResults = 2;
			$formats = array_filter($formats, function ($val) { return !in_array($val, ['keys']); });
			break;
		}
		
		foreach ($formats as $format) {
			$response = API::userGet(
				self::$config['userID'],
				"$objectTypePlural?limit=$limit&format=$format"
			);
			$this->assert200($response);
			$this->assertNumResults($limit, $response);
			$this->assertTotalResults($totalResults, $response);
			$links = $this->parseLinkHeader($response->getHeader('Link'));
			$this->assertArrayNotHasKey('first', $links);
			$this->assertArrayNotHasKey('prev', $links);
			$this->assertArrayHasKey('next', $links);
			$this->assertEquals($limit, $links['next']['params']['start']);
			$this->assertEquals($limit, $links['next']['params']['limit']);
			$this->assertArrayHasKey('last', $links);
			$lastStart = $totalResults - ($totalResults % $limit);
			if ($lastStart == $totalResults) {
				$lastStart -= $limit;
			}
			$this->assertEquals($lastStart, $links['last']['params']['start']);
			$this->assertEquals($limit, $links['last']['params']['limit']);
		}
	}
	
	
	// Test disabled because it's slow
	/*public function testPaginationWithItemKey() {
		$totalResults = 27;
		
		for ($i=0; $i<$totalResults; $i++) {
			API::createItem("book", false, $this, 'key');
		}
		
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys&limit=50"
		);
		$keys = explode("\n", trim($response->getBody()));
		
		$response = API::userGet(
			self::$config['userID'],
			"items?format=json&itemKey=" . join(",", $keys)
		);
		$json = API::getJSONFromResponse($response);;
		$this->assertCount($totalResults, $json);
	}*/
	
	
	public function testCollectionQuickSearch() {
		$title1 = "Test Title";
		$title2 = "Another Title";
		
		$keys = [];
		$keys[] = API::createCollection($title1, [], $this, 'key');
		$keys[] = API::createCollection($title2, [], $this, 'key');
		
		// Search by title
		$response = API::userGet(
			self::$config['userID'],
			"collections?q=another"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($keys[1], $json[0]['key']);
		
		// No results
		$response = API::userGet(
			self::$config['userID'],
			"collections?q=nothing"
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
			"items?q=" . urlencode($title1)
		);
		$this->assert200($response);                                                                         
		$this->assertNumResults(1, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($keys[0], $json[0]['key']);
		
		// TODO: Search by creator
		
		// Search by year
		$response = API::userGet(
			self::$config['userID'],
			"items?q=$year2"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($keys[1], $json[0]['key']);
		
		// Search by year + 1
		$response = API::userGet(
			self::$config['userID'],
			"items?q=" . ($year2 + 1)
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
			"items?q=" . urlencode($title1)
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($keys[0], $json[0]['key']);
		
		// Search by both by title, date asc
		$response = API::userGet(
			self::$config['userID'],
			"items?q=title&sort=date&direction=asc"
		);
		$this->assert200($response);
		$this->assertNumResults(2, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($keys[1], $json[0]['key']);
		$this->assertEquals($keys[0], $json[1]['key']);
		
		// Search by both by title, date asc, with old-style parameters
		$response = API::userGet(
			self::$config['userID'],
			"items?q=title&order=date&sort=asc"
		);
		$this->assert200($response);
		$this->assertNumResults(2, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($keys[1], $json[0]['key']);
		$this->assertEquals($keys[0], $json[1]['key']);
		
		// Search by both by title, date desc
		$response = API::userGet(
			self::$config['userID'],
			"items?q=title&sort=date&direction=desc"
		);
		$this->assert200($response);
		$this->assertNumResults(2, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($keys[0], $json[0]['key']);
		$this->assertEquals($keys[1], $json[1]['key']);
		
		// Search by both by title, date desc, with old-style parameters
		$response = API::userGet(
			self::$config['userID'],
			"items?q=title&order=date&sort=desc"
		);
		$this->assert200($response);
		$this->assertNumResults(2, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($keys[0], $json[0]['key']);
		$this->assertEquals($keys[1], $json[1]['key']);
	}
	
	
	private function parseLinkHeader($links) {
		$this->assertNotNull($links);
		$links = explode(',', $links);
		$parsedLinks = [];
		foreach  ($links as $link) {
			list($uri, $rel) = explode('; ', trim($link));
			$this->assertRegExp('/^<https?:\/\/[^ ]+>$/', $uri);
			$this->assertRegExp('/^rel="[a-z]+"$/', $rel);
			$uri = substr($uri, 1, -1);
			$rel = substr($rel, strlen('rel="'), -1);
			
			parse_str(parse_url($uri, PHP_URL_QUERY), $params);
			$parsedLinks[$rel] = [
				'uri' => $uri,
				'params' => $params
			];
		}
		return $parsedLinks;
	}
}
