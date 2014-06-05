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

use API2 as API;
require_once 'include/api2.inc.php';
require_once 'include/sync.inc.php';

class SyncTests extends PHPUnit_Framework_TestCase {
	protected static $config;
	protected static $sessionID;
	
	public static function setUpBeforeClass() {
		require 'include/config.inc.php';
		foreach ($config as $k => $v) {
			self::$config[$k] = $v;
		}
		
		API::useAPIVersion(2);
	}
	
	
	public function setUp() {
		API::userClear(self::$config['userID']);
		API::groupClear(self::$config['ownedPrivateGroupID']);
		API::groupClear(self::$config['ownedPublicGroupID']);
		self::$sessionID = Sync::login();
	}
	
	
	public function tearDown() {
		Sync::logout(self::$sessionID);
		self::$sessionID = null;
	}
	
	
	public function testSyncEmpty() {
		$xml = Sync::updated(self::$sessionID);
		$this->assertEquals("0", (string) $xml['earliest']);
		$this->assertFalse(isset($xml->updated->items));
		$this->assertEquals(self::$config['userID'], (int) $xml['userID']);
	}
	
	
	/**
	 * @depends testSyncEmpty
	 */
	public function testSync() {
		$xml = Sync::updated(self::$sessionID);
		
		// Upload
		$data = file_get_contents("data/sync1upload.xml");
		$data = str_replace('libraryID=""', 'libraryID="' . self::$config['libraryID'] . '"', $data);
		
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Download
		$xml = Sync::updated(self::$sessionID);
		unset($xml->updated->groups);
		$xml['timestamp'] = "";
		$xml['updateKey'] = "";
		$xml['earliest'] = "";
		
		$this->assertXmlStringEqualsXmlFile("data/sync1download.xml", $xml->asXML());
		
		// Test fully cached download
		$xml = Sync::updated(self::$sessionID);
		unset($xml->updated->groups);
		$xml['timestamp'] = "";
		$xml['updateKey'] = "";
		$xml['earliest'] = "";
		
		$this->assertXmlStringEqualsXmlFile("data/sync1download.xml", $xml->asXML());
		
		// Test item-level cached download
		$xml = Sync::updated(self::$sessionID, 2);
		unset($xml->updated->groups);
		$xml['timestamp'] = "";
		$xml['updateKey'] = "";
		$xml['earliest'] = "";
		
		$this->assertXmlStringEqualsXmlFile("data/sync1download.xml", $xml->asXML());
	}
	
	
	public function testDownloadCache() {
		$keys = [];
		$keys[] = API::createItem("book", false, false, 'key');
		$keys[] = API::createItem("journalArticle", false, false, 'key');
		$keys[] = API::createItem("newspaperArticle", false, false, 'key');
		$keys[] = API::createItem("magazineArticle", false, false, 'key');
		$keys[] = API::createItem("bookSection", false, false, 'key');
		$keys[] = API::createItem("audioRecording", false, false, 'key');
		
		$xml1 = Sync::updated(self::$sessionID);
		$xml2 = Sync::updated(self::$sessionID);
		$this->assertEquals($xml1->asXML(), $xml2->asXML());
	}
}