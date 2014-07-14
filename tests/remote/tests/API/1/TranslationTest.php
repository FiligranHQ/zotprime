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

namespace APIv1;
use API2 as API;
require_once 'APITests.inc.php';
require_once 'include/api2.inc.php';

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
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode([
				"url" => "http://www.amazon.com/Zotero-Guide-Librarians-Researchers-Educators/dp/0838985890/"
			]),
			array("Content-Type: application/json")
		);
		$this->assert201($response);
		$xml = API::getXMLFromResponse($response);
		$json = json_decode(API::parseDataFromAtomEntry($xml)['content']);
		$this->assertEquals('Zotero: A Guide for Librarians, Researchers and Educators', $json->title);
	}
	
	
	public function testWebTranslationMultiple() {
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode([
				"url" => "http://www.amazon.com/s/field-keywords=zotero"
			]),
			array("Content-Type: application/json")
		);
		$this->assert300($response);
		$json = json_decode($response->getBody());
		$results = get_object_vars($json);
		
		$key = array_keys($results)[0];
		$val = array_values($results)[0];
		
		$this->assertEquals(0, strpos($key, 'http'));
		$this->assertEquals('Zotero: A Guide for Librarians, Researchers and Educators', $val);
		
		// Can't test posting on v1, because generated token isn't returned
	}
}
