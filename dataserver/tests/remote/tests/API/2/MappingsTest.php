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

namespace APIv2;
use API2 as API;
require_once 'APITests.inc.php';
require_once 'include/api2.inc.php';

class MappingsTests extends APITests {
	public function testNewItem() {
		$response = API::get("items/new?itemType=invalidItemType");
		$this->assert400($response);
		
		$response = API::get("items/new?itemType=book");
		$this->assert200($response);
		$this->assertContentType('application/json', $response);
		$json = json_decode($response->getBody());
		$this->assertEquals('book', $json->itemType);
	}
	
	
	public function testNewItemAttachment() {
		$response = API::get("items/new?itemType=attachment");
		$this->assert400($response);
		
		$response = API::get("items/new?itemType=attachment&linkMode=invalidLinkMode");
		$this->assert400($response);
		
		$response = API::get("items/new?itemType=attachment&linkMode=linked_url");
		$this->assert200($response);
		$json = json_decode($response->getBody());
		$this->assertNotNull($json);
		$this->assertObjectHasAttribute('url', $json);
		
		$response = API::get("items/new?itemType=attachment&linkMode=linked_file");
		$this->assert200($response);
		$json = json_decode($response->getBody());
		$this->assertNotNull($json);
		$this->assertObjectNotHasAttribute('url', $json);
	}
	
	
	public function testComputerProgramVersion() {
		$response = API::get("items/new?itemType=computerProgram");
		$this->assert200($response);
		$json = json_decode($response->getBody());
		$this->assertObjectHasAttribute('version', $json);
		$this->assertObjectNotHasAttribute('versionNumber', $json);
		
		$response = API::get("itemTypeFields?itemType=computerProgram");
		$this->assert200($response);
		$json = json_decode($response->getBody());
		$fields = array_map(function ($val) {
			return $val->field;
		}, $json);
		$this->assertContains('version', $fields);
		$this->assertNotContains('versionNumber', $fields);
	}
}
?>