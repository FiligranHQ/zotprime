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

require_once 'APITests.inc.php';
require_once 'include/api.inc.php';

class FullTextTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testSetItemContent() {
		$key = API::createItem("book", false, $this, 'key');
		$xml = API::createAttachmentItem("imported_url", $key, $this, 'atom');
		$data = API::parseDataFromAtomEntry($xml);
		
		$response = API::userGet(
			self::$config['userID'],
			"items/{$data['key']}/fulltext?key=" . self::$config['apiKey']
		);
		$this->assert404($response);
		$this->assertNull($response->getHeader("Last-Modified-Version"));
		
		$libraryVersion = API::getLibraryVersion();
		
		$content = "Here is some full-text content";
		
		// No Content-Type
		$response = API::userPut(
			self::$config['userID'],
			"items/{$data['key']}/fulltext?key=" . self::$config['apiKey'],
			$content
		);
		$this->assert400($response, "Content-Type must be text/plain");
		
		// Store content
		$response = API::userPut(
			self::$config['userID'],
			"items/{$data['key']}/fulltext?key=" . self::$config['apiKey'],
			$content,
			array("Content-Type: text/plain")
		);
		
		$this->assert200($response);
		$contentVersion = $response->getHeader("Last-Modified-Version");
		$this->assertGreaterThan($libraryVersion, $contentVersion);
		
		// Retrieve it
		$response = API::userGet(
			self::$config['userID'],
			"items/{$data['key']}/fulltext?key=" . self::$config['apiKey']
		);
		$this->assert200($response);
		$this->assertContentType("text/plain; charset=UTF-8", $response);
		$this->assertEquals($content, $response->getBody());
		$this->assertEquals($contentVersion, $response->getHeader("Last-Modified-Version"));
	}
	
	
	public function testNewerContent() {
		API::userClear(self::$config['userID']);
		
		// Store content for one item
		$key = API::createItem("book", false, $this, 'key');
		$xml = API::createAttachmentItem("imported_url", $key, $this, 'atom');
		$data = API::parseDataFromAtomEntry($xml);
		$key1 = $data['key'];
		
		$content = "Here is some full-text content";
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key1/fulltext?key=" . self::$config['apiKey'],
			$content,
			array("Content-Type: text/plain")
		);
		$this->assert200($response);
		$contentVersion1 = $response->getHeader("Last-Modified-Version");
		
		// And another
		$key = API::createItem("book", false, $this, 'key');
		$xml = API::createAttachmentItem("imported_url", $key, $this, 'atom');
		$data = API::parseDataFromAtomEntry($xml);
		$key2 = $data['key'];
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key2/fulltext?key=" . self::$config['apiKey'],
			$content,
			array("Content-Type: text/plain")
		);
		$this->assert200($response);
		$contentVersion2 = $response->getHeader("Last-Modified-Version");
		
		// Get newer one
		$response = API::userGet(
			self::$config['userID'],
			"fulltext?key=" . self::$config['apiKey']
				. "&newer=$contentVersion1&format=versions"
		);
		$this->assert200($response);
		$this->assertContentType("application/json", $response);
		$json = API::getJSONFromResponse($response);
		$this->assertCount(1, $json);
		$this->assertArrayHasKey($key2, $json);
		$this->assertEquals($contentVersion2, $json[$key2]);
	}
	
	
	public function testDeleteItemContent() {
		$key = API::createItem("book", false, $this, 'key');
		$xml = API::createAttachmentItem("imported_file", $key, $this, 'atom');
		$data = API::parseDataFromAtomEntry($xml);
		
		$content = "Ыюм мютат дэбетиз конвынёры эю, ку мэль жкрипта трактатоз.\nПро ут чтэт эрепюят граэкйж, дуо нэ выро рыкючабо пырикюлёз.";
		
		// Store content
		$response = API::userPut(
			self::$config['userID'],
			"items/{$data['key']}/fulltext?key=" . self::$config['apiKey'],
			$content,
			array("Content-Type: text/plain")
		);
		$this->assert200($response);
		$contentVersion = $response->getHeader("Last-Modified-Version");
		
		// Retrieve it
		$response = API::userGet(
			self::$config['userID'],
			"items/{$data['key']}/fulltext?key=" . self::$config['apiKey']
		);
		$this->assert200($response);
		
		// Delete it
		$response = API::userDelete(
			self::$config['userID'],
			"items/{$data['key']}/fulltext?key=" . self::$config['apiKey']
		);
		$this->assert204($response);
		$this->assertGreaterThan($contentVersion, $response->getHeader("Last-Modified-Version"));
		
		// Make sure it's gone
		$response = API::userGet(
			self::$config['userID'],
			"items/{$data['key']}/fulltext?key=" . self::$config['apiKey']
		);
		$this->assert404($response);
	}
}
