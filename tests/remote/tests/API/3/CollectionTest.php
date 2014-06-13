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

class CollectionTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testNewCollection() {
		$name = "Test Collection";
		$json = API::createCollection($name, false, $this, 'json');
		$this->assertEquals($name, (string) $json['data']['name']);
		return $json['key'];
	}
	
	
	/**
	 * @depends testNewCollection
	 */
	public function testNewSubcollection($parent) {
		$name = "Test Subcollection";
		
		$json = API::createCollection($name, $parent, $this, 'json');
		$this->assertEquals($name, (string) $json['data']['name']);
		$this->assertEquals($parent, (string) $json['data']['parentCollection']);
		
		$response = API::userGet(
			self::$config['userID'],
			"collections/$parent"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals(1, (int) $json['meta']['numCollections']);
	}
	
	
	public function testNewMultipleCollections() {
		$json = API::createCollection("Test Collection 1", false, $this, 'jsonData');
		
		$name1 = "Test Collection 2";
		$name2 = "Test Subcollection";
		$parent2 = $json['key'];
		
		$json = [
			[
				'name' => $name1
			],
			[
				'name' => $name2,
				'parentCollection' => $parent2
			]
		];
		
		$response = API::userPost(
			self::$config['userID'],
			"collections",
			json_encode($json),
			array("Content-Type: application/json")
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertCount(2, $json['success']);
		
		$response = API::getCollectionResponse($json['success']);
		$this->assertTotalResults(2, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($name1, $json[0]['data']['name']);
		$this->assertFalse($json[0]['data']['parentCollection']);
		$this->assertEquals($name2, $json[1]['data']['name']);
		$this->assertEquals($parent2, $json[1]['data']['parentCollection']);
	}
	
	
	public function testEditMultipleCollections() {
		$key1 = API::createCollection("Test 1", false, $this, 'key');
		$data = API::createCollection("Test 2", false, $this, 'jsonData');
		$key2 = $data['key'];
		
		$newName1 = "Test 1 Modified";
		$newName2 = "Test 2 Modified";
		$response = API::userPost(
			self::$config['userID'],
			"collections",
			json_encode([
				[
					'key' => $key1,
					'name' => $newName1
				],
				[
					'key' => $key2,
					'name' => $newName2
				]
			]),
			[
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: " . $data['version']
			]
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertCount(2, $json['success']);
		
		$response = API::getCollectionResponse($json['success']);
		$this->assertTotalResults(2, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($newName1, $json[0]['data']['name']);
		$this->assertFalse($json[0]['data']['parentCollection']);
		$this->assertEquals($newName2, $json[1]['data']['name']);
		$this->assertFalse($json[1]['data']['parentCollection']);
	}
	
	
	public function testCollectionItemChange() {
		$collectionKey1 = API::createCollection('Test', false, $this, 'key');
		$collectionKey2 = API::createCollection('Test', false, $this, 'key');
		
		$json = API::createItem("book", array(
			'collections' => array($collectionKey1)
		), $this, 'json');
		$itemKey1 = $json['key'];
		$itemVersion1 = $json['version'];
		$this->assertEquals([$collectionKey1], $json['data']['collections']);
		
		$json = API::createItem("journalArticle", array(
			'collections' => array($collectionKey2)
		), $this, 'json');
		$itemKey2 = $json['key'];
		$itemVersion2 = $json['version'];
		$this->assertEquals([$collectionKey2], $json['data']['collections']);
		
		$json = API::getCollection($collectionKey1, $this);
		$this->assertEquals(1, $json['meta']['numItems']);
		
		$json = API::getCollection($collectionKey2, $this);
		$this->assertEquals(1, $json['meta']['numItems']);
		$collectionData2 = $json['data'];
		
		$libraryVersion = API::getLibraryVersion();
		
		// Add items to collection
		$response = API::userPatch(
			self::$config['userID'],
			"items/$itemKey1",
			json_encode(array(
				"collections" => array($collectionKey1, $collectionKey2)
			)),
			array(
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: $itemVersion1"
			)
		);
		$this->assert204($response);
		
		// Item version should change
		$json = API::getItem($itemKey1, $this);
		$this->assertEquals($libraryVersion + 1, $json['version']);
		
		// Collection timestamp shouldn't change, but numItems should
		$json = API::getCollection($collectionKey2, $this);
		$this->assertEquals(2, $json['meta']['numItems']);
		$this->assertEquals($collectionData2['version'], $json['version']);
		$collectionData2 = $json['data'];
		
		$libraryVersion = API::getLibraryVersion();
		
		// Remove collections
		$response = API::userPatch(
			self::$config['userID'],
			"items/$itemKey2",
			json_encode(array(
				"collections" => array()
			)),
			array(
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: $itemVersion2"
			)
		);
		$this->assert204($response);
		
		// Item version should change
		$json = API::getItem($itemKey2, $this);
		$this->assertEquals($libraryVersion + 1, $json['version']);
		
		// Collection timestamp shouldn't change, but numItems should
		$json = API::getCollection($collectionKey2, $this);
		$this->assertEquals(1, $json['meta']['numItems']);
		$this->assertEquals($collectionData2['version'], $json['version']);
		
		// Check collections arrays and numItems
		$json = API::getItem($itemKey1, $this);
		$this->assertCount(2, $json['data']['collections']);
		$this->assertContains($collectionKey1, $json['data']['collections']);
		$this->assertContains($collectionKey2, $json['data']['collections']);
		
		$json = API::getItem($itemKey2, $this);
		$this->assertCount(0, $json['data']['collections']);
		
		$json = API::getCollection($collectionKey1, $this);
		$this->assertEquals(1, $json['meta']['numItems']);
		
		$json = API::getCollection($collectionKey2, $this);
		$this->assertEquals(1, $json['meta']['numItems']);
	}
	
	
	public function testCollectionChildItemError() {
		$collectionKey = API::createCollection('Test', false, $this, 'key');
		
		$key = API::createItem("book", array(), $this, 'key');
		$json = API::createNoteItem("Test Note", $key, $this, 'jsonData');
		$json['collections'] = [$collectionKey];
		
		$response = API::userPut(
			self::$config['userID'],
			"items/{$json['key']}",
			json_encode($json),
			array(
				"Content-Type: application/json"
			)
		);
		$this->assert400($response);
		$this->assertEquals("Child items cannot be assigned to collections", $response->getBody());
	}
	
	
	public function testCollectionItems() {
		$collectionKey = API::createCollection('Test', false, $this, 'key');
		
		$json = API::createItem("book", ['collections' => [$collectionKey]], $this, 'jsonData');
		$itemKey1 = $json['key'];
		$itemVersion1 = $json['version'];
		$this->assertEquals([$collectionKey], $json['collections']);
		
		$json = API::createItem("journalArticle", ['collections' => [$collectionKey]], $this, 'jsonData');
		$itemKey2 = $json['key'];
		$itemVersion2 = $json['version'];
		$this->assertEquals([$collectionKey], $json['collections']);
		
		$childItemKey1 = API::createAttachmentItem("linked_url", [], $itemKey1, $this, 'key');
		$childItemKey2 = API::createAttachmentItem("linked_url", [], $itemKey2, $this, 'key');
		
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items?format=keys"
		);
		$this->assert200($response);
		$keys = explode("\n", trim($response->getBody()));
		$this->assertCount(4, $keys);
		$this->assertContains($itemKey1, $keys);
		$this->assertContains($itemKey2, $keys);
		$this->assertContains($childItemKey1, $keys);
		$this->assertContains($childItemKey2, $keys);
		
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top?format=keys"
		);
		$this->assert200($response);
		$keys = explode("\n", trim($response->getBody()));
		$this->assertCount(2, $keys);
		$this->assertContains($itemKey1, $keys);
		$this->assertContains($itemKey2, $keys);
	}
}
?>
