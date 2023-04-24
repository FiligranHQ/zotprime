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

namespace APIv2;
use API2 as API;
require_once 'APITests.inc.php';
require_once 'include/bootstrap.inc.php';

class StorageAdminTests extends APITests {
	const DEFAULT_QUOTA = 300;
	
	private static $toDelete = array();
	
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
	}
	
	public function setUp() {
		parent::setUp();
		
		// Clear subscription
		$response = API::post(
			'users/' . self::$config['userID'] . '/storageadmin',
			'quota=0&expiration=0',
			[],
			[
				"username" => self::$config['rootUsername'],
				"password" => self::$config['rootPassword']
			]
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(self::DEFAULT_QUOTA, (int) $xml->quota);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		
		// Clear subscription
		$response = API::post(
			'users/' . self::$config['userID'] . '/storageadmin',
			'quota=0&expiration=0',
			[],
			[
				"username" => self::$config['rootUsername'],
				"password" => self::$config['rootPassword']
			]
		);
	}
	
	
	public function test2GB() {
		$quota = 2000;
		$expiration = time() + (86400 * 365);
		
		$response = API::post(
			'users/' . self::$config['userID'] . '/storageadmin',
			"quota=$quota&expiration=$expiration",
			[],
			[
				"username" => self::$config['rootUsername'],
				"password" => self::$config['rootPassword']
			]
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals($quota, (int) $xml->quota);
		$this->assertEquals($expiration, (int) $xml->expiration);
	}
	
	
	public function testUnlimited() {
		$quota = 'unlimited';
		$expiration = time() + (86400 * 365);
		
		$response = API::post(
			'users/' . self::$config['userID'] . '/storageadmin',
			"quota=$quota&expiration=$expiration",
			[],
			[
				"username" => self::$config['rootUsername'],
				"password" => self::$config['rootPassword']
			]
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals($quota, (string) $xml->quota);
		$this->assertEquals($expiration, (int) $xml->expiration);
	}
}
