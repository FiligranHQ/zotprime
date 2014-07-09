<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2012 Center for History and New Media
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

class TagTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		require 'include/config.inc.php';
		API::userClear($config['userID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		require 'include/config.inc.php';
		API::userClear($config['userID']);
	}
	
	
	public function testEmptyTag() {
		$json = API::getItemTemplate("book");
		$json->tags[] = array(
			"tag" => "",
			"type" => 1
		);
		
		$response = API::postItem($json);
		$this->assert400ForObject($response, "Tag cannot be empty");
	}
	
	
	public function testInvalidTagObject() {
		$json = API::getItemTemplate("book");
		$json->tags[] = array("invalid");
		
		$response = API::postItem($json);
		$this->assert400ForObject($response, "Tag must be an object");
	}
	
	
	public function testItemTagSearch() {
		API::userClear(self::$config['userID']);
		
		// Create items with tags
		$key1 = API::createItem("book", array(
			"tags" => array(
				array("tag" => "a"),
				array("tag" => "b")
			)
		), $this, 'key');
		
		$key2 = API::createItem("book", array(
			"tags" => array(
				array("tag" => "a"),
				array("tag" => "c")
			)
		), $this, 'key');
		
		//
		// Searches
		//
		
		// a (both)
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys&tag=a"
		);
		$this->assert200($response);
		$keys = explode("\n", trim($response->getBody()));
		$this->assertCount(2, $keys);
		$this->assertContains($key1, $keys);
		$this->assertContains($key2, $keys);
		
		// a and c (#2)
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys&tag=a&tag=c"
		);
		$this->assert200($response);
		$keys = explode("\n", trim($response->getBody()));
		$this->assertCount(1, $keys);
		$this->assertContains($key2, $keys);
		
		// b and c (none)
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys&tag=b&tag=c"
		);
		$this->assert200($response);
		$this->assertEmpty(trim($response->getBody()));
		
		// b or c (both)
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys&tag=b%20||%20c"
		);
		$this->assert200($response);
		$keys = explode("\n", trim($response->getBody()));
		$this->assertCount(2, $keys);
		$this->assertContains($key1, $keys);
		$this->assertContains($key2, $keys);
		
		// a or b or c (both)
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys&tag=a%20||%20b%20||%20c"
		);
		$this->assert200($response);
		$keys = explode("\n", trim($response->getBody()));
		$this->assertCount(2, $keys);
		$this->assertContains($key1, $keys);
		$this->assertContains($key2, $keys);
		
		// not a (none)
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys&tag=-a"
		);
		$this->assert200($response);
		$this->assertEmpty(trim($response->getBody()));
		
		// not b (#2)
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys&tag=-b"
		);
		$this->assert200($response);
		$keys = explode("\n", trim($response->getBody()));
		$this->assertCount(1, $keys);
		$this->assertContains($key2, $keys);
		
		// (b or c) and a (both)
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys&tag=b%20||%20c&tag=a"
		);
		$this->assert200($response);
		$keys = explode("\n", trim($response->getBody()));
		$this->assertCount(2, $keys);
		$this->assertContains($key1, $keys);
		$this->assertContains($key2, $keys);
		
		// not nonexistent (both)
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys&tag=-z"
		);
		$this->assert200($response);
		$keys = explode("\n", trim($response->getBody()));
		$this->assertCount(2, $keys);
		$this->assertContains($key1, $keys);
		$this->assertContains($key2, $keys);
		
		// A (case-insensitive search)
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys&tag=B"
		);
		$this->assert200($response);
		$keys = explode("\n", trim($response->getBody()));
		$this->assertCount(1, $keys);
		$this->assertContains($key1, $keys);
	}
	
	
	public function testTagSearch() {
		$tags1 = array("a", "aa", "b");
		$tags2 = array("b", "c", "cc");
		
		$itemKey1 = API::createItem("book", array(
			"tags" => array_map(function ($tag) {
				return array("tag" => $tag);
			}, $tags1)
		), $this, 'key');
		
		$itemKey2 = API::createItem("book", array(
			"tags" => array_map(function ($tag) {
				return array("tag" => $tag);
			}, $tags2)
		), $this, 'key');
		
		$response = API::userGet(
			self::$config['userID'],
			"tags?tag=" . implode("%20||%20", $tags1)
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($tags1), $response);
	}
	
	
	public function testTagNewer() {
		API::userClear(self::$config['userID']);
		
		// Create items with tags
		API::createItem("book", array(
			"tags" => array(
				array("tag" => "a"),
				array("tag" => "b")
			)
		), $this);
		
		$version = API::getLibraryVersion();
		
		// 'newer' shouldn't return any results
		$response = API::userGet(
			self::$config['userID'],
			"tags?newer=$version"
		);
		$this->assert200($response);
		$this->assertNumResults(0, $response);
		
		// Create another item with tags
		API::createItem("book", array(
			"tags" => array(
				array("tag" => "a"),
				array("tag" => "c")
			)
		), $this);
		
		// 'newer' should return new tag (Atom)
		$response = API::userGet(
			self::$config['userID'],
			"tags?content=json&newer=$version"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$this->assertGreaterThan($version, $response->getHeader('Last-Modified-Version'));
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$data = json_decode($data['content'], true);
		$this->assertEquals("c", $data['tag']);
		$this->assertEquals(0, $data['type']);
		
		// 'newer' should return new tag (JSON)
		$response = API::userGet(
			self::$config['userID'],
			"tags?newer=$version"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$this->assertGreaterThan($version, $response->getHeader('Last-Modified-Version'));
		$json = API::getJSONFromResponse($response)[0];
		$this->assertEquals("c", $json['tag']);
		$this->assertEquals(0, $json['meta']['type']);
	}
	
	
	public function testMultiTagDelete() {
		$tags1 = array("a", "aa", "b");
		$tags2 = array("b", "c", "cc");
		$tags3 = array("Foo");
		
		API::createItem("book", array(
			"tags" => array_map(function ($tag) {
				return array("tag" => $tag);
			}, $tags1)
		), $this, 'key');
		
		API::createItem("book", array(
			"tags" => array_map(function ($tag) {
				return array("tag" => $tag, "type" => 1);
			}, $tags2)
		), $this, 'key');
		
		API::createItem("book", array(
			"tags" => array_map(function ($tag) {
				return array("tag" => $tag);
			}, $tags3)
		), $this, 'key');
		
		$libraryVersion = API::getLibraryVersion();
		
		// Missing version header
		$response = API::userDelete(
			self::$config['userID'],
			"tags?tag=" . implode("%20||%20", array_merge($tags1, $tags2))
		);
		$this->assert428($response);
		
		// Outdated version header
		$response = API::userDelete(
			self::$config['userID'],
			"tags?tag=" . implode("%20||%20", array_merge($tags1, $tags2)),
			array("If-Unmodified-Since-Version: " . ($libraryVersion - 1))
		);
		$this->assert412($response);
		
		// Delete
		$response = API::userDelete(
			self::$config['userID'],
			"tags?tag=" . implode("%20||%20", array_merge($tags1, $tags2)),
			array("If-Unmodified-Since-Version: $libraryVersion")
		);
		$this->assert204($response);
		
		// Make sure they're gone
		$response = API::userGet(
			self::$config['userID'],
			"tags?tag=" . implode("%20||%20", array_merge($tags1, $tags2, $tags3))
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
	}
	
	
	/**
	 * When modifying a tag on an item, only the item itself should have its
	 * version updated, not other items that had (and still have) the same tag
	 */
	public function testTagAddItemVersionChange() {
		$data1 = API::createItem("book", array(
			"tags" => array(
				array("tag" => "a"),
				array("tag" => "b")
			)
		), $this, 'jsonData');
		$version1 = $data1['version'];
		
		$data2 = API::createItem("book", array(
			"tags" => array(
				array("tag" => "a"),
				array("tag" => "c")
			)
		), $this, 'jsonData');
		$version2 = $data2['version'];
		
		// Remove tag 'a' from item 1
		$json1['tags'] = array(
			array("tag" => "d"),
			array("tag" => "c")
		);
		
		$response = API::postItem($data1);
		$this->assert200($response);
		
		// Item 1 version should be one greater than last update
		$json1 = API::getItem($data1['key'], $this, 'json');
		$this->assertEquals($version2 + 1, $json1['version']);
		
		// Item 2 version shouldn't have changed
		$json2 = API::getItem($data2['key'], $this, 'json');
		$this->assertEquals($version2, $json2['version']);
	}
	
	
	public function testTagDiacritics() {
		$data = API::createItem("book", [
			"tags" => [
				["tag" => "ëtest"],
			]
		], $this, 'jsonData');
		$version = $data['version'];
		
		// Add 'etest', without accent
		$data['tags'] = [
			["tag" => "ëtest"],
			["tag" => "etest"],
		];
		
		$response = API::postItem($data);
		$this->assert200($response);
		$this->assert200ForObject($response);
		
		// Item version should be one greater than last update
		$data = API::getItem($data['key'], $this, 'json')['data'];
		$this->assertEquals($version + 1, $data['version']);
		$this->assertCount(2, $data['tags']);
		$this->assertContains(["tag" => "ëtest"], $data['tags']);
		$this->assertContains(["tag" => "etest"], $data['tags']);
	}
}
