<?
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

class ObjectTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testSingleObjectDelete() {
		self::_testSingleObjectDelete('collection');
		self::_testSingleObjectDelete('item');
	}
	
	
	/*public function testMultiObjectDelete() {
		self::_testMultiObjectDelete('collection');
		self::_testMultiObjectDelete('item');
	}*/
	
	
	public function testPartialWriteFailure() {
		self::_testPartialWriteFailure('item');
	}
	
	
	private function _testSingleObjectDelete($objectType) {
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		switch ($objectType) {
		case 'collection':
			$xml = API::createCollection("Name", false, $this);
			break;
		
		case 'item':
			$xml = API::createItem("book", array("title" => "Title"), $this);
			break;
		}
		
		$data = API::parseDataFromAtomEntry($xml);
		$objectKey = $data['key'];
		$objectVersion = $data['version'];
		
		$response = API::userDelete(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey'],
			array(
				"Zotero-If-Unmodified-Since-Version: " . $objectVersion
			)
		);
		$this->assert204($response);
		
		$response = API::userGet(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey']
		);
		$this->assert404($response);
	}
	
	
	
	private function _testPartialWriteFailure($objectType) {
		API::userClear(self::$config['userID']);
		
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		switch ($objectType) {
		case 'collection':
			$xml = API::createCollection("Name", false, $this);
			break;
		
		case 'item':
			$json1 = API::getItemTemplate('book');
			$json2 = clone $json1;
			$json2->title = str_repeat("1234567890", 6554);
			$response = API::userPost(
				self::$config['userID'],
				"items?key=" . self::$config['apiKey'],
				json_encode(array(
					"items" => array(
						$json1, $json2)
				)),
				array("Content-Type: application/json")
			);
			
			$this->assert200($response);
			$json = API::getJSONFromResponse($response);
			$this->assert200ForObject($response, false, 0);
			$this->assert400ForObject($response, false, 1);
			$successKey = API::getFirstSuccessKeyFromResponse($response);
			
			switch ($objectType) {
			case 'item':
				$response = API::userGet(
					self::$config['userID'],
					"items?format=keys&key=" . self::$config['apiKey']
				);
				$this->assertEquals($successKey, trim($response->getBody()));
				break;
			}
		}
	}
}
