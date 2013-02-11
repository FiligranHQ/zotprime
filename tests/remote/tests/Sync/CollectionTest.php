<?php
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

class SyncCollectionTests extends PHPUnit_Framework_TestCase {
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
	
	
	public function testCollectionItemUpdate() {
		$collectionKey = Sync::createCollection(
			self::$sessionID, self::$config['libraryID'], "Test", null, $this
		);
		$itemKey = Sync::createItem(
			self::$sessionID, self::$config['libraryID'], "book", null, $this
		);
		
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		// Get the item version
		$itemXML = API::getItemXML($itemKey);
		$data = API::parseDataFromAtomEntry($itemXML);
		$json = json_decode($data['content'], true);
		$itemVersion = $json['itemVersion'];
		$this->assertNotNull($itemVersion);
		
		// Add via sync
		$collectionXML = $xml->updated[0]->collections[0]->collection[0];
		$collectionXML['libraryID'] = self::$config['libraryID'];
		$collectionXML->addChild("items", $itemKey);
		
		$data = '<data version="9"><collections>'
			. $collectionXML->asXML()
			. '</collections>'
			. '</data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data, true);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Make sure item was updated
		$itemXML = API::getItemXML($itemKey);
		$data = API::parseDataFromAtomEntry($itemXML);
		$json = json_decode($data['content'], true);
		$this->assertGreaterThan($itemVersion, $json['itemVersion']);
		$itemVersion = $json['itemVersion'];
		$this->assertCount(1, $json['collections']);
		$this->assertContains($collectionKey, $json['collections']);
		
		// Remove via sync
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		$collectionXML = $xml->updated[0]->collections[0]->collection[0];
		$collectionXML['libraryID'] = self::$config['libraryID'];
		unset($collectionXML->items);
		
		$data = '<data version="9"><collections>'
			. $collectionXML->asXML()
			. '</collections>'
			. '</data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data, true);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Make sure item was removed
		$itemXML = API::getItemXML($itemKey);
		$data = API::parseDataFromAtomEntry($itemXML);
		$json = json_decode($data['content'], true);
		$this->assertGreaterThan($itemVersion, $json['itemVersion']);
		$this->assertCount(0, $json['collections']);
	}
}
