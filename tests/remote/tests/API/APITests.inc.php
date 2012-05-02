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
		self::$nsZAPI = 'http://zotero.org/ns/api';
	}
	
	public function test() {}
	
	public function __call($name, $arguments) {
		if (preg_match("/^assert([1-5][0-9]{2})$/", $name, $matches)) {
			$this->assertHTTPStatus($arguments[0], $matches[1]);
			return;
		}
		throw new Exception("Invalid function $name");
	}
	
	
	protected function assertHasResults($req) {
		$xml = $req->getBody();
		$xml = new SimpleXMLElement($xml);
		
		$zapiNodes = $xml->children(self::$nsZAPI);
		$this->assertNotEquals(0, (int) $zapiNodes->totalResults);
		$this->assertNotEquals(0, count($xml->entry));
	}
	
	protected function assertNumResults($num, $req) {
		$xml = $req->getBody();
		$xml = new SimpleXMLElement($xml);
		
		$zapiNodes = $xml->children(self::$nsZAPI);
		$this->assertEquals($num, (int) $zapiNodes->totalResults);
		$this->assertEquals($num, count($xml->entry));
	}
	
	protected function assertNoResults($req) {
		$xml = $req->getBody();
		$xml = new SimpleXMLElement($xml);
		
		$zapiNodes = $xml->children(self::$nsZAPI);
		$this->assertEquals(1, count($zapiNodes->totalResults));
		$this->assertEquals(0, (int) $zapiNodes->totalResults);
		$this->assertEquals(0, count($xml->entry));
	}
	
	
	protected function assertContentType($contentType, $response) {
		try {
			$this->assertEquals($contentType, $response->getHeader("Content-Type"));
		}
		catch (Exception $e) {
			echo "\n" . $response->getBody() . "\n";
			throw ($e);
		}
	}
	
	private function assertHTTPStatus($response, $status) {
		try {
			$this->assertEquals($status, $response->getStatus());
		}
		catch (Exception $e) {
			echo "\n" . $response->getBody() . "\n";
			throw ($e);
		}
	}
}

