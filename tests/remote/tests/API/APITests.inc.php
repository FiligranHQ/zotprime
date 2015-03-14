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

require_once 'include/bootstrap.inc.php';

//
// Helper functions
//
class APITests extends PHPUnit_Framework_TestCase {
	protected static $config;
	protected static $nsZAPI;
	
	public static function setUpBeforeClass() {
		require 'include/config.inc.php';
		foreach ($config as $k => $v) {
			self::$config[$k] = $v;
		}
	}
	
	
	public function setUp() {
	}
	
	
	public function test() {}
	
	
	protected function assertContentType($contentType, $response) {
		try {
			$this->assertEquals($contentType, $response->getHeader("Content-Type"));
		}
		catch (Exception $e) {
			echo "\n" . $response->getBody() . "\n";
			throw ($e);
		}
	}
	
	
	protected function assertHTTPStatus($status, $response) {
		try {
			$this->assertEquals($status, $response->getStatus());
		}
		catch (Exception $e) {
			echo "\n" . $response->getBody() . "\n";
			throw ($e);
		}
	}
	
	
	protected function assertISO8601Date($date) {
		$this->assertTrue(\Zotero_Date::isISO8601($date));
	}
}

