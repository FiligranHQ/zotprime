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

namespace APIv2;
use API2 as API;
require_once 'APITests.inc.php';
require_once 'include/api2.inc.php';

class RelationTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testNewItemRelations() {
		$relations = array(
			"owl:sameAs" => "http://zotero.org/groups/1/items/AAAAAAAA",
			"dc:relation" => array(
				"http://zotero.org/users/" . self::$config['userID'] . "/items/AAAAAAAA",
				"http://zotero.org/users/" . self::$config['userID'] . "/items/BBBBBBBB",
			)
		);
		
		$xml = API::createItem("book", array(
			"relations" => $relations
		), $this);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$this->assertCount(sizeOf($relations), $json['relations']);
		foreach ($relations as $predicate => $object) {
			if (is_string($object)) {
				$this->assertEquals($object, $json['relations'][$predicate]);
			}
			else {
				foreach ($object as $rel) {
					$this->assertContains($rel, $json['relations'][$predicate]);
				}
			}
		}
	}
	
	
	public function testRelatedItemRelations() {
		$relations = [
			"owl:sameAs" => "http://zotero.org/groups/1/items/AAAAAAAA",
		];
		
		$item1JSON = API::createItem("book", [
			"relations" => $relations
		], $this, 'json');
		$item2JSON = API::createItem("book", null, $this, 'json');
		
		$uriPrefix = "http://zotero.org/users/" . self::$config['userID'] . "/items/";
		$item1URI = $uriPrefix . $item1JSON['itemKey'];
		$item2URI = $uriPrefix . $item2JSON['itemKey'];
		
		// Add item 2 as related item of item 1
		$relations["dc:relation"] = $item2URI;
		$item1JSON["relations"] = $relations;
		$response = API::userPut(
			self::$config['userID'],
			"items/{$item1JSON['itemKey']}?key=" . self::$config['apiKey'],
			json_encode($item1JSON)
		);
		$this->assert204($response);
		
		// Make sure it exists on item 1
		$xml = API::getItemXML($item1JSON['itemKey']);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$this->assertCount(sizeOf($relations), $json['relations']);
		foreach ($relations as $predicate => $object) {
			$this->assertEquals($object, $json['relations'][$predicate]);
		}
		
		// And item 2, since related items are bidirectional
		$xml = API::getItemXML($item2JSON['itemKey']);
		$data = API::parseDataFromAtomEntry($xml);
		$item2JSON = json_decode($data['content'], true);
		$this->assertCount(1, $item2JSON['relations']);
		$this->assertEquals($item1URI, $item2JSON["relations"]["dc:relation"]);
		
		// Sending item 2's unmodified JSON back up shouldn't cause the item to be updated.
		// Even though we're sending a relation that's technically not part of the item,
		// when it loads the item it will load the reverse relations too and therefore not
		// add a relation that it thinks already exists.
		$response = API::userPut(
			self::$config['userID'],
			"items/{$item2JSON['itemKey']}?key=" . self::$config['apiKey'],
			json_encode($item2JSON)
		);
		$this->assert204($response);
		$this->assertEquals($item2JSON['itemVersion'], $response->getHeader("Last-Modified-Version"));
	}
	
	
	// Same as above, but in a single request
	public function testRelatedItemRelationsSingleRequest() {
		$uriPrefix = "http://zotero.org/users/" . self::$config['userID'] . "/items/";
		// TEMP: Use autoloader
		require_once '../../model/Utilities.inc.php';
		require_once '../../model/ID.inc.php';
		$item1Key = \Zotero_ID::getKey();
		$item2Key = \Zotero_ID::getKey();
		$item1URI = $uriPrefix . $item1Key;
		$item2URI = $uriPrefix . $item2Key;
		
		$item1JSON = API::getItemTemplate('book');
		$item1JSON->itemKey = $item1Key;
		$item1JSON->itemVersion = 0;
		$item1JSON->relations->{'dc:relation'} = $item2URI;
		$item2JSON = API::getItemTemplate('book');
		$item2JSON->itemKey = $item2Key;
		$item2JSON->itemVersion = 0;
		
		$response = API::postItems([$item1JSON, $item2JSON]);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		
		// Make sure it exists on item 1
		$xml = API::getItemXML($item1JSON->itemKey);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$this->assertCount(1, $json['relations']);
		$this->assertEquals($item2URI, $json['relations']['dc:relation']);
		
		// And item 2, since related items are bidirectional
		$xml = API::getItemXML($item2JSON->itemKey);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$this->assertCount(1, $json['relations']);
		$this->assertEquals($item1URI, $json['relations']['dc:relation']);
	}
	
	
	public function testInvalidItemRelation() {
		$response = API::createItem("book", array(
			"relations" => array(
				"foo:unknown" => "http://zotero.org/groups/1/items/AAAAAAAA"
			)
		), $this, 'response');
		$this->assert400ForObject($response, "Unsupported predicate 'foo:unknown'");
		
		$response = API::createItem("book", array(
			"relations" => array(
				"owl:sameAs" => "Not a URI"
			)
		), $this, 'response');
		$this->assert400ForObject($response, "'relations' values currently must be Zotero item URIs");
		
		$response = API::createItem("book", array(
			"relations" => array(
				"owl:sameAs" => ["Not a URI"]
			)
		), $this, 'response');
		$this->assert400ForObject($response, "'relations' values currently must be Zotero item URIs");
	}
	
	
	public function testDeleteItemRelation() {
		$relations = array(
			"owl:sameAs" => [
				"http://zotero.org/groups/1/items/AAAAAAAA",
				"http://zotero.org/groups/1/items/BBBBBBBB"
			],
			"dc:relation" => "http://zotero.org/users/"
				. self::$config['userID'] . "/items/AAAAAAAA",
		);
		
		$data = API::createItem("book", array(
			"relations" => $relations
		), $this, 'data');
		
		$json = json_decode($data['content'], true);
		
		// Remove a relation
		$json['relations']['owl:sameAs'] = $relations['owl:sameAs'] = $relations['owl:sameAs'][0];
		$response = API::userPut(
			self::$config['userID'],
			"items/{$data['key']}?key=" . self::$config['apiKey'],
			json_encode($json)
		);
		$this->assert204($response);
		
		// Make sure it's gone
		$xml = API::getItemXML($data['key']);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$this->assertCount(sizeOf($relations), $json['relations']);
		foreach ($relations as $predicate => $object) {
			$this->assertEquals($object, $json['relations'][$predicate]);
		}
		
		// Delete all
		$json['relations'] = new \stdClass;
		$response = API::userPut(
			self::$config['userID'],
			"items/{$data['key']}?key=" . self::$config['apiKey'],
			json_encode($json)
		);
		$this->assert204($response);
		
		// Make sure they're gone
		$xml = API::getItemXML($data['key']);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$this->assertCount(0, $json['relations']);
	}
	
	
	//
	// Collections
	//
	public function testNewCollectionRelations() {
		$relations = array(
			"owl:sameAs" => "http://zotero.org/groups/1/collections/AAAAAAAA"
		);
		
		$data = API::createCollection("Test", array(
			"relations" => $relations
		), $this, 'data');
		$json = json_decode($data['content'], true);
		$this->assertCount(sizeOf($relations), $json['relations']);
		foreach ($relations as $predicate => $object) {
			$this->assertEquals($object, $json['relations'][$predicate]);
		}
	}
	
	
	public function testInvalidCollectionRelation() {
		$json = array(
			"name" => "Test",
			"relations" => array(
				"foo:unknown" => "http://zotero.org/groups/1/collections/AAAAAAAA"
			)
		);
		$response = API::userPost(
			self::$config['userID'],
			"collections?key=" . self::$config['apiKey'],
			json_encode(array("collections" => array($json)))
		);
		$this->assert400ForObject($response, "Unsupported predicate 'foo:unknown'");
		
		$json["relations"] = array(
			"owl:sameAs" => "Not a URI"
		);
		$response = API::userPost(
			self::$config['userID'],
			"collections?key=" . self::$config['apiKey'],
			json_encode(array("collections" => array($json)))
		);
		$this->assert400ForObject($response, "'relations' values currently must be Zotero collection URIs");
		
		$json["relations"] = ["http://zotero.org/groups/1/collections/AAAAAAAA"];
		$response = API::userPost(
			self::$config['userID'],
			"collections?key=" . self::$config['apiKey'],
			json_encode(array("collections" => array($json)))
		);
		$this->assert400ForObject($response, "'relations' property must be an object");
	}
	
	
	public function testDeleteCollectionRelation() {
		$relations = array(
			"owl:sameAs" => "http://zotero.org/groups/1/collections/AAAAAAAA"
		);
		$data = API::createCollection("Test", array(
			"relations" => $relations
		), $this, 'data');
		$json = json_decode($data['content'], true);
		
		// Remove all relations
		$json['relations'] = new \stdClass;
		unset($relations['owl:sameAs']);
		$response = API::userPut(
			self::$config['userID'],
			"collections/{$data['key']}?key=" . self::$config['apiKey'],
			json_encode($json)
		);
		$this->assert204($response);
		
		// Make sure it's gone
		$xml = API::getCollectionXML($data['key']);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$this->assertCount(sizeOf($relations), $json['relations']);
		foreach ($relations as $predicate => $object) {
			$this->assertEquals($object, $json['relations'][$predicate]);
		}
	}
}
