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

require_once 'APITests.inc.php';
require_once 'include/api.inc.php';

class ObjectTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testSingleObjectDelete() {
		$this->_testSingleObjectDelete('collection');
		$this->_testSingleObjectDelete('item');
		$this->_testSingleObjectDelete('search');
	}
	
	
	public function testMultiObjectDelete() {
		$this->_testMultiObjectDelete('collection');
		$this->_testMultiObjectDelete('item');
		$this->_testMultiObjectDelete('search');
	}
	
	
	public function testPartialWriteFailure() {
		$this->_testPartialWriteFailure('collection');
		$this->_testPartialWriteFailure('item');
		$this->_testPartialWriteFailure('search');
	}
	
	
	private function _testSingleObjectDelete($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		switch ($objectType) {
		case 'collection':
			$xml = API::createCollection("Name", false, $this);
			break;
		
		case 'item':
			$xml = API::createItem("book", array("title" => "Title"), $this);
			break;
		
		case 'search':
			$xml = API::createSearch("Name", 'default', $this);
			break;
		}
		
		$data = API::parseDataFromAtomEntry($xml);
		$objectKey = $data['key'];
		$objectVersion = $data['version'];
		
		$response = API::userDelete(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey'],
			array(
				"Zotero-If-Unmodified-Since-Version: " . $objectVersion
			)
		);
		$this->assert204($response);
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey']
		);
		$this->assert404($response);
	}
	
	
	private function _testMultiObjectDelete($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		$keyProp = $objectType . "Key";
		
		$deleteKeys = array();
		$keepKeys = array();
		switch ($objectType) {
		case 'collection':
			$deleteKeys[] = API::createCollection("Name", false, $this, 'key');
			$deleteKeys[] = API::createCollection("Name", false, $this, 'key');
			$keepKeys[] = API::createCollection("Name", false, $this, 'key');
			break;
		
		case 'item':
			$deleteKeys[] = API::createItem("book", array("title" => "Title"), $this, 'key');
			$deleteKeys[] = API::createItem("book", array("title" => "Title"), $this, 'key');
			$keepKeys[] = API::createItem("book", array("title" => "Title"), $this, 'key');
			break;
		
		case 'search':
			$deleteKeys[] = API::createSearch("Name", 'default', $this, 'key');
			$deleteKeys[] = API::createSearch("Name", 'default', $this, 'key');
			$keepKeys[] = API::createSearch("Name", 'default', $this, 'key');
			break;
		}
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($deleteKeys) + sizeOf($keepKeys), $response);
		$libraryVersion = $response->getHeader("Zotero-Last-Modified-Version");
		
		$response = API::userDelete(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&$keyProp=" . implode(',', $deleteKeys),
			array(
				"Zotero-If-Unmodified-Since-Version: " . $libraryVersion
			)
		);
		$this->assert204($response);
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($keepKeys), $response);
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&$keyProp=" . implode(',', $keepKeys)
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($keepKeys), $response);
	}
	
	
	private function _testPartialWriteFailure($objectType) {
		API::userClear(self::$config['userID']);
		
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		switch ($objectType) {
		case 'collection':
			$json1 = array("name" => "Test");
			$json2 = array("name" => str_repeat("1234567890", 6554));
			$json3 = array("name" => "Test");
			break;
		
		case 'item':
			$json1 = API::getItemTemplate('book');
			$json2 = clone $json1;
			$json3 = clone $json1;
			$json2->title = str_repeat("1234567890", 6554);
			break;
		
		case 'search':
			$conditions = array(
				array(
					'condition' => 'title',
					'operator' => 'contains',
					'value' => 'value'
				)
			);
			$json1 = array("name" => "Test", "conditions" => $conditions);
			$json2 = array("name" => str_repeat("1234567890", 6554), "conditions" => $conditions);
			$json3 = array("name" => "Test", "conditions" => $conditions);
			break;
		}
		
		$response = API::userPost(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'],
			json_encode(array(
				"$objectTypePlural" => array($json1, $json2, $json3)
			)),
			array("Content-Type: application/json")
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assert200ForObject($response, false, 0);
		$this->assert400ForObject($response, false, 1);
		$this->assert200ForObject($response, false, 2);
		$json = API::getJSONFromResponse($response);
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?format=keys&key=" . self::$config['apiKey']
		);
		$this->assert200($response);
		$keys = explode("\n", trim($response->getBody()));
		$this->assertCount(2, $keys);
		foreach ($json['success'] as $key) {
			$this->assertContains($key, $keys);
		}
	}
}
