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

require_once 'APITests.inc.php';
require_once 'include/api.inc.php';

class ParamsTests extends APITests {
	private static $collectionKeys = array();
	private static $itemKeys = array();
	private static $searchKeys = array();
	
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
		
		//
		// Collections
		//
		for ($i=0; $i<5; $i++) {
			self::$collectionKeys[] = API::createCollection("Test", false, null, 'key');
		}
		
		//
		// Items
		//
		for ($i=0; $i<5; $i++) {
			self::$itemKeys[] = API::createItem("book", false, null, 'key');
		}
		self::$itemKeys[] = API::createAttachmentItem("imported_file", false, null, 'key');
		
		//
		// Searches
		//
		for ($i=0; $i<5; $i++) {
			self::$searchKeys[] = API::createSearch("Test", 'default', null, 'key');
		}
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testFormatKeys() {
		$this->_testFormatKeys('collection');
		$this->_testFormatKeys('item');
		$this->_testFormatKeys('search');
	}
	
	
	public function testFormatKeysSorted() {
		$this->_testFormatKeysSorted('collection');
		$this->_testFormatKeysSorted('item');
		$this->_testFormatKeysSorted('search');
	}
	
	
	private function _testFormatKeys($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		$keysVar = $objectType . "Keys";
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&format=keys"
		);
		$this->assert200($response);
		
		$keys = explode("\n", trim($response->getBody()));
		sort($keys);
		$this->assertEmpty(
			array_merge(
				array_diff(self::$$keysVar, $keys), array_diff($keys, self::$$keysVar)
			)
		);
	}
	
	
	private function _testFormatKeysSorted($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		$keysVar = $objectType . "Keys";
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural?key=" . self::$config['apiKey']
				. "&format=keys&order=title"
		);
		$this->assert200($response);
		
		$keys = explode("\n", trim($response->getBody()));
		sort($keys);
		$this->assertEmpty(
			array_merge(
				array_diff(self::$$keysVar, $keys), array_diff($keys, self::$$keysVar)
			)
		);
	}
}
