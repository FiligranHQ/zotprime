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

class SettingsSyncTests extends PHPUnit_Framework_TestCase {
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
		self::$sessionID = Sync::login();
	}
	
	
	public function tearDown() {
		Sync::logout(self::$sessionID);
		self::$sessionID = null;
	}
	
	
	
	public function testSettings() {
		$settingKey = 'tagColors';
		$value = array(
			array(
				"name" => "_READ",
				"color" => "#990000"
			)
		);
		
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		$lastSyncTimestamp = (int) $xml['timestamp'];
		
		$libraryVersion = API::getLibraryVersion();
		
		// Create item via sync
		$data = '<data version="9"><settings><setting libraryID="'
			. self::$config['libraryID'] . '" name="' . $settingKey . '">'
			. htmlspecialchars(json_encode($value))
			. '</setting></settings></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Check via sync
		$xml = Sync::updated(self::$sessionID, $lastSyncTimestamp);
		$updateKey = (string) $xml['updateKey'];
		$lastSyncTimestamp = $xml['timestamp'];
		$settingXML = $xml->updated[0]->settings[0]->setting[0];
		$this->assertEquals(self::$config['libraryID'], (int) $settingXML['libraryID']);
		$this->assertEquals($settingKey, (string) $settingXML['name']);
		$this->assertEquals($value, json_decode((string) $settingXML, true));
		
		// Get setting via API and check value
		$response = API::userGet(
			self::$config['userID'],
			"settings/$settingKey?key=" . self::$config['apiKey']
		);
		$this->assertEquals(200, $response->getStatus());
		$json = json_decode($response->getBody(), true);
		$this->assertNotNull($json);
		$this->assertEquals($value, $json['value']);
		$this->assertEquals($libraryVersion + 1, $json['version']);
		
		// Delete via sync
		$xmlstr = '<data version="9">'
			. '<deleted>'
			. '<settings>'
			. '<setting libraryID="' . self::$config['libraryID']
				. '" key="' . $settingKey . '"/>'
			. '</settings>'
			. '</deleted>'
			. '</data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $xmlstr);
		$xml = Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Get setting via API and check value
		$response = API::userGet(
			self::$config['userID'],
			"settings/$settingKey?key=" . self::$config['apiKey']
		);
		$this->assertEquals(404, $response->getStatus());
		
		// Check for missing via sync
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		$lastSyncTimestamp = $xml['timestamp'];
		$this->assertEquals(0, $xml->updated[0]->settings->count());
		$this->assertEquals(1, $xml->updated[0]->deleted[0]->settings[0]->setting->count());
		$this->assertEquals(self::$config['libraryID'], (int) $xml->updated[0]->deleted[0]->settings[0]->setting[0]['libraryID']);
		$this->assertEquals($settingKey, (string) $xml->updated[0]->deleted[0]->settings[0]->setting[0]['key']);
	}
}
