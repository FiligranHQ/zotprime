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

class TagTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		require 'include/config.inc.php';
		API::userClear($config['userID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		require 'include/config.inc.php';
		API::userClear($config['userID']);
	}
	
	
	public function testEmptyTag() {
		$json = API::getItemTemplate("book");
		$json->tags[] = array(
			"tag" => "",
			"type" => 1
		);
		
		$response = API::postItem($json);
		$this->assert400($response);
	}
	
	
	/**
	 * Adding a tag to an item should update the item's ETag
	 */
	public function testTagItemModTime() {
		$xml = API::createItem("book", false, $this);
		$t = time();
		$data = API::parseDataFromItemEntry($xml);
		$etag = $data['etag'];
		
		$json = json_decode($data['content']);
		$json->tags[] = array(
			"tag" => "Test"
		);
		$response = API::userPut(
			self::$config['userID'],
			"items/{$data['key']}?key=" . self::$config['apiKey'],
			json_encode($json),
			array(
				"Content-Type: application/json",
				"If-Match: " . $etag
			)
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromItemEntry($xml);
		$this->assertNotEquals($etag, (string) $data['etag']);
	}
}
