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

class SearchTests extends APITests {
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
		// TODO
		//$this->_testObjectKeyParameter('search');
	}
	
	
	private function _testObjectKeyParameter($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		switch ($objectType) {
		case 'collection':
			$xml = API::createCollection("Name", false, $this);
			$data = API::parseDataFromAtomEntry($xml);
			$key1 = $data['key'];
			
			$xml = API::createCollection("Name", false, $this);
			$data = API::parseDataFromAtomEntry($xml);
			$key2 = $data['key'];
			break;
		
		case 'item':
			$xml = API::createItem("book", false, $this);
			$data = API::parseDataFromAtomEntry($xml);
			$key1 = $data['key'];
			
			$xml = API::createItem("book", false, $this);
			$data = API::parseDataFromAtomEntry($xml);
			$key2 = $data['key'];
			break;
		
		// TODO
		case 'search':
			break;
		}
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&content=json&{$objectType}Key=$key1"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$this->assertEquals($key1, $data['key']);
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&content=json&{$objectType}Key=$key1,$key2&order={$objectType}KeyList"
		);
		$this->assert200($response);
		$this->assertNumResults(2, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$key = (string) array_shift($xpath);
		$this->assertEquals($key1, $key);
		$key = (string) array_shift($xpath);
		$this->assertEquals($key2, $key);
	}
}
