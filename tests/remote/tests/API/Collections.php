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

class ItemTests extends APITests {
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
		$this->assert201($response);
		
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
		$this->assert201($response);
		
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
		
		$this->assert201($response);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		$this->assertEquals($name, (string) $json->name);
	}
}

?>
