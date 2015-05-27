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

namespace APIv3;
use API3 as API;
require_once 'APITests.inc.php';
require_once 'include/api3.inc.php';

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
	
	public function testCreateByPut() {
		$this->_testCreateByPut('collection');
		$this->_testCreateByPut('item');
		$this->_testCreateByPut('search');
	}
	
	private function _testCreateByPut($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		$json = API::createUnsavedDataObject($objectType);
		require_once '../../model/ID.inc.php';
		$key = \Zotero_ID::getKey();
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/$key",
			json_encode($json),
			[
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: 0"
			]
		);
		$this->assert204($response);
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
		$objectKeys['item'][] = API::createItem("book", array("title" => "Title"), $this, 'key');
		$objectKeys['search'][] = API::createSearch("Name", 'default', $this, 'key');
		$objectKeys['search'][] = API::createSearch("Name", 'default', $this, 'key');
		$objectKeys['search'][] = API::createSearch("Name", 'default', $this, 'key');
		
		// Get library version
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'] . "&format=keys&limit=1"
		);
		$libraryVersion1 = $response->getHeader("Last-Modified-Version");
		
		// Delete first object
		$config = self::$config;
		$func = function ($objectType, $libraryVersion) use ($config, $self, $objectKeys) {
			$objectTypePlural = API::getPluralObjectType($objectType);
			$keyProp = $objectType . "Key";
			$response = API::userDelete(
				$config['userID'],
				"$objectTypePlural?key=" . $config['apiKey']
					. "&$keyProp=" . $objectKeys[$objectType][0],
				array("If-Unmodified-Since-Version: " . $libraryVersion)
			);
			$self->assert204($response);
			return $response->getHeader("Last-Modified-Version");
		};
		$tempLibraryVersion = $func('collection', $libraryVersion1);
		$tempLibraryVersion = $func('item', $tempLibraryVersion);
		$tempLibraryVersion = $func('search', $tempLibraryVersion);
		$libraryVersion2 = $tempLibraryVersion;
		
		// Delete second and third objects
		$func = function ($objectType, $libraryVersion) use ($config, $self, $objectKeys) {
			$objectTypePlural = API::getPluralObjectType($objectType);
			$keyProp = $objectType . "Key";
			$response = API::userDelete(
				$config['userID'],
				"$objectTypePlural?key=" . $config['apiKey']
					. "&$keyProp=" . implode(',', array_slice($objectKeys[$objectType], 1)),
				array("If-Unmodified-Since-Version: " . $libraryVersion)
			);
			$self->assert204($response);
			return $response->getHeader("Last-Modified-Version");
		};
		$tempLibraryVersion = $func('collection', $tempLibraryVersion);
		$tempLibraryVersion = $func('item', $tempLibraryVersion);
		$libraryVersion3 = $func('search', $tempLibraryVersion);
		
		
		// Request all deleted objects
		$response = API::userGet(
			self::$config['userID'],
			"deleted?key=" . self::$config['apiKey'] . "&since=$libraryVersion1"
		);
		$this->assert200($response);
		$json = json_decode($response->getBody(), true);
		$version = $response->getHeader("Last-Modified-Version");
		$this->assertNotNull($version);
		$this->assertContentType("application/json", $response);
		
		// Make sure 'newer' is equivalent
		$responseNewer = API::userGet(
			self::$config['userID'],
			"deleted?key=" . self::$config['apiKey'] . "&newer=$libraryVersion1"
		);
		$this->assertEquals($response->getStatus(), $responseNewer->getStatus());
		$this->assertEquals($response->getBody(), $responseNewer->getBody());
		$this->assertEquals($response->getHeader('Last-Modified-Version'), $responseNewer->getHeader('Last-Modified-Version'));
		$this->assertEquals($response->getHeader('Content-Type'), $responseNewer->getHeader('Content-Type'));
		
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
		$func($json, 'tag', []);
		
		
		// Request second and third deleted objects
		$response = API::userGet(
			self::$config['userID'],
			"deleted?key=" . self::$config['apiKey'] . "&newer=$libraryVersion2"
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
		$func($json, 'collection', array_slice($objectKeys['collection'], 1));
		$func($json, 'item', array_slice($objectKeys['item'], 1));
		$func($json, 'search', array_slice($objectKeys['search'], 1));
		// Tags aren't deleted by removing from items
		$func($json, 'tag', []);
		
		
		// Explicit tag deletion
		$response = API::userDelete(
			self::$config['userID'],
			"tags?key=" . self::$config['apiKey']
			. "&tag=" . implode('%20||%20', $objectKeys['tag']),
			array("If-Unmodified-Since-Version: " . $libraryVersion3)
		);
		$self->assert204($response);
		
		// Verify deleted tags
		$response = API::userGet(
			self::$config['userID'],
			"deleted?key=" . self::$config['apiKey'] . "&newer=$libraryVersion3"
		);
		$this->assert200($response);
		$json = json_decode($response->getBody(), true);
		$func($json, 'tag', $objectKeys['tag']);
	}
	
	
	public function testEmptyVersionsResponse() {
		$this->_testEmptyVersionsResponse('collection');
		$this->_testEmptyVersionsResponse('item');
		$this->_testEmptyVersionsResponse('search');
	}
	
	
	private function _testEmptyVersionsResponse($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		$keyProp = $objectType . "Key";
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?format=versions&$keyProp=NNNNNNNN"
		);
		$this->assert200($response);
		$json = json_decode($response->getBody());
		$this->assertInternalType('object', $json);
		$this->assertCount(0, get_object_vars($json));
	}
	
	
	public function testResponseJSONPost() {
		$this->_testResponseJSONPost('collection');
		$this->_testResponseJSONPost('item');
		$this->_testResponseJSONPost('search');
	}
	
	
	public function testResponseJSONPut() {
		$this->_testResponseJSONPut('collection');
		$this->_testResponseJSONPut('item');
		$this->_testResponseJSONPut('search');
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
		
		// HEAD request should include Total-Results
		$response = API::userHead(
			self::$config['userID'],
			"$objectTypePlural?$keyProp=" . implode(',', $keys)
		);
		$this->assert200($response);
		$this->assertTotalResults(sizeOf($keys), $response);
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?$keyProp=" . implode(',', $keys)
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($keys), $response);
		
		// Trailing comma in itemKey parameter
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?$keyProp=" . implode(',', $keys) . ","
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($keys), $response);
	}
	
	
	private function _testSingleObjectDelete($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		switch ($objectType) {
		case 'collection':
			$json = API::createCollection("Name", false, $this, 'json');
			break;
		
		case 'item':
			$json = API::createItem("book", array("title" => "Title"), $this, 'json');
			break;
		
		case 'search':
			$json = API::createSearch("Name", 'default', $this, 'json');
			break;
		}
		
		$objectKey = $json['key'];
		$objectVersion = $json['version'];
		
		$response = API::userDelete(
			self::$config['userID'],
			"$objectTypePlural/$objectKey",
			array(
				"If-Unmodified-Since-Version: " . $objectVersion
			)
		);
		$this->assert204($response);
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural/$objectKey"
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
			"$objectTypePlural"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($deleteKeys) + sizeOf($keepKeys), $response);
		$libraryVersion = $response->getHeader("Last-Modified-Version");
		
		$response = API::userDelete(
			self::$config['userID'],
			"$objectTypePlural?$keyProp=" . implode(',', $deleteKeys),
			array(
				"If-Unmodified-Since-Version: " . $libraryVersion
			)
		);
		$this->assert204($response);
		$libraryVersion = $response->getHeader("Last-Modified-Version");
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($keepKeys), $response);
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?$keyProp=" . implode(',', $keepKeys)
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($keepKeys), $response);
		
		// Add trailing comma to itemKey param, to test key parsing
		$response = API::userDelete(
			self::$config['userID'],
			"$objectTypePlural?$keyProp=" . implode(',', $keepKeys) . ",",
			array(
				"If-Unmodified-Since-Version: " . $libraryVersion
			)
		);
		$this->assert204($response);
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural"
		);
		$this->assert200($response);
		$this->assertNumResults(0, $response);
	}
	
	
	private function _testResponseJSONPost($objectType) {
		API::userClear(self::$config['userID']);
		
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		switch ($objectType) {
		case 'collection':
			$json1 = ["name" => "Test 1"];
			$json2 = ["name" => "Test 2"];
			break;
		
		case 'item':
			$json1 = API::getItemTemplate('book');
			$json2 = clone $json1;
			$json1->title = "Test 1";
			$json2->title = "Test 2";
			break;
		
		case 'search':
			$conditions = array(
				array(
					'condition' => 'title',
					'operator' => 'contains',
					'value' => 'value'
				)
			);
			$json1 = ["name" => "Test 1", "conditions" => $conditions];
			$json2 = ["name" => "Test 2", "conditions" => $conditions];
			break;
		}
		
		$response = API::userPost(
			self::$config['userID'],
			$objectTypePlural,
			json_encode([$json1, $json2]),
			array("Content-Type: application/json")
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assert200ForObject($response, false, 0);
		$this->assert200ForObject($response, false, 1);
		
		$response = API::userGet(
			self::$config['userID'],
			$objectTypePlural
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		
		switch ($objectType) {
		case 'item':
			$json[0]['data']['title'] = $json[0]['data']['title'] == "Test 1" ? "Test A" : "Test B";
			$json[1]['data']['title'] = $json[1]['data']['title'] == "Test 2" ? "Test B" : "Test A";
			break;
		
		case 'collection':
		case 'search':
			$json[0]['data']['name'] = $json[0]['data']['name'] == "Test 1" ? "Test A" : "Test B";
			$json[1]['data']['name'] = $json[1]['data']['name'] == "Test 2" ? "Test B" : "Test A";
			break;
		}
		
		$response = API::userPost(
			self::$config['userID'],
			$objectTypePlural,
			json_encode($json),
			array("Content-Type: application/json")
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assert200ForObject($response, false, 0);
		$this->assert200ForObject($response, false, 1);
		
		// Check
		$response = API::userGet(
			self::$config['userID'],
			$objectTypePlural
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		
		switch ($objectTypePlural) {
		case 'item':
			$this->assertEquals("Test A", $json[0]['data']['title']);
			$this->assertEquals("Test B", $json[1]['data']['title']);
			break;
		
		case 'collection':
		case 'search':
			$this->assertEquals("Test A", $json[0]['data']['name']);
			$this->assertEquals("Test B", $json[1]['data']['name']);
			break;
		}
	}
	
	
	private function _testResponseJSONPut($objectType) {
		API::userClear(self::$config['userID']);
		
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		switch ($objectType) {
		case 'collection':
			$json1 = ["name" => "Test 1"];
			break;
		
		case 'item':
			$json1 = API::getItemTemplate('book');
			$json1->title = "Test 1";
			break;
		
		case 'search':
			$conditions = array(
				array(
					'condition' => 'title',
					'operator' => 'contains',
					'value' => 'value'
				)
			);
			$json1 = ["name" => "Test 1", "conditions" => $conditions];
			break;
		}
		
		$response = API::userPost(
			self::$config['userID'],
			$objectTypePlural,
			json_encode([$json1]),
			array("Content-Type: application/json")
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assert200ForObject($response, false, 0);
		$objectKey = $json['success'][0];
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural/$objectKey"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		
		switch ($objectType) {
		case 'item':
			$json['data']['title'] = "Test 2";
			break;
		
		case 'collection':
		case 'search':
			$json['data']['name'] = "Test 2";
			break;
		}
		
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/$objectKey",
			json_encode($json),
			array("Content-Type: application/json")
		);
		$this->assert204($response);
		
		// Check
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural/$objectKey"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		
		switch ($objectTypePlural) {
		case 'item':
			$this->assertEquals("Test 2", $json['data']['title']);
			break;
		
		case 'collection':
		case 'search':
			$this->assertEquals("Test 2", $json['data']['name']);
			break;
		}
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
			"$objectTypePlural",
			json_encode([$json1, $json2, $json3]),
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
			$json1 = API::createCollection("Test", false, $this, 'jsonData');
			$json2 = array("name" => str_repeat("1234567890", 6554));
			$json3 = array("name" => "Test");
			break;
		
		case 'item':
			$json1 = API::createItem("book", array("title" => "Title"), $this, 'jsonData');
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
			$json1 = API::createSearch("Name", $conditions, $this, 'jsonData');
			$json2 = array("name" => str_repeat("1234567890", 6554), "conditions" => $conditions);
			$json3 = array("name" => "Test", "conditions" => $conditions);
			break;
		}
		
		$response = API::userPost(
			self::$config['userID'],
			"$objectTypePlural",
			json_encode([$json1, $json2, $json3]),
			array("Content-Type: application/json")
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertUnchangedForObject($response, 0);
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
			"$objectTypePlural",
			json_encode(["foo" => "bar"]),
			array("Content-Type: application/json")
		);
		$this->assert400($response, "Uploaded data must be a JSON array");
		
		$response = API::userPost(
			self::$config['userID'],
			"$objectTypePlural",
			json_encode([[], ""]),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject($response, "Invalid value for index 0 in uploaded data; expected JSON $objectType object");
		$this->assert400ForObject($response, "Invalid value for index 1 in uploaded data; expected JSON $objectType object", 1);
	}
}
