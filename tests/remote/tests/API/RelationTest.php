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

class RelationTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testNewRelations() {
		$relations = array(
			"owl:sameAs" => "http://zotero.org/groups/1/items/AAAAAAAA",
			"dc:relation" => "http://zotero.org/users/"
				. self::$config['userID'] . "/items/AAAAAAAA",
		);
		
		$xml = API::createItem("book", array(
			"relations" => $relations
		), $this);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$this->assertCount(sizeOf($relations), $json['relations']);
		foreach ($relations as $predicate => $object) {
			$this->assertEquals($object, $json['relations'][$predicate]);
		}
	}
	
	
	public function testInvalidRelation() {
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
			"relations" => array()
		), $this, 'response');
		$this->assert400ForObject($response, "'relations' property must be an object");
	}
	
	
	public function testDeleteRelation() {
		$relations = array(
			"owl:sameAs" => "http://zotero.org/groups/1/items/AAAAAAAA",
			"dc:relation" => "http://zotero.org/users/"
				. self::$config['userID'] . "/items/AAAAAAAA",
		);
		
		$data = API::createItem("book", array(
			"relations" => $relations
		), $this, 'data');
		
		$json = json_decode($data['content'], true);
		
		// Remove a relation
		unset($json['relations']['owl:sameAs']);
		unset($relations['owl:sameAs']);
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
		$json['relations'] = new stdClass;
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
}
