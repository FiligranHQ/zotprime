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
	private static $keys = array();
	
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
		
		// Create test data
		for ($i=0; $i<5; $i++) {
			$xml = API::createItem("book");
			$data = API::parseDataFromItemEntry($xml);
			self::$keys[] = $data['key'];
		}
		
		// Create top-level attachment
		$response = API::get("items/new?itemType=attachment&linkMode=imported_file");
		$json = json_decode($response->getBody());
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromItemEntry($xml);
		self::$keys[] = $data['key'];
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testFormatKeys() {
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'] . "&format=keys"
		);
		$this->assert200($response);
		
		$keys = explode("\n", trim($response->getBody()));
		sort($keys);
		$this->assertEmpty(
			array_merge(
				array_diff(self::$keys, $keys), array_diff($keys, self::$keys)
			)
		);
	}
	
	
	public function testFormatKeysSorted() {
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'] . "&format=keys&order=itemType"
		);
		$this->assert200($response);
		
		$keys = explode("\n", trim($response->getBody()));
		sort($keys);
		$this->assertEmpty(
			array_merge(
				array_diff(self::$keys, $keys), array_diff($keys, self::$keys)
			)
		);
	}
}
