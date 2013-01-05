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
	
	
	private function _testSingleObjectLastModifiedVersion($objectType) {
		if ($objectType == 'search') {
			$objectTypePlural = $objectType . "es";
		}
		else {
			$objectTypePlural = $objectType . "s";
		}
		
		switch ($objectType) {
		case 'item':
			$xml = API::createItem("book", array("title" => "Title"), $this);
			break;
		
		case 'collection':
			$xml = API::createCollection("Name", false, $this);
			break;
		}
		
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		
		// Make sure object version matches library version
		$data = API::parseDataFromAtomEntry($xml);
		$objectKey = $data['key'];
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey'] . "&content=json"
		);
		$this->assert200($response);
		$objectVersion = $response->getHeader("Zotero-Last-Modified-Version");
		$json = json_decode(API::getContentFromResponse($response));
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'] . "&limit=1"
		);
		$this->assert200($response);
		$libraryVersion = $response->getHeader("Zotero-Last-Modified-Version");
		
		$this->assertEquals($libraryVersion, $objectVersion);
		
		// Modifying object should increase its version
		switch ($objectType) {
		case 'item':
			$json->title = "New Title";
			break;
		
		case 'collection':
			$json->name = "New Name";
			break;
		}
		
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey'],
			json_encode($json),
			array(
				"Zotero-If-Unmodified-Since-Version: " . $objectVersion
			)
		);
		$this->assert200($response);
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
	}
	
	
	private function _testMultiObjectLastModifiedVersion($objectType) {
		if ($objectType == 'search') {
			$objectTypePlural = $objectType . "es";
		}
		else {
			$objectTypePlural = $objectType . "s";
		}
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'] . "&limit=1"
		);
		$version = $response->getHeader("Zotero-Last-Modified-Version");
		$this->assertTrue(is_numeric($version));
		
		switch ($objectType) {
		case 'item':
			$json = API::getItemTemplate("book");
			break;
			
		case 'collection':
			$json = new stdClass();
			$json->name = "Name";
			break;
		}
		
		// Version should be incremented on new object
		$response = API::userPost(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'],
			json_encode(array(
				$objectTypePlural => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert200($response);
		$version2 = $response->getHeader("Zotero-Last-Modified-Version");
		$this->assertTrue(is_numeric($version2));
		$this->assertGreaterThan($version, $version2);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$objectKey = $data['key'];
		
		// Check single-object request
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey']
		);
		$version = $response->getHeader("Zotero-Last-Modified-Version");
		$this->assertTrue(is_numeric($version));
		$this->assertEquals($version2, $version);
		
		// Version should be incremented on modified object
		switch ($objectType) {
		case 'item':
			$json->title = "New Title";
			break;
		
		case 'collection':
			$json->name = "New Name";
			break;
		}
		
		$response = API::userPost(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey'],
			json_encode(array(
				$objectTypePlural => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert200($response);
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
		$this->assertEquals($version3, $version);
		
		// Check single-object request
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey']
		);
		$version = $response->getHeader("Zotero-Last-Modified-Version");
		$this->assertTrue(is_numeric($version));
		$this->assertEquals($version3, $version);
		
		// TODO: Version should be incremented on deleted item
	}
}
