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

use API2 as API;
require_once 'include/api2.inc.php';
require_once 'include/sync.inc.php';

class SyncPermissionsTests extends PHPUnit_Framework_TestCase {
	protected static $config;
	protected static $sessionID;
	protected static $sessionID2;
	
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
		API::groupClear(self::$config['ownedPrivateGroupID2']);
		
		self::$sessionID = Sync::login();
		self::$sessionID2 = Sync::login(
			array(
				'username' => self::$config['username2'],
				'password' => self::$config['password2']
			)
		);
	}
	
	
	public function tearDown() {
		Sync::logout(self::$sessionID);
		self::$sessionID = null;
		Sync::logout(self::$sessionID2);
		self::$sessionID2 = null;
	}
	
	
	public function testAddItemLibraryAccessDenied() {
		$xml = Sync::updated(self::$sessionID2);
		$updateKey = (string) $xml['updateKey'];
		
		// Create item
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="note" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="AAAAAAAA"><note>Test</note></item></items></data>';
		$response = Sync::upload(self::$sessionID2, $updateKey, $data, true);
		$xml = Sync::waitForUpload(self::$sessionID2, $response, $this, true);
		
		$this->assertTrue(isset($xml->error));
		$this->assertEquals("LIBRARY_ACCESS_DENIED", $xml->error["code"]);
	}
	
	
	public function testDeleteItemLibraryAccessDenied() {
		// Create item
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['libraryID'] . '" itemType="note" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="AAAAAAAA"><note>Test</note></item></items></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data, true);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Delete item without permissions
		$xml = Sync::updated(self::$sessionID2);
		$updateKey = (string) $xml['updateKey'];
		$data = '<data version="9"><deleted><items><item libraryID="'
			. self::$config['libraryID'] . '" key="AAAAAAAA"/>'
			. "</items></deleted></data>";
		$response = Sync::upload(self::$sessionID2, $updateKey, $data, true);
		$xml = Sync::waitForUpload(self::$sessionID2, $response, $this, true);
		
		$this->assertTrue(isset($xml->error));
		$this->assertEquals("LIBRARY_ACCESS_DENIED", $xml->error["code"]);
	}
	
	
	public function testGroupAddItemLibraryAccessDenied() {
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		
		// Create item
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['ownedPrivateGroupLibraryID2'] . '" itemType="note" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="AAAAAAAA"><note>Test</note></item></items></data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $data, true);
		$xml = Sync::waitForUpload(self::$sessionID, $response, $this, true);
		
		$this->assertTrue(isset($xml->error));
		$this->assertEquals("LIBRARY_ACCESS_DENIED", $xml->error["code"]);
	}
	
	
	public function testGroupDeleteItemLibraryAccessDenied() {
		// Create item
		$xml = Sync::updated(self::$sessionID2);
		$updateKey = (string) $xml['updateKey'];
		$data = '<data version="9"><items><item libraryID="'
			. self::$config['ownedPrivateGroupLibraryID2'] . '" itemType="note" '
			. 'dateAdded="2009-03-07 04:53:20" '
			. 'dateModified="2009-03-07 04:54:09" '
			. 'key="AAAAAAAA"><note>Test</note></item></items></data>';
		$response = Sync::upload(self::$sessionID2, $updateKey, $data, true);
		Sync::waitForUpload(self::$sessionID2, $response, $this);
		
		// Delete item without permissions
		$xml = Sync::updated(self::$sessionID);
		$updateKey = (string) $xml['updateKey'];
		$data = '<data version="9"><deleted><items><item libraryID="'
			. self::$config['ownedPrivateGroupLibraryID2'] . '" key="AAAAAAAA"/>'
			. "</items></deleted></data>";
		$response = Sync::upload(self::$sessionID, $updateKey, $data, true);
		$xml = Sync::waitForUpload(self::$sessionID, $response, $this, true);
		
		$this->assertTrue(isset($xml->error));
		$this->assertEquals("LIBRARY_ACCESS_DENIED", $xml->error["code"]);
	}
}

