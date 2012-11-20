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

class DBTests extends PHPUnit_Framework_TestCase {
	public function setUp() {
		Zotero_DB::query("DROP TABLE IF EXISTS test");
	}
	
	public function tearDown() {
		Zotero_DB::query("DROP TABLE IF EXISTS test");
		Zotero_DB::query("SET wait_timeout = 28800");
	}
	
	public function testCloseDB() {
		Zotero_DB::query("SET @foo='bar'");
		$this->assertEquals("bar", Zotero_DB::valueQuery("SELECT @foo"));
		Zotero_DB::close();
		
		sleep(3);
		
		// The false check ensures this is a different connection
		$this->assertEquals(false, Zotero_DB::valueQuery("SELECT @foo"));
	}
	
	public function testAutoReconnect() {
		Zotero_DB::query("SET wait_timeout = 1");
		
		Zotero_DB::query("SET @foo='bar'");
		$this->assertEquals("bar", Zotero_DB::valueQuery("SELECT @foo"));
		
		sleep(3);
		
		try {
			Zotero_DB::valueQuery("SELECT @foo");
			$fail = true;
		}
		catch (Exception $e) {
			$this->assertContains("MySQL server has gone away", $e->getMessage());
		}
		
		if (isset($fail)) {
			$this->fail("Reconnect should not be automatic");
		}
		
		Zotero_DB::close();
		$this->assertEquals(false, Zotero_DB::valueQuery("SELECT @foo"));
	}
	
	public function testLastInsertIDFromStatement() {
		Zotero_DB::query("CREATE TABLE test (foo INTEGER PRIMARY KEY NOT NULL AUTO_INCREMENT, foo2 INTEGER NOT NULL)");
		$sql = "INSERT INTO test VALUES (NULL, ?)";
		$stmt = Zotero_DB::getStatement($sql, true);
		$insertID = Zotero_DB::queryFromStatement($stmt, array(1));
		$this->assertEquals($insertID, 1);
		$insertID = Zotero_DB::queryFromStatement($stmt, array(2));
		$this->assertEquals($insertID, 2);
	}
	
	public function testNull() {
		Zotero_DB::query("CREATE TABLE test (foo INTEGER NULL, foo2 INTEGER NULL DEFAULT NULL)");
		Zotero_DB::query("INSERT INTO test VALUES (?,?)", array(null, 3));
		$result = Zotero_DB::query("SELECT * FROM test WHERE foo=?", null);
		$this->assertNull($result[0]['foo']);
		$this->assertEquals($result[0]['foo2'], 3);
	}
	
	public function testPreparedStatement() {
		Zotero_DB::query("CREATE TABLE test (foo INTEGER NULL, foo2 INTEGER NULL DEFAULT NULL)");
		$stmt = Zotero_DB::getStatement("INSERT INTO test (foo) VALUES (?)");
		$stmt->execute(array(1));
		$stmt->execute(array(2));
		$result = Zotero_DB::columnQuery("SELECT foo FROM test");
		$this->assertEquals($result[0], 1);
		$this->assertEquals($result[1], 2);
	}
	
	public function testValueQuery_Null() {
		Zotero_DB::query("CREATE TABLE test (foo INTEGER NULL)");
		Zotero_DB::query("INSERT INTO test VALUES (NULL)");
		$val = Zotero_DB::valueQuery("SELECT * FROM test");
		$this->assertNull($val);
	}
	
	public function testQuery_boundZero() {
		Zotero_DB::query("CREATE TABLE test (foo INTEGER, foo2 INTEGER)");
		Zotero_DB::query("INSERT INTO test VALUES (1, 0)");
		$this->assertEquals(Zotero_DB::valueQuery("SELECT foo FROM test WHERE foo2=?", 0), 1);
	}
	
	public function testBulkInsert() {
		Zotero_DB::query("CREATE TABLE test (foo INTEGER, foo2 INTEGER)");
		$sql = "INSERT INTO test VALUES ";
		$sets = array(
			array(1,2),
			array(2,3),
			array(3,4),
			array(4,5),
			array(5,6),
			array(6,7)
		);
		
		// Different maxInsertGroups values
		for ($i=1; $i<8; $i++) {
			Zotero_DB::bulkInsert($sql, $sets, $i);
			$rows = Zotero_DB::query("SELECT * FROM test");
			$this->assertEquals(sizeOf($rows), sizeOf($sets));
			$rowVals = array();
			foreach ($rows as $row) {
				$rowVals[] = array($row['foo'], $row['foo2']);
			}
			$this->assertEquals($rowVals, $sets);
			
			Zotero_DB::query("DELETE FROM test");
		}
		
		// First val
		$sets2 = array();
		$sets2Comp = array();
		foreach ($sets as $set) {
			$sets2[] = $set[1];
			$sets2Comp[] = array(1, $set[1]);
		}
		Zotero_DB::bulkInsert($sql, $sets2, 2, 1);
		$rows = Zotero_DB::query("SELECT * FROM test");
		$this->assertEquals(sizeOf($rows), sizeOf($sets2Comp));
		$rowVals = array();
		foreach ($rows as $row) {
			$rowVals[] = array($row['foo'], $row['foo2']);
		}
		$this->assertEquals($rowVals, $sets2Comp);
	}
	
	public function testID() {
		$id = Zotero_ID_DB_1::valueQuery("SELECT id FROM items");
		$this->assertNotEquals(false, $id);
		
		$id = Zotero_ID_DB_2::valueQuery("SELECT id FROM items");
		$this->assertNotEquals(false, $id);
	}
}
