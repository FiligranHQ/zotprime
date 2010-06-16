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

error_reporting(E_ALL | E_STRICT);

set_include_path("../include");
require_once("header.inc.php");

class Tests extends PHPUnit_Framework_TestSuite {
	public static function suite() {
		$suite = new PHPUnit_Framework_TestSuite();
		$suite->addTestSuite('CreatorsTests');
		$suite->addTestSuite('DBTests');
		$suite->addTestSuite('MemcacheTests');
		$suite->addTestSuite('TagsTests');
		$suite->addTestSuite('UsersTests');
		return $suite;
	}
	
	protected function setUp() {}
	protected function tearDown() {}
}


class CreatorsTests extends PHPUnit_Framework_TestCase {
	public function testGetDataValuesFromXML() {
		$xml = <<<'EOD'
		<data version="6">
			<creators>
				<creator libraryID="1" key="AAAAAAAA" dateAdded="2009-04-13 20:43:19" dateModified="2009-04-13 20:43:19">
					<firstName>A.</firstName>
					<lastName>Testperson</lastName>
				</creator>
				<creator libraryID="1" key="BBBBBBBB" dateAdded="2009-04-13 20:45:18" dateModified="2009-04-13 20:45:18">
					<firstName>B</firstName>
					<lastName>Téstër</lastName>
				</creator>
				<creator libraryID="1" key="CCCCCCCC" dateAdded="2009-04-13 20:55:12" dateModified="2009-04-13 20:55:12">
					<name>Center før History and New Media</name>
					<fieldMode>1</fieldMode>
				</creator>
			</creators>
			<tags>
				<tag libraryID="1" key="AAAAAAAA" name="Foo" dateAdded="2009-08-06 10:20:06" dateModified="2009-08-06 10:20:06">
					<items>BBBBBBBB</items>
				</tag>
			</tags>
		</data>
EOD;
		$xml = new SimpleXMLElement($xml);
		$domSXE = dom_import_simplexml($xml->creators);
		$doc = new DOMDocument();
		$domSXE = $doc->importNode($domSXE, true);
		$domSXE = $doc->appendChild($domSXE);
		
		$objs = Zotero_Creators::getDataValuesFromXML($doc);
		
		usort($objs, function ($a, $b) {
			if ($a->lastName == $b->lastName) {
				return 0;
			}
			
			return ($a->lastName < $b->lastName) ? -1 : 1;
		});
		
		$this->assertEquals(sizeOf($objs), 3);
		$this->assertEquals($objs[0]->fieldMode, 1);
		$this->assertEquals($objs[0]->firstName, "");
		$this->assertEquals($objs[0]->lastName, "Center før History and New Media");
		$this->assertEquals($objs[0]->birthYear, null);
		
		$this->assertEquals($objs[1]->fieldMode, 0);
		$this->assertEquals($objs[1]->firstName, "A.");
		$this->assertEquals($objs[1]->lastName, "Testperson");
		$this->assertEquals($objs[1]->birthYear, null);
		
		$this->assertEquals($objs[2]->fieldMode, 0);
		$this->assertEquals($objs[2]->firstName, "B");
		$this->assertEquals($objs[2]->lastName, "Téstër");
		$this->assertEquals($objs[2]->birthYear, null);
	}
	
	
	public function testGetLongDataValueFromXML() {
		$longName = 'Longfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellowlongfellow';
		
		$xml = <<<EOD
		<data version="6">
			<creators>
				<creator libraryID="1" key="AAAAAAAA" dateAdded="2009-04-13 20:43:19" dateModified="2009-04-13 20:43:19">
					<firstName>A.</firstName>
					<lastName>Testperson</lastName>
				</creator>
				<creator libraryID="1" key="BBBBBBBB" dateAdded="2009-04-13 20:45:18" dateModified="2009-04-13 20:45:18">
					<firstName>B</firstName>
					<lastName>$longName</lastName>
				</creator>
				<creator libraryID="1" key="CCCCCCCC" dateAdded="2009-04-13 20:55:12" dateModified="2009-04-13 20:55:12">
					<name>Center før History and New Media</name>
					<fieldMode>1</fieldMode>
				</creator>
			</creators>
		</data>
EOD;
		
		$doc = new DOMDocument();
		$doc->loadXML($xml);
		$node = Zotero_Creators::getLongDataValueFromXML($doc);
		$this->assertEquals($node->nodeName, 'lastName');
		$this->assertEquals($node->nodeValue, $longName);
		
		$xml = <<<EOD
		<data version="6">
			<creators>
				<creator libraryID="1" key="BBBBBBBB" dateAdded="2009-04-13 20:45:18" dateModified="2009-04-13 20:45:18">
					<firstName>$longName</firstName>
					<lastName>Testperson</lastName>
				</creator>
			</creators>
		</data>
EOD;
		
		$doc = new DOMDocument();
		$doc->loadXML($xml);
		$node = Zotero_Creators::getLongDataValueFromXML($doc);
		$this->assertEquals($node->nodeName, 'firstName');
		$this->assertEquals($node->nodeValue, $longName);
		
		$xml = <<<EOD
		<data version="6">
			<creators>
				<creator libraryID="1" key="BBBBBBBB" dateAdded="2009-04-13 20:45:18" dateModified="2009-04-13 20:45:18">
					<name>$longName</name>
				</creator>
			</creators>
		</data>
EOD;
		
		$doc = new DOMDocument();
		$doc->loadXML($xml);
		$node = Zotero_Creators::getLongDataValueFromXML($doc);
		$this->assertEquals($node->nodeName, 'name');
		$this->assertEquals($node->nodeValue, $longName);
	}
}



class DBTests extends PHPUnit_Framework_TestCase {
	public function testLastInsertIDFromStatement() {
		Zotero_DB::query("CREATE TEMPORARY TABLE foo (bar INTEGER PRIMARY KEY NOT NULL AUTO_INCREMENT, bar2 INTEGER NOT NULL)");
		$sql = "INSERT INTO foo VALUES (NULL, ?)";
		$stmt = Zotero_DB::getStatement($sql, true);
		$insertID = Zotero_DB::queryFromStatement($stmt, array(1));
		$this->assertEquals($insertID, 1);
		$insertID = Zotero_DB::queryFromStatement($stmt, array(2));
		$this->assertEquals($insertID, 2);
		Zotero_DB::query("DROP TEMPORARY TABLE foo");
	}
	
	public function testNull() {
		Zotero_DB::query("CREATE TEMPORARY TABLE foo (bar INTEGER NULL, bar2 INTEGER NULL DEFAULT NULL)");
		Zotero_DB::query("INSERT INTO foo VALUES (?,?)", array(null, 3));
		$result = Zotero_DB::query("SELECT * FROM foo WHERE bar=?", null);
		$this->assertNull($result[0]['bar']);
		$this->assertEquals($result[0]['bar2'], 3);
		Zotero_DB::query("DROP TEMPORARY TABLE foo");
	}
	
	public function testPreparedStatement() {
		Zotero_DB::query("CREATE TEMPORARY TABLE foo (bar INTEGER NULL, bar2 INTEGER NULL DEFAULT NULL)");
		$stmt = Zotero_DB::getStatement("INSERT INTO foo (bar) VALUES (?)");
		$stmt->execute(array(1));
		$stmt->execute(array(2));
		$result = Zotero_DB::columnQuery("SELECT bar FROM foo");
		$this->assertEquals($result[0], 1);
		$this->assertEquals($result[1], 2);
		Zotero_DB::query("DROP TEMPORARY TABLE foo");
	}
	
	public function testValueQuery_Null() {
		Zotero_DB::query("CREATE TEMPORARY TABLE foo (bar INTEGER NULL)");
		Zotero_DB::query("INSERT INTO foo VALUES (NULL)");
		$val = Zotero_DB::valueQuery("SELECT * FROM foo");
		$this->assertNull($val);
		Zotero_DB::query("DROP TEMPORARY TABLE foo");
	}
	
	public function testQuery_boundZero() {
		Zotero_DB::query("CREATE TEMPORARY TABLE foo (bar INTEGER, bar2 INTEGER)");
		Zotero_DB::query("INSERT INTO foo VALUES (1, 0)");
		$this->assertEquals(Zotero_DB::valueQuery("SELECT bar FROM foo WHERE bar2=?", 0), 1);
		Zotero_DB::query("DROP TEMPORARY TABLE foo");
	}
	
	public function testBulkInsert() {
		Zotero_DB::query("CREATE TEMPORARY TABLE foo (bar INTEGER, bar2 INTEGER)");
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
		
		Zotero_DB::query("DROP TEMPORARY TABLE foo");
	}
}


class ItemsTests extends PHPUnit_Framework_TestCase {
	public function testGetDataValuesFromXML() {
		$xml = <<<'EOD'
			<data>
				<items>
					<item libraryID="1" key="AAAAAAAA" itemType="journalArticle" dateAdded="2010-01-08 10:29:36" dateModified="2010-01-08 10:29:36">
						<field name="title">Foo</field>
						<field name="abstractNote">Bar bar bar
Bar bar</field>
						<creator libraryID="1" key="AAAAAAAA" creatorType="author" index="0">
							<creator libraryID="1" key="AAAAAAAA" dateAdded="2010-01-08 10:29:36" dateModified="2010-01-08 10:29:36">
								<firstName>Irrelevant</firstName>
								<lastName>Creator</lastName>
							</creator>
						</creator>
					</item>
					<item libraryID="1" key="BBBBBBBB" itemType="attachment" dateAdded="2010-01-08 10:31:09" dateModified="2010-01-08 10:31:17" sourceItem="VN9DPHBB" linkMode="0" mimeType="application/pdf" storageModTime="1262946676" storageHash="41125f70cc25117b0da961bd7108938 9">
						<field name="title">Test_Filename.pdf</field>
					</item>
					<item libraryID="1" key="CCCCCCCC" itemType="journalArticle" dateAdded="2010-01-08 10:34:03" dateModified="2010-01-08 10:34:03">
						<field name="title">Tést 汉字漢字</field>
						<field name="volume">38</field>
						<field name="pages">546-553</field>
						<field name="date">1990-06-00 May - Jun., 1990</field>
					</item>
				</items>
			</data>
EOD;
		$xml = new SimpleXMLElement($xml);
		$domSXE = dom_import_simplexml($xml->items);
		$doc = new DOMDocument();
		$domSXE = $doc->importNode($domSXE, true);
		$domSXE = $doc->appendChild($domSXE);
		
		$values = Zotero_Items::getDataValuesFromXML($doc);
		sort($values);
		$this->assertEquals(sizeOf($values), 7);
		$this->assertEquals($values[0], "1990-06-00 May - Jun., 1990");
		$this->assertEquals($values[1], "38");
		$this->assertEquals($values[2], "546-553");
		$this->assertEquals($values[3], "Bar bar bar\nBar bar");
		$this->assertEquals($values[4], "Foo");
		$this->assertEquals($values[5], "Test_Filename.pdf");
		$this->assertEquals($values[6], "Tést 汉字漢字");
	}
	
	
	public function testGetLongDataValueFromXML() {
		$longStr = str_pad("", 65534, "-") . "\nFoobar";
		$xml = <<<EOD
			<data>
				<items>
					<item libraryID="1" key="BBBBBBBB" itemType="attachment" dateAdded="2010-01-08 10:31:09" dateModified="2010-01-08 10:31:17" sourceItem="VN9DPHBB" linkMode="0" mimeType="application/pdf" storageModTime="1262946676" storageHash="41125f70cc25117b0da961bd7108938 9">
						<field name="title">Test_Filename.pdf</field>
					</item>
					<item libraryID="1" key="AAAAAAAA" itemType="journalArticle" dateAdded="2010-01-08 10:29:36" dateModified="2010-01-08 10:29:36">
						<field name="title">Foo</field>
						<field name="abstractNote">$longStr</field>
						<creator libraryID="1" key="AAAAAAAA" creatorType="author" index="0">
							<creator libraryID="1" key="AAAAAAAA" dateAdded="2010-01-08 10:29:36" dateModified="2010-01-08 10:29:36">
								<firstName>Irrelevant</firstName>
								<lastName>Creator</lastName>
							</creator>
						</creator>
					</item>
				</items>
			</data>
EOD;
		$doc = new DOMDocument();
		$doc->loadXML($xml);
		$node = Zotero_Items::getLongDataValueFromXML($doc);
		$this->assertEquals("abstractNote", $node->getAttribute('name'));
		$this->assertEquals($longStr, $node->nodeValue);
	}
}


class MemcacheTests extends PHPUnit_Framework_TestCase {
	public function testQueue() {
		Z_Core::$MC->delete("testFoo");
		Z_Core::$MC->delete("testFoo2");
		
		Z_Core::$MC->begin();
		Z_Core::$MC->set("testFoo", "bar");
		Z_Core::$MC->add("testFoo2", "bar2");
		
		// For now, this should return true, since local caching is used throughout code
		//
		// Eventually, gets within a transaction should return the queued value
		$this->assertEquals(Z_Core::$MC->get("testFoo"), false);
		$this->assertEquals(Z_Core::$MC->get("testFoo2"), false);
		
		Z_Core::$MC->commit();
		
		$this->assertEquals(Z_Core::$MC->get("testFoo"), "bar");
		$this->assertEquals(Z_Core::$MC->get("testFoo2"), "bar2");
	}
	
	public function testUnicode() {
		$str1 = "Øüévrê";
		$str2 = "汉字漢字";
		
		Z_Core::$MC->delete("testUnicode1");
		Z_Core::$MC->set("testUnicode1", $str1 . $str2);
		$this->assertEquals(Z_Core::$MC->get("testUnicode1"), $str1 . $str2);
		
		Z_Core::$MC->delete("testUnicode2");
		$arr = array('foo1' => $str1, 'foo2' => $str2);
		Z_Core::$MC->set("testUnicode2", $arr);
		$this->assertEquals(Z_Core::$MC->get("testUnicode2"), $arr);
	}
}


class TagsTests extends PHPUnit_Framework_TestCase {
	public function testGetDataValuesFromXML() {
		$xml = <<<'EOD'
			<data>
				<creators><creator/></creators>
				<tags>
					<tag libraryID="1" key="AAAAAAAA" name="Animal" dateAdded="2009-04-13 12:22:31" dateModified="2009-08-06 10:21:20">
						<items>AAAAAAAA</items>
					</tag>
					<tag libraryID="1" key="BBBBBBBB" name="Vegetable" dateAdded="2009-04-13 12:22:31" dateModified="2009-08-06 10:21:20"/>
					<tag libraryID="1" key="CCCCCCCC" name="Mineral" dateAdded="2009-04-13 12:22:31" dateModified="2009-08-06 10:21:20"/>
					<tag libraryID="1" key="DDDDDDDD" name="mineral" dateAdded="2009-04-13 12:22:31" dateModified="2009-08-06 10:21:20"/>
					<tag libraryID="1" key="EEEEEEEE" name="Minéral" dateAdded="2009-04-13 12:22:31" dateModified="2009-08-06 10:21:20"/>
					<tag libraryID="2" key="FFFFFFFF" name="Animal" dateAdded="2009-04-13 12:22:31" dateModified="2009-08-06 10:21:20"/>
				</tags>
			</data>
EOD;
		$xml = new SimpleXMLElement($xml);
		$domSXE = dom_import_simplexml($xml->tags);
		$doc = new DOMDocument();
		$domSXE = $doc->importNode($domSXE, true);
		$domSXE = $doc->appendChild($domSXE);
		
		$values = Zotero_Tags::getDataValuesFromXML($doc);
		sort($values);
		$this->assertEquals(5, sizeOf($values));
		$this->assertEquals("Animal", $values[0]);
		$this->assertEquals("Mineral", $values[1]);
		$this->assertEquals("Minéral", $values[2]);
		$this->assertEquals("Vegetable", $values[3]);
		$this->assertEquals("mineral", $values[4]);
	}
	
	
	public function testGetLongDataValueFromXML() {
		$longTag = "Longlonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglonglong";
		$xml = <<<EOD
			<data>
				<creators><creator/></creators>
				<tags>
					<tag libraryID="1" key="AAAAAAAA" name="Test" dateAdded="2009-04-13 12:22:31" dateModified="2009-08-06 10:21:20">
						<items>AAAAAAAA</items>
					</tag>
					<tag libraryID="1" key="BBBBBBBB" name="$longTag" dateAdded="2009-04-13 12:22:31" dateModified="2009-08-06 10:21:20"/>
				</tags>
			</data>
EOD;
		$doc = new DOMDocument();
		$doc->loadXML($xml);
		$tag = Zotero_Tags::getLongDataValueFromXML($doc);
		$this->assertEquals($longTag, $tag);
	}
}



class UsersTests extends PHPUnit_Framework_TestCase {
	public function testExists() {
		$this->assertEquals(Zotero_Users::exists(1), true);
		$this->assertEquals(Zotero_Users::exists(100), false);
	}
	
	public function testAuthenticate() {
		$this->assertEquals(Zotero_Users::authenticate('password', array('username'=>'testuser', 'password'=>'letmein')), 1);
		$this->assertEquals(Zotero_Users::authenticate('password', array('username'=>'testuser', 'password'=>'letmein2')), false);
	}
}
?>
