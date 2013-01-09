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
		
		$xml = API::createCollection($name, false, $this);
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
		
		$xml = API::createCollection($name, $parent, $this);
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		$this->assertEquals($name, (string) $json->name);
		$this->assertEquals($parent, (string) $json->parent);
		
		$response = API::userGet(
			self::$config['userID'],
			"collections/$parent?key=" . self::$config['apiKey']
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:entry/zapi:numCollections')));
	}
	
	
	public function testNewSingleCollection() {
		$name = "Test Collection";
		
		$json = array(
			'name' => $name,
			'parent' => false
		);
		
		$response = API::userPost(
			self::$config['userID'],
			"collections?key=" . self::$config['apiKey'],
			json_encode($json),
			array("Content-Type: application/json")
		);
		$this->assert200($response);
		
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		$this->assertEquals(0, (int) array_shift($xml->xpath('/atom:feed/zapi:numCollections')));
		
		$data = API::parseDataFromAtomEntry($xml);
		
		$json = json_decode($data['content']);
		$this->assertEquals($name, (string) $json->name);
		
		return $data;
	}
	
	
	/**
	 * @depends testNewSingleCollection
	 */
	public function testNewSingleSubcollection($data) {
		$name = "Test Subcollection";
		$parent = $data['key'];
		
		$json = array(
			'name' => $name,
			'parent' => $parent
		);
		
		$response = API::userPost(
			self::$config['userID'],
			"collections?key=" . self::$config['apiKey'],
			json_encode($json),
			array("Content-Type: application/json")
		);
		$this->assert200($response);
		
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		
		$data = API::parseDataFromAtomEntry($xml);
		
		$json = json_decode($data['content']);
		$this->assertEquals($name, (string) $json->name);
		$this->assertEquals($parent, (string) $json->parent);
		
		$response = API::userGet(
			self::$config['userID'],
			"collections/$parent?key=" . self::$config['apiKey']
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:entry/zapi:numCollections')));
	}
	
	
	public function testNewSingleCollectionWithoutParentProperty() {
		$name = "Test Collection";
		
		$json = array(
			'name' => $name
		);
		
		$response = API::userPost(
			self::$config['userID'],
			"collections?key=" . self::$config['apiKey'],
			json_encode($json),
			array("Content-Type: application/json")
		);
		
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		$this->assertEquals($name, (string) $json->name);
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
					'parent' => $parent2
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
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(2, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		
		$contents = $xml->xpath('/atom:feed/atom:entry/atom:content');
		$content = json_decode(array_shift($contents));
		$this->assertEquals($name1, $content->name);
		$this->assertFalse($content->parent);
		$content = json_decode(array_shift($contents));
		$this->assertEquals($name2, $content->name);
		$this->assertEquals($parent2, $content->parent);
	}
	
	
	public function testEditMultipleCollections() {
		$xml = API::createCollection("Test 1", false, $this);
		$data = API::parseDataFromAtomEntry($xml);
		$key1 = $data['key'];
		$xml = API::createCollection("Test 2", false, $this);
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
				"Zotero-If-Unmodified-Since-Version: " . $data['version']
			)
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(2, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		
		$contents = $xml->xpath('/atom:feed/atom:entry/atom:content');
		$content = json_decode(array_shift($contents));
		$this->assertEquals($newName1, $content->name);
		$this->assertFalse($content->parent);
		$content = json_decode(array_shift($contents));
		$this->assertEquals($newName2, $content->name);
		$this->assertFalse($content->parent);
	}
}
?>
