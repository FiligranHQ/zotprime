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

namespace APIv3;
use API3 as API;
require_once 'APITests.inc.php';
require_once 'include/api3.inc.php';

class FullTextTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testVersionsAnonymous() {
		API::useAPIKey(false);
		$response = API::userGet(
			self::$config['userID'],
			"fulltext"
		);
		$this->assert403($response);
	}
	
	
	public function testContentAnonymous() {
		API::useAPIKey(false);
		$response = API::userGet(
			self::$config['userID'],
			"items/AAAAAAAA/fulltext"
		);
		$this->assert403($response);
	}
	
	public function testSetItemContent() {
		$key = API::createItem("book", false, $this, 'key');
		$attachmentKey = API::createAttachmentItem("imported_url", [], $key, $this, 'key');
		
		$response = API::userGet(
			self::$config['userID'],
			"items/$attachmentKey/fulltext"
		);
		$this->assert404($response);
		$this->assertNull($response->getHeader("Last-Modified-Version"));
		
		$libraryVersion = API::getLibraryVersion();
		
		$content = "Here is some full-text content";
		$pages = 50;
		
		// No Content-Type
		$response = API::userPut(
			self::$config['userID'],
			"items/$attachmentKey/fulltext",
			$content
		);
		$this->assert400($response, "Content-Type must be application/json");
		
		// Store content
		$response = API::userPut(
			self::$config['userID'],
			"items/$attachmentKey/fulltext",
			json_encode([
				"content" => $content,
				"indexedPages" => $pages,
				"totalPages" => $pages,
				"invalidParam" => "shouldBeIgnored"
			]),
			array("Content-Type: application/json")
		);
		
		$this->assert204($response);
		$contentVersion = $response->getHeader("Last-Modified-Version");
		$this->assertGreaterThan($libraryVersion, $contentVersion);
		
		// Retrieve it
		$response = API::userGet(
			self::$config['userID'],
			"items/$attachmentKey/fulltext"
		);
		$this->assert200($response);
		$this->assertContentType("application/json", $response);
		$json = json_decode($response->getBody(), true);
		$this->assertEquals($content, $json['content']);
		$this->assertArrayHasKey('indexedPages', $json);
		$this->assertArrayHasKey('totalPages', $json);
		$this->assertEquals($pages, $json['indexedPages']);
		$this->assertEquals($pages, $json['totalPages']);
		$this->assertArrayNotHasKey("indexedChars", $json);
		$this->assertArrayNotHasKey("invalidParam", $json);
		$this->assertEquals($contentVersion, $response->getHeader("Last-Modified-Version"));
	}
	
	
	public function testModifyAttachmentWithFulltext() {
		$key = API::createItem("book", false, $this, 'key');
		$json = API::createAttachmentItem("imported_url", [], $key, $this, 'jsonData');
		$attachmentKey = $json['key'];
		$content = "Here is some full-text content";
		$pages = 50;
		
		// Store content
		$response = API::userPut(
			self::$config['userID'],
			"items/$attachmentKey/fulltext",
			json_encode([
				"content" => $content,
				"indexedPages" => $pages,
				"totalPages" => $pages
			]),
			array("Content-Type: application/json")
		);
		$this->assert204($response);
		
		$json['title'] = "This is a new attachment title";
		$json['contentType'] = 'text/plain';
		
		// Modify attachment item
		$response = API::userPut(
			self::$config['userID'],
			"items/$attachmentKey",
			json_encode($json),
			array("If-Unmodified-Since-Version: " . $json['version'])
		);
		$this->assert204($response);
	}
	
	
	public function testSinceContent() {
		self::_testSinceContent('since');
		self::_testSinceContent('newer');
	}
	
	
	public function testSearchItemContent() {
		$key = API::createItem("book", false, $this, 'key');
		$json = API::createAttachmentItem("imported_url", [], $key, $this, 'jsonData');
		$attachmentKey = $json['key'];
		
		$response = API::userGet(
			self::$config['userID'],
			"items/$attachmentKey/fulltext"
		);
		$this->assert404($response);
		
		$content = "Here is some unique full-text content";
		$pages = 50;
		
		// Store content
		$response = API::userPut(
			self::$config['userID'],
			"items/$attachmentKey/fulltext",
			json_encode([
				"content" => $content,
				"indexedPages" => $pages,
				"totalPages" => $pages
			]),
			array("Content-Type: application/json")
		);
		
		$this->assert204($response);
		
		// Wait for refresh
		sleep(1);
		
		// Search for a word
		$response = API::userGet(
			self::$config['userID'],
			"items?q=unique&qmode=everything&format=keys"
			. "&key=" . self::$config['apiKey']
		);
		$this->assert200($response);
		$this->assertEquals($json['key'], trim($response->getBody()));
		
		// Search for a phrase
		$response = API::userGet(
			self::$config['userID'],
			"items?q=unique%20full-text&qmode=everything&format=keys"
			. "&key=" . self::$config['apiKey']
		);
		$this->assert200($response);
		$this->assertEquals($attachmentKey, trim($response->getBody()));
		
		// Search for nonexistent word
		$response = API::userGet(
			self::$config['userID'],
			"items?q=nothing&qmode=everything&format=keys"
			. "&key=" . self::$config['apiKey']
		);
		$this->assert200($response);
		$this->assertEquals("", trim($response->getBody()));
	}
	
	
	public function testDeleteItemContent() {
		$key = API::createItem("book", false, $this, 'key');
		$attachmentKey = API::createAttachmentItem("imported_file", [], $key, $this, 'key');
		
		$content = "Ыюм мютат дэбетиз конвынёры эю, ку мэль жкрипта трактатоз.\nПро ут чтэт эрепюят граэкйж, дуо нэ выро рыкючабо пырикюлёз.";
		
		// Store content
		$response = API::userPut(
			self::$config['userID'],
			"items/$attachmentKey/fulltext",
			json_encode([
				"content" => $content,
				"indexedPages" => 50
			]),
			array("Content-Type: application/json")
		);
		$this->assert204($response);
		$contentVersion = $response->getHeader("Last-Modified-Version");
		
		// Retrieve it
		$response = API::userGet(
			self::$config['userID'],
			"items/$attachmentKey/fulltext"
		);
		$this->assert200($response);
		$json = json_decode($response->getBody(), true);
		$this->assertEquals($content, $json['content']);
		$this->assertEquals(50, $json['indexedPages']);
		
		// Set to empty string
		$response = API::userPut(
			self::$config['userID'],
			"items/$attachmentKey/fulltext",
			json_encode([
				"content" => ""
			]),
			array("Content-Type: application/json")
		);
		$this->assert204($response);
		$this->assertGreaterThan($contentVersion, $response->getHeader("Last-Modified-Version"));
		
		// Make sure it's gone
		$response = API::userGet(
			self::$config['userID'],
			"items/$attachmentKey/fulltext"
		);
		$this->assert200($response);
		$json = json_decode($response->getBody(), true);
		$this->assertEquals("", $json['content']);
		$this->assertArrayNotHasKey("indexedPages", $json);
	}
	
	
	private function _testSinceContent($param) {
		API::userClear(self::$config['userID']);
		
		// Store content for one item
		$key = API::createItem("book", false, $this, 'key');
		$json = API::createAttachmentItem("imported_url", [], $key, $this, 'jsonData');
		$key1 = $json['key'];
		
		$content = "Here is some full-text content";
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key1/fulltext",
			json_encode([
				"content" => $content
			]),
			array("Content-Type: application/json")
		);
		$this->assert204($response);
		$contentVersion1 = $response->getHeader("Last-Modified-Version");
		$this->assertGreaterThan(0, $contentVersion1);
		
		// And another
		$key = API::createItem("book", false, $this, 'key');
		$json = API::createAttachmentItem("imported_url", [], $key, $this, 'jsonData');
		$key2 = $json['key'];
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key2/fulltext",
			json_encode([
				"content" => $content
			]),
			array("Content-Type: application/json")
		);
		$this->assert204($response);
		$contentVersion2 = $response->getHeader("Last-Modified-Version");
		$this->assertGreaterThan(0, $contentVersion2);
		
		// Get newer one
		$response = API::userGet(
			self::$config['userID'],
			"fulltext?$param=$contentVersion1"
		);
		$this->assert200($response);
		$this->assertContentType("application/json", $response);
		$this->assertEquals($contentVersion2, $response->getHeader("Last-Modified-Version"));
		$json = API::getJSONFromResponse($response);
		$this->assertCount(1, $json);
		$this->assertArrayHasKey($key2, $json);
		$this->assertEquals($contentVersion2, $json[$key2]);
		
		// Get both with since=0
		$response = API::userGet(
			self::$config['userID'],
			"fulltext?$param=0"
		);
		$this->assert200($response);
		$this->assertContentType("application/json", $response);
		$json = API::getJSONFromResponse($response);
		$this->assertCount(2, $json);
		$this->assertArrayHasKey($key1, $json);
		$this->assertEquals($contentVersion1, $json[$key1]);
		$this->assertArrayHasKey($key1, $json);
		$this->assertEquals($contentVersion2, $json[$key2]);
	}
}
