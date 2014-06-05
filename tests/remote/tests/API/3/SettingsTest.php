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

namespace APIv3;
use API3 as API;
require_once 'APITests.inc.php';
require_once 'include/api3.inc.php';

class SettingsTests extends APITests {
	public function setUp() {
		parent::setUp();
		API::userClear(self::$config['userID']);
		API::groupClear(self::$config['ownedPrivateGroupID']);
	}
	
	
	public function tearDown() {
		API::userClear(self::$config['userID']);
		API::groupClear(self::$config['ownedPrivateGroupID']);
	}
	
	
	public function testAddUserSetting() {
		$settingKey = "tagColors";
		$value = array(
			array(
				"name" => "_READ",
				"color" => "#990000"
			)
		);
		
		$libraryVersion = API::getLibraryVersion();
		
		$json = array(
			"value" => $value
		);
		
		// No version
		$response = API::userPut(
			self::$config['userID'],
			"settings/$settingKey",
			json_encode($json),
			array("Content-Type: application/json")
		);
		$this->assert428($response);
		
		// Version must be 0 for non-existent setting
		$response = API::userPut(
			self::$config['userID'],
			"settings/$settingKey",
			json_encode($json),
			array(
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: 1"
			)
		);
		$this->assert412($response);
		
		// Create
		$response = API::userPut(
			self::$config['userID'],
			"settings/$settingKey",
			json_encode($json),
			array(
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: 0"
			)
		);
		$this->assert204($response);
		
		// Multi-object GET
		$response = API::userGet(
			self::$config['userID'],
			"settings"
		);
		$this->assert200($response);
		$this->assertContentType("application/json", $response);
		$json = json_decode($response->getBody(), true);
		$this->assertNotNull($json);
		$this->assertArrayHasKey($settingKey, $json);
		$this->assertEquals($value, $json[$settingKey]['value']);
		$this->assertEquals($libraryVersion + 1, $json[$settingKey]['version']);
		
		// Single-object GET
		$response = API::userGet(
			self::$config['userID'],
			"settings/$settingKey"
		);
		$this->assert200($response);
		$this->assertContentType("application/json", $response);
		$json = json_decode($response->getBody(), true);
		$this->assertNotNull($json);
		$this->assertEquals($value, $json['value']);
		$this->assertEquals($libraryVersion + 1, $json['version']);
	}
	
	
	public function testAddUserSettingMultiple() {
		$settingKey = "tagColors";
		$value = array(
			array(
				"name" => "_READ",
				"color" => "#990000"
			)
		);
		
		// TODO: multiple, once more settings are supported
		
		$libraryVersion = API::getLibraryVersion();
		
		$json = array(
			$settingKey => array(
				"value" => $value
			)
		);
		$response = API::userPost(
			self::$config['userID'],
			"settings",
			json_encode($json),
			array("Content-Type: application/json")
		);
		$this->assert204($response);
		
		// Multi-object GET
		$response = API::userGet(
			self::$config['userID'],
			"settings"
		);
		$this->assert200($response);
		$this->assertContentType("application/json", $response);
		$json = json_decode($response->getBody(), true);
		$this->assertNotNull($json);
		$this->assertArrayHasKey($settingKey, $json);
		$this->assertEquals($value, $json[$settingKey]['value']);
		$this->assertEquals($libraryVersion + 1, $json[$settingKey]['version']);
		
		// Single-object GET
		$response = API::userGet(
			self::$config['userID'],
			"settings/$settingKey"
		);
		$this->assert200($response);
		$this->assertContentType("application/json", $response);
		$json = json_decode($response->getBody(), true);
		$this->assertNotNull($json);
		$this->assertEquals($value, $json['value']);
		$this->assertEquals($libraryVersion + 1, $json['version']);
	}
	
	
	public function testAddGroupSettingMultiple() {
		$settingKey = "tagColors";
		$value = array(
			array(
				"name" => "_READ",
				"color" => "#990000"
			)
		);
		
		// TODO: multiple, once more settings are supported
		
		$groupID = self::$config['ownedPrivateGroupID'];
		$libraryVersion = API::getGroupLibraryVersion($groupID);
		
		$json = array(
			$settingKey => array(
				"value" => $value
			)
		);
		$response = API::groupPost(
			$groupID,
			"settings",
			json_encode($json),
			array("Content-Type: application/json")
		);
		$this->assert204($response);
		
		// Multi-object GET
		$response = API::groupGet(
			$groupID,
			"settings"
		);
		$this->assert200($response);
		$this->assertContentType("application/json", $response);
		$json = json_decode($response->getBody(), true);
		$this->assertNotNull($json);
		$this->assertArrayHasKey($settingKey, $json);
		$this->assertEquals($value, $json[$settingKey]['value']);
		$this->assertEquals($libraryVersion + 1, $json[$settingKey]['version']);
		
		// Single-object GET
		$response = API::groupGet(
			$groupID,
			"settings/$settingKey"
		);
		$this->assert200($response);
		$this->assertContentType("application/json", $response);
		$json = json_decode($response->getBody(), true);
		$this->assertNotNull($json);
		$this->assertEquals($value, $json['value']);
		$this->assertEquals($libraryVersion + 1, $json['version']);
	}
	
	
	public function testUpdateUserSetting() {
		$settingKey = "tagColors";
		$value = array(
			array(
				"name" => "_READ",
				"color" => "#990000"
			)
		);
		
		$libraryVersion = API::getLibraryVersion();
		
		$json = array(
			"value" => $value,
			"version" => 0
		);
		
		// Create
		$response = API::userPut(
			self::$config['userID'],
			"settings/$settingKey",
			json_encode($json),
			array(
				"Content-Type: application/json"
			)
		);
		$this->assert204($response);
		
		// Check
		$response = API::userGet(
			self::$config['userID'],
			"settings/$settingKey"
		);
		$this->assert200($response);
		$this->assertContentType("application/json", $response);
		$json = json_decode($response->getBody(), true);
		$this->assertNotNull($json);
		$this->assertEquals($value, $json['value']);
		$this->assertEquals($libraryVersion + 1, $json['version']);
		
		// Update with no change
		$response = API::userPut(
			self::$config['userID'],
			"settings/$settingKey",
			json_encode($json),
			array(
				"Content-Type: application/json"
			)
		);
		$this->assert204($response);
		
		// Check
		$response = API::userGet(
			self::$config['userID'],
			"settings/$settingKey"
		);
		$this->assert200($response);
		$this->assertContentType("application/json", $response);
		$json = json_decode($response->getBody(), true);
		$this->assertNotNull($json);
		$this->assertEquals($value, $json['value']);
		$this->assertEquals($libraryVersion + 1, $json['version']);
		
		$newValue = array(
			array(
				"name" => "_READ",
				"color" => "#CC9933"
			)
		);
		$json['value'] = $newValue;
		
		// Update, no change
		$response = API::userPut(
			self::$config['userID'],
			"settings/$settingKey",
			json_encode($json),
			array(
				"Content-Type: application/json"
			)
		);
		$this->assert204($response);
		
		// Check
		$response = API::userGet(
			self::$config['userID'],
			"settings/$settingKey"
		);
		$this->assert200($response);
		$this->assertContentType("application/json", $response);
		$json = json_decode($response->getBody(), true);
		$this->assertNotNull($json);
		$this->assertEquals($newValue, $json['value']);
		$this->assertEquals($libraryVersion + 2, $json['version']);
	}
	
	
	public function testDeleteUserSetting() {
		$settingKey = "tagColors";
		$value = array(
			array(
				"name" => "_READ",
				"color" => "#990000"
			)
		);
		
		$json = array(
			"value" => $value,
			"version" => 0
		);
		
		$libraryVersion = API::getLibraryVersion();
		
		// Create
		$response = API::userPut(
			self::$config['userID'],
			"settings/$settingKey",
			json_encode($json),
			array("Content-Type: application/json")
		);
		$this->assert204($response);
		
		// Delete
		$response = API::userDelete(
			self::$config['userID'],
			"settings/$settingKey",
			array(
				"If-Unmodified-Since-Version: " . ($libraryVersion + 1)
			)
		);
		$this->assert204($response);
		
		// Check
		$response = API::userGet(
			self::$config['userID'],
			"settings/$settingKey"
		);
		$this->assert404($response);
		
		$this->assertEquals($libraryVersion + 2, API::getLibraryVersion());
	}
	
	
	public function testDeleteNonexistentSetting() {
		$response = API::userDelete(
			self::$config['userID'],
			"settings/nonexistentSetting",
			array(
				"If-Unmodified-Since-Version: 0"
			)
		);
		$this->assert404($response);
	}
	
	
	public function testUnsupportedSetting() {
		$settingKey = "unsupportedSetting";
		$value = true;
		
		$json = array(
			"value" => $value,
			"version" => 0
		);
		
		$response = API::userPut(
			self::$config['userID'],
			"settings/$settingKey",
			json_encode($json),
			array("Content-Type: application/json")
		);
		$this->assert400($response, "Invalid setting '$settingKey'");
	}
	
	
	public function testUnsupportedSettingMultiple() {
		$settingKey = "unsupportedSetting";
		$json = array(
			"tagColors" => array(
				"value" => array(
					"name" => "_READ",
					"color" => "#990000"
				),
				"version" => 0
			),
			$settingKey => array(
				"value" => false,
				"version" => 0
			)
		);
		
		$libraryVersion = API::getLibraryVersion();
		
		$response = API::userPut(
			self::$config['userID'],
			"settings/$settingKey",
			json_encode($json),
			array("Content-Type: application/json")
		);
		$this->assert400($response, "Invalid setting '$settingKey'");
		
		// Valid setting shouldn't exist, and library version should be unchanged
		$response = API::userGet(
			self::$config['userID'],
			"settings/$settingKey"
		);
		$this->assert404($response);
		$this->assertEquals($libraryVersion, API::getLibraryVersion());
	}
	
	
	public function testOverlongSetting() {
		$settingKey = "tagColors";
		$value = array(
			array(
				"name" => $this->content = str_repeat("abcdefghij", 1001),
				"color" => "#990000"
			)
		);
		
		$json = array(
			"value" => $value,
			"version" => 0
		);
		
		$response = API::userPut(
			self::$config['userID'],
			"settings/$settingKey",
			json_encode($json),
			array("Content-Type: application/json")
		);
		$this->assert400($response, "'value' cannot be longer than 1000 characters");
	}
}
