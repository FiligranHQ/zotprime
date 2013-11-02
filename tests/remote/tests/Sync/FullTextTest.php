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

require_once 'include/api.inc.php';
require_once 'include/sync.inc.php';

class SyncFullTextTests extends PHPUnit_Framework_TestCase {
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
		self::$sessionID = Sync::login();
	}
	
	public function tearDown() {
		Sync::logout(self::$sessionID);
		self::$sessionID = null;
	}
	
	public function testFullTextSync() {
		$xml = Sync::updated(self::$sessionID);
		$initialTimestamp = (int) $xml['timestamp'];
		
		$updateKey = (string) $xml['updateKey'];
		$key = Zotero_Utilities::randomString(8, 'key', true);
		$dateAdded = date( 'Y-m-d H:i:s', time() - 1);
		$dateModified = date( 'Y-m-d H:i:s', time());
		
		$content = "This is some full-text content.";
		$totalChars = 2500;
		
		$xmlstr = '<data version="9">'
			. '<items>'
			. '<item libraryID="' . self::$config['libraryID'] . '" '
				. 'itemType="attachment" '
				. 'dateAdded="' . $dateAdded . '" '
				. 'dateModified="' . $dateModified . '" '
				. 'key="' . $key . '"/>'
			. '</items>'
			. '<fulltexts>'
			. '<fulltext libraryID="' . self::$config['libraryID'] . '" '
				. 'key="' . $key . '" '
				. 'indexedChars="' . strlen($content) . '" '
				. 'totalChars="' . $totalChars . '" '
				. 'indexedPages="0" '
				. 'totalPages="0">'
				. htmlspecialchars($content)
			. '</fulltext>'
			. '</fulltexts>'
			. '</data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $xmlstr);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		$xml = Sync::updated(self::$sessionID);
		$lastSyncTimestamp = (int) $xml['timestamp'];
		$this->assertEquals(1, $xml->updated[0]->fulltexts->count());
		$this->assertEquals(1, $xml->updated[0]->fulltexts[0]->fulltext->count());
		$this->assertEquals($content, (string) $xml->updated[0]->fulltexts[0]->fulltext[0]);
		$this->assertEquals(strlen($content), (int) $xml->updated[0]->fulltexts[0]->fulltext[0]['indexedChars']);
		$this->assertEquals($totalChars, (int) $xml->updated[0]->fulltexts[0]->fulltext[0]['totalChars']);
		
		$xml = Sync::updated(self::$sessionID, $lastSyncTimestamp + 1);
		$this->assertEquals(0, $xml->updated[0]->fulltexts->count());
		
		$xml = Sync::updated(self::$sessionID, $initialTimestamp);
		$this->assertEquals(1, $xml->updated[0]->fulltexts->count());
	}
}
