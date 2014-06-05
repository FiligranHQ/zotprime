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

class GeneralTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testZoteroWriteToken() {
		$json = API::getItemTemplate("book");
		
		$token = md5(uniqid());
		
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode(array(
				"items" => array($json)
			)),
			array(
				"Content-Type: application/json",
				"Zotero-Write-Token: $token"
			)
		);
		$this->assert200ForObject($response);
		
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode(array(
				"items" => array($json)
			)),
			array(
				"Content-Type: application/json",
				"Zotero-Write-Token: $token"
			)
		);
		$this->assert412($response);
	}
	
	
	public function testInvalidCharacters() {
		$data = array(
			'title' => "A" . chr(0) . "A",
			'creators' => array(
				array(
					'creatorType' => "author",
					'name' => "B" . chr(1) . "B"
				)
			),
			'tags' => array(
				array(
					'tag' => "C" . chr(2) . "C"
				)
			)
		);
		$json = API::createItem("book", $data, $this, 'jsonData');
		$this->assertEquals("AA", $json['title']);
		$this->assertEquals("BB", $json['creators'][0]['name']);
		$this->assertEquals("CC", $json['tags'][0]['tag']);
	}
}
