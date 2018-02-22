<?php
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2014 Center for History and New Media
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

class TranslationTests extends APITests {
	public function setUp() {
		parent::setUp();
		API::userClear(self::$config['userID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testWebTranslationSingle() {
		$title = 'Zotero: A Guide for Librarians, Researchers and Educators';
		
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([
				"url" => "http://www.amazon.com/Zotero-Guide-Librarians-Researchers-Educators/dp/0838985890/"
			]),
			array("Content-Type: application/json")
		);
		$this->assert200($response);
		$this->assert200ForObject($response);
		$json = API::getJSONFromResponse($response);
		$itemKey = $json['success'][0];
		$data = API::getItem($itemKey, $this, 'json')['data'];
		$this->assertEquals($title, $data['title']);
	}
	
	/**
	 * @group failing-translation
	 */
	/*public function testWebTranslationSingleWithChildItems() {
		$title = 'A Clustering Approach to Identify Intergenic Non-coding RNA in Mouse Macrophages';
		
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([
				"url" => "http://www.computer.org/csdl/proceedings/bibe/2010/4083/00/4083a001-abs.html"
			]),
			array("Content-Type: application/json")
		);
		$this->assert200($response);
		$this->assert200ForObject($response, false, 0);
		$this->assert200ForObject($response, false, 1);
		$json = API::getJSONFromResponse($response);
		
		// Check item
		$itemKey = $json['success'][0];
		$data = API::getItem($itemKey, $this, 'json')['data'];
		$this->assertEquals($title, $data['title']);
		// NOTE: Tags currently not served via BibTeX (though available in RIS)
		$this->assertCount(0, $data['tags']);
		//$this->assertContains(['tag' => 'chip-seq; clustering; non-coding rna; rna polymerase; macrophage', 'type' => 1], $data['tags']); // TODO: split in translator
		
		// Check note
		$itemKey = $json['success'][1];
		$data = API::getItem($itemKey, $this, 'json')['data'];
		$this->assertEquals("Complete PDF document was either not available or accessible. "
			. "Please make sure you're logged in to the digital library to retrieve the "
			. "complete PDF document.", $data['note']);
	}*/
	
	/**
	 * @group failing-translation
	 */
	public function testWebTranslationMultiple() {
		$title = 'Zotero: A guide for librarians, researchers, and educators, Second Edition';
		
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode([
				"url" => "http://www.amazon.com/s/field-keywords=zotero+guide+librarians"
			]),
			array("Content-Type: application/json")
		);
		$this->assert300($response);
		$json = json_decode($response->getBody());
		
		$results = get_object_vars($json->items);
		$key = array_keys($results)[0];
		$val = array_values($results)[0];
		$this->assertEquals('0', $key);
		$this->assertEquals($title, $val);
		
		$items = new \stdClass;
		$items->$key = $val;
		
		// Missing token
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode([
				"url" => "http://www.amazon.com/s/field-keywords=zotero+guide+librarians",
				"items" => $items
			]),
			array("Content-Type: application/json")
		);
		$this->assert400($response, "Token not provided with selected items");
		
		// Invalid selection
		$items2 = clone $items;
		$invalidKey = "12345";
		$items2->$invalidKey = $items2->$key;
		unset($items2->$key);
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode([
				"url" => "http://www.amazon.com/s/field-keywords=zotero+guide+librarians",
				"token" => $json->token,
				"items" => $items2
			]),
			array("Content-Type: application/json")
		);
		$this->assert400($response, "Index '$invalidKey' not found for URL and token");
		
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode([
				"url" => "http://www.amazon.com/s/field-keywords=zotero+guide+librarians",
				"token" => $json->token,
				"items" => $items
			]),
			array("Content-Type: application/json")
		);
		
		$this->assert200($response);
		$this->assert200ForObject($response);
		$json = API::getJSONFromResponse($response);
		$itemKey = $json['success'][0];
		$data = API::getItem($itemKey, $this, 'json')['data'];
		$this->assertEquals($title, $data['title']);
	}
	
	
	public function testWebTranslationInvalidToken() {
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode([
				"url" => "http://www.amazon.com/s/field-keywords=zotero+guide+librarians",
				"token" => md5(uniqid())
			]),
			["Content-Type: application/json"]
		);
		$this->assert400($response, "'token' is valid only for item selection requests");
	}
}
