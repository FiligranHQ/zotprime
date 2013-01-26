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

require_once 'tests/API/APITests.inc.php';
require_once 'include/api.inc.php';

class PreviousItemTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
		API::groupClear(self::$config['ownedPrivateGroupID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
		API::groupClear(self::$config['ownedPrivateGroupID']);
	}
	
	
	public function setUp() {
		parent::setUp();
		API::useFutureVersion(false);
	}
	
	
	public function testCreateItemWithChildren() {
		$json = API::getItemTemplate("newspaperArticle");
		$noteJSON = API::getItemTemplate("note");
		$noteJSON->note = "<p>Here's a test note</p>";
		$json->notes = array(
			$noteJSON
		);
		
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json)
			))
		);
		$this->assert201($response);
		$xml = API::getXMLFromResponse($response);
		$this->assertNumResults(1, $response);
		$this->assertEquals(1, (int) array_shift($xml->xpath('//atom:entry/zapi:numChildren')));
	}
}
