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
		
		// Enable note access
		API::setKeyOption(
			self::$config['userID'], self::$config['apiKey'], 'libraryNotes', 1
		);
		
		API::useFutureVersion(true);
	}
	
	
	public function setUp() {
		API::useFutureVersion(true);
	}
	
	
	public function test() {}
	
	public function __call($name, $arguments) {
		if (preg_match("/^assert([1-5][0-9]{2})$/", $name, $matches)) {
			$this->assertHTTPStatus($arguments[0], $matches[1]);
			return;
		}
		// assertNNNForObject($response, $message=false, $pos=0)
		if (preg_match("/^assert([1-5][0-9]{2}|Unchanged)ForObject$/", $name, $matches)) {
			$code = $matches[1];
			if ($arguments[0] instanceof HTTP_Request2_Response) {
				$this->assert200($arguments[0]);
				$json = json_decode($arguments[0]->getBody(), true);
			}
			else if (is_string($arguments[0])) {
				$json = json_decode($arguments[0], true);
			}
			else {
				$json = $arguments[0];
			}
			$this->assertNotNull($json);
			
			$expectedMessage = !empty($arguments[1]) ? $arguments[1] : false;
			$index = isset($arguments[2]) ? $arguments[2] : 0;
			
			if ($code == 200) {
				$this->assertArrayHasKey('success', $json);
				if (!isset($json['success'][$index])) {
					var_dump($json);
					throw new Exception("Index $index not found in success object");
				}
				if ($expectedMessage) {
					throw new Exception("Cannot check response message of object for HTTP $code");
				}
			}
			else if ($code == 'Unchanged') {
				$this->assertArrayHasKey('unchanged', $json);
				$this->assertArrayHasKey($index, $json['unchanged']);
				if ($expectedMessage) {
					throw new Exception("Cannot check response message of unchanged object");
				}
			}
			else if ($code[0] == '4' || $code[0] == '5') {
				$this->assertArrayHasKey('failed', $json);
				$this->assertArrayHasKey($index, $json['failed']);
				$this->assertEquals($code, $json['failed'][$index]['code']);
				if ($expectedMessage) {
					$this->assertEquals($expectedMessage, $json['failed'][$index]['message']);
				}
			}
			else {
				throw new Exception("HTTP $code cannot be returned for an individual object");
			}
			return;
		}
		throw new Exception("Invalid function $name");
	}
	
	
	protected function assertHasResults($res) {
		$xml = $res->getBody();
		$xml = new SimpleXMLElement($xml);
		
		$zapiNodes = $xml->children(self::$nsZAPI);
		$this->assertNotEquals(0, (int) $zapiNodes->totalResults);
		$this->assertNotEquals(0, count($xml->entry));
	}
	
	
	protected function assertNumResults($num, $res) {
		$xml = $res->getBody();
		$xml = new SimpleXMLElement($xml);
		
		$this->assertEquals($num, count($xml->entry));
	}
	
	protected function assertTotalResults($num, $res) {
		$xml = $res->getBody();
		$xml = new SimpleXMLElement($xml);
		
		$zapiNodes = $xml->children(self::$nsZAPI);
		$this->assertEquals($num, (int) $zapiNodes->totalResults);
	}
	
	
	protected function assertNoResults($res) {
		$xml = $res->getBody();
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

