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
	private $content;
	private $json;
	
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
	
	
	public function setUp() {
		// Create too-long note content
		$this->content = str_repeat("1234567890", 25001);
		
		// Create JSON template
		$this->json = API::getItemTemplate("note");
		$this->json->note = $this->content;
	}
	
	
	public function testNoteTooLong() {
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($this->json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject(
			$response,
			"Note '1234567890123456789012345678901234567890123456789012345678901234567890123456789...' too long"
		);
	}
	
	// Blank first two lines
	public function testNoteTooLongBlankFirstLines() {
		$this->json->note = " \n \n" . $this->content;
		
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($this->json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject(
			$response,
			"Note '1234567890123456789012345678901234567890123456789012345678901234567890123456789...' too long"
		);
	}
	
	
	public function testNoteTooLongBlankFirstLinesHTML() {
		$this->json->note = "\n<p>&nbsp;</p>\n<p>&nbsp;</p>\n" . $this->content;
		
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($this->json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject(
			$response,
			"Note '1234567890123456789012345678901234567890123456789012345678901234567890123...' too long"
		);
	}
	
	
	public function testNoteTooLongTitlePlusNewlines() {
		$this->json->note = "Full Text:\n\n" . $this->content;
		
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($this->json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject(
			$response,
			"Note 'Full Text: 1234567890123456789012345678901234567890123456789012345678901234567...' too long"
		);
	}
	
	
	// All content within HTML tags
	public function testNoteTooLongWithinHTMLTags() {
		$this->json->note = "&nbsp;\n<p><!-- " . $this->content . " --></p>";
		
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($this->json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject(
			$response,
			"Note '&lt;p&gt;&lt;!-- 1234567890123456789012345678901234567890123456789012345678901234...' too long"
		);
	}
}
?>
