<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2013 Center for History and New Media
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

require_once 'include/api.inc.php';
require_once 'include/sync.inc.php';

class SyncRelationTests extends PHPUnit_Framework_TestCase {
	protected static $config;
	protected static $sessionID;
	
	public static function setUpBeforeClass() {
		require 'include/config.inc.php';
		foreach ($config as $k => $v) {
			self::$config[$k] = $v;
		}
		
		API::useFutureVersion(true);
	}
	
	
	public function setUp() {
		API::userClear(self::$config['userID']);
		API::groupClear(self::$config['ownedPrivateGroupID']);
		API::groupClear(self::$config['ownedPublicGroupID']);
		self::$sessionID = Sync::login();
	}
	
	
	public function tearDown() {
		Sync::logout(self::$sessionID);
		self::$sessionID = null;
	}
	
	
	public function testModifyRelationsViaSync() {
		$items = array();
		$items[] = array(
			"key" => API::createItem("book", false, null, 'key'),
			"relations" => array(
				"owl:sameAs" => "http://zotero.org/groups/1/items/AAAAAAAA"
			)
		);
		
		$xml = Sync::updated(self::$sessionID);
		$updateKey = $xml['updateKey'];
		$lastSyncTimestamp = $xml['timestamp'];
		
		$xmlstr = '<data version="9">'
			. '<relations>';
		foreach ($items as $item) {
			$subject = 'http://zotero.org/users/'
				. self::$config['userID'] . '/items/' . $item['key'];
			foreach ($item['relations'] as $predicate => $object) {
				$xmlstr .= '<relation libraryID="' . self::$config['libraryID'] . '">'
				. "<subject>$subject</subject>"
				. "<predicate>$predicate</predicate>"
				. "<object>$object</object>"
				. '</relation>';
			}
		}
		$xmlstr .= '</relations>'
			. '</data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $xmlstr);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Check via API
		foreach ($items as $item) {
			$response = API::userGet(
				self::$config['userID'],
				"items/{$item['key']}?key=" . self::$config['apiKey'] . "&content=json"
			);
			$content = API::getContentFromResponse($response);
			$json = json_decode($content, true);
			$this->assertCount(sizeOf($item['relations']), $json['relations']);
			foreach ($item['relations'] as $predicate => $object) {
				$this->assertEquals($object, $json['relations'][$predicate]);
			}
		}
		
		$xml = Sync::updated(self::$sessionID);
		
		// Deleting item via API should log sync deletes for relations
		$item = $items[0];
		$subject = 'http://zotero.org/users/'
				. self::$config['userID'] . '/items/' . $item['key'];
		
		$response = API::userGet(
			self::$config['userID'],
			"items/{$item['key']}?key=" . self::$config['apiKey'] . "&content=json"
		);
		$this->assertEquals(200, $response->getStatus());
		$libraryVersion = $response->getHeader('Zotero-Last-Modified-Version');
		
		$response = API::userDelete(
			self::$config['userID'],
			"items/{$item['key']}?key=" . self::$config['apiKey'],
			array("Zotero-If-Unmodified-Since-Version: $libraryVersion")
		);
		$this->assertEquals(204, $response->getStatus());
		
		$xml = Sync::updated(self::$sessionID);
		
		$this->assertEquals(0, $xml->updated[0]->relations->count());
		$this->assertEquals(1, $xml->updated[0]->deleted[0]->items[0]->item->count());
		$this->assertEquals(sizeOf($item['relations']), $xml->updated[0]->deleted[0]->relations[0]->relation->count());
		foreach ($item['relations'] as $predicate => $object) {
			$relKey = md5($subject . "_" . $predicate . "_" . $object);
			$this->assertEquals(1, sizeOf($xml->updated[0]->deleted[0]->relations[0]->xpath("relation[@key='$relKey']")));
		}
	}
	
	
	public function testIsReplacedBy() {
		$xml = Sync::updated(self::$sessionID);
		$updateKey = $xml['updateKey'];
		$lastSyncTimestamp = $xml['timestamp'];
		
		$key1 = 'AAAAAAAA';
		$uri1 = "http://zotero.org/users/" . self::$config['userID'] . '/items/' . $key1;
		$data = API::createItem("journalArticle", array(
			"relations" => array(
				"dc:replaces" => $uri1
			)
		), null, 'data');
		$key2 = $data['key'];
		$libraryVersion = $data['version'];
		$uri2 = "http://zotero.org/users/" . self::$config['userID'] . '/items/' . $key2;
		
		$xml = Sync::updated(self::$sessionID);
		
		// For classic sync, dc:replaces should be swapped for dc:isReplacedBy
		$this->assertEquals($uri1, (string) $xml->updated[0]->relations->relation[0]->subject);
		$this->assertEquals("dc:isReplacedBy", (string) $xml->updated[0]->relations->relation[0]->predicate);
		$this->assertEquals($uri2, (string) $xml->updated[0]->relations->relation[0]->object);
		
		$response = API::userDelete(
			self::$config['userID'],
			"items/$key2?key=" . self::$config['apiKey'],
			array("Zotero-If-Unmodified-Since-Version: $libraryVersion")
		);
		$this->assertEquals(204, $response->getStatus());
		
		$xml = Sync::updated(self::$sessionID);
		
		$this->assertEquals(1, $xml->updated[0]->relations->count());
		$this->assertEquals(1, $xml->updated[0]->deleted[0]->items[0]->item->count());
		$this->assertEquals(0, $xml->updated[0]->deleted[0]->items[0]->relations->count());
	}
	
	
	public function testRelatedItems() {
		$itemKey1 = API::createItem("audioRecording", array(
			"relations" => array(
				'owl:sameAs' => 'http://zotero.org/groups/1/items/AAAAAAAA'
			)
		), null, 'key');
		$itemURI1 = 'http://zotero.org/users/'
					. self::$config['userID'] . '/items/' . $itemKey1;
		// dc:relation already exists, so item shouldn't change
		$itemKey2 = API::createItem("interview", array(
			"relations" => array(
				'dc:relation' => $itemURI1
			)
		), null, 'key');
		$itemURI2 = 'http://zotero.org/users/'
					. self::$config['userID'] . '/items/' . $itemKey2;
		
		$libraryVersion = API::getLibraryVersion();
		
		// Add related items via sync
		$xml = Sync::updated(self::$sessionID);
		$updateKey = $xml['updateKey'];
		$lastSyncTimestamp = $xml['timestamp'];
		
		$itemXML1 = array_shift($xml->updated[0]->items[0]->xpath("item[@key='$itemKey1']"));
		$itemXML2 = array_shift($xml->updated[0]->items[0]->xpath("item[@key='$itemKey2']"));
		$itemXML1['libraryID'] = self::$config['libraryID'];
		$itemXML2['libraryID'] = self::$config['libraryID'];
		$itemXML1->related = $itemKey2;
		$itemXML2->related = $itemKey1;
		
		$xmlstr = '<data version="9">'
			. '<items>'
			. $itemXML1->asXML()
			. $itemXML2->asXML()
			. '</items>'
			. '</data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $xmlstr);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Check via API
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey']
				. "&content=json&newer=$libraryVersion"
		);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		$json = json_decode(API::getContentFromResponse($response), true);
		$this->assertEquals($itemURI2, $json['relations']['dc:relation']);
	}
}
