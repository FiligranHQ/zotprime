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

namespace APIv2;
use API2 as API;
require_once 'APITests.inc.php';
require_once 'include/api2.inc.php';

class ObjectTests extends APITests {
	public function setUp() {
		parent::setUp();
		API::userClear(self::$config['userID']);
	}
	
	public function tearDown() {
		API::userClear(self::$config['userID']);
	}
	
	
	public function testMultiObjectGet() {
		$this->_testMultiObjectGet('collection');
		$this->_testMultiObjectGet('item');
		$this->_testMultiObjectGet('search');
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
	
	
	public function testDeleted() {
		$self = $this;
		
		API::userClear(self::$config['userID']);
		
		//
		// Create objects
		//
		$objectKeys = array();
		$objectKeys['tag'] = array("foo", "bar");
		
		$objectKeys['collection'][] = API::createCollection("Name", false, $this, 'key');
		$objectKeys['collection'][] = API::createCollection("Name", false, $this, 'key');
		$objectKeys['item'][] = API::createItem(
			"book",
			array(
				"title" => "Title",
				"tags" => array_map(function ($tag) {
					return array("tag" => $tag);
				}, $objectKeys['tag'])
			),
			$this,
			'key'
		);
		$objectKeys['item'][] = API::createItem("book", array("title" => "Title"), $this, 'key');
		$objectKeys['search'][] = API::createSearch("Name", 'default', $this, 'key');
		$objectKeys['search'][] = API::createSearch("Name", 'default', $this, 'key');
		
		// Get library version
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'] . "&format=keys&limit=1"
		);
		$libraryVersion = $response->getHeader("Last-Modified-Version");
		
		// Delete objects
		$config = self::$config;
		$func = function ($objectType, $libraryVersion) use ($config, $self, $objectKeys) {
			$objectTypePlural = API::getPluralObjectType($objectType);
			$keyProp = $objectType . "Key";
			$response = API::userDelete(
				$config['userID'],
				"$objectTypePlural?key=" . $config['apiKey']
					. "&$keyProp=" . implode(',', $objectKeys[$objectType]),
				array("If-Unmodified-Since-Version: " . $libraryVersion)
			);
			$self->assert204($response);
			return $response->getHeader("Last-Modified-Version");
		};
		$newLibraryVersion = $func('collection', $libraryVersion);
		$newLibraryVersion = $func('item', $newLibraryVersion);
		$newLibraryVersion = $func('search', $newLibraryVersion);
		
		// Request deleted objects
		$response = API::userGet(
			self::$config['userID'],
			"deleted?key=" . self::$config['apiKey'] . "&newer=$libraryVersion"
		);
		$this->assert200($response);
		$json = json_decode($response->getBody(), true);
		$version = $response->getHeader("Last-Modified-Version");
		$this->assertNotNull($version);
		$this->assertContentType("application/json", $response);
		
		// Verify keys
		$func = function ($json, $objectType, $objectKeys) use ($self) {
			$objectTypePlural = API::getPluralObjectType($objectType);
			$self->assertArrayHasKey($objectTypePlural, $json);
			$self->assertCount(sizeOf($objectKeys), $json[$objectTypePlural]);
			foreach ($objectKeys as $key) {
				$self->assertContains($key, $json[$objectTypePlural]);
			}
		};
		$func($json, 'collection', $objectKeys['collection']);
		$func($json, 'item', $objectKeys['item']);
		$func($json, 'search', $objectKeys['search']);
		// Tags aren't deleted by removing from items
		$func($json, 'tag', array());
		
		// Explicit tag deletion
		$response = API::userDelete(
			self::$config['userID'],
			"tags?key=" . self::$config['apiKey']
			. "&tag=" . implode('%20||%20', $objectKeys['tag']),
			array("If-Unmodified-Since-Version: " . $newLibraryVersion)
		);
		$self->assert204($response);
		
		// Verify deleted tags
		$response = API::userGet(
			self::$config['userID'],
			"deleted?key=" . self::$config['apiKey'] . "&newer=$libraryVersion"
		);
		$this->assert200($response);
		$json = json_decode($response->getBody(), true);
		$func($json, 'tag', $objectKeys['tag']);
	}
	
	
	public function testPartialWriteFailure() {
		$this->_testPartialWriteFailure('collection');
		$this->_testPartialWriteFailure('item');
		$this->_testPartialWriteFailure('search');
	}
	
	
	public function testPartialWriteFailureWithUnchanged() {
		$this->_testPartialWriteFailureWithUnchanged('collection');
		$this->_testPartialWriteFailureWithUnchanged('item');
		$this->_testPartialWriteFailureWithUnchanged('search');
	}
	
	
	public function testMultiObjectWriteInvalidObject() {
		$this->_testMultiObjectWriteInvalidObject('collection');
		$this->_testMultiObjectWriteInvalidObject('item');
		$this->_testMultiObjectWriteInvalidObject('search');
	}
	
	
	private function _testMultiObjectGet($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		$keyProp = $objectType . "Key";
		
		$keys = [];
		switch ($objectType) {
		case 'collection':
			$keys[] = API::createCollection("Name", false, $this, 'key');
			$keys[] = API::createCollection("Name", false, $this, 'key');
			API::createCollection("Name", false, $this, 'key');
			break;
		
		case 'item':
			$keys[] = API::createItem("book", array("title" => "Title"), $this, 'key');
			$keys[] = API::createItem("book", array("title" => "Title"), $this, 'key');
			API::createItem("book", array("title" => "Title"), $this, 'key');
			break;
		
		case 'search':
			$keys[] = API::createSearch("Name", 'default', $this, 'key');
			$keys[] = API::createSearch("Name", 'default', $this, 'key');
			API::createSearch("Name", 'default', $this, 'key');
			break;
		}
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&$keyProp=" . implode(',', $keys)
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($keys), $response);
		
		// Trailing comma in itemKey parameter
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&$keyProp=" . implode(',', $keys) . ","
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($keys), $response);
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
				"If-Unmodified-Since-Version: " . $objectVersion
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
		$libraryVersion = $response->getHeader("Last-Modified-Version");
		
		$response = API::userDelete(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&$keyProp=" . implode(',', $deleteKeys),
			array(
				"If-Unmodified-Since-Version: " . $libraryVersion
			)
		);
		$this->assert204($response);
		$libraryVersion = $response->getHeader("Last-Modified-Version");
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
		
		// Add trailing comma to itemKey param, to test key parsing
		$response = API::userDelete(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&$keyProp=" . implode(',', $keepKeys) . ",",
			array(
				"If-Unmodified-Since-Version: " . $libraryVersion
			)
		);
		$this->assert204($response);
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
		);
		$this->assert200($response);
		$this->assertNumResults(0, $response);
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
	
	
	private function _testPartialWriteFailureWithUnchanged($objectType) {
		API::userClear(self::$config['userID']);
		
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		switch ($objectType) {
		case 'collection':
			$data = API::createCollection("Test", false, $this, 'data');
			$json1 = json_decode($data['content']);
			$json2 = array("name" => str_repeat("1234567890", 6554));
			$json3 = array("name" => "Test");
			break;
		
		case 'item':
			$data = API::createItem("book", array("title" => "Title"), $this, 'data');
			$json1 = json_decode($data['content']);
			$json2 = API::getItemTemplate('book');
			$json3 = clone $json2;
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
			$data = API::createSearch("Name", $conditions, $this, 'data');
			$json1 = json_decode($data['content']);
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
		$this->assertUnchangedForObject($response, false, 0);
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
	
	
	private function _testMultiObjectWriteInvalidObject($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		$response = API::userPost(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'],
			json_encode([[]]),
			array("Content-Type: application/json")
		);
		$this->assert400($response, "Uploaded data must be a JSON object");
		
		$response = API::userPost(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'],
			json_encode(array(
				"$objectTypePlural" => array(
					"foo" => "bar"
				)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400($response, "'$objectTypePlural' must be an array");
	}
}
