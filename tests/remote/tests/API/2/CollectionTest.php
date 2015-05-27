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

namespace APIv2;
use API2 as API, stdClass;
require_once 'APITests.inc.php';
require_once 'include/api2.inc.php';

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
		
		$xml = API::createCollection($name, false, $this, 'atom');
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		
		$data = API::parseDataFromAtomEntry($xml);
		
		$json = json_decode($data['content']);
		$this->assertEquals($name, (string) $json->name);
		
		return $data;
	}
	
	
	/**
	 * @depends testNewCollection
	 */
	public function testNewSubcollection($data) {
		$name = "Test Subcollection";
		$parent = $data['key'];
		
		$xml = API::createCollection($name, $parent, $this, 'atom');
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		$this->assertEquals($name, (string) $json->name);
		$this->assertEquals($parent, (string) $json->parentCollection);
		
		$response = API::userGet(
			self::$config['userID'],
			"collections/$parent?key=" . self::$config['apiKey']
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:entry/zapi:numCollections')));
	}
	
	
	public function testNewMultipleCollections() {
		$xml = API::createCollection("Test Collection 1", false, $this);
		$data = API::parseDataFromAtomEntry($xml);
		
		$name1 = "Test Collection 2";
		$name2 = "Test Subcollection";
		$parent2 = $data['key'];
		
		$json = array(
			"collections" => array(
				array(
					'name' => $name1
				),
				array(
					'name' => $name2,
					'parentCollection' => $parent2
				)
			)
		);
		
		$response = API::userPost(
			self::$config['userID'],
			"collections?key=" . self::$config['apiKey'],
			json_encode($json),
			array("Content-Type: application/json")
		);
		
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertCount(2, $json['success']);
		$xml = API::getCollectionXML($json['success']);
		$this->assertEquals(2, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		
		$contents = $xml->xpath('/atom:feed/atom:entry/atom:content');
		$content = json_decode(array_shift($contents));
		$this->assertEquals($name1, $content->name);
		$this->assertFalse($content->parentCollection);
		$content = json_decode(array_shift($contents));
		$this->assertEquals($name2, $content->name);
		$this->assertEquals($parent2, $content->parentCollection);
	}
	
	
	public function testEditMultipleCollections() {
		$xml = API::createCollection("Test 1", false, $this, 'atom');
		$data = API::parseDataFromAtomEntry($xml);
		$key1 = $data['key'];
		$xml = API::createCollection("Test 2", false, $this, 'atom');
		$data = API::parseDataFromAtomEntry($xml);
		$key2 = $data['key'];
		
		$newName1 = "Test 1 Modified";
		$newName2 = "Test 2 Modified";
		$response = API::userPost(
			self::$config['userID'],
			"collections?key=" . self::$config['apiKey'],
			json_encode(array(
				"collections" => array(
					array(
						'collectionKey' => $key1,
						'name' => $newName1
					),
					array(
						'collectionKey' => $key2,
						'name' => $newName2
					)
				)
			)),
			array(
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: " . $data['version']
			)
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertCount(2, $json['success']);
		$xml = API::getCollectionXML($json['success']);
		$this->assertEquals(2, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		
		$contents = $xml->xpath('/atom:feed/atom:entry/atom:content');
		$content = json_decode(array_shift($contents));
		$this->assertEquals($newName1, $content->name);
		$this->assertFalse($content->parentCollection);
		$content = json_decode(array_shift($contents));
		$this->assertEquals($newName2, $content->name);
		$this->assertFalse($content->parentCollection);
	}
	
	
	public function testCollectionItemChange() {
		$collectionKey1 = API::createCollection('Test', false, $this, 'key');
		$collectionKey2 = API::createCollection('Test', false, $this, 'key');
		
		$xml = API::createItem("book", array(
			'collections' => array($collectionKey1)
		), $this, 'atom');
		$data = API::parseDataFromAtomEntry($xml);
		$itemKey1 = $data['key'];
		$itemVersion1 = $data['version'];
		$json = json_decode($data['content']);
		$this->assertEquals(array($collectionKey1), $json->collections);
		
		$xml = API::createItem("journalArticle", array(
			'collections' => array($collectionKey2)
		), $this, 'atom');
		$data = API::parseDataFromAtomEntry($xml);
		$itemKey2 = $data['key'];
		$itemVersion2 = $data['version'];
		$json = json_decode($data['content']);
		$this->assertEquals(array($collectionKey2), $json->collections);
		
		$xml = API::getCollectionXML($collectionKey1);
		$collectionData1 = API::parseDataFromAtomEntry($xml);
		$this->assertEquals(1, (int) array_shift($xml->xpath('//atom:entry/zapi:numItems')));
		
		$xml = API::getCollectionXML($collectionKey2);
		$collectionData2 = API::parseDataFromAtomEntry($xml);
		$this->assertEquals(1, (int) array_shift($xml->xpath('//atom:entry/zapi:numItems')));
		
		$libraryVersion = API::getLibraryVersion();
		
		// Add items to collection
		$response = API::userPatch(
			self::$config['userID'],
			"items/$itemKey1?key=" . self::$config['apiKey'],
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
		$xml = API::getItemXML($itemKey1);
		$data = API::parseDataFromAtomEntry($xml);
		$this->assertEquals($libraryVersion + 1, $data['version']);
		
		// Collection timestamp shouldn't change, but numItems should
		$xml = API::getCollectionXML($collectionKey2);
		$data = API::parseDataFromAtomEntry($xml);
		$this->assertEquals(2, (int) array_shift($xml->xpath('//atom:entry/zapi:numItems')));
		$this->assertEquals($collectionData2['version'], $data['version']);
		$collectionData2 = $data;
		
		$libraryVersion = API::getLibraryVersion();
		
		// Remove collections
		$response = API::userPatch(
			self::$config['userID'],
			"items/$itemKey2?key=" . self::$config['apiKey'],
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
		$xml = API::getItemXML($itemKey2);
		$data = API::parseDataFromAtomEntry($xml);
		$this->assertEquals($libraryVersion + 1, $data['version']);
		
		// Collection timestamp shouldn't change, but numItems should
		$xml = API::getCollectionXML($collectionKey2);
		$data = API::parseDataFromAtomEntry($xml);
		$this->assertEquals(1, (int) array_shift($xml->xpath('//atom:entry/zapi:numItems')));
		$this->assertEquals($collectionData2['version'], $data['version']);
		
		// Check collections arrays and numItems
		$xml = API::getItemXML($itemKey1);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		$this->assertCount(2, $json->collections);
		$this->assertContains($collectionKey1, $json->collections);
		$this->assertContains($collectionKey2, $json->collections);
		
		$xml = API::getItemXML($itemKey2);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		$this->assertCount(0, $json->collections);
		
		$xml = API::getCollectionXML($collectionKey1);
		$this->assertEquals(1, (int) array_shift($xml->xpath('//atom:entry/zapi:numItems')));
		
		$xml = API::getCollectionXML($collectionKey2);
		$this->assertEquals(1, (int) array_shift($xml->xpath('//atom:entry/zapi:numItems')));
	}
	
	
	public function testCollectionChildItemError() {
		$collectionKey = API::createCollection('Test', false, $this, 'key');
		
		$key = API::createItem("book", array(), $this, 'key');
		$xml = API::createNoteItem("Test Note", $key, $this, 'atom');
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$json['collections'] = array($collectionKey);
		$json['relations'] = new stdClass;
		
		$libraryVersion = API::getLibraryVersion();
		
		$response = API::userPut(
			self::$config['userID'],
			"items/{$data['key']}?key=" . self::$config['apiKey'],
			json_encode($json),
			[
				"Content-Type: application/json"
			]
		);
		$this->assert400($response);
		$this->assertEquals("Child items cannot be assigned to collections", $response->getBody());
	}
	
	
	public function testCollectionItems() {
		$collectionKey = API::createCollection('Test', false, $this, 'key');
		
		$xml = API::createItem("book", array('collections' => array($collectionKey)), $this);
		$data = API::parseDataFromAtomEntry($xml);
		$itemKey1 = $data['key'];
		$itemVersion1 = $data['version'];
		$json = json_decode($data['content']);
		$this->assertEquals(array($collectionKey), $json->collections);
		
		$xml = API::createItem("journalArticle", array('collections' => array($collectionKey)), $this);
		$data = API::parseDataFromAtomEntry($xml);
		$itemKey2 = $data['key'];
		$itemVersion2 = $data['version'];
		$json = json_decode($data['content']);
		$this->assertEquals(array($collectionKey), $json->collections);
		
		$childItemKey1 = API::createAttachmentItem("linked_url", [], $itemKey1, $this, 'key');
		$childItemKey2 = API::createAttachmentItem("linked_url", [], $itemKey2, $this, 'key');
		
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items?key=" . self::$config['apiKey'] . "&format=keys"
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
			"collections/$collectionKey/items/top?key=" . self::$config['apiKey'] . "&format=keys"
		);
		$this->assert200($response);
		$keys = explode("\n", trim($response->getBody()));
		$this->assertCount(2, $keys);
		$this->assertContains($itemKey1, $keys);
		$this->assertContains($itemKey2, $keys);
	}
}
?>
