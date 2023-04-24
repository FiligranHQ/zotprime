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
		
		$json = API::createItem("book", array(
			"relations" => $relations
		), $this, 'jsonData');
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
		], $this, 'jsonData');
		$item2JSON = API::createItem("book", null, $this, 'jsonData');
		
		$uriPrefix = "http://zotero.org/users/" . self::$config['userID'] . "/items/";
		$item1URI = $uriPrefix . $item1JSON['key'];
		$item2URI = $uriPrefix . $item2JSON['key'];
		
		// Add item 2 as related item of item 1
		$relations["dc:relation"] = $item2URI;
		$item1JSON["relations"] = $relations;
		$response = API::userPut(
			self::$config['userID'],
			"items/{$item1JSON['key']}",
			json_encode($item1JSON)
		);
		$this->assert204($response);
		
		// Make sure it exists on item 1
		$json = API::getItem($item1JSON['key'], $this, 'json')['data'];
		$this->assertCount(sizeOf($relations), $json['relations']);
		foreach ($relations as $predicate => $object) {
			$this->assertEquals($object, $json['relations'][$predicate]);
		}
		
		// And item 2, since related items are bidirectional
		$item2JSON = API::getItem($item2JSON['key'], $this, 'json')['data'];
		$this->assertCount(1, $item2JSON['relations']);
		$this->assertEquals($item1URI, $item2JSON["relations"]["dc:relation"]);
		
		// Sending item 2's unmodified JSON back up shouldn't cause the item to be updated.
		// Even though we're sending a relation that's technically not part of the item,
		// when it loads the item it will load the reverse relations too and therefore not
		// add a relation that it thinks already exists.
		$response = API::userPut(
			self::$config['userID'],
			"items/{$item2JSON['key']}",
			json_encode($item2JSON)
		);
		$this->assert204($response);
		$this->assertEquals($item2JSON['version'], $response->getHeader("Last-Modified-Version"));
	}
	
	
	// Same as above, but in a single request
	public function testRelatedItemRelationsSingleRequest() {
		$uriPrefix = "http://zotero.org/users/" . self::$config['userID'] . "/items/";
		// TEMP: Use autoloader
		require_once '../../model/ID.inc.php';
		$item1Key = \Zotero_ID::getKey();
		$item2Key = \Zotero_ID::getKey();
		$item1URI = $uriPrefix . $item1Key;
		$item2URI = $uriPrefix . $item2Key;
		
		$item1JSON = API::getItemTemplate('book');
		$item1JSON->key = $item1Key;
		$item1JSON->version = 0;
		$item1JSON->relations->{'dc:relation'} = $item2URI;
		$item2JSON = API::getItemTemplate('book');
		$item2JSON->key = $item2Key;
		$item2JSON->version = 0;
		
		$response = API::postItems([$item1JSON, $item2JSON]);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		
		// Make sure it exists on item 1
		$json = API::getItem($item1JSON->key, $this, 'json')['data'];
		$this->assertCount(1, $json['relations']);
		$this->assertEquals($item2URI, $json['relations']['dc:relation']);
		
		// And item 2, since related items are bidirectional
		$json = API::getItem($item2JSON->key, $this, 'json')['data'];
		$this->assertCount(1, $json['relations']);
		$this->assertEquals($item1URI, $json['relations']['dc:relation']);
	}
	
	
	public function test_should_add_a_URL_to_a_relation_with_PATCH() {
		$relations = [
			"dc:replaces" => [
				"http://zotero.org/users/" . self::$config['userID'] . "/items/AAAAAAAA"
			]
		];
		
		$itemJSON = API::createItem("book", [
			"relations" => $relations
		], $this, 'jsonData');
		
		$relations["dc:replaces"][] = "http://zotero.org/users/" . self::$config['userID'] . "/items/BBBBBBBB";
		
		$patchJSON = [
			"version" => $itemJSON['version'],
			"relations" => $relations
		];
		$response = API::userPatch(
			self::$config['userID'],
			"items/{$itemJSON['key']}",
			json_encode($patchJSON)
		);
		$this->assert204($response);
		
		// Make sure the array was updated
		$json = API::getItem($itemJSON['key'], $this, 'json')['data'];
		$this->assertCount(sizeOf($relations), $json['relations']);
		$this->assertCount(sizeOf($relations['dc:replaces']), $json['relations']['dc:replaces']);
		$this->assertContains($relations['dc:replaces'][0], $json['relations']['dc:replaces']);
		$this->assertContains($relations['dc:replaces'][1], $json['relations']['dc:replaces']);
	}
	
	
	public function test_should_remove_a_URL_from_a_relation_with_PATCH() {
		$userID = self::$config['userID'];
		
		$relations = [
			"dc:replaces" => [
				"http://zotero.org/users/$userID/items/AAAAAAAA",
				"http://zotero.org/users/$userID/items/BBBBBBBB"
			]
		];
		
		$itemJSON = API::createItem("book", [
			"relations" => $relations
		], $this, 'jsonData');
		
		$relations["dc:replaces"] = array_slice($relations["dc:replaces"], 0, 1);
		
		$patchJSON = [
			"version" => $itemJSON['version'],
			"relations" => $relations
		];
		$response = API::userPatch(
			self::$config['userID'],
			"items/{$itemJSON['key']}",
			json_encode($patchJSON)
		);
		$this->assert204($response);
		
		// Make sure the value (now a string) was updated
		$json = API::getItem($itemJSON['key'], $this, 'json')['data'];
		$this->assertEquals($relations['dc:replaces'][0], $json['relations']['dc:replaces']);
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
	
	
	public function testCircularItemRelations() {
		$item1Data = API::createItem("book", null, $this, 'jsonData');
		$item2Data = API::createItem("book", null, $this, 'jsonData');
		$userID = self::$config['userID'];
		
		$item1Data['relations'] = [
			'dc:relation' => "http://zotero.org/users/$userID/items/{$item2Data['key']}"
		];
		$item2Data['relations'] = [
			'dc:relation' => "http://zotero.org/users/$userID/items/{$item1Data['key']}"
		];
		$response = API::postItems([$item1Data, $item2Data]);
		$this->assert200ForObject($response, false, 0);
		$this->assertUnchangedForObject($response, 1);
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
		), $this, 'jsonData');
		$itemKey = $data['key'];
		
		// Remove a relation
		$data['relations']['owl:sameAs'] = $relations['owl:sameAs'] = $relations['owl:sameAs'][0];
		$response = API::userPut(
			self::$config['userID'],
			"items/$itemKey",
			json_encode($data)
		);
		$this->assert204($response);
		
		// Make sure it's gone
		$data = API::getItem($data['key'], $this, 'json')['data'];
		$this->assertCount(sizeOf($relations), $data['relations']);
		foreach ($relations as $predicate => $object) {
			$this->assertEquals($object, $data['relations'][$predicate]);
		}
		
		// Delete all
		$data['relations'] = new \stdClass;
		$response = API::userPut(
			self::$config['userID'],
			"items/$itemKey",
			json_encode($data)
		);
		$this->assert204($response);
		
		// Make sure they're gone
		$data = API::getItem($itemKey, $this, 'json')['data'];
		$this->assertCount(0, $data['relations']);
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
		), $this, 'jsonData');
		$this->assertCount(sizeOf($relations), $data['relations']);
		foreach ($relations as $predicate => $object) {
			$this->assertEquals($object, $data['relations'][$predicate]);
		}
	}
	
	
	public function testInvalidCollectionRelation() {
		$json = [
			"name" => "Test",
			"relations" => array(
				"foo:unknown" => "http://zotero.org/groups/1/collections/AAAAAAAA"
			)
		];
		$response = API::userPost(
			self::$config['userID'],
			"collections",
			json_encode([$json])
		);
		$this->assert400ForObject($response, "Unsupported predicate 'foo:unknown'");
		
		$json["relations"] = array(
			"owl:sameAs" => "Not a URI"
		);
		$response = API::userPost(
			self::$config['userID'],
			"collections",
			json_encode([$json])
		);
		$this->assert400ForObject($response, "'relations' values currently must be Zotero collection URIs");
		
		$json["relations"] = ["http://zotero.org/groups/1/collections/AAAAAAAA"];
		$response = API::userPost(
			self::$config['userID'],
			"collections",
			json_encode([$json])
		);
		$this->assert400ForObject($response, "'relations' property must be an object");
	}
	
	
	public function testDeleteCollectionRelation() {
		$relations = array(
			"owl:sameAs" => "http://zotero.org/groups/1/collections/AAAAAAAA"
		);
		$data = API::createCollection("Test", array(
			"relations" => $relations
		), $this, 'jsonData');
		
		// Remove all relations
		$data['relations'] = new \stdClass;
		unset($relations['owl:sameAs']);
		$response = API::userPut(
			self::$config['userID'],
			"collections/{$data['key']}",
			json_encode($data)
		);
		$this->assert204($response);
		
		// Make sure it's gone
		$data = API::getCollection($data['key'], $this, 'json')['data'];
		$this->assertCount(sizeOf($relations), $data['relations']);
		foreach ($relations as $predicate => $object) {
			$this->assertEquals($object, $data['relations'][$predicate]);
		}
	}
	
	
	public function test_should_return_200_for_values_for_mendeleyDB_collection_relation() {
		$relations = [
			"mendeleyDB:remoteFolderUUID" => "b95b84b9-8b27-4a55-b5ea-5b98c1cac205"
		];
		$data = API::createCollection(
			"Test",
			[
				"relations" => $relations
			],
			$this,
			'jsonData'
		);
		$this->assertEquals($relations['mendeleyDB:remoteFolderUUID'], $data['relations']['mendeleyDB:remoteFolderUUID']);
	}
	
	
	public function test_should_return_200_for_arrays_for_mendeleyDB_collection_relation() {
		$json = [
			"name" => "Test",
			"relations" => [
				"mendeleyDB:remoteFolderUUID" => ["b95b84b9-8b27-4a55-b5ea-5b98c1cac205"]
			]
        ];
		$response = API::userPost(
			self::$config['userID'],
			"collections",
			json_encode([$json])
		);
		$this->assert200ForObject($response);
	}
}
