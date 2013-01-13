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
		API::userClear(self::$config['userID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testSingleObjectLastModifiedVersion() {
		$this->_testSingleObjectLastModifiedVersion('collection');
		$this->_testSingleObjectLastModifiedVersion('item');
	}
	
	
	public function testMultiObjectLastModifiedVersion() {
		$this->_testMultiObjectLastModifiedVersion('collection');
		$this->_testMultiObjectLastModifiedVersion('item');
	}
	
	
	public function testMultiObject304NotModified() {
		$this->_testMultiObject304NotModified('collection');
		$this->_testMultiObject304NotModified('item');
	}
	
	
	public function testNewerAndVersionsFormat() {
		$this->_testNewerAndVersionsFormat('collection');
		$this->_testNewerAndVersionsFormat('item');
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
		
		// Modifying object should increase its version
		switch ($objectType) {
		case 'collection':
			$json->name = "New Name";
			break;
		
		case 'item':
			$json->title = "New Title";
			break;
		}
		
		// No Zotero-If-Unmodified-Since-Version
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
		
		// Update object
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
		
		// Make sure new library version matches new object version
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'] . "&limit=1"
		);
		$this->assert200($response);
		$newLibraryVersion = $response->getHeader("Zotero-Last-Modified-Version");
		$this->assertEquals($newLibraryVersion, $newObjectVersion);
		
		//
		// Delete object
		//
		
		// No Zotero-If-Unmodified-Since-Version
		$response = API::userDelete(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey']
		);
		$this->assert428($response);
		
		// No Zotero-If-Unmodified-Since-Version
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
				"Zotero-If-Unmodified-Since-Version: " . $newObjectVersion
			)
		);
		$this->assert204($response);
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
		
		switch ($objectType) {
		case 'collection':
			$xml = API::createCollection("Name", false, $this);
			$data = API::parseDataFromAtomEntry($xml);
			$objects[] = array(
				"key" => $data['key'],
				"version" => $data['version']
			);
			
			$xml = API::createCollection("Name", false, $this);
			$data = API::parseDataFromAtomEntry($xml);
			$objects[] = array(
				"key" => $data['key'],
				"version" => $data['version']
			);
			
			$xml = API::createCollection("Name", false, $this);
			$data = API::parseDataFromAtomEntry($xml);
			$objects[] = array(
				"key" => $data['key'],
				"version" => $data['version']
			);
			break;
		
		case 'item':
			$xml = API::createItem("book", array("title" => "Title"), $this);
			$data = API::parseDataFromAtomEntry($xml);
			$objects[] = array(
				"key" => $data['key'],
				"version" => $data['version']
			);
			
			$xml = API::createItem("book", array("title" => "Title"), $this);
			$data = API::parseDataFromAtomEntry($xml);
			$objects[] = array(
				"key" => $data['key'],
				"version" => $data['version']
			);
			
			$xml = API::createItem("book", array("title" => "Title"), $this);
			$data = API::parseDataFromAtomEntry($xml);
			$objects[] = array(
				"key" => $data['key'],
				"version" => $data['version']
			);
			break;
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
}
