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

use API2 as API;
require_once 'include/api2.inc.php';
require_once 'include/sync.inc.php';

class SyncItemTests extends PHPUnit_Framework_TestCase {
	protected static $config;
	protected static $sessionID;
	
	public static function setUpBeforeClass() {
		require 'include/config.inc.php';
		foreach ($config as $k => $v) {
			self::$config[$k] = $v;
		}
		
		API::useAPIVersion(2);
	}
	
	
	public function setUp() {
		API::userClear(self::$config['userID']);
		self::$sessionID = Sync::login();
	}
	
	
	public function tearDown() {
		Sync::logout(self::$sessionID);
		self::$sessionID = null;
	}
	
	
	public function testCachedItem() {
		$itemKey = Sync::createItem(
			self::$sessionID, self::$config['libraryID'], "book", array(
				"title" => "Test",
				"numPages" => "204"
			), $this
		);
		
		Sync::updated(self::$sessionID);
		
		$xml = API::getItemXML($itemKey);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$json['creators'] = array(
			array(
				"firstName" => "First",
				"lastName" => "Last",
				"creatorType" => "author"
			)
		);
		$response = API::userPut(
			self::$config['userID'],
			"items/$itemKey?key=" . self::$config['apiKey'],
			json_encode($json)
		);
		$this->assertEquals(204, $response->getStatus());
		
		$xml = Sync::updated(self::$sessionID);
		$this->assertEquals("Test", $xml->updated[0]->items[0]->item[0]->field[0]);
		$this->assertEquals("204", $xml->updated[0]->items[0]->item[0]->field[1]);
		$this->assertEquals(1, $xml->updated[0]->items[0]->item[0]->creator->count());
		
		// Fully cached response
		$xml = Sync::updated(self::$sessionID);
		$this->assertEquals("Test", $xml->updated[0]->items[0]->item[0]->field[0]);
		$this->assertEquals("204", $xml->updated[0]->items[0]->item[0]->field[1]);
		$this->assertEquals(1, $xml->updated[0]->items[0]->item[0]->creator->count());
		
		// Item-level caching
		$xml = Sync::updated(self::$sessionID, 2);
		$this->assertEquals("Test", $xml->updated[0]->items[0]->item[0]->field[0]);
		$this->assertEquals("204", $xml->updated[0]->items[0]->item[0]->field[1]);
		$this->assertEquals(1, $xml->updated[0]->items[0]->item[0]->creator->count());
		
		$xml = API::getItemXML($itemKey);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		
		$json['title'] = "Test 2";
		$json['creators'] = array(
			array(
				"firstName" => "First",
				"lastName" => "Last",
				"creatorType" => "author"
			),
			array(
				"name" => "Test Name",
				"creatorType" => "editor"
			)
		);
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$itemKey?key=" . self::$config['apiKey'],
			json_encode($json)
		);
		
		$xml = Sync::updated(self::$sessionID);
		$this->assertEquals("Test 2", $xml->updated[0]->items[0]->item[0]->field[0]);
		$this->assertEquals("204", $xml->updated[0]->items[0]->item[0]->field[1]);
		$this->assertEquals(2, $xml->updated[0]->items[0]->item[0]->creator->count());
		
		$xml = Sync::updated(self::$sessionID, 3);
		$this->assertEquals("Test 2", $xml->updated[0]->items[0]->item[0]->field[0]);
		$this->assertEquals("204", $xml->updated[0]->items[0]->item[0]->field[1]);
		$this->assertEquals(2, $xml->updated[0]->items[0]->item[0]->creator->count());
	}
	
	
	public function testComputerProgram() {
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		$itemKey = 'AAAAAAAA';
		
		// Create item via sync
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="computerProgram" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="' . $itemKey . '">'
			. '<field name="version">1.0</field>'
			. '</item></items></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Get item version via API
		$response = API::userGet(
			self::$config['userID'],
			"items/$itemKey?key=" . self::$config['apiKey'] . "&content=json"
		);
		$this->assertEquals(200, $response->getStatus());
		$xml = API::getItemXML($itemKey);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$this->assertEquals('1.0', $json['version']);
		
		$json['version'] = '1.1';
		$response = API::userPut(
			self::$config['userID'],
			"items/$itemKey?key=" . self::$config['apiKey'],
			json_encode($json)
		);
		$this->assertEquals(204, $response->getStatus());
		
		$xml = Sync::updated(self::$sessionID);
		$this->assertEquals('version', (string) $xml->updated[0]->items[0]->item[0]->field[0]['name']);
	}
}
