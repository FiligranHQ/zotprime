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
		$this->assertTotalResults(1, $response);
		
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
		$this->assertTotalResults(2, $response);
	}
	
	
	/**
	 * A key without note access shouldn't be able to create a note
	 */
	/*public function testKeyNoteAccessWriteError() {
		API::setKeyOption(
			self::$config['userID'], self::$config['apiKey'], 'libraryNotes', 0
		);
		
		$response = API::get("items/new?itemType=note");
		$json = json_decode($response->getBody());
		$json->note = "Test";
		
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert403($response);
	}*/
	
	
	public function testKeyNoteAccess() {
		API::userClear(self::$config['userID']);
		
		API::setKeyOption(
			self::$config['userID'], self::$config['apiKey'], 'libraryNotes', 1
		);
		
		$keys = array();
		$topLevelKeys = array();
		$bookKeys = array();
		
		$xml = API::createItem('book', array("title" => "A"), $this);
		$data = API::parseDataFromAtomEntry($xml);
		$keys[] = $data['key'];
		$topKeys[] = $data['key'];
		$bookKeys[] = $data['key'];
		
		$xml = API::createNoteItem("B", false, $this);
		$data = API::parseDataFromAtomEntry($xml);
		$keys[] = $data['key'];
		$topKeys[] = $data['key'];
		
		$xml = API::createNoteItem("C", false, $this);
		$data = API::parseDataFromAtomEntry($xml);
		$keys[] = $data['key'];
		$topKeys[] = $data['key'];
		
		$xml = API::createNoteItem("D", false, $this);
		$data = API::parseDataFromAtomEntry($xml);
		$keys[] = $data['key'];
		$topKeys[] = $data['key'];
		
		$xml = API::createNoteItem("E", false, $this);
		$data = API::parseDataFromAtomEntry($xml);
		$keys[] = $data['key'];
		$topKeys[] = $data['key'];
		
		$xml = API::createItem('book', array("title" => "F"), $this);
		$data = API::parseDataFromAtomEntry($xml);
		$keys[] = $data['key'];
		$topKeys[] = $data['key'];
		$bookKeys[] = $data['key'];
		
		$xml = API::createNoteItem("G", $data['key'], $this);
		$data = API::parseDataFromAtomEntry($xml);
		$keys[] = $data['key'];
		
		// Create collection and add items to it
		$response = API::userPost(
			self::$config['userID'],
			"collections?key=" . self::$config['apiKey'],
			json_encode(array(
				"name" => "Test",
				"parent" => false
			)),
			array("Content-Type: application/json")
		);
		// TEMP: should be 201
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$collectionKey = $data['key'];
		
		$response = API::userPost(
			self::$config['userID'],
			"collections/$collectionKey/items?key=" . self::$config['apiKey'],
			implode(" ", $topKeys)
		);
		$this->assert204($response);
		
		//
		// format=atom
		//
		// Root
		$response = API::userGet(
			self::$config['userID'], "items?key=" . self::$config['apiKey']
		);
		$this->assertNumResults(sizeOf($keys), $response);
		$this->assertTotalResults(sizeOf($keys), $response);
		
		// Top
		$response = API::userGet(
			self::$config['userID'], "items/top?key=" . self::$config['apiKey']
		);
		$this->assertNumResults(sizeOf($topKeys), $response);
		$this->assertTotalResults(sizeOf($topKeys), $response);
		
		// Collection
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items?key=" . self::$config['apiKey']
		);
		$this->assertNumResults(sizeOf($topKeys), $response);
		$this->assertTotalResults(sizeOf($topKeys), $response);
		
		//
		// format=keys
		//
		// Root
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'] . "&format=keys"
		);
		$this->assertCount(sizeOf($keys), explode("\n", trim($response->getBody())));
		
		// Top
		$response = API::userGet(
			self::$config['userID'],
			"items/top?key=" . self::$config['apiKey'] . "&format=keys"
		);
		$this->assertCount(sizeOf($topKeys), explode("\n", trim($response->getBody())));
		
		// Collection
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items?key=" . self::$config['apiKey'] . "&format=keys"
		);
		$this->assertCount(sizeOf($topKeys), explode("\n", trim($response->getBody())));
		
		// Remove notes privilege from key
		API::setKeyOption(
			self::$config['userID'], self::$config['apiKey'], 'libraryNotes', 0
		);
		
		//
		// format=atom
		//
		// totalResults with limit
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'] . "&limit=1"
		);
		$this->assertNumResults(1, $response);
		$this->assertTotalResults(sizeOf($bookKeys), $response);
		
		// And without limit
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey']
		);
		$this->assertNumResults(sizeOf($bookKeys), $response);
		$this->assertTotalResults(sizeOf($bookKeys), $response);
		
		// Top
		$response = API::userGet(
			self::$config['userID'],
			"items/top?key=" . self::$config['apiKey']
		);
		$this->assertNumResults(sizeOf($bookKeys), $response);
		$this->assertTotalResults(sizeOf($bookKeys), $response);
		
		// Collection
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items?key=" . self::$config['apiKey']
		);
		$this->assertNumResults(sizeOf($bookKeys), $response);
		$this->assertTotalResults(sizeOf($bookKeys), $response);
		
		//
		// format=keys
		//
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'] . "&format=keys"
		);
		$keys = explode("\n", trim($response->getBody()));
		sort($keys);
		$this->assertEmpty(
			array_merge(
				array_diff($bookKeys, $keys), array_diff($keys, $bookKeys)
			)
		);
	}
}
