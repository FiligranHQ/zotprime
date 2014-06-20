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

class SortTests extends APITests {
	private static $collectionKeys = [];
	private static $itemKeys = [];
	private static $childAttachmentKeys = [];
	private static $childNoteKeys = [];
	private static $searchKeys = [];
	
	private static $titles = ['q', 'c', 'a', 'j', 'e', 'h', 'i'];
	private static $names = ['m', 's', 'a', 'bb', 'ba', '', ''];
	private static $attachmentTitles = ['v', 'x', null, 'a', null];
	private static $notes = [null, 'aaa', null, null, 'taf'];
	
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
		
		//
		// Collections
		//
		/*for ($i=0; $i<5; $i++) {
			self::$collectionKeys[] = API::createCollection("Test", false, null, 'key');
		}*/
		
		//
		// Items
		//
		$titles = self::$titles;
		$names = self::$names;
		for ($i = 0; $i < sizeOf(self::$titles) - 2; $i++) {
			$key = API::createItem("book", [
				'title' => array_shift($titles),
				'creators' => [
					[
						"creatorType" => "author",
						"name" => array_shift($names)
					]
				]
			], null, 'key');
			
			// Child attachments
			if (!is_null(self::$attachmentTitles[$i])) {
				self::$childAttachmentKeys[] = API::createAttachmentItem("imported_file", [
					'title' => self::$attachmentTitles[$i]
				], $key, null, 'key');
			}
			// Child notes
			if (!is_null(self::$notes[$i])) {
				self::$childNoteKeys[] = API::createNoteItem(self::$notes[$i], $key, null, 'key');
			}
			
			self::$itemKeys[] = $key;
		}
		// Top-level attachment
		self::$itemKeys[] = API::createAttachmentItem("imported_file", [
			'title' => array_shift($titles)
		], false, null, 'key');
		// Top-level note
		self::$itemKeys[] = API::createNoteItem(array_shift($titles), false, null, 'key');
		
		//
		// Searches
		//
		/*for ($i=0; $i<5; $i++) {
			self::$searchKeys[] = API::createSearch("Test", 'default', null, 'key');
		}*/
	}
	
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testSortTopItemsTitle() {
		$response = API::userGet(
			self::$config['userID'],
			"items/top?format=keys&sort=title"
		);
		$this->assert200($response);
		$keys = explode("\n", trim($response->getBody()));
		$titles = self::$titles;
		asort($titles);
		$this->assertCount(sizeOf($titles), $keys);
		$correct = [];
		foreach ($titles as $k => $v) {
			// The key at position k in itemKeys should be at the same position in keys
			$correct[] = self::$itemKeys[$k];
		}
		$this->assertEquals($correct, $keys);
	}
	
	
	// Same thing, but with order parameter for backwards compatibility
	public function testSortTopItemsTitleOrder() {
		$response = API::userGet(
			self::$config['userID'],
			"items/top?format=keys&order=title"
		);
		$this->assert200($response);
		$keys = explode("\n", trim($response->getBody()));
		$titles = self::$titles;
		asort($titles);
		$this->assertCount(sizeOf($titles), $keys);
		$correct = [];
		foreach ($titles as $k => $v) {
			// The key at position k in itemKeys should be at the same position in keys
			$correct[] = self::$itemKeys[$k];
		}
		$this->assertEquals($correct, $keys);
	}
	
	
	public function testSortTopItemsCreator() {
		$response = API::userGet(
			self::$config['userID'],
			"items/top?format=keys&sort=creator"
		);
		$this->assert200($response);
		$keys = explode("\n", trim($response->getBody()));
		$names = self::$names;
		uasort($names, function ($a, $b) {
			if ($a === '' && $b !== '') return 1;
			if ($b === '' && $a !== '') return -1;
			return strcmp($a, $b);
		});
		$this->assertCount(sizeOf($names), $keys);
		$endKeys = array_splice($keys, -2);
		$correct = [];
		foreach ($names as $k => $v) {
			// The key at position k in itemKeys should be at the same position in keys
			$correct[] = self::$itemKeys[$k];
		}
		// Remove empty names
		array_splice($correct, -2);
		$this->assertEquals($correct, $keys);
		// Check attachment and note, which should fall back to ordered added (itemID)
		$this->assertEquals(array_slice(self::$itemKeys, -2), $endKeys);
	}
	
	
	// Same thing, but with 'order' for backwards compatibility
	public function testSortTopItemsCreatorOrder() {
		$response = API::userGet(
			self::$config['userID'],
			"items/top?format=keys&order=creator"
		);
		$this->assert200($response);
		$keys = explode("\n", trim($response->getBody()));
		$names = self::$names;
		uasort($names, function ($a, $b) {
			if ($a === '' && $b !== '') return 1;
			if ($b === '' && $a !== '') return -1;
			return strcmp($a, $b);
		});
		$this->assertCount(sizeOf($names), $keys);
		$endKeys = array_splice($keys, -2);
		$correct = [];
		foreach ($names as $k => $v) {
			// The key at position k in itemKeys should be at the same position in keys
			$correct[] = self::$itemKeys[$k];
		}
		// Remove empty names
		array_splice($correct, -2);
		$this->assertEquals($correct, $keys);
		// Check attachment and note, which should fall back to ordered added (itemID)
		$this->assertEquals(array_slice(self::$itemKeys, -2), $endKeys);
	}
	
	
	public function testSortDefault() {
		API::userClear(self::$config['userID']);
		
		// Setup
		$dataArray = [];
		
		$dataArray[] = API::createItem("book", [
			'title' => "B",
			'creators' => [
				[
					"creatorType" => "author",
					"name" => "B"
				]
			],
			'dateAdded' => '2014-02-05T00:00:00Z',
			'dateModified' => '2014-04-05T01:00:00Z'
		], $this, 'jsonData');
		
		$dataArray[] = API::createItem("journalArticle", [
			'title' => "A",
			'creators' => [
				[
					"creatorType" => "author",
					"name" => "A"
				]
			],
			'dateAdded' => '2014-02-04T00:00:00Z',
			'dateModified' => '2014-01-04T01:00:00Z'
		], $this, 'jsonData');
		
		$dataArray[] = API::createItem("newspaperArticle", [
			'title' => "F",
			'creators' => [
				[
					"creatorType" => "author",
					"name" => "F"
				]
			],
			'dateAdded' => '2014-02-03T00:00:00Z',
			'dateModified' => '2014-02-03T01:00:00Z'

		], $this, 'jsonData');
		
		
		$dataArray[] = API::createItem("book", [
			'title' => "C",
			'creators' => [
				[
					"creatorType" => "author",
					"name" => "C"
				]
			],
			'dateAdded' => '2014-02-02T00:00:00Z',
			'dateModified' => '2014-03-02T01:00:00Z'
		], $this, 'jsonData');
		
		// Get sorted keys
		usort($dataArray, function ($a, $b) {
			return strcmp($a['dateAdded'], $b['dateAdded']) * -1;
		});
		$keysByDateAddedDescending = array_map(function ($data) {
			return $data['key'];
		}, $dataArray);
		usort($dataArray, function ($a, $b) {
			return strcmp($a['dateModified'], $b['dateModified']) * -1;
		});
		$keysByDateModifiedDescending = array_map(function ($data) {
			return $data['key'];
		}, $dataArray);
		
		// Tests
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys"
		);
		$this->assert200($response);
		$this->assertEquals($keysByDateModifiedDescending, explode("\n", trim($response->getBody())));
		
		$response = API::userGet(
			self::$config['userID'],
			"items?format=json"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$keys = array_map(function ($val) {
			return $val['key'];
		}, $json);
		$this->assertEquals($keysByDateModifiedDescending, $keys);
		
		$response = API::userGet(
			self::$config['userID'],
			"items?format=atom"
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$keys = array_map(function ($val) {
			return (string) $val;
		}, $xml->xpath('//atom:entry/zapi:key'));
		$this->assertEquals($keysByDateAddedDescending, $keys);
	}
	
	
	public function testSortDirection() {
		API::userClear(self::$config['userID']);
		
		// Setup
		$dataArray = [];
		
		$dataArray[] = API::createItem("book", [
			'title' => "B",
			'creators' => [
				[
					"creatorType" => "author",
					"name" => "B"
				]
			],
			'dateAdded' => '2014-02-05T00:00:00Z',
			'dateModified' => '2014-04-05T01:00:00Z'
		], $this, 'jsonData');
		
		$dataArray[] = API::createItem("journalArticle", [
			'title' => "A",
			'creators' => [
				[
					"creatorType" => "author",
					"name" => "A"
				]
			],
			'dateAdded' => '2014-02-04T00:00:00Z',
			'dateModified' => '2014-01-04T01:00:00Z'
		], $this, 'jsonData');
		
		$dataArray[] = API::createItem("newspaperArticle", [
			'title' => "F",
			'creators' => [
				[
					"creatorType" => "author",
					"name" => "F"
				]
			],
			'dateAdded' => '2014-02-03T00:00:00Z',
			'dateModified' => '2014-02-03T01:00:00Z'

		], $this, 'jsonData');
		
		
		$dataArray[] = API::createItem("book", [
			'title' => "C",
			'creators' => [
				[
					"creatorType" => "author",
					"name" => "C"
				]
			],
			'dateAdded' => '2014-02-02T00:00:00Z',
			'dateModified' => '2014-03-02T01:00:00Z'
		], $this, 'jsonData');
		
		// Get sorted keys
		usort($dataArray, function ($a, $b) {
			return strcmp($a['dateAdded'], $b['dateAdded']);
		});
		$keysByDateAddedAscending = array_map(function ($data) {
			return $data['key'];
		}, $dataArray);
		
		$keysByDateAddedDescending = array_reverse($keysByDateAddedAscending);
		
		// Ascending
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys&sort=dateAdded&direction=asc"
		);
		$this->assert200($response);
		$this->assertEquals($keysByDateAddedAscending, explode("\n", trim($response->getBody())));
		
		$response = API::userGet(
			self::$config['userID'],
			"items?format=json&sort=dateAdded&direction=asc"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$keys = array_map(function ($val) {
			return $val['key'];
		}, $json);
		$this->assertEquals($keysByDateAddedAscending, $keys);
		
		$response = API::userGet(
			self::$config['userID'],
			"items?format=atom&sort=dateAdded&direction=asc"
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$keys = array_map(function ($val) {
			return (string) $val;
		}, $xml->xpath('//atom:entry/zapi:key'));
		$this->assertEquals($keysByDateAddedAscending, $keys);
		
		// Ascending using old 'order'/'sort' instead of 'sort'/'direction'
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys&order=dateAdded&sort=asc"
		);
		$this->assert200($response);
		$this->assertEquals($keysByDateAddedAscending, explode("\n", trim($response->getBody())));
		
		$response = API::userGet(
			self::$config['userID'],
			"items?format=json&order=dateAdded&sort=asc"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$keys = array_map(function ($val) {
			return $val['key'];
		}, $json);
		$this->assertEquals($keysByDateAddedAscending, $keys);
		
		$response = API::userGet(
			self::$config['userID'],
			"items?format=atom&order=dateAdded&sort=asc"
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$keys = array_map(function ($val) {
			return (string) $val;
		}, $xml->xpath('//atom:entry/zapi:key'));
		$this->assertEquals($keysByDateAddedAscending, $keys);
		
		// Descending
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys&sort=dateAdded&direction=desc"
		);
		$this->assert200($response);
		$this->assertEquals($keysByDateAddedDescending, explode("\n", trim($response->getBody())));
		
		$response = API::userGet(
			self::$config['userID'],
			"items?format=json&sort=dateAdded&direction=desc"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$keys = array_map(function ($val) {
			return $val['key'];
		}, $json);
		$this->assertEquals($keysByDateAddedDescending, $keys);
		
		$response = API::userGet(
			self::$config['userID'],
			"items?format=atom&sort=dateAdded&direction=desc"
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$keys = array_map(function ($val) {
			return (string) $val;
		}, $xml->xpath('//atom:entry/zapi:key'));
		$this->assertEquals($keysByDateAddedDescending, $keys);
		
		// Descending
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys&order=dateAdded&sort=desc"
		);
		$this->assert200($response);
		$this->assertEquals($keysByDateAddedDescending, explode("\n", trim($response->getBody())));
		
		$response = API::userGet(
			self::$config['userID'],
			"items?format=json&order=dateAdded&sort=desc"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$keys = array_map(function ($val) {
			return $val['key'];
		}, $json);
		$this->assertEquals($keysByDateAddedDescending, $keys);
		
		$response = API::userGet(
			self::$config['userID'],
			"items?format=atom&order=dateAdded&sort=desc"
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$keys = array_map(function ($val) {
			return (string) $val;
		}, $xml->xpath('//atom:entry/zapi:key'));
		$this->assertEquals($keysByDateAddedDescending, $keys);
	}
}
