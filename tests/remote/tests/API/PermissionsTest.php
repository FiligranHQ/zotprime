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

class PermissionsTest extends APITests {
	public function testUserGroupsAnonymous() {
		$response = API::get("users/" . self::$config['userID'] . "/groups?content=json");
		$this->assert200($response);
		
		// There should be only one public group
		$this->assertNumResults(1, $response);
		
		// Make sure it's the right group
		$xml = API::getXMLFromResponse($response);
		$groupID = (int) array_shift($xml->xpath('//atom:entry/zapi:groupID'));
		$this->assertEquals(self::$config['ownedPublicGroupID'], $groupID);
	}
	
	
	public function testUserGroupsOwned() {
		$response = API::get(
			"users/" . self::$config['userID'] . "/groups?content=json"
			. "&key=" . self::$config['apiKey']
		);
		$this->assert200($response);
		
		$this->assertNumResults(2, $response);
	}
}
