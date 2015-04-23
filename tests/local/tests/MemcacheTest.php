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

class MemcacheTests extends PHPUnit_Framework_TestCase {
	public function testQueue() {
		// Clean up
		Z_Core::$MC->rollback(true);
		Z_Core::$MC->delete("testFoo");
		Z_Core::$MC->delete("testFoo2");
		Z_Core::$MC->delete("testFoo3");
		Z_Core::$MC->delete("testDeleted");
		
		// Used below
		Z_Core::$MC->set("testDeleted1", "foo1");
		
		Z_Core::$MC->begin();
		Z_Core::$MC->set("testFoo", "bar");
		Z_Core::$MC->set("testFoo", "bar2");
		Z_Core::$MC->add("testFoo", "bar3"); // should be ignored
		
		Z_Core::$MC->add("testFoo2", "bar4");
		
		Z_Core::$MC->add("testFoo3", "bar5");
		Z_Core::$MC->set("testFoo3", "bar6");
		
		// Gets within a transaction should return the queued value
		$this->assertEquals(Z_Core::$MC->get("testFoo"), "bar2");
		$this->assertEquals(Z_Core::$MC->get("testFoo2"), "bar4");
		$this->assertEquals(Z_Core::$MC->get("testFoo3"), "bar6");
		
		// Multi-gets within a transaction should return the queued value
		$arr = array("testFoo" => "bar2", "testFoo2" => "bar4", "testFoo3" => "bar6");
		$this->assertEquals(Z_Core::$MC->get(array("testFoo", "testFoo2", "testFoo3")), $arr);
		
		// Gets for a deleted key within the transaction should return false,
		// whether the key was set before or during the transaction
		Z_Core::$MC->set("testDeleted2", "foo2");
		Z_Core::$MC->delete("testDeleted1");
		$this->assertFalse(Z_Core::$MC->get("testDeleted1"));
		Z_Core::$MC->delete("testDeleted2");
		$this->assertFalse(Z_Core::$MC->get("testDeleted2"));
		
		Z_Core::$MC->commit();
		
		$this->assertEquals(Z_Core::$MC->get("testFoo"), "bar2");
		$this->assertEquals(Z_Core::$MC->get("testFoo2"), "bar4");
		$this->assertEquals(Z_Core::$MC->get("testFoo3"), "bar6");
		$this->assertFalse(Z_Core::$MC->get("testDeleted"));
		
		// Clean up
		Z_Core::$MC->delete("testFoo");
		Z_Core::$MC->delete("testFoo2");
		Z_Core::$MC->delete("testFoo3");
		Z_Core::$MC->delete("testDeleted");
	}
	
	public function testUnicode() {
		// Clean up
		Z_Core::$MC->rollback(true);
		Z_Core::$MC->delete("testUnicode1");
		Z_Core::$MC->delete("testUnicode2");
		
		$str1 = "Øüévrê";
		$str2 = "汉字漢字";
		
		Z_Core::$MC->set("testUnicode1", $str1 . $str2);
		$this->assertEquals(Z_Core::$MC->get("testUnicode1"), $str1 . $str2);
		
		$arr = array('foo1' => $str1, 'foo2' => $str2);
		Z_Core::$MC->set("testUnicode2", $arr);
		$this->assertEquals(Z_Core::$MC->get("testUnicode2"), $arr);
		
		// Clean up
		Z_Core::$MC->delete("testUnicode1");
		Z_Core::$MC->delete("testUnicode2");
	}
	
	public function testNonExistent() {
		// Clean up
		Z_Core::$MC->rollback(true);
		Z_Core::$MC->delete("testMissing");
		Z_Core::$MC->delete("testZero");
		
		Z_Core::$MC->set("testZero", 0);
		
		$this->assertFalse(Z_Core::$MC->get("testMissing"));
		$this->assertTrue(0 === Z_Core::$MC->get("testZero"));
		
		// Clean up
		Z_Core::$MC->delete("testZero");
	}
	
	public function testMultiGet() {
		// Clean up
		Z_Core::$MC->rollback(true);
		Z_Core::$MC->delete("testFoo");
		Z_Core::$MC->delete("testFoo2");
		Z_Core::$MC->delete("testFoo3");
		
		Z_Core::$MC->set("testFoo", "bar");
		Z_Core::$MC->set("testFoo2", "bar2");
		Z_Core::$MC->set("testFoo3", "bar3");
		
		$arr = array(
			"testFoo" => "bar",
			"testFoo2" => "bar2",
			"testFoo3" => "bar3"
		);
		$this->assertEquals(Z_Core::$MC->get(array("testFoo", "testFoo2", "testFoo3")), $arr);
		
		// Clean up
		Z_Core::$MC->delete("testFoo");
		Z_Core::$MC->delete("testFoo2");
		Z_Core::$MC->delete("testFoo3");
	}
}
