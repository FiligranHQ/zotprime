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

require_once 'include/api.inc.php';
require_once 'include/sync.inc.php';

class CreatorSyncTests extends PHPUnit_Framework_TestCase {
	protected static $config;
	protected static $sessionID;
	
	public static function setUpBeforeClass() {
		require 'include/config.inc.php';
		foreach ($config as $k => $v) {
			self::$config[$k] = $v;
		}
	}
	
	
	public function setUp() {
		API::userClear(self::$config['userID']);
		self::$sessionID = Sync::login();
	}
	
	
	public function tearDown() {
		Sync::logout(self::$sessionID);
		self::$sessionID = null;
	}
	
	
	public function testCreatorItemChange() {
		$key = 'AAAAAAAA';
		
		$response = Sync::updated(self::$sessionID);
		$xml = Sync::getXMLFromResponse($response);
		$updateKey = (string) $xml['updateKey'];
		
		// Create item via sync
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="book" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="' . $key . '">'
			. '<creator key="BBBBBBBB" creatorType="author" index="0">'
			. '<creator libraryID="' . self::$config['libraryID'] . '" '
			. 'key="BBBBBBBB" dateAdded="2009-03-07 04:53:20" dateModified="2009-03-07 04:54:09">'
			. '<firstName>First</firstName>'
			. '<lastName>Last</lastName>'
			. '<fieldMode>0</fieldMode>'
			. '</creator></creator></item></items></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Get item ETag via API
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$version = $data['version'];
		
		// Get item via sync
		$response = Sync::updated(self::$sessionID);
		$xml = Sync::getXMLFromResponse($response);
		$updateKey = (string) $xml['updateKey'];
		$this->assertEquals(1, sizeOf($xml->updated->items->item));
		
		//
		// Modify creator, and include unmodified item
		//
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="book" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="' . $key . '">'
			. '<creator key="BBBBBBBB" creatorType="author" index="0"/>'
			. '</item></items>'
			. '<creators><creator libraryID="' . self::$config['libraryID'] . '" '
			. 'key="BBBBBBBB" dateAdded="2009-03-07 04:53:20" dateModified="2009-03-07 04:54:09">'
			. '<name>First Last</name>'
			. '<fieldMode>1</fieldMode>'
			. '</creator></creators></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Get item via API
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		
		$this->assertTrue(isset($json->creators[0]->name));
		$this->assertEquals("First Last", $json->creators[0]->name);
		$this->assertNotEquals($version, $data['version']);
		
		return $data;
	}
}
