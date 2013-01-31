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

class VersionTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	public function setUp() {
		API::userClear(self::$config['userID']);
	}
	
	
	public function testSingleObjectLastModifiedVersion() {
		$this->_testSingleObjectLastModifiedVersion('collection');
		$this->_testSingleObjectLastModifiedVersion('item');
		$this->_testSingleObjectLastModifiedVersion('search');
	}
	
	
	public function testMultiObjectLastModifiedVersion() {
		$this->_testMultiObjectLastModifiedVersion('collection');
		$this->_testMultiObjectLastModifiedVersion('item');
		$this->_testMultiObjectLastModifiedVersion('search');
	}
	
	
	public function testMultiObject304NotModified() {
		$this->_testMultiObject304NotModified('collection');
		$this->_testMultiObject304NotModified('item');
		$this->_testMultiObject304NotModified('search');
	}
	
	
	public function testNewerAndVersionsFormat() {
		$this->_testNewerAndVersionsFormat('collection');
		$this->_testNewerAndVersionsFormat('item');
		$this->_testNewerAndVersionsFormat('search');
	}
	
	
	public function testUploadUnmodified() {
		$this->_testUploadUnmodified('collection');
		$this->_testUploadUnmodified('item');
		$this->_testUploadUnmodified('search');
	}
	
	
	public function testNewerTags() {
		$tags1 = array("a", "aa", "b");
		$tags2 = array("b", "c", "cc");
		
		$data1 = API::createItem("book", array(
			"tags" => array_map(function ($tag) {
				return array("tag" => $tag);
			}, $tags1)
		), $this, 'data');
		
		$data2 = API::createItem("book", array(
			"tags" => array_map(function ($tag) {
				return array("tag" => $tag);
			}, $tags2)
		), $this, 'data');
		
		// Only newly added tags should be included in newer,
		// not previously added tags or tags added to items
		$response = API::userGet(
			self::$config['userID'],
			"tags?key=" . self::$config['apiKey']
				. "&newer=" . $data1['version']
		);
		$this->assertNumResults(2, $response);
		
		// Deleting an item shouldn't update associated tag versions
		$response = API::userDelete(
			self::$config['userID'],
			"items/{$data1['key']}?key=" . self::$config['apiKey'],
			array(
				"Zotero-If-Unmodified-Since-Version: " . $data1['version']
			)
		);
		$this->assert204($response);
		
		$response = API::userGet(
			self::$config['userID'],
			"tags?key=" . self::$config['apiKey']
				. "&newer=" . $data1['version']
		);
		$this->assertNumResults(2, $response);
		$libraryVersion = $response->getHeader("Zotero-Last-Modified-Version");
		
		$response = API::userGet(
			self::$config['userID'],
			"tags?key=" . self::$config['apiKey']
				. "&newer=" . $libraryVersion
		);
		$this->assertNumResults(0, $response);
	}
	
	
	private function _testSingleObjectLastModifiedVersion($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		$keyProp = $objectType . "Key";
		$versionProp = $objectType . "Version";
		
		switch ($objectType) {
		case 'collection':
			$objectKey = API::createCollection("Name", false, $this, 'key');
			break;
		
		case 'item':
			$objectKey = API::createItem("book", array("title" => "Title"), $this, 'key');
			break;
		
		case 'search':
			$objectKey = API::createSearch(
				"Name",
				array(
					array(
						"condition" => "title",
						"operator" => "contains",
						"value" => "test"
					)
				),
				$this,
				'key'
			);
			break;
		}
		
		// Make sure all three instances of the object version
		// (Zotero-Last-Modified-Version, zapi:version, and the JSON
		// {$objectType}Version property match the library version
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey'] . "&content=json"
		);
		$this->assert200($response);
		$objectVersion = $response->getHeader("Zotero-Last-Modified-Version");
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		$this->assertEquals($objectVersion, $json->$versionProp);
		$this->assertEquals($objectVersion, $data['version']);
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'] . "&limit=1"
		);
		$this->assert200($response);
		$libraryVersion = $response->getHeader("Zotero-Last-Modified-Version");
		
		$this->assertEquals($libraryVersion, $objectVersion);
		
		$this->_modifyJSONObject($objectType, $json);
		
		// No Zotero-If-Unmodified-Since-Version or JSON version property
		unset($json->$versionProp);
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey'],
			json_encode($json)
		);
		$this->assert428($response);
		
		// Out of date version
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey'],
			json_encode($json),
			array(
				"Zotero-If-Unmodified-Since-Version: " . ($objectVersion - 1)
			)
		);
		$this->assert412($response);
		
		// Update with version header
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey'],
			json_encode($json),
			array(
				"Zotero-If-Unmodified-Since-Version: " . $objectVersion
			)
		);
		$this->assert204($response);
		$newObjectVersion = $response->getHeader("Zotero-Last-Modified-Version");
		$this->assertGreaterThan($objectVersion, $newObjectVersion);
		
		// Update object with JSON version property
		$this->_modifyJSONObject($objectType, $json);
		$json->$versionProp = $newObjectVersion;
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey'],
			json_encode($json)
		);
		$this->assert204($response);
		$newObjectVersion2 = $response->getHeader("Zotero-Last-Modified-Version");
		$this->assertGreaterThan($newObjectVersion, $newObjectVersion2);
		
		// Make sure new library version matches new object version
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'] . "&limit=1"
		);
		$this->assert200($response);
		$newLibraryVersion = $response->getHeader("Zotero-Last-Modified-Version");
		$this->assertEquals($newLibraryVersion, $newObjectVersion2);
		
		// Create an item to increase the library version, and make sure
		// original object version stays the same
		API::createItem("book", array("title" => "Title"), $this, 'key');
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey'] . "&limit=1"
		);
		$this->assert200($response);
		$newObjectVersion2 = $response->getHeader("Zotero-Last-Modified-Version");
		$this->assertEquals($newLibraryVersion, $newObjectVersion2);
		
		//
		// Delete object
		//
		
		// No Zotero-If-Unmodified-Since-Version
		$response = API::userDelete(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey']
		);
		$this->assert428($response);
		
		// Outdated Zotero-If-Unmodified-Since-Version
		$response = API::userDelete(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey'],
			array(
				"Zotero-If-Unmodified-Since-Version: " . $objectVersion
			)
		);
		$this->assert412($response);
		
		// Delete object
		$response = API::userDelete(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey'],
			array(
				"Zotero-If-Unmodified-Since-Version: " . $newObjectVersion2
			)
		);
		$this->assert204($response);
	}
	
	
	private function _modifyJSONObject($objectType, $json) {
		// Modifying object should increase its version
		switch ($objectType) {
		case 'collection':
			$json->name = "New Name " . uniqid();
			break;
		
		case 'item':
			$json->title = "New Title" . uniqid();
			break;
		
		case 'search':
			$json->name = "New Name" . uniqid();
			break;
		}
	}
	
	
	private function _testMultiObjectLastModifiedVersion($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		$objectKeyProp = $objectType . "Key";
		$objectVersionProp = $objectType . "Version";
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'] . "&limit=1"
		);
		$version = $response->getHeader("Zotero-Last-Modified-Version");
		$this->assertTrue(is_numeric($version));
		
		switch ($objectType) {
		case 'collection':
			$json = new stdClass();
			$json->name = "Name";
			break;
		
		case 'item':
			$json = API::getItemTemplate("book");
			break;
		
		case 'search':
			$json = new stdClass();
			$json->name = "Name";
			$json->conditions = array(
				array(
					"condition" => "title",
					"operator" => "contains",
					"value" => "test"
				)
			);
			break;
		}
		
		// Outdated library version
		$response = API::userPost(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'],
			json_encode(array(
				$objectTypePlural => array($json)
			)),
			array(
				"Content-Type: application/json",
				"Zotero-If-Unmodified-Since-Version: " . ($version - 1)
			)
		);
		$this->assert412($response);
		
		// Make sure version didn't change during failure
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'] . "&limit=1"
		);
		$this->assertEquals($version, $response->getHeader("Zotero-Last-Modified-Version"));
		
		// Create a new object, using library timestamp
		$response = API::userPost(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'],
			json_encode(array(
				$objectTypePlural => array($json)
			)),
			array(
				"Content-Type: application/json",
				"Zotero-If-Unmodified-Since-Version: $version"
			)
		);
		$this->assert200($response);
		$version2 = $response->getHeader("Zotero-Last-Modified-Version");
		$this->assertTrue(is_numeric($version2));
		// Version should be incremented on new object
		$this->assertGreaterThan($version, $version2);
		$objectKey = API::getFirstSuccessKeyFromResponse($response);
		
		// Check single-object request
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey'] . "&content=json"
		);
		$this->assert200($response);
		$version = $response->getHeader("Zotero-Last-Modified-Version");
		$this->assertTrue(is_numeric($version));
		$this->assertEquals($version, $version2);
		$json = json_decode(API::getContentFromResponse($response));
		
		// Modify object
		$json->$objectKeyProp = $objectKey;
		switch ($objectType) {
		case 'collection':
			$json->name = "New Name";
			break;
		
		case 'item':
			$json->title = "New Title";
			break;
		
		case 'search':
			$json->name = "New Name";
			break;
		}
		
		// No Zotero-If-Unmodified-Since-Version or object version property
		unset($json->$objectVersionProp);
		$response = API::userPost(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'],
			json_encode(array(
				$objectTypePlural => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert428ForObject($response);
		
		// Outdated object version property
		$json->$objectVersionProp = $version - 1;
		$response = API::userPost(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'],
			json_encode(array(
				$objectTypePlural => array($json)
			)),
			array(
				"Content-Type: application/json"
			)
		);
		$this->assert412ForObject($response);
		
		// Modify object, using object version property
		$json->$objectVersionProp = $version;
		$response = API::userPost(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'],
			json_encode(array(
				$objectTypePlural => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert200($response);
		// Version should be incremented on modified object
		$version3 = $response->getHeader("Zotero-Last-Modified-Version");
		$this->assertTrue(is_numeric($version3));
		$this->assertGreaterThan($version2, $version3);
		
		// Check library version
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
		);
		$version = $response->getHeader("Zotero-Last-Modified-Version");
		$this->assertTrue(is_numeric($version));
		$this->assertEquals($version, $version3);
		
		// Check single-object request
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey']
		);
		$version = $response->getHeader("Zotero-Last-Modified-Version");
		$this->assertTrue(is_numeric($version));
		$this->assertEquals($version, $version3);
		
		// TODO: Version should be incremented on deleted item
	}
	
	
	private function _testMultiObject304NotModified($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
		);
		$version = $response->getHeader("Zotero-Last-Modified-Version");
		$this->assertTrue(is_numeric($version));
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'],
			array(
				"Zotero-If-Modified-Since-Version: $version"
			)
		);
		$this->assert304($response);
	}
	
	
	private function _testNewerAndVersionsFormat($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		$xmlArray = array();
		
		switch ($objectType) {
		case 'collection':
			$xmlArray[] = API::createCollection("Name", false, $this);
			$xmlArray[] = API::createCollection("Name", false, $this);
			$xmlArray[] = API::createCollection("Name", false, $this);
			break;
		
		case 'item':
			$xmlArray[] = API::createItem("book", array("title" => "Title"), $this);
			$xmlArray[] = API::createItem("book", array("title" => "Title"), $this);
			$xmlArray[] = API::createItem("book", array("title" => "Title"), $this);
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
		}
		
		$objects = array();
		while ($xml = array_shift($xmlArray)) {
			$data = API::parseDataFromAtomEntry($xml);
			$objects[] = array(
				"key" => $data['key'],
				"version" => $data['version']
			);
		}
		
		$firstVersion = $objects[0]['version'];
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&format=versions&newer=$firstVersion"
		);
		
		$this->assert200($response);
		$json = json_decode($response->getBody(), true);
		$this->assertNotNull($json);
		
		$keys = array_keys($json);
		
		$this->assertEquals($objects[2]['key'], array_shift($keys));
		$this->assertEquals($objects[2]['version'], array_shift($json));
		$this->assertEquals($objects[1]['key'], array_shift($keys));
		$this->assertEquals($objects[1]['version'], array_shift($json));
		$this->assertEmpty($json);
	}
	
	
	private function _testUploadUnmodified($objectType) {
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
		
		$version = (int) array_shift($xml->xpath('//atom:entry/zapi:version'));
		$this->assertNotEquals(0, $version);
		
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/{$data['key']}?key=" . self::$config['apiKey'],
			json_encode($json)
		);
		$this->assert204($response);
		$this->assertEquals($version, $response->getHeader("Zotero-Last-Modified-Version"));
		
		switch ($objectType) {
		case 'collection':
			$xml = API::getCollectionXML($data['key']);
			break;
		
		case 'item':
			$xml = API::getItemXML($data['key']);
			break;
		
		case 'search':
			$xml = API::getSearchXML($data['key']);
			break;
		}
		$data = API::parseDataFromAtomEntry($xml);
		$this->assertEquals($version, $data['version']);
	}
}
