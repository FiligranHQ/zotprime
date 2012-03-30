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
