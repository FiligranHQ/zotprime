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

class CacheTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	/**
	 * An object type's primary data cache for a library has to be created before
	 * 
	 */
	public function testCacheCreatorPrimaryData() {
		$data = array(
			"title" => "Title",
			"creators" => array(
				array(
					"creatorType" => "author",
					"firstName" => "First",
					"lastName" => "Last"
				),
				array(
					"creatorType" => "editor",
					"firstName" => "Ed",
					"lastName" => "McEditor"
				)
			)
		);
		
		$xml = API::createItem("book", $data);
		$data = API::parseDataFromAtomEntry($xml);
		
		$response = API::userGet(
			self::$config['userID'],
			"items/{$data['key']}?key=" . self::$config['apiKey'] . "&content=csljson"
		);
		$json = json_decode(API::getContentFromResponse($response));
		$this->assertEquals("First", $json->author[0]->given);
		$this->assertEquals("Last", $json->author[0]->family);
		$this->assertEquals("Ed", $json->editor[0]->given);
		$this->assertEquals("McEditor", $json->editor[0]->family);
	}
}
