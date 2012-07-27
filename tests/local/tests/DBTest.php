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
	/*public function testLastInsertIDFromStatement() {
		Zotero_DB::query("DROP TABLE IF EXISTS foo");
		Zotero_DB::query("CREATE TABLE foo (bar INTEGER PRIMARY KEY NOT NULL AUTO_INCREMENT, bar2 INTEGER NOT NULL)");
		$sql = "INSERT INTO foo VALUES (NULL, ?)";
		$stmt = Zotero_DB::getStatement($sql, true);
		$insertID = Zotero_DB::queryFromStatement($stmt, array(1));
		$this->assertEquals($insertID, 1);
		$insertID = Zotero_DB::queryFromStatement($stmt, array(2));
		$this->assertEquals($insertID, 2);
		Zotero_DB::query("DROP TABLE foo");
	}
	
	public function testNull() {
		Zotero_DB::query("DROP TABLE IF EXISTS foo");
		Zotero_DB::query("CREATE TABLE foo (bar INTEGER NULL, bar2 INTEGER NULL DEFAULT NULL)");
		Zotero_DB::query("INSERT INTO foo VALUES (?,?)", array(null, 3));
		$result = Zotero_DB::query("SELECT * FROM foo WHERE bar=?", null);
		$this->assertNull($result[0]['bar']);
		$this->assertEquals($result[0]['bar2'], 3);
		Zotero_DB::query("DROP TABLE foo");
	}
	
	public function testPreparedStatement() {
		Zotero_DB::query("DROP TABLE IF EXISTS foo");
		Zotero_DB::query("CREATE TABLE foo (bar INTEGER NULL, bar2 INTEGER NULL DEFAULT NULL)");
		$stmt = Zotero_DB::getStatement("INSERT INTO foo (bar) VALUES (?)");
		$stmt->execute(array(1));
		$stmt->execute(array(2));
		$result = Zotero_DB::columnQuery("SELECT bar FROM foo");
		$this->assertEquals($result[0], 1);
		$this->assertEquals($result[1], 2);
		Zotero_DB::query("DROP TABLE foo");
	}
	
	public function testValueQuery_Null() {
		Zotero_DB::query("DROP TABLE IF EXISTS foo");
		Zotero_DB::query("CREATE TABLE foo (bar INTEGER NULL)");
		Zotero_DB::query("INSERT INTO foo VALUES (NULL)");
		$val = Zotero_DB::valueQuery("SELECT * FROM foo");
		$this->assertNull($val);
		Zotero_DB::query("DROP TABLE foo");
	}
	
	public function testQuery_boundZero() {
		Zotero_DB::query("DROP TABLE IF EXISTS foo");
		Zotero_DB::query("CREATE TABLE foo (bar INTEGER, bar2 INTEGER)");
		Zotero_DB::query("INSERT INTO foo VALUES (1, 0)");
		$this->assertEquals(Zotero_DB::valueQuery("SELECT bar FROM foo WHERE bar2=?", 0), 1);
		Zotero_DB::query("DROP TABLE foo");
	}
	
	public function testBulkInsert() {
		Zotero_DB::query("DROP TABLE IF EXISTS foo");
		Zotero_DB::query("CREATE TABLE foo (bar INTEGER, bar2 INTEGER)");
		$sql = "INSERT INTO foo VALUES ";
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
			$rows = Zotero_DB::query("SELECT * FROM foo");
			$this->assertEquals(sizeOf($rows), sizeOf($sets));
			$rowVals = array();
			foreach ($rows as $row) {
				$rowVals[] = array($row['bar'], $row['bar2']);
			}
			$this->assertEquals($rowVals, $sets);
			
			Zotero_DB::query("DELETE FROM foo");
		}
		
		// First val
		$sets2 = array();
		$sets2Comp = array();
		foreach ($sets as $set) {
			$sets2[] = $set[1];
			$sets2Comp[] = array(1, $set[1]);
		}
		Zotero_DB::bulkInsert($sql, $sets2, 2, 1);
		$rows = Zotero_DB::query("SELECT * FROM foo");
		$this->assertEquals(sizeOf($rows), sizeOf($sets2Comp));
		$rowVals = array();
		foreach ($rows as $row) {
			$rowVals[] = array($row['bar'], $row['bar2']);
		}
		$this->assertEquals($rowVals, $sets2Comp);
		
		Zotero_DB::query("DROP TABLE foo");
	}
	
	public function testIDDB() {
		$id = Zotero_ID_DB_1::valueQuery("SELECT id FROM items");
		$this->assertNotEquals(false, $id);
		
		$id = Zotero_ID_DB_2::valueQuery("SELECT id FROM items");
		$this->assertNotEquals(false, $id);
	}
	
	public function testCloseDB() {
		throw new Exception("Reimplement without temporary tables");
		
		// Create a table and close the connection
		Zotero_DB::query("CREATE TEMPORARY TABLE foo (bar INTEGER)");
		Zotero_DB::query("INSERT INTO foo VALUES (1)");
		
		$bar = Zotero_DB::valueQuery("SELECT * FROM foo");
		$this->assertEquals(1, $bar);
		
		Zotero_DB::close();
		
		// Reconnect -- temporary table should be gone
		try {
			Zotero_DB::valueQuery("SELECT * FROM foo");
			throw new Exception("Table exists");
		}
		catch (Exception $e) {
			$this->assertRegExp("/doesn't exist/", $e->getMessage());
		}
		
		// Make sure new connection is working
		Zotero_DB::query("CREATE TEMPORARY TABLE foo (bar INTEGER)");
		Zotero_DB::query("INSERT INTO foo VALUES (1)");
		
		$bar = Zotero_DB::valueQuery("SELECT * FROM foo");
		$this->assertEquals(1, $bar);
		
		Zotero_DB::query("DROP TEMPORARY TABLE foo");
	}*/
}
