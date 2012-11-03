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

require_once 'APITests.inc.php';
require_once 'include/api.inc.php';

class NoteTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		require 'include/config.inc.php';
		API::userClear($config['userID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		require 'include/config.inc.php';
		API::userClear($config['userID']);
	}
	
	
	public function testNoteTooLong() {
		$content = str_repeat("1234567890", 25001);
		
		$json = API::getItemTemplate("note");
		$json->note = $content;
		
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400($response);
		//$this->assertRegExp('/^Note \'.+\' too long$/', $response->getBody());
		$this->assertEquals(
			"Note '1234567890123456789012345678901234567890123456789012345678901234567890123456789...' too long",
			$response->getBody()
		);
		
		// Blank first two lines
		$content = " \n \n" . $content;
		
		$json = API::getItemTemplate("note");
		$json->note = $content;
		
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400($response);
		//$this->assertRegExp('/^Note \'.+\' too long$/', (string) $response->getBody());
		
		$this->assertEquals(
			"Note '1234567890123456789012345678901234567890123456789012345678901234567890123456789...' too long",
			$response->getBody()
		);
		
		// Title and then more after newlines
		// Blank first line
		$content = "Full Text:\n\n" . $content;
		
		$json = API::getItemTemplate("note");
		$json->note = $content;
		
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400($response);
		//$this->assertRegExp('/^Note \'.+\' too long$/', (string) $response->getBody());
		
		$this->assertEquals(
			"Note 'Full Text: 123456789012345678901234567890123456789012345678901234567890123...' too long",
			$response->getBody()
		);
		
		// All content within HTML tags
		$content = "<p><!-- $content --></p>";
		
		$json = API::getItemTemplate("note");
		$json->note = $content;
		
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400($response);
		
		$this->assertEquals(
			"Note '&amp;lt;p&amp;gt;&amp;lt;!-- Full Text: 1234567890123456789012345678901234567890123456789012345...' too long",
			$response->getBody()
		);
	}
}
?>
