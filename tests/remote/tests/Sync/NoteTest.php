<?php
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

class SyncNoteTests extends PHPUnit_Framework_TestCase {
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
	
	
	public function testNoteTooLong() {
		$response = Sync::updated(self::$sessionID);
		$xml = Sync::getXMLFromResponse($response);
		$updateKey = (string) $xml['updateKey'];
		
		$content = str_repeat("1234567890", 25001);
		
		// Create too-long note via sync
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="note" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="AAAAAAAA"><note>' . $content . '</note></item></items></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data, true);
		$xml = Sync::waitForUpload(self::$sessionID, $response, $this, true);
		
		$this->assertTrue(isset($xml->error));
		$this->assertEquals("ERROR_PROCESSING_UPLOAD_DATA", $xml->error["code"]);
		$this->assertRegExp('/^Note \'.+\' too long for item \'AAAAAAAA\'$/', (string) $xml->error);
		
		// Create too-long note with content within HTML tags
		$content = "<p><!-- $content --></p>";
		
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="note" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="AAAAAAAA"><note>' . htmlentities($content) . '</note></item></items></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data, true);
		$xml = Sync::waitForUpload(self::$sessionID, $response, $this, true);
		
		$this->assertTrue(isset($xml->error));
		$this->assertEquals("ERROR_PROCESSING_UPLOAD_DATA", $xml->error["code"]);
		$this->assertRegExp('/^Note \'<p><!-- 12345678901234567890123456789012345678901234567890123456789012345678901...\' too long for item \'AAAAAAAA\'$/', (string) $xml->error);
		
		// Create note under the length limit
		$response = Sync::updated(self::$sessionID);
		$xml = Sync::getXMLFromResponse($response);
		$updateKey = (string) $xml['updateKey'];
		
		$content = str_repeat("1234567890", 24999);
		
		// Create item via sync
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="note" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="AAAAAAAA"><note>' . $content . '</note></item></items></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data);
		Sync::waitForUpload(self::$sessionID, $response, $this);
	}
	
	
	public function testNoteTooLongWithA0() {
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		$content = file_get_contents('data/bad_string.xml') . str_repeat("1234567890", 25001);
		
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="note" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="AAAAAAAA"><note>' . $content . '</note></item></items></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data, true);
		$xml = Sync::waitForUpload(self::$sessionID, $response, $this, true);
		
		$this->assertTrue(isset($xml->error));
		$this->assertEquals("ERROR_PROCESSING_UPLOAD_DATA", $xml->error["code"]);
		$this->assertRegExp('/^The note \'.+\' in your library is too long /', (string) $xml->error);
		$this->assertRegExp('/ copy and paste \'AAAAAAAA\' into /', (string) $xml->error);
	}
}
?>