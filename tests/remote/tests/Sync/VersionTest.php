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

class SyncVersionTests extends PHPUnit_Framework_TestCase {
	protected static $config;
	protected static $sessionID;
	
	public static function setUpBeforeClass() {
		require 'include/config.inc.php';
		foreach ($config as $k => $v) {
			self::$config[$k] = $v;
		}
	}
	
	
	public function setUp() {
		API::userClear(self::$config['userID']);
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
		
		$response = Sync::updated(self::$sessionID);
		$xml = Sync::getXMLFromResponse($response);
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
		$response = Sync::updated(self::$sessionID);
		$xml = Sync::getXMLFromResponse($response);
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
}
