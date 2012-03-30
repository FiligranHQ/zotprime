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
