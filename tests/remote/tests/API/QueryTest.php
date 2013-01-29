<?php
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

require_once 'APITests.inc.php';
require_once 'include/api.inc.php';

class QueryTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testObjectKeyParameter() {
		$this->_testObjectKeyParameter('collection');
		$this->_testObjectKeyParameter('item');
		$this->_testObjectKeyParameter('search');
	}
	
	
	private function _testObjectKeyParameter($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		$xmlArray = array();
		
		switch ($objectType) {
		case 'collection':
			$xmlArray[] = API::createCollection("Name", false, $this);
			$xmlArray[] = API::createCollection("Name", false, $this);
			break;
		
		case 'item':
			$xmlArray[] = API::createItem("book", false, $this);
			$xmlArray[] = API::createItem("book", false, $this);
			break;
		
		case 'search':
			$xmlArray[] = API::createSearch(
				"Name",
				array(
					array(
						"condition" => "title",
						"operator" => "contains",
						"value" => "test"
					)
				),
				$this
			);
			$xmlArray[] = API::createSearch(
				"Name",
				array(
					array(
						"condition" => "title",
						"operator" => "contains",
						"value" => "test"
					)
				),
				$this
			);
			break;
		}
		
		$keys = array();
		foreach ($xmlArray as $xml) {
			$data = API::parseDataFromAtomEntry($xml);
			$keys[] = $data['key'];
		}
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&content=json&{$objectType}Key={$keys[0]}"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$this->assertEquals($keys[0], $data['key']);
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&content=json&{$objectType}Key={$keys[0]},{$keys[1]}&order={$objectType}KeyList"
		);
		$this->assert200($response);
		$this->assertNumResults(2, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$key = (string) array_shift($xpath);
		$this->assertEquals($keys[0], $key);
		$key = (string) array_shift($xpath);
		$this->assertEquals($keys[1], $key);
	}
}
