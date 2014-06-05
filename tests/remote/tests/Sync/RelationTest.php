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

use API2 as API;
require_once 'include/api2.inc.php';
require_once 'include/sync.inc.php';

class SyncRelationTests extends PHPUnit_Framework_TestCase {
	protected static $config;
	protected static $sessionID;
	
	public static function setUpBeforeClass() {
		require 'include/config.inc.php';
		foreach ($config as $k => $v) {
			self::$config[$k] = $v;
		}
		
		API::useAPIVersion(2);
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
				array("owl:sameAs", "http://zotero.org/groups/1/items/AAAAAAAA")
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
			foreach ($item['relations'] as $rel) {
				$xmlstr .= '<relation libraryID="' . self::$config['libraryID'] . '">'
				. "<subject>$subject</subject>"
				. "<predicate>{$rel[0]}</predicate>"
				. "<object>{$rel[1]}</object>"
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
			$uniquePredicates = array_unique(array_map(function ($x) { return $x[0]; }, $item['relations']));
			$this->assertCount(sizeOf($uniquePredicates), $json['relations']);
			foreach ($item['relations'] as $rel) {
				$this->assertArrayHasKey($rel[0], $json['relations']);
				$this->assertContains($rel[1], $json['relations'][$rel[0]]);
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
		$libraryVersion = $response->getHeader('Last-Modified-Version');
		
		$response = API::userDelete(
			self::$config['userID'],
			"items/{$item['key']}?key=" . self::$config['apiKey'],
			array("If-Unmodified-Since-Version: $libraryVersion")
		);
		$this->assertEquals(204, $response->getStatus());
		
		$xml = Sync::updated(self::$sessionID);
		
		$this->assertEquals(0, $xml->updated[0]->relations->count());
		$this->assertEquals(1, $xml->updated[0]->deleted[0]->items[0]->item->count());
		$this->assertEquals(sizeOf($item['relations']), $xml->updated[0]->deleted[0]->relations[0]->relation->count());
		foreach ($item['relations'] as $rel) {
			$relKey = md5($subject . " " . $rel[0] . " " . $rel[1]);
			$this->assertEquals(1, sizeOf($xml->updated[0]->deleted[0]->relations[0]->xpath("relation[@key='$relKey']")));
		}
	}
	
	
	public function testModifyRelationsArrayViaSync() {
		$items = array();
		$items[] = array(
			"key" => API::createItem("book", false, null, 'key'),
			"relations" => array(
				array("owl:sameAs", "http://zotero.org/groups/1/items/AAAAAAAA"),
				array("owl:sameAs", "http://zotero.org/groups/1/items/BBBBBBBB")
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
			foreach ($item['relations'] as $rel) {
				$xmlstr .= '<relation libraryID="' . self::$config['libraryID'] . '">'
				. "<subject>$subject</subject>"
				. "<predicate>{$rel[0]}</predicate>"
				. "<object>{$rel[1]}</object>"
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
			$uniquePredicates = array_unique(array_map(function ($x) { return $x[0]; }, $item['relations']));
			$this->assertCount(sizeOf($uniquePredicates), $json['relations']);
			foreach ($item['relations'] as $rel) {
				$this->assertArrayHasKey($rel[0], $json['relations']);
				$this->assertContains($rel[1], $json['relations'][$rel[0]]);
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
		$libraryVersion = $response->getHeader('Last-Modified-Version');
		
		$response = API::userDelete(
			self::$config['userID'],
			"items/{$item['key']}?key=" . self::$config['apiKey'],
			array("If-Unmodified-Since-Version: $libraryVersion")
		);
		$this->assertEquals(204, $response->getStatus());
		
		$xml = Sync::updated(self::$sessionID);
		
		$this->assertEquals(0, $xml->updated[0]->relations->count());
		$this->assertEquals(1, $xml->updated[0]->deleted[0]->items[0]->item->count());
		$this->assertEquals(sizeOf($item['relations']), $xml->updated[0]->deleted[0]->relations[0]->relation->count());
		foreach ($item['relations'] as $rel) {
			$relKey = md5($subject . " " . $rel[0] . " " . $rel[1]);
			$this->assertEquals(1, sizeOf($xml->updated[0]->deleted[0]->relations[0]->xpath("relation[@key='$relKey']")));
		}
	}
	
	
	public function testReverseSameAs() {
		$items = array();
		$item = [
			"key" => API::createItem("book", false, null, 'key'),
			"relations" => [
				["owl:sameAs", "http://zotero.org/groups/1/items/AAAAAAAA"],
			]
		];
		
		$xml = Sync::updated(self::$sessionID);
		$updateKey = $xml['updateKey'];
		$lastSyncTimestamp = $xml['timestamp'];
		
		$xmlstr = '<data version="9">'
			. '<relations>';
		$subject = 'http://zotero.org/users/'
			. self::$config['userID'] . '/items/' . $item['key'];
		// Insert backwards, as client does via classic sync
		// if group item is dragged to personal library
		foreach ($item['relations'] as $rel) {
			$xmlstr .= '<relation libraryID="' . self::$config['libraryID'] . '">'
			. "<subject>{$rel[1]}</subject>"
			. "<predicate>{$rel[0]}</predicate>"
			. "<object>$subject</object>"
			. '</relation>';
		}
		$xmlstr .= '</relations>'
			. '</data>';
		$response = Sync::upload(self::$sessionID, $updateKey, $xmlstr);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		// Check via API
		$response = API::userGet(
			self::$config['userID'],
			"items/{$item['key']}?key=" . self::$config['apiKey'] . "&content=json"
		);
		$content = API::getContentFromResponse($response);
		$json = json_decode($content, true);
		$uniquePredicates = array_unique(array_map(function ($x) { return $x[0]; }, $item['relations']));
		$this->assertCount(sizeOf($uniquePredicates), $json['relations']);
		foreach ($item['relations'] as $rel) {
			$this->assertArrayHasKey($rel[0], $json['relations']);
			$this->assertContains($rel[1], $json['relations'][$rel[0]]);
		}
		
		// PUT via API, which should be unchanged
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode([
				"items" => [
					$json
				]
			])
		);
		$results = json_decode($response->getBody(), true);
		$this->assertArrayHasKey('unchanged', $results);
		$this->assertContains($item['key'], $results['unchanged']);
		
		// Add another owl:sameAs via API
		if (is_string($json['relations']['owl:sameAs'])) {
			$json['relations']['owl:sameAs'] = [$json['relations']['owl:sameAs']];
		}
		$newURI = "http://zotero.org/groups/1/items/BBBBBBBB";
		$json['relations']['owl:sameAs'][] = $newURI;
		$item['relations'][] = ['owl:sameAs', $newURI];
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode([
				"items" => [
					$json
				]
			])
		);
		$this->assertEquals(200, $response->getStatus());
		$results = json_decode($response->getBody(), true);
		$this->assertArrayHasKey('success', $results);
		$this->assertContains($item['key'], $results['success']);
		
		// Check via API
		$response = API::userGet(
			self::$config['userID'],
			"items/{$item['key']}?key=" . self::$config['apiKey'] . "&content=json"
		);
		$content = API::getContentFromResponse($response);
		$json = json_decode($content, true);
		$uniquePredicates = array_unique(array_map(function ($x) { return $x[0]; }, $item['relations']));
		$this->assertCount(sizeOf($uniquePredicates), $json['relations']);
		foreach ($item['relations'] as $rel) {
			$this->assertArrayHasKey($rel[0], $json['relations']);
			$this->assertContains($rel[1], $json['relations'][$rel[0]]);
		}
		$this->assertArrayHasKey("owl:sameAs", $json['relations']);
		$this->assertContains($newURI, $json['relations']['owl:sameAs']);
		
		$xml = Sync::updated(self::$sessionID);
		$updateKey = $xml['updateKey'];
		
		// First URL should still be in reverse order
		$this->assertEquals(2, sizeOf($xml->updated[0]->relations->xpath("//relations/relation")));
		$subRel = $xml->updated[0]->relations->xpath("//relations/relation[subject/text() = '{$item['relations'][0][1]}']");
		$objRel = $xml->updated[0]->relations->xpath("//relations/relation[object/text() = '$newURI']");
		$this->assertEquals(1, sizeOf($subRel));
		$this->assertEquals($subject, $subRel[0]->object);
		$this->assertEquals(1, sizeOf($objRel));
		$this->assertEquals($subject, $objRel[0]->subject);
		
		// Resave second relation via classic sync in reverse order
		$xmlstr = '<data version="9"><relations>';
		$xmlstr .= '<relation libraryID="' . self::$config['libraryID'] . '">'
		. "<subject>$newURI</subject>"
		. "<predicate>owl:sameAs</predicate>"
		. "<object>$subject</object>"
		. "</relation></relations></data>";
		$response = Sync::upload(self::$sessionID, $updateKey, $xmlstr);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		$xml = Sync::updated(self::$sessionID);
		$this->assertEquals(2, sizeOf($xml->updated[0]->relations->xpath("//relations/relation")));
		
		// Delete reverse relation via API
		$response = API::userGet(
			self::$config['userID'],
			"items/{$item['key']}?key=" . self::$config['apiKey'] . "&content=json"
		);
		$content = API::getContentFromResponse($response);
		$json = json_decode($content, true);
		// Leave just the relation that's entered in normal order
		$json['relations']['owl:sameAs'] = [$newURI];
		$item['relations'] = ['owl:sameAs', $newURI];
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode([
				"items" => [
					$json
				]
			])
		);
		$this->assertEquals(200, $response->getStatus());
		$results = json_decode($response->getBody(), true);
		$this->assertArrayHasKey('success', $results);
		$this->assertContains($item['key'], $results['success']);
		
		$response = API::userGet(
			self::$config['userID'],
			"items/{$item['key']}?key=" . self::$config['apiKey'] . "&content=json"
		);
		$content = API::getContentFromResponse($response);
		$json = json_decode($content, true);
		$this->assertArrayHasKey("owl:sameAs", $json['relations']);
		$this->assertContains($newURI, $json['relations']['owl:sameAs']);
		// Should only have one relation left
		$this->assertEquals(1, sizeOf($json['relations']['owl:sameAs']));
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
			array("If-Unmodified-Since-Version: $libraryVersion")
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
		$itemURI1 = 'http://zotero.org/users/' . self::$config['userID'] . '/items/' . $itemKey1;
		
		$itemKey2 = API::createItem("interview", array(
			"relations" => array(
				'dc:relation' => $itemURI1
			)
		), null, 'key');
		$itemURI2 = 'http://zotero.org/users/' . self::$config['userID'] . '/items/' . $itemKey2;
		
		$itemKey3 = API::createItem("book", null, null, 'key');
		$itemURI3 = 'http://zotero.org/users/' . self::$config['userID'] . '/items/' . $itemKey3;
		
		$libraryVersion = API::getLibraryVersion();
		
		// Add related items via sync
		$xml = Sync::updated(self::$sessionID);
		
		$updateKey = $xml['updateKey'];
		$lastSyncTimestamp = $xml['timestamp'];
		
		$itemXML1 = array_shift($xml->updated[0]->items[0]->xpath("item[@key='$itemKey1']"));
		$itemXML2 = array_shift($xml->updated[0]->items[0]->xpath("item[@key='$itemKey2']"));
		$itemXML3 = array_shift($xml->updated[0]->items[0]->xpath("item[@key='$itemKey3']"));
		$itemXML1['libraryID'] = self::$config['libraryID'];
		$itemXML2['libraryID'] = self::$config['libraryID'];
		$itemXML3['libraryID'] = self::$config['libraryID'];
		$itemXML1->related = $itemKey2 . ' ' . $itemKey3;
		$itemXML2->related = $itemKey1;
		$itemXML3->related = $itemKey1;
		
		$xmlstr = '<data version="9">'
			. '<items>'
			. $itemXML1->asXML()
			. $itemXML2->asXML()
			. $itemXML3->asXML()
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
		// Item 2 already had the relation and shouldn't have been updated
		$this->assertEquals(2, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		$itemJSON1 = json_decode(array_shift($xml->xpath("//atom:entry[atom:id='$itemURI1']"))->content, 1);
		$itemJSON3 = json_decode(array_shift($xml->xpath("//atom:entry[atom:id='$itemURI3']"))->content, 1);
		$this->assertInternalType('array', $itemJSON1['relations']['dc:relation']);
		$this->assertInternalType('string', $itemJSON3['relations']['dc:relation']);
		$this->assertCount(2, $itemJSON1['relations']['dc:relation']);
		$this->assertTrue(in_array($itemURI2, $itemJSON1['relations']['dc:relation']));
		$this->assertTrue(in_array($itemURI3, $itemJSON1['relations']['dc:relation']));
		$this->assertEquals($itemURI1, $itemJSON3['relations']['dc:relation']);
	}
	
	
	public function testCircularRelatedItems() {
		$keys = [
			API::createItem("book", false, null, 'key'),
			API::createItem("book", false, null, 'key'),
			API::createItem("book", false, null, 'key')
		];
		$keys[] = API::createAttachmentItem("linked_url", [], $keys[0], null, 'key');
		
		$xml = Sync::updated(self::$sessionID);
		$updateKey = $xml['updateKey'];
		
		$item1XML = array_shift($xml->updated[0]->items->xpath("//item[@key = '{$keys[0]}']"));
		$item2XML = array_shift($xml->updated[0]->items->xpath("//item[@key = '{$keys[1]}']"));
		$item3XML = array_shift($xml->updated[0]->items->xpath("//item[@key = '{$keys[2]}']"));
		$item4XML = array_shift($xml->updated[0]->items->xpath("//item[@key = '{$keys[3]}']"));
		
		$item1XML['libraryID'] = self::$config['libraryID'];
		$item2XML['libraryID'] = self::$config['libraryID'];
		$item3XML['libraryID'] = self::$config['libraryID'];
		$item4XML['libraryID'] = self::$config['libraryID'];
		
		$item1XML->related = implode(' ', [
			(string) $item2XML['key'],
			(string) $item3XML['key'],
			(string) $item4XML['key']
		]);
		
		$item2XML->related = implode(' ', [
			(string) $item1XML['key'],
			(string) $item3XML['key'],
			(string) $item4XML['key']
		]);
		
		$item3XML->related = implode(' ', [
			(string) $item1XML['key'],
			(string) $item2XML['key'],
			(string) $item4XML['key']
		]);
		
		$item4XML->related = implode(' ', [
			(string) $item1XML['key'],
			(string) $item2XML['key'],
			(string) $item3XML['key']
		]);
		
		$xmlstr = '<data version="9">'
			. '<items>'
			. $item1XML->asXML()
			. $item2XML->asXML()
			. $item3XML->asXML()
			. $item4XML->asXML()
			. '</items>'
			. '</data>';
		
		var_dump($xmlstr);
		
		$response = Sync::upload(self::$sessionID, $updateKey, $xmlstr);
		Sync::waitForUpload(self::$sessionID, $response, $this);
		
		$xml = Sync::updated(self::$sessionID);
		var_dump($xml->asXML());
	}
}
