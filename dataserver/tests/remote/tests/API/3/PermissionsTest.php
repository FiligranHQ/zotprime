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

namespace APIv3;
use API3 as API;
require_once 'APITests.inc.php';
require_once 'include/api3.inc.php';

class PermissionsTest extends APITests {
	private static $publicGroupID;
	
	public function setUp() {
		parent::setUp();
		API::resetKey(self::$config['apiKey']);
		API::setKeyUserPermission(self::$config['apiKey'], 'library', true);
		API::setKeyUserPermission(self::$config['apiKey'], 'write', true);
		API::setKeyGroupPermission(self::$config['apiKey'], 0, 'write', true);
	}
	
	
	public function testUserGroupsAnonymousJSON() {
		API::useAPIKey(false);
		$response = API::get("users/" . self::$config['userID'] . "/groups");
		$this->assert200($response);
		
		$this->assertTotalResults(self::$config['numPublicGroups'], $response);
		
		// Make sure they're the right groups
		$json = API::getJSONFromResponse($response);
		$groupIDs = array_map(function ($data) {
			return $data['id'];
		}, $json);
		$this->assertContains(self::$config['ownedPublicGroupID'], $groupIDs);
		$this->assertContains(self::$config['ownedPublicNoAnonymousGroupID'], $groupIDs);
	}
	
	
	public function testUserGroupsAnonymousAtom() {
		API::useAPIKey(false);
		$response = API::get("users/" . self::$config['userID'] . "/groups?content=json");
		$this->assert200($response);
		
		$this->assertTotalResults(self::$config['numPublicGroups'], $response);
		
		// Make sure they're the right groups
		$xml = API::getXMLFromResponse($response);
		$groupIDs = array_map(function ($id) {
			return (int) $id;
		}, $xml->xpath('//atom:entry/zapi:groupID'));
		$this->assertContains(self::$config['ownedPublicGroupID'], $groupIDs);
		$this->assertContains(self::$config['ownedPublicNoAnonymousGroupID'], $groupIDs);
	}
	
	
	public function testUserGroupsOwned() {
		API::useAPIKey(self::$config['apiKey']);
		$response = API::userGet(self::$config['userID'], "groups");
		$this->assert200($response);
		
		$this->assertNumResults(self::$config['numOwnedGroups'], $response);
		$this->assertTotalResults(self::$config['numOwnedGroups'], $response);
	}
	
	
	public function test_should_see_private_group_listed_when_using_key_with_library_read_access() {
		API::resetKey(self::$config['apiKey']);
		$response = API::userGet(self::$config['userID'], "groups");
		$this->assert200($response);
		
		$this->assertNumResults(self::$config['numPublicGroups'], $response);
		
		// Grant key read permission to library
		API::setKeyGroupPermission(
			self::$config['apiKey'],
			self::$config['ownedPrivateGroupID'],
			'library',
			true
		);
		
		$response = API::userGet(self::$config['userID'], "groups");
		$this->assertNumResults(self::$config['numOwnedGroups'], $response);
		$this->assertTotalResults(self::$config['numOwnedGroups'], $response);
		
		$json = API::getJSONFromResponse($response);
		$groupIDs = array_map(function ($data) {
			return $data['id'];
		}, $json);
		$this->assertContains(self::$config['ownedPrivateGroupID'], $groupIDs);
	}
	
	
	public function testGroupLibraryReading() {
		$groupID = self::$config['ownedPublicNoAnonymousGroupID'];
		API::groupClear($groupID);
		
		$json = API::groupCreateItem(
			$groupID,
			'book',
			[
				'title' => "Test"
			],
			$this
		);
		
		try {
			API::useAPIKey(self::$config['apiKey']);
			$response = API::groupGet($groupID, "items");
			$this->assert200($response);
			$this->assertNumResults(1, $response);
			
			// An anonymous request should fail, because libraryReading is members
			API::useAPIKey(false);
			$response = API::groupGet($groupID, "items");
			$this->assert403($response);
		}
		finally {
			API::groupClear($groupID);
		}
	}
	
	
	public function test_shouldnt_be_able_to_write_to_group_using_key_with_library_read_access() {
		API::resetKey(self::$config['apiKey']);
		
		// Grant key read (not write) permission to library
		API::setKeyGroupPermission(
			self::$config['apiKey'],
			self::$config['ownedPrivateGroupID'],
			'library',
			true
		);
		
		$response = API::get("items/new?itemType=book");
		$json = json_decode($response->getBody(), true);
		
		$response = API::groupPost(
			self::$config['ownedPrivateGroupID'],
			"items",
			json_encode([
				"items" => [$json]
			]),
			["Content-Type: application/json"]
		);
		$this->assert403($response);
	}
	
	
	/**
	 * A key without note access shouldn't be able to create a note
	 */
	/*public function testKeyNoteAccessWriteError() {
		API::setKeyUserPermission(self::$config['apiKey'], 'notes', false);
		
		$response = API::get("items/new?itemType=note");
		$json = json_decode($response->getBody());
		$json->note = "Test";
		
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert403($response);
	}*/
	
	
	public function testKeyNoteAccess() {
		API::userClear(self::$config['userID']);
		
		API::setKeyUserPermission(self::$config['apiKey'], 'notes', true);
		
		$keys = array();
		$topLevelKeys = array();
		$bookKeys = array();
		
		$key = API::createItem('book', array("title" => "A"), $this, 'key');
		$keys[] = $key;
		$topKeys[] = $key;
		$bookKeys[] = $key;
		
		$key = API::createNoteItem("B", false, $this, 'key');
		$keys[] = $key;
		$topKeys[] = $key;
		
		$key = API::createNoteItem("C", false, $this, 'key');
		$keys[] = $key;
		$topKeys[] = $key;
		
		$key = API::createNoteItem("D", false, $this, 'key');
		$keys[] = $key;
		$topKeys[] = $key;
		
		$key = API::createNoteItem("E", false, $this, 'key');
		$keys[] = $key;
		$topKeys[] = $key;
		
		$key = API::createItem('book', array("title" => "F"), $this, 'key');
		$keys[] = $key;
		$topKeys[] = $key;
		$bookKeys[] = $key;
		
		$key = API::createNoteItem("G", $key, $this, 'key');
		$keys[] = $key;
		
		// Create collection and add items to it
		$response = API::userPost(
			self::$config['userID'],
			"collections",
			json_encode([
				[
					"name" => "Test",
					"parentCollection" => false
				]
			]),
			array("Content-Type: application/json")
		);
		$this->assert200ForObject($response);
		$collectionKey = API::getFirstSuccessKeyFromResponse($response);
		
		$response = API::userPost(
			self::$config['userID'],
			"collections/$collectionKey/items",
			implode(" ", $topKeys)
		);
		$this->assert204($response);
		
		//
		// format=atom
		//
		// Root
		$response = API::userGet(
			self::$config['userID'], "items"
		);
		$this->assertNumResults(sizeOf($keys), $response);
		$this->assertTotalResults(sizeOf($keys), $response);
		
		// Top
		$response = API::userGet(
			self::$config['userID'], "items/top"
		);
		$this->assertNumResults(sizeOf($topKeys), $response);
		$this->assertTotalResults(sizeOf($topKeys), $response);
		
		// Collection
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top"
		);
		$this->assertNumResults(sizeOf($topKeys), $response);
		$this->assertTotalResults(sizeOf($topKeys), $response);
		
		//
		// format=keys
		//
		// Root
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys"
		);
		$this->assert200($response);
		$this->assertCount(sizeOf($keys), explode("\n", trim($response->getBody())));
		
		// Top
		$response = API::userGet(
			self::$config['userID'],
			"items/top?format=keys"
		);
		$this->assert200($response);
		$this->assertCount(sizeOf($topKeys), explode("\n", trim($response->getBody())));
		
		// Collection
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top?format=keys"
		);
		$this->assert200($response);
		$this->assertCount(sizeOf($topKeys), explode("\n", trim($response->getBody())));
		
		// Remove notes privilege from key
		API::setKeyUserPermission(self::$config['apiKey'], 'notes', false);
		
		//
		// format=json
		//
		// totalResults with limit
		$response = API::userGet(
			self::$config['userID'],
			"items?limit=1"
		);
		$this->assertNumResults(1, $response);
		$this->assertTotalResults(sizeOf($bookKeys), $response);
		
		// And without limit
		$response = API::userGet(
			self::$config['userID'],
			"items"
		);
		$this->assertNumResults(sizeOf($bookKeys), $response);
		$this->assertTotalResults(sizeOf($bookKeys), $response);
		
		// Top
		$response = API::userGet(
			self::$config['userID'],
			"items/top"
		);
		$this->assertNumResults(sizeOf($bookKeys), $response);
		$this->assertTotalResults(sizeOf($bookKeys), $response);
		
		// Collection
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items"
		);
		$this->assertNumResults(sizeOf($bookKeys), $response);
		$this->assertTotalResults(sizeOf($bookKeys), $response);
		
		//
		// format=atom
		//
		// totalResults with limit
		$response = API::userGet(
			self::$config['userID'],
			"items?format=atom&limit=1"
		);
		$this->assertNumResults(1, $response);
		$this->assertTotalResults(sizeOf($bookKeys), $response);
		
		// And without limit
		$response = API::userGet(
			self::$config['userID'],
			"items?format=atom"
		);
		$this->assertNumResults(sizeOf($bookKeys), $response);
		$this->assertTotalResults(sizeOf($bookKeys), $response);
		
		// Top
		$response = API::userGet(
			self::$config['userID'],
			"items/top?format=atom"
		);
		$this->assertNumResults(sizeOf($bookKeys), $response);
		$this->assertTotalResults(sizeOf($bookKeys), $response);
		
		// Collection
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items?format=atom"
		);
		$this->assertNumResults(sizeOf($bookKeys), $response);
		$this->assertTotalResults(sizeOf($bookKeys), $response);
		
		//
		// format=keys
		//
		$response = API::userGet(
			self::$config['userID'],
			"items?format=keys"
		);
		$keys = explode("\n", trim($response->getBody()));
		sort($keys);
		$this->assertEmpty(
			array_merge(
				array_diff($bookKeys, $keys), array_diff($keys, $bookKeys)
			)
		);
	}
	
	
	public function testTagDeletePermissions() {
		API::userClear(self::$config['userID']);
		
		API::createItem('book', array(
			"tags" => array(
				array(
					"tag" => "A"
				)
			)
		), $this);
		
		$libraryVersion = API::getLibraryVersion();
		
		API::setKeyUserPermission(self::$config['apiKey'], 'write', false);
		
		$response = API::userDelete(
			self::$config['userID'],
			"tags?tag=A&key=" . self::$config['apiKey']
		);
		$this->assert403($response);
		
		API::setKeyUserPermission(self::$config['apiKey'], 'write', true);
		
		$response = API::userDelete(
			self::$config['userID'],
			"tags?tag=A&key=" . self::$config['apiKey'],
			array("If-Unmodified-Since-Version: $libraryVersion")
		);
		$this->assert204($response);
	}
}
