<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2015 Zotero
                     https://www.zotero.org
    
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

class NotificationsTests extends APITests {
	public function testNewItemNotification() {
		$response = API::createItem("book", false, $this, 'response');
		$this->assertCountNotifications(1, $response);
		$this->assertHasNotification([
			'event' => 'topicUpdated',
			'topic' => '/users/' . self::$config['userID']
		], $response);
	}
	
	
	public function testModifyItemNotification() {
		$json = API::createItem("book", false, $this, 'jsonData');
		$json['title'] = 'test';
		$response = API::userPut(
			self::$config['userID'],
			"items/{$json['key']}",
			json_encode($json)
		);
		$this->assertCountNotifications(1, $response);
		$this->assertHasNotification([
			'event' => 'topicUpdated',
			'topic' => '/users/' . self::$config['userID']
		], $response);
	}
	
	
	public function testDeleteItemNotification() {
		$json = API::createItem("book", false, $this, 'json');
		$response = API::userDelete(
			self::$config['userID'],
			"items/{$json['key']}",
			[
				"If-Unmodified-Since-Version: {$json['version']}"
			]
		);
		$this->assertCountNotifications(1, $response);
		$this->assertHasNotification([
			'event' => 'topicUpdated',
			'topic' => '/users/' . self::$config['userID']
		], $response);
	}
	
	
	public function testKeyCreateNotification() {
		API::useAPIKey("");
		
		$name = "Test " . uniqid();
		$response = API::superPost(
			'users/' . self::$config['userID'] . '/keys',
			json_encode([
				'name' => $name,
				'access' => [
					'user' => [
						'library' => true
					]
				]
			])
		);
		try {
			// No notification when creating a new key
			$this->assertCountNotifications(0, $response);
		}
		// Clean up
		finally {
			$json = API::getJSONFromResponse($response);
			$key = $json['key'];
			$response = API::userDelete(
				self::$config['userID'],
				"keys/$key"
			);
		}
	}
	
	
	/**
	 * Grant an API key access to a group
	 */
	public function testKeyAddLibraryNotification() {
		API::useAPIKey("");
		
		$name = "Test " . uniqid();
		$json = [
			'name' => $name,
			'access' => [
				'user' => [
					'library' => true
				]
			]
		];
		
		$response = API::superPost(
			'users/' . self::$config['userID'] . '/keys',
			json_encode($json)
		);
		$this->assert201($response);
		try {
			$json = API::getJSONFromResponse($response);
			$apiKey = $json['key'];
			
			// Add a group to the key, which should trigger topicAdded
			$json['access']['groups'][self::$config['ownedPrivateGroupID']] = [
				'library' => true,
				'write' => true
			];
			$response = API::superPut(
				"keys/$apiKey",
				json_encode($json)
			);
			$this->assert200($response);
			
			$this->assertCountNotifications(1, $response);
			$this->assertHasNotification([
				'event' => 'topicAdded',
				'apiKey' => $apiKey,
				'topic' => '/groups/' . self::$config['ownedPrivateGroupID']
			], $response);
		}
		// Clean up
		finally {
			$response = API::superDelete("keys/$apiKey");
		}
	}
	
	
	/**
	 * Make a group public, which should trigger topicAdded
	 */
	
	/**
	 * Show public groups in topic list for single-key requests
	 */
	
	/**
	 * Revoke access for a group from an API key
	 */
	public function testKeyRemoveLibraryNotification() {
		API::useAPIKey("");
		
		$json = $this->createKey(self::$config['userID'], [
			'user' => [
				'library' => true
			],
			'groups' => [
				self::$config['ownedPrivateGroupID'] => [
					'library' => true
				]
			]
		]);
		$apiKey = $json['key'];
		
		try {
			// Remove group from the key, which should trigger topicRemoved
			unset($json['access']['groups']);
			$response = API::superPut(
				"keys/$apiKey",
				json_encode($json)
			);
			$this->assert200($response);
			
			$this->assertCountNotifications(1, $response);
			$this->assertHasNotification([
				'event' => 'topicRemoved',
				'apiKey' => $apiKey,
				'topic' => '/groups/' . self::$config['ownedPrivateGroupID']
			], $response);
		}
		// Clean up
		finally {
			$response = API::superDelete("keys/$apiKey");
		}
	}
	
	
	/**
	 * Grant access to all groups to an API key that has access to a single group
	 */
	public function testKeyAddAllGroupsToNoneNotification() {
		API::useAPIKey("");
		
		$json = $this->createKey(self::$config['userID'], [
			'user' => [
				'library' => true
			]
		]);
		$apiKey = $json['key'];
		
		try {
			// Get list of available groups
			$response = API::superGet('users/' . self::$config['userID'] . '/groups');
			$groupIDs = array_map(function ($group) {
				return $group['id'];
			}, API::getJSONFromResponse($response));
			
			// Add all groups to the key, which should trigger topicAdded for each groups
			$json['access']['groups'][0] = [
				'library' => true
			];
			$response = API::superPut(
				"keys/$apiKey",
				json_encode($json)
			);
			$this->assert200($response);
			
			$this->assertCountNotifications(sizeOf($groupIDs), $response);
			foreach ($groupIDs as $groupID) {
				$this->assertHasNotification([
					'event' => 'topicAdded',
					'apiKey' => $apiKey,
					'topic' => '/groups/' . $groupID
				], $response);
			}
		}
		// Clean up
		finally {
			$response = API::superDelete("keys/$apiKey");
		}
	}
	
	
	/**
	 * Grant access to all groups to an API key that has access to a single group
	 */
	public function testKeyAddAllGroupsToOneNotification() {
		API::useAPIKey("");
		
		$json = $this->createKey(self::$config['userID'], [
			'user' => [
				'library' => true
			],
			'groups' => [
				self::$config['ownedPrivateGroupID'] => [
					'library' => true
				]
			]
		]);
		$apiKey = $json['key'];
		
		try {
			// Get list of available groups
			$response = API::superGet('users/' . self::$config['userID'] . '/groups');
			$groupIDs = array_map(function ($group) {
				return $group['id'];
			}, API::getJSONFromResponse($response));
			// Remove group that already had access
			$groupIDs = array_diff($groupIDs, [self::$config['ownedPrivateGroupID']]);
			
			// Add all groups to the key, which should trigger topicAdded for each new group
			// but not the group that was previously accessible
			unset($json['access']['groups'][self::$config['ownedPrivateGroupID']]);
			$json['access']['groups']['all'] = [
				'library' => true
			];
			$response = API::superPut(
				"keys/$apiKey",
				json_encode($json)
			);
			$this->assert200($response);
			
			$this->assertCountNotifications(sizeOf($groupIDs), $response);
			foreach ($groupIDs as $groupID) {
				$this->assertHasNotification([
					'event' => 'topicAdded',
					'apiKey' => $apiKey,
					'topic' => '/groups/' . $groupID
				], $response);
			}
		}
		// Clean up
		finally {
			$response = API::superDelete("keys/$apiKey");
		}
	}
	
	
	/**
	 * Revoke access for a group from an API key that has access to all groups
	 */
	public function testKeyRemoveLibraryFromAllGroupsNotification() {
		API::useAPIKey("");
		
		$removedGroup = self::$config['ownedPrivateGroupID'];
		
		$json = $this->createKeyWithAllGroupAccess(self::$config['userID']);
		$apiKey = $json['key'];
		
		try {
			// Get list of available groups
			API::useAPIKey($apiKey);
			$response = API::userGet(
				self::$config['userID'],
				'groups'
			);
			$groupIDs = array_map(function ($group) {
				return $group['id'];
			}, API::getJSONFromResponse($response));
			
			// Remove one group, and replace access array with new set
			$groupIDs = array_diff($groupIDs, [$removedGroup]);
			unset($json['access']['groups']['all']);
			foreach ($groupIDs as $groupID) {
				$json['access']['groups'][$groupID]['library'] = true;
			}
			
			// Post new JSON, which should trigger topicRemoved for the removed group
			API::useAPIKey("");
			$response = API::superPut(
				"keys/$apiKey",
				json_encode($json)
			);
			$this->assert200($response);
			
			$this->assertCountNotifications(1, $response);
			foreach ($groupIDs as $groupID) {
				$this->assertHasNotification([
					'event' => 'topicRemoved',
					'apiKey' => $apiKey,
					'topic' => '/groups/' . $removedGroup
				], $response);
			}
		}
		// Clean up
		finally {
			$response = API::superDelete("keys/$apiKey");
		}
	}
	
	
	/**
	 * Create and delete group owned by user
	 */
	public function testAddDeleteOwnedGroupNotification() {
		API::useAPIKey("");
		
		$json = $this->createKeyWithAllGroupAccess(self::$config['userID']);
		$apiKey = $json['key'];
		
		try {
			$allGroupsKeys = $this->getKeysWithAllGroupAccess(self::$config['userID']);
			
			// Create new group owned by user
			$response = $this->createGroup(self::$config['userID']);
			$xml = API::getXMLFromResponse($response);
			$groupID = (int) $xml->xpath("/atom:entry/zapi:groupID")[0];
			
			try {
				$this->assertCountNotifications(sizeOf($allGroupsKeys), $response);
				foreach ($allGroupsKeys as $key) {
					$this->assertHasNotification([
						'event' => 'topicAdded',
						'apiKey' => $key,
						'topic' => '/groups/' . $groupID
					], $response);
				}
			}
			// Delete group
			finally {
				$response = API::superDelete("groups/$groupID");
				$this->assert204($response);
				$this->assertCountNotifications(1, $response);
				$this->assertHasNotification([
					'event' => 'topicDeleted',
					'topic' => '/groups/' . $groupID
				], $response);
			}
		}
		// Delete key
		finally {
			$response = API::superDelete("keys/$apiKey");
			try {
				$this->assert204($response);
			}
			catch (Exception $e) {
				var_dump($e);
			}
		}
	}
	
	
	public function testAddRemoveGroupMemberNotification() {
		API::useAPIKey("");
		
		$json = $this->createKeyWithAllGroupAccess(self::$config['userID']);
		$apiKey = $json['key'];
		
		try {
			// Get all keys with access to all groups
			$allGroupsKeys = $this->getKeysWithAllGroupAccess(self::$config['userID']);
			
			// Create group owned by another user
			$response = $this->createGroup(self::$config['userID2']);
			$xml = API::getXMLFromResponse($response);
			$groupID = (int) $xml->xpath("/atom:entry/zapi:groupID")[0];
			
			try {
				// Add user to group
				$response = API::superPost(
					"groups/$groupID/users",
					'<user id="' . self::$config['userID']. '" role="member"/>'
				);
				$this->assert200($response);
				$this->assertCountNotifications(sizeOf($allGroupsKeys), $response);
				foreach ($allGroupsKeys as $key) {
					$this->assertHasNotification([
						'event' => 'topicAdded',
						'apiKey' => $key,
						'topic' => '/groups/' . $groupID
					], $response);
				}
				
				// Remove user from group
				$response = API::superDelete("groups/$groupID/users/" . self::$config['userID']);
				$this->assert204($response);
				$this->assertCountNotifications(sizeOf($allGroupsKeys), $response);
				foreach ($allGroupsKeys as $key) {
					$this->assertHasNotification([
						'event' => 'topicRemoved',
						'apiKey' => $key,
						'topic' => '/groups/' . $groupID
					], $response);
				}
			}
			// Delete group
			finally {
				$response = API::superDelete("groups/$groupID");
				$this->assert204($response);
				$this->assertCountNotifications(1, $response);
				$this->assertHasNotification([
					'event' => 'topicDeleted',
					'topic' => '/groups/' . $groupID
				], $response);
			}
		}
		// Delete key
		finally {
			$response = API::superDelete("keys/$apiKey");
			try {
				$this->assert204($response);
			}
			catch (Exception $e) {
				var_dump($e);
			}
		}
	}
	
	
	//
	// Private functions
	//
	private function createKey($userID, $access) {
		$name = "Test " . uniqid();
		$json = [
			'name' => $name,
			'access' => $access
		];
		$response = API::superPost(
			"users/$userID/keys",
			json_encode($json)
		);
		$this->assert201($response);
		$json = API::getJSONFromResponse($response);
		return $json;
	}
	
	private function createKeyWithAllGroupAccess($userID) {
		return $this->createKey($userID, [
			'user' => [
				'library' => true
			],
			'groups' => [
				'all' => [
					'library' => true
				]
			]
		]);
	}
	
	private function createGroup($ownerID) {
		// Create new group owned by another
		$xml = new \SimpleXMLElement('<group/>');
		$xml['owner'] = $ownerID;
		$xml['name'] = 'Test';
		$xml['type'] = 'Private';
		$xml['libraryEditing'] = 'admins';
		$xml['libraryReading'] = 'members';
		$xml['fileEditing'] = 'admins';
		$xml['description'] = 'This is a description';
		$xml['url'] = '';
		$xml['hasImage'] = 0;
		$xml = $xml->asXML();
		$response = API::superPost(
			'groups',
			$xml
		);
		$this->assert201($response);
		return $response;
	}
	
	private function getKeysWithAllGroupAccess($userID) {
		$response = API::superGet("users/$userID/keys");
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		return array_map(
			function ($keyObj) {
				return $keyObj['key'];
			},
			array_filter($json, function ($keyObj) {
				return !empty($keyObj['access']['groups']['all']['library']);
			})
		);
	}
}
