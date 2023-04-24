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

require_once 'APITests.inc.php';
require_once 'include/api3.inc.php';
use API3 as API;

class GeneralTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	public function setUp() {
		parent::setUp();
		API::useAPIKey(self::$config['apiKey']);
		API::useAPIVersion(false);
	}
	
	public function testAPIVersionHeader() {
		$minVersion = 1;
		$maxVersion = 3;
		$defaultVersion = 3;
		
		for ($i = $minVersion; $i <= $maxVersion; $i++) {
			$response = API::userGet(
				self::$config['userID'],
				"items?format=keys&limit=1",
				[
					"Zotero-API-Version: $i"
				]
			);
			$this->assertEquals($i, $response->getHeader("Zotero-API-Version"));
		}
		
		// Default
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys&limit=1"
		);
		$this->assertEquals($defaultVersion, $response->getHeader("Zotero-API-Version"));
	}
	
	
	public function testAPIVersionParameter() {
		$minVersion = 1;
		$maxVersion = 3;
		
		for ($i = $minVersion; $i <= $maxVersion; $i++) {
			$response = API::userGet(
				self::$config['userID'],
				"items?format=keys&limit=1&v=$i"
			);
			$this->assertEquals($i, $response->getHeader("Zotero-API-Version"));
		}
	}
	
	
	public function testAuthorization() {
		$apiKey = self::$config['apiKey'];
		API::useAPIKey(false);
		
		// Zotero-API-Key header
		$response = API::userGet(
			self::$config['userID'],
			"items",
			[
				"Zotero-API-Key: $apiKey"
			]
		);
		$this->assertHTTPStatus(200, $response);
		
		// Authorization header
		$response = API::userGet(
			self::$config['userID'],
			"items",
			[
				"Authorization: Bearer $apiKey"
			]
		);
		$this->assertHTTPStatus(200, $response);
		
		// Query parameter
		$response = API::userGet(
			self::$config['userID'],
			"items?key=$apiKey"
		);
		$this->assertHTTPStatus(200, $response);
		
		// Zotero-API-Key header and query parameter
		$response = API::userGet(
			self::$config['userID'],
			"items?key=$apiKey",
			[
				"Zotero-API-Key: $apiKey"
			]
		);
		$this->assertHTTPStatus(200, $response);
		
		// No key
		$response = API::userGet(
			self::$config['userID'],
			"items"
		);
		$this->assertHTTPStatus(403, $response);
		
		// Zotero-API-Key header and empty key (which is still an error)
		$response = API::userGet(
			self::$config['userID'],
			"items?key=",
			[
				"Zotero-API-Key: $apiKey"
			]
		);
		$this->assertHTTPStatus(400, $response);
		
		// Zotero-API-Key header and incorrect Authorization key (which is ignored)
		$response = API::userGet(
			self::$config['userID'],
			"items",
			[
				"Zotero-API-Key: $apiKey",
				"Authorization: Bearer invalidkey"
			]
		);
		$this->assertHTTPStatus(200, $response);
		
		// Zotero-API-Key header and key mismatch
		$response = API::userGet(
			self::$config['userID'],
			"items?key=invalidkey",
			[
				"Zotero-API-Key: $apiKey"
			]
		);
		$this->assertHTTPStatus(400, $response);
		
		// Invalid Bearer format
		$response = API::userGet(
			self::$config['userID'],
			"items",
			[
				"Authorization: Bearer key=$apiKey"
			]
		);
		$this->assertHTTPStatus(400, $response);
		
		// Ignored OAuth 1.0 header, with key query parameter
		$response = API::userGet(
			self::$config['userID'],
			"items?key=$apiKey",
			[
				'Authorization: OAuth oauth_consumer_key="aaaaaaaaaaaaaaaaaaaa"'
			]
		);
		$this->assertHTTPStatus(200, $response);
		
		// Ignored OAuth 1.0 header, with no key query parameter
		$response = API::userGet(
			self::$config['userID'],
			"items",
			[
				'Authorization: OAuth oauth_consumer_key="aaaaaaaaaaaaaaaaaaaa"'
			]
		);
		$this->assertHTTPStatus(403, $response);
	}
	
	
	public function testCORS() {
		$response = HTTP::options(
			self::$config['apiURLPrefix'],
			[
				'Origin: http://example.com'
			]
		);
		$this->assertHTTPStatus(200, $response);
		$this->assertEquals('', $response->getBody());
		$this->assertEquals('*', $response->getHeader('Access-Control-Allow-Origin'));
	}
	
	
	public function test200Compression() {
		$response = API::get("itemTypes");
		$this->assertHTTPStatus(200, $response);
		$this->assertCompression($response);
	}
	
	
	public function test404Compression() {
		$response = API::get("invalidurl");
		$this->assertHTTPStatus(404, $response);
		$this->assertCompression($response);
	}
	
	
	public function test204NoCompression() {
		$json = API::createItem("book", [], null, 'jsonData');
		$response = API::userDelete(
			self::$config['userID'],
			"items/{$json['key']}",
			[
				"If-Unmodified-Since-Version: {$json['version']}"
			]
		);
		$this->assertHTTPStatus(204, $response);
		$this->assertNoCompression($response);
		$this->assertContentLength(0, $response);
	}
}
