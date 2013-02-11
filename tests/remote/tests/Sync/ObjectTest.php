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

require_once 'include/api.inc.php';
require_once 'include/sync.inc.php';

class SyncObjectTests extends PHPUnit_Framework_TestCase {
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
	 * Create and delete an object via sync and check with /deleted?newer=0
	 */
	public function testDeleteAndDeleted() {
		// TODO
		//$this->_testDeleteAndDeleted('collection');
		$this->_testDeleteAndDeleted('item');
		//$this->_testDeleteAndDeleted('search');
	}
	
	
	private function _testDeleteAndDeleted($objectType) {
		API::userClear(self::$config['userID']);
		
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		$xml = Sync::updated(self::$sessionID);
		$lastSyncTimestamp = $xml['timestamp'];
		
		// Create via sync
		switch ($objectType) {
		case 'item':
			$keys[] = Sync::createItem(
				self::$sessionID, self::$config['libraryID'], "book", false, $this
			);
			break;
		}
		
		// Check via API
		foreach ($keys as $key) {
			$response = API::userGet(
				self::$config['userID'],
				"$objectTypePlural/$key?key=" . self::$config['apiKey']
			);
			$this->assertEquals(200, $response->getStatus());
			$version = $response->getHeader("Last-Modified-Version");
			$this->assertNotNull($version);
		}
		
		// Get empty deleted via API
		$response = API::userGet(
			self::$config['userID'],
			"deleted?key=" . self::$config['apiKey'] . "&newer=$version"
		);
		$this->assertEquals(200, $response->getStatus());
		$json = json_decode($response->getBody(), true);
		$this->assertEmpty($json[$objectTypePlural]);
		
		// Get empty deleted via API with newertime
		$response = API::userGet(
			self::$config['userID'],
			"deleted?key=" . self::$config['apiKey'] . "&newertime=$lastSyncTimestamp"
		);
		$this->assertEquals(200, $response->getStatus());
		$json = json_decode($response->getBody(), true);
		$this->assertEmpty($json[$objectTypePlural]);
		
		// Delete via sync
		foreach ($keys as $key) {
			switch ($objectType) {
			case 'item':
				Sync::deleteItem(self::$sessionID, self::$config['libraryID'], $key, $this);
				break;
			}
		}
		
		// Check 404 via API
		foreach ($keys as $key) {
			$response = API::userGet(
				self::$config['userID'],
				"$objectTypePlural/$key?key=" . self::$config['apiKey']
			);
			$this->assertEquals(404, $response->getStatus());
		}
		
		// Get deleted via API
		$response = API::userGet(
			self::$config['userID'],
			"deleted?key=" . self::$config['apiKey'] . "&newer=$version"
		);
		$this->assertEquals(200, $response->getStatus());
		$json = json_decode($response->getBody(), true);
		$this->assertArrayHasKey($objectTypePlural, $json);
		$this->assertCount(sizeOf($keys), $json[$objectTypePlural]);
		foreach ($keys as $key) {
			$this->assertContains($key, $json[$objectTypePlural]);
		}
		
		// Get deleted via API with newertime
		$response = API::userGet(
			self::$config['userID'],
			"deleted?key=" . self::$config['apiKey']
				. "&newertime=$lastSyncTimestamp"
		);
		$this->assertEquals(200, $response->getStatus());
		$json = json_decode($response->getBody(), true);
		$this->assertArrayHasKey($objectTypePlural, $json);
		$this->assertCount(sizeOf($keys), $json[$objectTypePlural]);
		foreach ($keys as $key) {
			$this->assertContains($key, $json[$objectTypePlural]);
		}
		
		// Should be empty with later newertime
		$xml = Sync::updated(self::$sessionID);
		$lastSyncTimestamp = $xml['timestamp'];
		
		$response = API::userGet(
			self::$config['userID'],
			"deleted?key=" . self::$config['apiKey']
				// server uses NOW() + 1
				. "&newertime=" . ($lastSyncTimestamp + 2)
		);
		$this->assertEquals(200, $response->getStatus());
		$json = json_decode($response->getBody(), true);
		$this->assertEmpty($json[$objectTypePlural]);
	}
}
