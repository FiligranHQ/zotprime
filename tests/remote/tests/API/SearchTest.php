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
require_once 'include/api.inc.php';

class SearchTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testNewSearch() {
		$name = "Test Search";
		$conditions = array(
			array(
				"condition" => "title",
				"operator" => "contains",
				"value" => "test"
			),
			array(
				"condition" => "noChildren",
				"operator" => "false",
				"value" => ""
			),
			array(
				"condition" => "fulltextContent/regexp",
				"operator" => "contains",
				"value" => "/test/"
			)
		);
		
		$xml = API::createSearch($name, $conditions, $this);
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		$this->assertEquals($name, (string) $json->name);
		$this->assertInternalType('array', $json->conditions);
		$this->assertCount(sizeOf($conditions), $json->conditions);
		foreach ($conditions as $i => $condition) {
			foreach ($condition as $key => $val) {
				$this->assertEquals($val, $json->conditions[$i]->$key);
			}
		}
		
		return $data;
	}
	
	
	/**
	 * @depends testNewSearch
	 */
	public function testModifySearch($newSearchData) {
		$key = $newSearchData['key'];
		$version = $newSearchData['version'];
		$json = json_decode($newSearchData['content'], true);
		
		// Remove one search condition
		array_shift($json['conditions']);
		
		$name = $json['name'];
		$conditions = $json['conditions'];
		
		$response = API::userPut(
			self::$config['userID'],
			"searches/$key?key=" . self::$config['apiKey'],
			json_encode($json),
			array(
				"Content-Type: application/json",
				"Zotero-If-Unmodified-Since-Version: $version"
			)
		);
		$this->assert204($response);
		
		$xml = API::getSearchXML($key);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		$this->assertEquals($name, (string) $json->name);
		$this->assertInternalType('array', $json->conditions);
		$this->assertCount(sizeOf($conditions), $json->conditions);
		foreach ($conditions as $i => $condition) {
			foreach ($condition as $key => $val) {
				$this->assertEquals($val, $json->conditions[$i]->$key);
			}
		}
	}
	
	
	public function testNewSearchNoName() {
		$json = API::createSearch(
			"",
			array(
				array(
					"condition" => "title",
					"operator" => "contains",
					"value" => "test"
				)
			),
			$this,
			'json'
		);
		$this->assert400ForObject($json, "Search name cannot be empty");
	}
	
	
	public function testNewSearchNoConditions() {
		$json = API::createSearch("Test", array(), $this, 'json');
		$this->assert400ForObject($json, "'conditions' cannot be empty");
	}
	
	
	public function testNewSearchConditionErrors() {
		$json = API::createSearch(
			"Test",
			array(
				array(
					"operator" => "contains",
					"value" => "test"
				)
			),
			$this,
			'json'
		);
		$this->assert400ForObject($json, "'condition' property not provided for search condition");
		
		$json = API::createSearch(
			"Test",
			array(
				array(
					"condition" => "",
					"operator" => "contains",
					"value" => "test"
				)
			),
			$this,
			'json'
		);
		$this->assert400ForObject($json, "Search condition cannot be empty");
		
		$json = API::createSearch(
			"Test",
			array(
				array(
					"condition" => "title",
					"value" => "test"
				)
			),
			$this,
			'json'
		);
		$this->assert400ForObject($json, "'operator' property not provided for search condition");
		
		$json = API::createSearch(
			"Test",
			array(
				array(
					"condition" => "title",
					"operator" => "",
					"value" => "test"
				)
			),
			$this,
			'json'
		);
		$this->assert400ForObject($json, "Search operator cannot be empty");
	}
}
