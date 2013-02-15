<?php
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

class CreatorSyncTests extends PHPUnit_Framework_TestCase {
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
	
	
	public function testCreatorItemChange() {
		$key = 'AAAAAAAA';
		
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		// Create item via sync
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="book" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="' . $key . '">'
			. '<creator key="BBBBBBBB" creatorType="author" index="0">'
			. '<creator libraryID="' . self::$config['libraryID'] . '" '
			. 'key="BBBBBBBB" dateAdded="2009-03-07 04:53:20" dateModified="2009-03-07 04:54:09">'
			. '<firstName>First</firstName>'
			. '<lastName>Last</lastName>'
			. '<fieldMode>0</fieldMode>'
			. '</creator></creator></item></items></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Get item version via API and check creatorSummary
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$creatorSummary = (string) array_shift($xml->xpath('//atom:entry/zapi:creatorSummary'));
		$this->assertEquals("Last", $creatorSummary);
		$data = API::parseDataFromAtomEntry($xml);
		$version = $data['version'];
		
		// Get item via sync
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		$this->assertEquals(1, sizeOf($xml->updated->items->item));
		
		//
		// Modify creator
		//
		$data = '<data version="9">'
			. '<creators><creator libraryID="' . self::$config['libraryID'] . '" '
			. 'key="BBBBBBBB" dateAdded="2009-03-07 04:53:20" dateModified="2009-03-07 04:54:09">'
			. '<name>First Last</name>'
			. '<fieldMode>1</fieldMode>'
			. '</creator></creators></data>';
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
		
		$creatorSummary = (string) array_shift($xml->xpath('//atom:entry/zapi:creatorSummary'));
		$this->assertEquals("First Last", $creatorSummary);
		$this->assertTrue(isset($json->creators[0]->name));
		$this->assertEquals("First Last", $json->creators[0]->name);
		$this->assertEquals($version + 1, $data['version']);
		$version = $data['version'];
		
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		//
		// Modify creator, and include unmodified item
		//
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="book" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="' . $key . '">'
			. '<creator key="BBBBBBBB" creatorType="author" index="0"/>'
			. '</item></items>'
			. '<creators><creator libraryID="' . self::$config['libraryID'] . '" '
			. 'key="BBBBBBBB" dateAdded="2009-03-07 04:53:20" dateModified="2009-03-07 04:54:09">'
			. '<firstName>Foo</firstName>'
			. '<lastName>Bar</lastName>'
			. '<fieldMode>0</fieldMode>'
			. '</creator></creators></data>';
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
		
		$creatorSummary = (string) array_shift($xml->xpath('//atom:entry/zapi:creatorSummary'));
		$this->assertEquals("Bar", $creatorSummary);
		$this->assertTrue(isset($json->creators[0]->firstName));
		$this->assertEquals("Foo", $json->creators[0]->firstName);
		$this->assertTrue(isset($json->creators[0]->lastName));
		$this->assertEquals("Bar", $json->creators[0]->lastName);
		$this->assertEquals($version + 1, $data['version']);
	}
	
	
	public function testCreatorItemChangeViaAPI() {
		$key = 'AAAAAAAA';
		
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		// Create item via sync
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="book" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="' . $key . '">'
			. '<creator key="BBBBBBBB" creatorType="author" index="0">'
			. '<creator libraryID="' . self::$config['libraryID'] . '" '
			. 'key="BBBBBBBB" dateAdded="2009-03-07 04:53:20" dateModified="2009-03-07 04:54:09">'
			. '<firstName>First</firstName>'
			. '<lastName>Last</lastName>'
			. '<fieldMode>0</fieldMode>'
			. '</creator></creator></item></items></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Get item version via API and check creatorSummary
		API::useAPIVersion(1);
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$creatorSummary = (string) array_shift($xml->xpath('//atom:entry/zapi:creatorSummary'));
		$this->assertEquals("Last", $creatorSummary);
		$data = API::parseDataFromAtomEntry($xml);
		$etag = (string) array_shift($xml->xpath('//atom:entry/atom:content/@zapi:etag'));
		$this->assertNotEquals("", $etag);
		
		// Modify creator
		$json = json_decode($data['content'], true);
		$json['creators'][0] = array(
			"name" => "First Last",
			"creatorType" => "author"
		);
		
		// Modify via API
		$response = API::userPut(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'],
			json_encode($json),
			array("If-Match: $etag")
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		
		$creatorSummary = (string) array_shift($xml->xpath('//atom:entry/zapi:creatorSummary'));
		$this->assertEquals("First Last", $creatorSummary);
		$this->assertTrue(isset($json->creators[0]->name));
		$this->assertEquals("First Last", $json->creators[0]->name);
		$newETag = (string) array_shift($xml->xpath('//atom:entry/zapi:etag'));
		$this->assertNotEquals($etag, $newETag);
		
		// Get item again via API
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		
		$creatorSummary = (string) array_shift($xml->xpath('//atom:entry/zapi:creatorSummary'));
		$this->assertEquals("First Last", $creatorSummary);
		$this->assertTrue(isset($json->creators[0]->name));
		$this->assertEquals("First Last", $json->creators[0]->name);
		$newETag = (string) array_shift($xml->xpath('//atom:entry/zapi:etag'));
		$this->assertNotEquals($etag, $newETag);
	}
}
