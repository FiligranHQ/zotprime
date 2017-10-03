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

namespace APIv3;
require_once 'tests/API/APITests.inc.php';
use API3 as API, \Exception, \SimpleXMLElement;
require_once 'include/api3.inc.php';

//
// Helper functions
//
class APITests extends \APITests {
	protected static $config;
	protected static $nsZAPI;
	private $notificationHeader = 'zotero-debug-notifications';
	
	public static function setUpBeforeClass() {
		require 'include/config.inc.php';
		foreach ($config as $k => $v) {
			self::$config[$k] = $v;
		}
		self::$nsZAPI = 'http://zotero.org/ns/api';
		
		API::useAPIVersion(3);
		
		API::setKeyUserPermission(self::$config['apiKey'], 'notes', true);
		API::setKeyUserPermission(self::$config['apiKey'], 'write', true);
	}
	
	
	public function setUp() {
		parent::setUp();
		API::useAPIKey(self::$config['apiKey']);
		API::useAPIVersion(3);
		$this->apiVersion = 3;
	}
	
	
	public function test() {}
	
	public function __call($name, $arguments) {
		if (preg_match("/^assert([1-5][0-9]{2})$/", $name, $matches)) {
			$this->assertHTTPStatus($matches[1], $arguments[0]);
			// Check response body
			if (isset($arguments[1])) {
				$this->assertEquals($arguments[1], $arguments[0]->getBody());
			}
			return;
		}
		// assertNNNForObject($response, $message=false, $pos=0)
		if (preg_match("/^assert([1-5][0-9]{2}|Unchanged)ForObject$/", $name, $matches)) {
			$code = $matches[1];
			if ($arguments[0] instanceof \HTTP_Request2_Response) {
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
			
			if ($code == 'Unchanged') {
				$index = isset($arguments[1]) ? $arguments[1] : 0;
			}
			else {
				$expectedMessage = !empty($arguments[1]) ? $arguments[1] : false;
				$index = isset($arguments[2]) ? $arguments[2] : 0;
			}
			
			if ($code == 200) {
				$this->assertArrayHasKey('successful', $json);
				if (!isset($json['successful'][$index])) {
					var_dump($json);
					throw new Exception("Index $index not found in 'successful' object");
				}
				// Deprecated
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
				try {
					$this->assertArrayHasKey('unchanged', $json);
					$this->assertArrayHasKey($index, $json['unchanged']);
				}
				catch (Exception $e) {
					var_dump($json);
					throw $e;
				}
			}
			else if ($code[0] == '4' || $code[0] == '5') {
				try {
					$this->assertArrayHasKey('failed', $json);
					$this->assertArrayHasKey($index, $json['failed']);
				}
				catch (Exception $e) {
					var_dump($json);
					throw $e;
				}
				try {
					$this->assertEquals($code, $json['failed'][$index]['code']);
				}
				catch (Exception $e) {
					var_dump($json);
					throw $e;
				}
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
	
	
	protected function assertTotalResults($num, $response) {
		$this->assertTrue(is_numeric($response->getHeader('Total-Results')));
		$this->assertEquals($num, (int) $response->getHeader('Total-Results'));
	}
	
	
	protected function assertNumResults($num, $response) {
		$contentType = $response->getHeader('Content-Type');
		if ($contentType == 'application/json') {
			$json = API::getJSONFromResponse($response);
			$this->assertEquals($num, count($json));
		}
		else if (strpos($contentType, 'text/plain') === 0) {
			$rows = array_filter(explode("\n", trim($response->getBody())));
			$this->assertEquals($num, count($rows));
		}
		else if ($contentType == 'application/atom+xml') {
			$xml = $response->getBody();
			$xml = new SimpleXMLElement($xml);
			$this->assertEquals($num, count($xml->entry));
		}
		else if ($contentType == 'application/x-bibtex') {
			$matched = preg_match_all('/^@[a-z]+{/m', $response->getBody());
			$this->assertEquals($num, $matched);
		}
		else {
			throw new Exception("Unknown content type '$contentType'");
		}
	}
	
	
	protected function assertNoResults($response) {
		$this->assertTotalResults(0, $response);
		
		$contentType = $response->getHeader('Content-Type');
		if ($contentType == 'application/json') {
			$json = API::getJSONFromResponse($response);
			$this->assertEquals(0, count($json));
		}
		else if ($contentType == 'application/atom+xml') {
			$xml = new SimpleXMLElement($response->getBody());
			$zapiNodes = $xml->children(self::$nsZAPI);
			$this->assertEquals(0, count($xml->entry));
		}
		else {
			throw new Exception("Unknown content type '$contentType'");
		}
	}
	
	
	protected function assertLastModifiedVersion($expected, $response) {
		$this->assertSame(
			is_numeric($expected) ? (string) $expected : $expected,
			$response->getHeader('Last-Modified-Version')
		);
	}
	
	
	protected function assertCountNotifications($expected, $response) {
		$header = $response->getHeader($this->notificationHeader);
		try {
			if ($expected === 0) {
				$this->assertNull($header);
			}
			else {
				$this->assertNotNull($header);
				$this->assertCount($expected, json_decode(base64_decode($header), true));
			}
		}
		catch (Exception $e) {
			echo "\nHeaders: " . base64_decode($header) . "\n";
			throw $e;
		}
	}
	
	
	protected function assertHasNotification($notification, $response) {
		$header = $response->getHeader($this->notificationHeader);
		$this->assertNotNull($header);
		// Header contains a Base64-encode array of encoded JSON notifications
		$notifications = json_decode(base64_decode($header), true);
		try {
			$this->assertContains($notification, array_map(function ($x) {
				return json_decode($x, true);
			}, $notifications));
		}
		catch (Exception $e) {
			echo "\nHeaders: " . base64_decode($header) . "\n";
			throw $e;
		}
	}
}
