<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2010 Center for History and New Media
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

class DateTests extends PHPUnit_Framework_TestCase {
	public function test_strToDate() {
		$patterns = array(
			"February 28, 2011",
			"2011-02-28",
			"28-02-2011",
			"Feb 28 2011",
			"28 Feb 2011",
		);
		
		foreach ($patterns as $pattern) {
			$parts = Zotero_Date::strToDate($pattern);
			$this->assertEquals(2011, $parts['year']);
			$this->assertEquals(2, $parts['month']);
			$this->assertEquals(28, $parts['day']);
			$this->assertFalse(isset($parts['part']));
		}
	}
	
	
	public function test_strToDate_monthYear() {
		$patterns = array(
			//"9/10",
			//"09/10",
			//"9/2010",
			//"09/2010",
			//"09-2010",
			"September 2010",
			"Sep 2010",
			"Sep. 2010"
		);
		
		foreach ($patterns as $pattern) {
			$parts = Zotero_Date::strToDate($pattern);
			$this->assertEquals(2010, $parts['year']);
			$this->assertEquals(9, $parts['month']);
			$this->assertFalse(isset($parts['day']));
			$this->assertFalse(isset($parts['part']));
		}
	}
	
	
	public function test_strToDate_yearRange() {
		$pattern = "1983-84";
		$parts = Zotero_Date::strToDate($pattern);
		$this->assertEquals(1983, $parts['year']);
		$this->assertFalse(isset($parts['month']));
		$this->assertFalse(isset($parts['day']));
		$this->assertEquals("84", $parts['part']);
		
		$pattern = "1983-1984";
		$parts = Zotero_Date::strToDate($pattern);
		$this->assertEquals(1983, $parts['year']);
		$this->assertFalse(isset($parts['month']));
		$this->assertFalse(isset($parts['day']));
		$this->assertEquals("1984", $parts['part']);
	}
	
	
	/*public function test_strToDate_BCE() {
		$patterns = array(
			"c380 BC/1935",
			"2009 BC",
			"2009 B.C.",
			"2009 BCE",
			"2009 B.C.E.",
			"2009BC",
			"2009BCE",
			"2009B.C.",
			"2009B.C.E.",
			"c2009BC",
			"c2009BCE",
			"~2009BC",
			"~2009BCE",
			"-300"
		);
		
		foreach ($patterns as $pattern) {
			$parts = Zotero_Date::strToDate($pattern);
			var_dump($parts['year']);
			$this->assertTrue(!!preg_match("/^[0-9]+$/", $parts['year']));
		}
	}*/
}
