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

class TagsTests extends PHPUnit_Framework_TestCase {
	/*public function testGetDataValuesFromXML() {
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
	}*/
	
	
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
