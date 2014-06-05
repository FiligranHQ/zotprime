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

class CreatorTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testCreatorSummaryJSON() {
		$json = API::createItem("book", array(
			"creators" => array(
				array(
					"creatorType" => "author",
					"name" => "Test"
				)
			)
		), $this, 'json');
		$itemKey = $json['key'];
		
		$this->assertEquals("Test", $json['meta']['creatorSummary']);
		
		$json = $json['data'];
		$json['creators'][] = [
			"creatorType" => "author",
			"firstName" => "Alice",
			"lastName" => "Foo"
		];
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$itemKey",
			json_encode($json)
		);
		$this->assert204($response);
		
		$json = API::getItem($itemKey, $this, 'json');
		$this->assertEquals("Test and Foo", $json['meta']['creatorSummary']);
		
		$json = $json['data'];
		$json['creators'][] = array(
			"creatorType" => "author",
			"firstName" => "Bob",
			"lastName" => "Bar"
		);
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$itemKey",
			json_encode($json)
		);
		$this->assert204($response);
		
		$json = API::getItem($itemKey, $this, 'json');
		$this->assertEquals("Test et al.", $json['meta']['creatorSummary']);
	}
	
	
	public function testCreatorSummaryAtom() {
		$xml = API::createItem("book", array(
			"creators" => array(
				array(
					"creatorType" => "author",
					"name" => "Test"
				)
			)
		), $this, 'atom');
		$data = API::parseDataFromAtomEntry($xml);
		$itemKey = $data['key'];
		$json = json_decode($data['content'], true);
		
		$creatorSummary = (string) array_shift($xml->xpath('//atom:entry/zapi:creatorSummary'));
		$this->assertEquals("Test", $creatorSummary);
		
		$json['creators'][] = array(
			"creatorType" => "author",
			"firstName" => "Alice",
			"lastName" => "Foo"
		);
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$itemKey",
			json_encode($json)
		);
		$this->assert204($response);
		
		$xml = API::getItemXML($itemKey);
		$creatorSummary = (string) array_shift($xml->xpath('//atom:entry/zapi:creatorSummary'));
		$this->assertEquals("Test and Foo", $creatorSummary);
		
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		
		$json['creators'][] = array(
			"creatorType" => "author",
			"firstName" => "Bob",
			"lastName" => "Bar"
		);
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$itemKey",
			json_encode($json)
		);
		$this->assert204($response);
		
		$xml = API::getItemXML($itemKey);
		$creatorSummary = (string) array_shift($xml->xpath('//atom:entry/zapi:creatorSummary'));
		$this->assertEquals("Test et al.", $creatorSummary);
	}
}
