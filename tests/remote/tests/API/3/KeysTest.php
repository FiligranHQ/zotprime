<?php
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2014 Center for History and New Media
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

class KeysTest extends APITests {
	public function testGetKeys() {
		// No anonymous access
		API::useAPIKey("");
		$response = API::userGet(
			self::$config['userID'],
			'keys'
		);
		$this->assert403($response);
		
		// No access with user's API key
		API::useAPIKey(self::$config['apiKey']);
		$response = API::userGet(
			self::$config['userID'],
			'keys'
		);
		$this->assert403($response);
		
		// Root access
		$response = API::userGet(
			self::$config['userID'],
			'keys',
			[],
			[
				"username" => self::$config['rootUsername'],
				"password" => self::$config['rootPassword']
			]
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertTrue(is_array($json));
		$this->assertTrue(sizeOf($json) > 0);
		$this->assertArrayHasKey('dateAdded', $json[0]);
		$this->assertArrayHasKey('lastUsed', $json[0]);
		$this->assertArrayHasKey('recentIPs', $json[0]);
	}
	
	
	public function testGetKeyInfoCurrent() {
		API::useAPIKey("");
		$response = API::get(
			'keys/current',
			[
				"Zotero-API-Key" => self::$config['apiKey']
			]
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals(self::$config['apiKey'], $json['key']);
		$this->assertEquals(self::$config['userID'], $json['userID']);
		$this->arrayHasKey("user", $json['access']);
		$this->arrayHasKey("groups", $json['access']);
		$this->assertTrue($json['access']['user']['library']);
		$this->assertTrue($json['access']['user']['files']);
		$this->assertTrue($json['access']['user']['notes']);
		$this->assertTrue($json['access']['user']['write']);
		$this->assertTrue($json['access']['groups']['all']['library']);
		$this->assertTrue($json['access']['groups']['all']['write']);
		$this->assertArrayNotHasKey('name', $json);
		$this->assertArrayNotHasKey('dateAdded', $json);
		$this->assertArrayNotHasKey('lastUsed', $json);
		$this->assertArrayNotHasKey('recentIPs', $json);
	}
	
	
	public function testGetKeyInfoCurrentWithoutHeader() {
		API::useAPIKey("");
		$response = API::get('keys/current');
		$this->assert403($response);
	}
	
	
	public function testGetKeyInfoByPath() {
		API::useAPIKey("");
		$response = API::get('keys/' . self::$config['apiKey']);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals(self::$config['apiKey'], $json['key']);
		$this->assertEquals(self::$config['userID'], $json['userID']);
		$this->arrayHasKey("user", $json['access']);
		$this->arrayHasKey("groups", $json['access']);
		$this->assertTrue($json['access']['user']['library']);
		$this->assertTrue($json['access']['user']['files']);
		$this->assertTrue($json['access']['user']['notes']);
		$this->assertTrue($json['access']['user']['write']);
		$this->assertTrue($json['access']['groups']['all']['library']);
		$this->assertTrue($json['access']['groups']['all']['write']);
		$this->assertArrayNotHasKey('name', $json);
		$this->assertArrayNotHasKey('dateAdded', $json);
		$this->assertArrayNotHasKey('lastUsed', $json);
		$this->assertArrayNotHasKey('recentIPs', $json);
	}
	
	
	// Deprecated
	public function testGetKeyInfoWithUser() {
		API::useAPIKey("");
		$response = API::userGet(
			self::$config['userID'],
			'keys/' . self::$config['apiKey']
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals(self::$config['apiKey'], $json['key']);
		$this->assertEquals(self::$config['userID'], $json['userID']);
		$this->arrayHasKey("user", $json['access']);
		$this->arrayHasKey("groups", $json['access']);
		$this->assertTrue($json['access']['user']['library']);
		$this->assertTrue($json['access']['user']['files']);
		$this->assertTrue($json['access']['user']['notes']);
		$this->assertTrue($json['access']['user']['write']);
		$this->assertTrue($json['access']['groups']['all']['library']);
		$this->assertTrue($json['access']['groups']['all']['write']);
	}
	
	
	public function testKeyCreateAndDelete() {
		API::useAPIKey("");
		
		$name = "Test " . uniqid();
		
		// Can't create anonymously
		$response = API::userPost(
			self::$config['userID'],
			'keys',
			json_encode([
				'name' => $name,
				'access' => [
					'user' => [
						'library' => true
					]
				]
			])
		);
		$this->assert403($response);
		
		// Create as root
		$response = API::userPost(
			self::$config['userID'],
			'keys',
			json_encode([
				'name' => $name,
				'access' => [
					'user' => [
						'library' => true
					]
				]
			]),
			[],
			[
				"username" => self::$config['rootUsername'],
				"password" => self::$config['rootPassword']
			]
		);
		$this->assert201($response);
		$json = API::getJSONFromResponse($response);
		$key = $json['key'];
		$this->assertEquals($json['name'], $name);
		$this->assertEquals(['user' => ['library' => true, 'files' => true]], $json['access']);
		
		// Delete anonymously (with embedded key)
		$response = API::userDelete(
			self::$config['userID'],
			"keys/current",
			[
				"Zotero-API-Key" => $key
			]
		);
		$this->assert204($response);
		
		$response = API::userGet(
			self::$config['userID'],
			"keys/current",
			[
				"Zotero-API-Key" => $key
			]
		);
		$this->assert403($response);
	}
	
	
	// Private API
	public function testKeyCreateAndModifyWithCredentials() {
		API::useAPIKey("");
		
		$name = "Test " . uniqid();
		
		// Can't create on /users/:userID/keys with credentials
		$response = API::userPost(
			self::$config['userID'],
			'keys',
			json_encode([
				'username' => self::$config['username'],
				'password' => self::$config['password'],
				'name' => $name,
				'access' => [
					'user' => [
						'library' => true
					]
				]
			])
		);
		$this->assert403($response);
		
		// Create with credentials
		$response = API::post(
			'keys',
			json_encode([
				'username' => self::$config['username'],
				'password' => self::$config['password'],
				'name' => $name,
				'access' => [
					'user' => [
						'library' => true
					]
				]
			]),
			[],
			[]
		);
		$this->assert201($response);
		$json = API::getJSONFromResponse($response);
		$key = $json['key'];
		$this->assertEquals($json['userID'], self::$config['userID']);
		$this->assertEquals($json['name'], $name);
		$this->assertEquals(['user' => ['library' => true, 'files' => true]], $json['access']);
		
		$name = "Test " . uniqid();
		
		// Can't modify on /users/:userID/keys/:key with credentials
		$response = API::userPut(
			self::$config['userID'],
			"keys/$key",
			json_encode([
				'username' => self::$config['username'],
				'password' => self::$config['password'],
				'name' => $name,
				'access' => [
					'user' => [
						'library' => true
					]
				]
			])
		);
		$this->assert403($response);
		
		// Modify with credentials
		$response = API::put(
			"keys/$key",
			json_encode([
				'username' => self::$config['username'],
				'password' => self::$config['password'],
				'name' => $name,
				'access' => [
					'user' => [
						'library' => true
					]
				]
			])
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$key = $json['key'];
		$this->assertEquals($json['name'], $name);
		
		$response = API::userDelete(
			self::$config['userID'],
			"keys/$key"
		);
		$this->assert204($response);
	}
}
