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

use API2 as API;
require_once 'include/api2.inc.php';
require_once 'include/sync.inc.php';

class SyncVersionTests extends PHPUnit_Framework_TestCase {
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
	
	
	/**
	 * Create and delete an item via sync and check with /deleted?newer=0
	 */
	public function testAPINewerTimestamp() {
		$this->_testAPINewerTimestamp('collection');
		$this->_testAPINewerTimestamp('item');
		$this->_testAPINewerTimestamp('search');
	}
	
	
	private function _testAPINewerTimestamp($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		$xml = Sync::updated(self::$sessionID);
		$lastSyncTimestamp = $xml['timestamp'];
		
		// Create via sync
		switch ($objectType) {
		case 'collection':
			$keys[] = Sync::createCollection(
				self::$sessionID, self::$config['libraryID'], "Test", false, $this
			);
			break;
		
		case 'item':
			$keys[] = Sync::createItem(
				self::$sessionID, self::$config['libraryID'], "book", false, $this
			);
			break;
		
		case 'search':
			$keys[] = Sync::createSearch(
				self::$sessionID, self::$config['libraryID'], "Test", 'default', $this
			);
			break;
		}
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&newertime=$lastSyncTimestamp&format=keys"
		);
		$this->assertEquals(200, $response->getStatus());
		$responseKeys = explode("\n", trim($response->getBody()));
		$this->assertCount(sizeOf($keys), $responseKeys);
		foreach ($keys as $key) {
			$this->assertContains($key, $responseKeys);
		}
		
		// Should be empty with later timestamp
		$xml = Sync::updated(self::$sessionID);
		$lastSyncTimestamp = $xml['timestamp'];
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				// server uses NOW() + 1
				. "&newertime=" . ($lastSyncTimestamp + 2) . "&format=keys"
		);
		$this->assertEquals(200, $response->getStatus());
		$this->assertEquals("", trim($response->getBody()));
	}
	
	
	public function testSyncUploadUnchanged() {
		$data1 = API::createItem("audioRecording", array(
			"title" => "Test",
			"relations" => array(
				'owl:sameAs' => 'http://zotero.org/groups/1/items/AAAAAAAA'
			)
		), null, 'data');
		// dc:relation already exists, so item shouldn't change
		$data2 = API::createItem("interview", array(
			"relations" => array(
				'dc:relation' => 'http://zotero.org/users/'
					. self::$config['userID'] . '/items/' . $data1['key']
			)
		), null, 'data');
		
		// Upload unchanged via sync
		$xml = Sync::updated(self::$sessionID);
		$updateKey = $xml['updateKey'];
		$lastSyncTimestamp = $xml['timestamp'];
		
		$itemXML1 = array_get_first($xml->updated[0]->items[0]->xpath("item[@key='{$data1['key']}']"));
		$itemXML2 = array_get_first($xml->updated[0]->items[0]->xpath("item[@key='{$data2['key']}']"));
		$itemXML1['libraryID'] = self::$config['libraryID'];
		$itemXML2['libraryID'] = self::$config['libraryID'];
		
		$xmlstr = '<data version="9">'
			. '<items>'
			. $itemXML1->asXML()
			. $itemXML2->asXML()
			. '</items>'
			. '</data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $xmlstr);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Check via API to make sure they're the same
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey']
				. "&format=versions"
		);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($data1['version'], $json[$data1['key']]);
		$this->assertEquals($data2['version'], $json[$data2['key']]);
	}
}
