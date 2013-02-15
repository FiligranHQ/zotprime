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

require_once 'include/api.inc.php';
require_once 'include/sync.inc.php';

class SyncTagTests extends PHPUnit_Framework_TestCase {
	protected static $config;
	protected static $sessionID;
	
	public static function setUpBeforeClass() {
		require 'include/config.inc.php';
		foreach ($config as $k => $v) {
			self::$config[$k] = $v;
		}
		
		API::useAPIVersion(2);
	}
	
	
	public function setUp() {
		API::userClear(self::$config['userID']);
		API::groupClear(self::$config['ownedPrivateGroupID']);
		API::groupClear(self::$config['ownedPublicGroupID']);
		self::$sessionID = Sync::login();
	}
	
	
	public function tearDown() {
		Sync::logout(self::$sessionID);
		self::$sessionID = null;
	}
	
	
	public function testTagAddItemChange() {
		$key = 'AAAAAAAA';
		
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		// Create item via sync
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="book" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="' . $key . '"/></items></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Get item version via API
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$version = $data['version'];
		
		// Get item via sync
		$xml = Sync::updated(self::$sessionID);
		$this->assertEquals(1, sizeOf($xml->updated->items->item));
		
		// Increment the library version, since we're testing the
		// version below
		API::createItem('newspaperArticle', false, false, 'key');
		$libraryVersion = API::getLibraryVersion();
		
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		// Add tag to item via sync
		$data = '<data version="9"><tags><tag libraryID="'
			. self::$config['libraryID'] . '" name="Test" '
			. 'dateAdded="2009-03-07 04:54:56" '
			. 'dateModified="2009-03-07 04:54:56" '
			. 'key="BBBBBBBB">'
			. '<items>' . $key . '</items>'
			. '</tag></tags></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Get item via API
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		
		$this->assertCount(1, $json->tags);
		$this->assertTrue(isset($json->tags[0]->tag));
		$this->assertEquals("Test", $json->tags[0]->tag);
		// Item version should be increased
		$this->assertGreaterThan($version, $data['version']);
		// And should be one more than previous version
		$this->assertEquals($libraryVersion + 1, $data['version']);
	}
	
	
	// Also tests lastModifiedByUserID
	public function testGroupTagAddItemChange() {
		$key = 'AAAAAAAA';
		
		$groupID = self::$config['ownedPrivateGroupID'];
		$libraryID = self::$config['ownedPrivateGroupLibraryID'];
		$sessionID2 = Sync::login(
			array(
				'username' => self::$config['username2'],
				'password' => self::$config['password2']
			)
		);
		$xml = Sync::updated($sessionID2);
		$updateKey = (string) $xml['updateKey'];
		
		// Create item via sync
		$data = '<data version="9"><items><item libraryID="' . $libraryID . '" '
			. 'itemType="book" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="' . $key . '"/></items></data>';
		$response = Sync::upload($sessionID2, $updateKey, $data);
		Sync::waitForUpload($sessionID2, $response, $this);
		
		// Get item version via API
		$response = API::groupGet(
			$groupID,
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$version = $data['version'];
		
		// Verify createdByUserID and lastModifiedByUserID
		$response = API::groupGet(
			$groupID,
			"items/$key?key=" . self::$config['apiKey'] . "&content=none"
		);
		$xml = API::getXMLFromResponse($response);
		$xml->registerXPathNamespace('zxfer', 'http://zotero.org/ns/transfer');
		$createdByUser = (string) array_shift($xml->xpath('//atom:entry/atom:author/atom:name'));
		$lastModifiedByUser = (string) array_shift($xml->xpath('//atom:entry/zapi:lastModifiedByUser'));
		$this->assertEquals(self::$config['username2'], $createdByUser);
		$this->assertEquals(self::$config['username2'], $lastModifiedByUser);
		
		// Get item via sync
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		$this->assertEquals(1, sizeOf($xml->updated->items->item));
		
		// Add tag to item via sync
		$data = '<data version="9"><tags><tag libraryID="' . $libraryID . '" '
			. 'name="Test" '
			. 'dateAdded="2009-03-07 04:54:56" '
			. 'dateModified="2009-03-07 04:54:56" '
			. 'key="BBBBBBBB">'
			. '<items>' . $key . '</items>'
			. '</tag></tags></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Get item via API
		$response = API::groupGet(
			$groupID,
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		
		$this->assertCount(1, $json->tags);
		$this->assertTrue(isset($json->tags[0]->tag));
		$this->assertEquals("Test", $json->tags[0]->tag);
		$this->assertGreaterThan($version, $data['version']);
		
		// Verify createdByUserID and lastModifiedByUserID
		$response = API::groupGet(
			$groupID,
			"items/$key?key=" . self::$config['apiKey'] . "&content=none"
		);
		$xml = API::getXMLFromResponse($response);
		$xml->registerXPathNamespace('zxfer', 'http://zotero.org/ns/transfer');
		$createdByUser = (string) array_shift($xml->xpath('//atom:entry/atom:author/atom:name'));
		$lastModifiedByUser = (string) array_shift($xml->xpath('//atom:entry/zapi:lastModifiedByUser'));
		$this->assertEquals(self::$config['username2'], $createdByUser);
		$this->assertEquals(self::$config['username'], $lastModifiedByUser);
	}
	
	
	public function testTagAddUnmodifiedItemChange() {
		$key = 'AAAAAAAA';
		
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		// Create item via sync
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="book" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="' . $key . '"/></items></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Get item version via API
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$version = $data['version'];
		
		// Get item via sync
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		$this->assertEquals(1, sizeOf($xml->updated->items->item));
		
		// Add tag to item via sync, and include unmodified item
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="book" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="' . $key . '"/></items>'
			. '<tags><tag libraryID="'
			. self::$config['libraryID'] . '" name="Test" '
			. 'dateAdded="2009-03-07 04:54:56" '
			. 'dateModified="2009-03-07 04:54:56" '
			. 'key="BBBBBBBB">'
			. '<items>' . $key . '</items>'
			. '</tag></tags></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Get item via API
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		
		$this->assertCount(1, $json->tags);
		$this->assertTrue(isset($json->tags[0]->tag));
		$this->assertEquals("Test", $json->tags[0]->tag);
		$this->assertGreaterThan($version, $data['version']);
	}
	
	
	public function testTagRemoveItemChange() {
		$key = 'AAAAAAAA';
		
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		// Create item via sync
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="book" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="' . $key . '"/></items>'
			. '<tags><tag libraryID="'
			. self::$config['libraryID'] . '" name="Test" '
			. 'dateAdded="2009-03-07 04:54:56" '
			. 'dateModified="2009-03-07 04:54:56" '
			. 'key="BBBBBBBB">'
			. '<items>' . $key . '</items>'
			. '</tag>'
			. '<tag libraryID="'
			. self::$config['libraryID'] . '" name="Test2" '
			. 'dateAdded="2009-03-07 04:54:56" '
			. 'dateModified="2009-03-07 04:54:56" '
			. 'key="CCCCCCCC">'
			. '<items>' . $key . '</items>'
			. '</tag></tags></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Get item via API
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		$originalVersion = $data['version'];
		
		$this->assertCount(2, $json->tags);
		$this->assertTrue(isset($json->tags[0]->tag));
		$this->assertEquals("Test", $json->tags[0]->tag);
		$this->assertTrue(isset($json->tags[1]->tag));
		$this->assertEquals("Test2", $json->tags[1]->tag);
		
		// Get item via sync
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		$this->assertEquals(1, sizeOf($xml->updated->items->item));
		$this->assertEquals(2, sizeOf($xml->updated->tags->tag));
		$this->assertEquals(1, sizeOf($xml->updated->tags->tag[0]->items));
		$this->assertEquals(1, sizeOf($xml->updated->tags->tag[1]->items));
		
		// Remove tag from item via sync
		$data = '<data version="9"><tags><tag libraryID="'
			. self::$config['libraryID'] . '" name="Test" '
			. 'dateAdded="2009-03-07 04:54:56" '
			. 'dateModified="2009-03-07 04:54:56" '
			. 'key="BBBBBBBB">'
			. '</tag></tags></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Get item via sync
		$xml = Sync::updated(self::$sessionID);
		$this->assertEquals(2, sizeOf($xml->updated->tags->tag));
		$this->assertFalse(isset($xml->updated->tags->tag[0]->items));
		$this->assertEquals(1, sizeOf($xml->updated->tags->tag[1]->items));
		
		// Get item version via API
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		
		$this->assertGreaterThan($originalVersion, $data['version']);
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:entry/zapi:numTags')));
		$this->assertCount(1, $json->tags);
	}
	
	
	public function testTagDeleteItemChange() {
		$key = 'AAAAAAAA';
		
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		// Create item via sync
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="book" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="' . $key . '"/></items>'
			. '<tags><tag libraryID="'
			. self::$config['libraryID'] . '" name="Test" '
			. 'dateAdded="2009-03-07 04:54:56" '
			. 'dateModified="2009-03-07 04:54:56" '
			. 'key="BBBBBBBB">'
			. '<items>' . $key . '</items>'
			. '</tag></tags></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Get item via API
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		$originalVersion = $data['version'];
		
		$this->assertCount(1, $json->tags);
		$this->assertTrue(isset($json->tags[0]->tag));
		$this->assertEquals("Test", $json->tags[0]->tag);
		
		// Get item via sync
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		$this->assertEquals(1, sizeOf($xml->updated->items->item));
		$this->assertEquals(1, sizeOf($xml->updated->tags->tag));
		$this->assertEquals(1, sizeOf($xml->updated->tags->tag[0]->items));
		
		// Delete tag via sync
		$data = '<data version="9"><deleted><tags><tag libraryID="'
			. self::$config['libraryID'] . '" key="BBBBBBBB"/>'
			. '</tags></deleted></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Get item via sync
		$xml = Sync::updated(self::$sessionID);
		$this->assertEquals(1, sizeOf(isset($xml->updated->tags->tag)));
		$this->assertFalse(isset($xml->updated->tags->tag[0]->items));
		
		// Get item version via API
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		
		$this->assertEquals(0, (int) array_shift($xml->xpath('/atom:entry/zapi:numTags')));
		$this->assertCount(0, $json->tags);
		$this->assertGreaterThan($originalVersion, $data['version']);
	}
	
	
	public function testTagDeleteUnmodifiedItemChange() {
		$key = 'AAAAAAAA';
		
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		// Create item via sync
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="book" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="' . $key . '"/></items>'
			. '<tags><tag libraryID="'
			. self::$config['libraryID'] . '" name="Test" '
			. 'dateAdded="2009-03-07 04:54:56" '
			. 'dateModified="2009-03-07 04:54:56" '
			. 'key="BBBBBBBB">'
			. '<items>' . $key . '</items>'
			. '</tag></tags></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Get item via API
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		$originalVersion = $data['version'];
		
		$this->assertCount(1, $json->tags);
		$this->assertTrue(isset($json->tags[0]->tag));
		$this->assertEquals("Test", $json->tags[0]->tag);
		
		// Get item via sync
		$xml = Sync::updated(self::$sessionID);
		$this->assertEquals(1, sizeOf($xml->updated->items->item));
		$this->assertEquals(1, sizeOf($xml->updated->tags->tag));
		$this->assertEquals(1, sizeOf($xml->updated->tags->tag[0]->items));
		$lastsync = (int) $xml['timestamp'];
		
		usleep(1500000);
		
		// Increment the library version, since we're testing the
		// version below
		API::createItem('newspaperArticle', false, false, 'key');
		$libraryVersion = API::getLibraryVersion();
		
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		// Delete tag via sync, with unmodified item
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="book" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="' . $key . '"/></items>'
			. '<deleted><tags><tag libraryID="'
			. self::$config['libraryID'] . '" key="BBBBBBBB"/>'
			. '</tags></deleted></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Get item via sync
		$xml = Sync::updated(self::$sessionID);
		$this->assertEquals(1, sizeOf(isset($xml->updated->tags->tag)));
		$this->assertFalse(isset($xml->updated->tags->tag[0]->items));
		
		// Get item version via API
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		
		$this->assertEquals(0, (int) array_shift($xml->xpath('/atom:entry/zapi:numTags')));
		$this->assertCount(0, $json->tags);
		// New item version should be greater than before
		$this->assertGreaterThan($originalVersion, $data['version']);
		// And should be one more than previous version
		$this->assertEquals($libraryVersion + 1, $data['version']);
		
		// Only the newspaperArticle should be updated
		$xml = Sync::updated(self::$sessionID, $lastsync);
		$this->assertEquals(1, $xml->updated[0]->items[0]->count());
	}
}
