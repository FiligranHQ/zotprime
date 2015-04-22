<?php
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2015 Center for History and New Media
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

class PublicationsTests extends APITests {
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
		// Default to anonymous requests
		API::useAPIKey("");
	}
	
	
	//
	// Test read requests for publications library that hasn't been created yet
	//
	public function testNonExistentLibraryItems() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/items");
		$this->assert200($response);
		$this->assertNoResults($response);
		$this->assertLastModifiedVersion(0, $response);
	}
	
	
	public function testNonExistentLibraryItemsAtom() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/items?format=atom");
		$this->assert200($response);
		$this->assertNoResults($response);
		$this->assertLastModifiedVersion(0, $response);
	}
	
	
	public function testNonExistentLibraryTags() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/tags");
		$this->assert200($response);
		$this->assertNoResults($response);
		$this->assertLastModifiedVersion(0, $response);
	}
	
	
	public function testNonExistentLibraryTagsAtom() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/tags?format=atom");
		$this->assert200($response);
		$this->assertNoResults($response);
		$this->assertLastModifiedVersion(0, $response);
	}
	
	
	public function testNonExistentItem() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/items/ZZZZZZZZ");
		$this->assert404($response);
	}
	
	
	public function testNonExistentTag() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/tags/nonexistent");
		$this->assert404($response);
	}
	
	
	
	public function testNoCollectionsSupport() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/collections");
		$this->assert404($response);
	}
	
	
	public function testNoSearchesSupport() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/searches");
		$this->assert404($response);
	}
	
	
	public function testAnonymousWrite() {
		$json = API::getItemTemplate("book");
		$response = API::userPost(
			self::$config['userID'],
			"publications/items",
			json_encode($json)
		);
		// Should fail
		$this->assert403($response);
	}
	
	
	public function testCreateItemAndAnonymousRead() {
		// Create item
		API::useAPIKey(self::$config['apiKey']);
		$json = API::getItemTemplate("book");
		$response = API::userPost(
			self::$config['userID'],
			"publications/items",
			json_encode([$json])
		);
		$this->assert200ForObject($response);
		$json = API::getJSONFromResponse($response);
		$itemKey = $json['success'][0];
		
		// Read item anonymously
		API::useAPIKey("");
		$libraryName = self::$config['username'] . '’s Publications';
		
		// JSON
		$response = API::userGet(
			self::$config['userID'],
			"publications/items/$itemKey"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($libraryName, $json['library']['name']);
		$this->assertEquals("publications", $json['library']['type']);
		
		// Atom
		$response = API::userGet(
			self::$config['userID'],
			"publications/items/$itemKey?format=atom"
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals($libraryName, (string) $xml->author->name);
	}
	
	
	public function testTrashedItem() {
		API::useAPIKey(self::$config['apiKey']);
		$json = API::getItemTemplate("book");
		$json->deleted = true;
		$response = API::userPost(
			self::$config['userID'],
			"publications/items",
			json_encode([$json])
		);
		$this->assert400ForObject($response, "Items in publications libraries cannot be marked as deleted", 0);
	}
	
	
	public function testTopLevelAttachmentAndNote() {
		$msg = "Top-level notes and attachments cannot be added to publications libraries";
		
		// Attachment
		API::useAPIKey(self::$config['apiKey']);
		$json = API::getItemTemplate("attachment&linkMode=imported_file");
		$response = API::userPost(
			self::$config['userID'],
			"publications/items",
			json_encode([$json])
		);
		$this->assert400ForObject($response, $msg, 0);
		
		// Note
		API::useAPIKey(self::$config['apiKey']);
		$json = API::getItemTemplate("note");
		$response = API::userPost(
			self::$config['userID'],
			"publications/items",
			json_encode([$json])
		);
		$this->assert400ForObject($response, $msg, 0);
	}
	
	public function testLinkedFileAttachment() {
		$msg = "Linked-file attachments cannot be added to publications libraries";
		
		// Create top-level item
		API::useAPIKey(self::$config['apiKey']);
		$json = API::getItemTemplate("book");
		$response = API::userPost(
			self::$config['userID'],
			"publications/items",
			json_encode([$json])
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$itemKey = $json['success'][0];
		
		$json = API::getItemTemplate("attachment&linkMode=linked_file");
		$json->parentItem = $itemKey;
		API::useAPIKey(self::$config['apiKey']);
		$response = API::userPost(
			self::$config['userID'],
			"publications/items",
			json_encode([$json]),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject($response, $msg, 0);
	}
}
