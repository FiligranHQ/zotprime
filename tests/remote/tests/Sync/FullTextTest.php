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
		
		$updateKey = (string) $xml['updateKey'];
		$key = Zotero_Utilities::randomString(8, 'key', true);
		$dateAdded = date('Y-m-d H:i:s', time() - 1);
		$dateModified = date('Y-m-d H:i:s');
		
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
		
		$xml = Sync::updated(self::$sessionID, 1, false, false, ["ft" => 1]);
		$lastSyncTimestamp = (int) $xml['timestamp'];
		$this->assertEquals(1, $xml->updated[0]->fulltexts->count());
		$this->assertEquals(1, $xml->updated[0]->fulltexts[0]->fulltext->count());
		$this->assertEquals($content, (string) $xml->updated[0]->fulltexts[0]->fulltext[0]);
		$this->assertEquals(strlen($content), (int) $xml->updated[0]->fulltexts[0]->fulltext[0]['indexedChars']);
		$this->assertEquals($totalChars, (int) $xml->updated[0]->fulltexts[0]->fulltext[0]['totalChars']);
		
		$xml = Sync::updated(self::$sessionID, $lastSyncTimestamp + 1, false, false, ["ft" => 1]);
		$this->assertEquals(0, $xml->updated[0]->fulltexts->count());
		
		$xml = Sync::updated(self::$sessionID, 1, false, false, ["ft" => 1]);
		$this->assertEquals(1, $xml->updated[0]->fulltexts->count());
	}
	
	public function testLargeFullTextSync() {
		$xml = Sync::updated(self::$sessionID, 1, false, false, ["ft" => 1]);
		$timestamp1 = (int) $xml['timestamp'];
		$updateKey = (string) $xml['updateKey'];
		
		$key1 = Zotero_Utilities::randomString(8, 'key', true);
		$key2 = Zotero_Utilities::randomString(8, 'key', true);
		$key3 = Zotero_Utilities::randomString(8, 'key', true);
		$key4 = Zotero_Utilities::randomString(8, 'key', true);
		
		$dateAdded = date( 'Y-m-d H:i:s', time() - 1);
		$dateModified = date( 'Y-m-d H:i:s', time());
		
		$content1 = "This is test content";
		$content2 = "This is more test content";
		
		$maxChars = 500000;
		$str = "abcdf ghijklm ";
		$content3 = str_repeat("abcdf ghijklm ", ceil($maxChars / strlen($str)) + 1);
		
		$content4 = "This is even more test content";
		
		$xmlstr = '<data version="9">'
			. '<items>'
			. '<item libraryID="' . self::$config['libraryID'] . '" '
				. 'itemType="attachment" '
				. 'dateAdded="' . $dateAdded . '" '
				. 'dateModified="' . $dateModified . '" '
				. 'key="' . $key1 . '"/>'
			. '<item libraryID="' . self::$config['libraryID'] . '" '
				. 'itemType="attachment" '
				. 'dateAdded="' . $dateAdded . '" '
				. 'dateModified="' . $dateModified . '" '
				. 'key="' . $key2 . '"/>'
			. '</items>'
			. '<fulltexts>'
			. '<fulltext libraryID="' . self::$config['libraryID'] . '" '
				. 'key="' . $key1 . '" '
				. 'indexedChars="' . strlen($content1) . '" '
				. 'totalChars="200000" '
				. 'indexedPages="0" '
				. 'totalPages="0">'
				. htmlspecialchars($content1)
			. '</fulltext>'
			. '<fulltext libraryID="' . self::$config['libraryID'] . '" '
				. 'key="' . $key2 . '" '
				. 'indexedChars="' . strlen($content2) . '" '
				. 'totalChars="200000" '
				. 'indexedPages="0" '
				. 'totalPages="0">'
				. htmlspecialchars($content2)
			. '</fulltext>'
			. '</fulltexts>'
			. '</data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $xmlstr);
		$xml = Sync::waitForUpload(self::$sessionID, $response, $this);
		$timestamp2 = (int) $xml['timestamp'];
		
		$xml = Sync::updated(self::$sessionID, $timestamp2, false, false, ["ft" => 1]);
		$updateKey = (string) $xml['updateKey'];
		
		// Wait until the timestamp advances
		do {
			$xml = Sync::updated(self::$sessionID, $timestamp2, false, false, ["ft" => 1]);
			usleep(500);
		}
		while ((int) $xml['timestamp'] <= ($timestamp2 + 2));
		
		$xmlstr = '<data version="9">'
			. '<items>'
			. '<item libraryID="' . self::$config['libraryID'] . '" '
				. 'itemType="attachment" '
				. 'dateAdded="' . $dateAdded . '" '
				. 'dateModified="' . $dateModified . '" '
				. 'key="' . $key3 . '"/>'
			. '<item libraryID="' . self::$config['libraryID'] . '" '
				. 'itemType="attachment" '
				. 'dateAdded="' . $dateAdded . '" '
				. 'dateModified="' . $dateModified . '" '
				. 'key="' . $key4 . '"/>'
			. '</items>'
			. '<fulltexts>'
			. '<fulltext libraryID="' . self::$config['libraryID'] . '" '
				. 'key="' . $key3 . '" '
				. 'indexedChars="' . strlen($content3) . '" '
				. 'totalChars="200000" '
				. 'indexedPages="0" '
				. 'totalPages="0">'
				. htmlspecialchars($content3)
			. '</fulltext>'
			. '<fulltext libraryID="' . self::$config['libraryID'] . '" '
				. 'key="' . $key4 . '" '
				. 'indexedChars="' . strlen($content4) . '" '
				. 'totalChars="200000" '
				. 'indexedPages="0" '
				. 'totalPages="0">'
				. htmlspecialchars($content4)
			. '</fulltext>'
			. '</fulltexts>'
			. '</data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $xmlstr);
		$xml = Sync::waitForUpload(self::$sessionID, $response, $this);
		$timestamp3 = (int) $xml['timestamp'];
		
		// Get all results
		$xml = Sync::updated(self::$sessionID, 1, false, false, ["ft" => 1]);
		
		$this->assertEquals(1, $xml->updated[0]->fulltexts->count());
		$this->assertEquals(4, $xml->updated[0]->fulltexts[0]->fulltext->count());
		
		$resultContent1 = (string) array_shift($xml->updated[0]->fulltexts[0]->xpath("//fulltext[@key='$key1']"));
		$resultContent2 = (string) array_shift($xml->updated[0]->fulltexts[0]->xpath("//fulltext[@key='$key2']"));
		$resultContent3 = (string) array_shift($xml->updated[0]->fulltexts[0]->xpath("//fulltext[@key='$key3']"));
		$resultContent4 = (string) array_shift($xml->updated[0]->fulltexts[0]->xpath("//fulltext[@key='$key4']"));
		
		if ($resultContent3 === "") {
			$this->assertEquals($content1, $resultContent1);
			$this->assertEquals($content2, $resultContent2);
			$this->assertEquals($content4, $resultContent4);
		}
		else {
			$this->assertEquals("", $resultContent1);
			$this->assertEquals("", $resultContent2);
			$this->assertEquals($content3, $resultContent3);
			$this->assertEquals("", $resultContent4);
		}
		
		// Request past last content
		$xml = Sync::updated(self::$sessionID, $timestamp3, false, false, ["ft" => 1]);
		$this->assertEquals(0, $xml->updated[0]->fulltexts->count());
		
		// Request for explicit keys
		$params = ["ft" => 1];
		$params["ftkeys"][self::$config['libraryID']] = [$key1, $key2, $key3, $key4];
		$xml = Sync::updated(self::$sessionID, $timestamp3, false, false, $params);
		
		$this->assertEquals(1, $xml->updated[0]->fulltexts->count());
		$this->assertEquals(4, $xml->updated[0]->fulltexts[0]->fulltext->count());
		
		$resultContent1 = (string) array_shift($xml->updated[0]->fulltexts[0]->xpath("//fulltext[@key='$key1']"));
		$resultContent2 = (string) array_shift($xml->updated[0]->fulltexts[0]->xpath("//fulltext[@key='$key2']"));
		$resultContent3 = (string) array_shift($xml->updated[0]->fulltexts[0]->xpath("//fulltext[@key='$key3']"));
		$resultContent4 = (string) array_shift($xml->updated[0]->fulltexts[0]->xpath("//fulltext[@key='$key4']"));
		
		if ($resultContent3 === "") {
			$this->assertEquals($content1, $resultContent1);
			$this->assertEquals($content2, $resultContent2);
			$this->assertEquals($content4, $resultContent4);
		}
		else {
			$this->assertEquals("", $resultContent1);
			$this->assertEquals("", $resultContent2);
			$this->assertEquals($content3, $resultContent3);
			$this->assertEquals("", $resultContent4);
		}
		
		// Request for combo of time and keys
		$params = ["ft" => 1];
		$params["ftkeys"][self::$config['libraryID']] = [$key2];
		$xml = Sync::updated(self::$sessionID, $timestamp2, false, false, $params);
		
		$this->assertEquals(1, $xml->updated[0]->fulltexts->count());
		$this->assertEquals(3, $xml->updated[0]->fulltexts[0]->fulltext->count());
		
		$resultContent2 = (string) array_shift($xml->updated[0]->fulltexts[0]->xpath("//fulltext[@key='$key2']"));
		$resultContent3 = (string) array_shift($xml->updated[0]->fulltexts[0]->xpath("//fulltext[@key='$key3']"));
		$resultContent4 = (string) array_shift($xml->updated[0]->fulltexts[0]->xpath("//fulltext[@key='$key4']"));
		
		if ($resultContent3 === "") {
			$this->assertEquals($content2, $resultContent2);
			$this->assertEquals($content4, $resultContent4);
		}
		else {
			$this->assertEquals("", $resultContent2);
			$this->assertEquals($content3, $resultContent3);
			$this->assertEquals("", $resultContent4);
		}
		
		// Request past last content, again
		$xml = Sync::updated(self::$sessionID, $timestamp3, false, false, ["ft" => 1]);
		$this->assertEquals(0, $xml->updated[0]->fulltexts->count());
		
		// Request for single long content
		$params = ["ft" => 1];
		$params["ftkeys"][self::$config['libraryID']] = [$key3];
		$xml = Sync::updated(self::$sessionID, $timestamp3, false, false, $params);
		
		$this->assertEquals(1, $xml->updated[0]->fulltexts->count());
		$this->assertEquals(1, $xml->updated[0]->fulltexts[0]->fulltext->count());
		
		$resultContent3 = (string) array_shift($xml->updated[0]->fulltexts[0]->xpath("//fulltext[@key='$key3']"));
		$this->assertEquals($content3, $resultContent3);
		
		// Request for all items by upgrade flag
		$params = [
			"ft" => 1,
			"ftkeys" => "all"
		];
		$xml = Sync::updated(self::$sessionID, $timestamp3, false, false, $params);
		$this->assertEquals(1, $xml->updated[0]->fulltexts->count());
		$this->assertEquals(4, $xml->updated[0]->fulltexts[0]->fulltext->count());
		
		// Request for empty items with FT disabled
		$params = ["ft" => 0];
		$xml = Sync::updated(self::$sessionID, 1, false, false, $params);
		$this->assertEquals(1, $xml->updated[0]->fulltexts->count());
		$this->assertEquals(4, $xml->updated[0]->fulltexts[0]->fulltext->count());
		$this->assertEmpty((string) array_shift($xml->updated[0]->fulltexts[0]->xpath("//fulltext[@key='$key1']")));
		$this->assertEmpty((string) array_shift($xml->updated[0]->fulltexts[0]->xpath("//fulltext[@key='$key2']")));
		$this->assertEmpty((string) array_shift($xml->updated[0]->fulltexts[0]->xpath("//fulltext[@key='$key3']")));
		$this->assertEmpty((string) array_shift($xml->updated[0]->fulltexts[0]->xpath("//fulltext[@key='$key4']")));
	}
}
