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

class ItemTests extends APITests {
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
		API::groupClear(self::$config['ownedPrivateGroupID']);
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
		API::groupClear(self::$config['ownedPrivateGroupID']);
	}
	
	
	public function testNewEmptyBookItem() {
		$json = API::createItem("book", false, $this, 'jsonData');
		$this->assertEquals("book", (string) $json['itemType']);
		return $json;
	}
	
	
	public function testNewEmptyBookItemMultiple() {
		$json = API::getItemTemplate("book");
		
		$data = array();
		$json->title = "A";
		$data[] = $json;
		$json2 = clone $json;
		$json2->title = "B";
		$data[] = $json2;
		$json3 = clone $json;
		$json3->title = "C";
		$data[] = $json3;
		
		$response = API::postItems($data);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		
		$json = API::getItem($json['success'], $this, 'json');
		
		$itemJSON = array_shift($json);
		$this->assertEquals("A", $itemJSON['data']['title']);
		$itemJSON = array_shift($json);
		$this->assertEquals("B", $itemJSON['data']['title']);
		$itemJSON = array_shift($json);
		$this->assertEquals("C", $itemJSON['data']['title']);
	}
	
	
	/**
	 * @depends testNewEmptyBookItem
	 */
	public function testEditBookItem($json) {
		$key = $json['key'];
		$version = $json['version'];
		
		$newTitle = "New Title";
		$numPages = 100;
		$creatorType = "author";
		$firstName = "Firstname";
		$lastName = "Lastname";
		
		$json['title'] = $newTitle;
		$json['numPages'] = $numPages;
		$json['creators'][] = array(
			'creatorType' => $creatorType,
			'firstName' => $firstName,
			'lastName' => $lastName
		);
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key",
			json_encode($json),
			array(
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: $version"
			)
		);
		$this->assert204($response);
		$json = API::getItem($key, $this, 'json')['data'];
		
		$this->assertEquals($newTitle, $json['title']);
		$this->assertEquals($numPages, $json['numPages']);
		$this->assertEquals($creatorType, $json['creators'][0]['creatorType']);
		$this->assertEquals($firstName, $json['creators'][0]['firstName']);
		$this->assertEquals($lastName, $json['creators'][0]['lastName']);
	}
	
	
	public function testDate() {
		$date = 'Sept 18, 2012';
		$parsedDate = '2012-09-18';
		
		$json = API::createItem("book", array(
			"date" => $date
		), $this, 'jsonData');
		$key = $json['key'];
		
		$response = API::userGet(
			self::$config['userID'],
			"items/$key"
		);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($date, $json['data']['date']);
		
		// meta.parsedDate (JSON)
		$this->assertEquals($parsedDate, $json['meta']['parsedDate']);
		
		// zapi:parsedDate (Atom)
		$xml = API::getItem($key, $this, 'atom');
		$this->assertEquals($parsedDate, array_shift($xml->xpath('/atom:entry/zapi:parsedDate')));
	}
	
	
	public function testDateWithoutDay() {
		$date = 'Sept 2012';
		$parsedDate = '2012-09';
		
		$json = API::createItem("book", array(
			"date" => $date
		), $this, 'jsonData');
		$key = $json['key'];
		
		$response = API::userGet(
			self::$config['userID'],
			"items/$key"
		);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($date, $json['data']['date']);
		
		// meta.parsedDate (JSON)
		$this->assertEquals($parsedDate, $json['meta']['parsedDate']);
		
		// zapi:parsedDate (Atom)
		$xml = API::getItem($key, $this, 'atom');
		$this->assertEquals($parsedDate, array_shift($xml->xpath('/atom:entry/zapi:parsedDate')));
	}
	
	
	public function testDateWithoutMonth() {
		$date = '2012';
		$parsedDate = '2012';
		
		$json = API::createItem("book", array(
			"date" => $date
		), $this, 'jsonData');
		$key = $json['key'];
		
		$response = API::userGet(
			self::$config['userID'],
			"items/$key"
		);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($date, $json['data']['date']);
		
		// meta.parsedDate (JSON)
		$this->assertEquals($parsedDate, $json['meta']['parsedDate']);
		
		// zapi:parsedDate (Atom)
		$xml = API::getItem($key, $this, 'atom');
		$this->assertEquals($parsedDate, array_shift($xml->xpath('/atom:entry/zapi:parsedDate')));
	}
	
	
	public function testDateUnparseable() {
		$json = API::createItem("book", array(
			"date" => 'n.d.'
		), $this, 'jsonData');
		$key = $json['key'];
		
		$response = API::userGet(
			self::$config['userID'],
			"items/$key"
		);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals('n.d.', $json['data']['date']);
		
		// meta.parsedDate (JSON)
		$this->assertArrayNotHasKey('parsedDate', $json['meta']);
		
		// zapi:parsedDate (Atom)
		$xml = API::getItem($key, $this, 'atom');
		$this->assertCount(0, $xml->xpath('/atom:entry/zapi:parsedDate'));
	}
	
	
	public function testDateAccessed8601() {
		$date = '2014-02-01T01:23:45Z';
		$data = API::createItem("book", array(
			'accessDate' => $date
		), $this, 'jsonData');
		$this->assertEquals($date, $data['accessDate']);
	}
	
	
	public function testDateAccessed8601TZ() {
		$date = '2014-02-01T01:23:45-0400';
		$dateUTC = '2014-02-01T05:23:45Z';
		$data = API::createItem("book", array(
			'accessDate' => $date
		), $this, 'jsonData');
		$this->assertEquals($dateUTC, $data['accessDate']);
	}
	
	
	public function testDateAccessedSQL() {
		$date = '2014-02-01 01:23:45';
		$date8601 = '2014-02-01T01:23:45Z';
		$data = API::createItem("book", array(
			'accessDate' => $date
		), $this, 'jsonData');
		$this->assertEquals($date8601, $data['accessDate']);
	}
	
	
	public function testDateAccessedInvalid() {
		$date = 'February 1, 2014';
		$response = API::createItem("book", array(
			'accessDate' => $date
		), $this, 'response');
		$this->assert400ForObject($response, "'accessDate' must be in ISO 8601 or UTC 'YYYY-MM-DD[ hh-mm-dd]' format or 'CURRENT_TIMESTAMP' (February 1, 2014)");
	}
	
	
	public function testDateAddedNewItem8601() {
		// In case this is ever extended to other objects
		$objectType = 'item';
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		$dateAdded = "2013-03-03T21:33:53Z";
		
		switch ($objectType) {
		case 'item':
			$itemData = array(
				"title" => "Test",
				"dateAdded" => $dateAdded
			);
			$data = API::createItem("videoRecording", $itemData, $this, 'jsonData');
			break;
		}
		
		$this->assertEquals($dateAdded, $data['dateAdded']);
	}
	
	
	public function testDateAddedNewItem8601TZ() {
		// In case this is ever extended to other objects
		$objectType = 'item';
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		$dateAdded = "2013-03-03T17:33:53-0400";
		$dateAddedUTC = "2013-03-03T21:33:53Z";
		
		switch ($objectType) {
		case 'item':
			$itemData = array(
				"title" => "Test",
				"dateAdded" => $dateAdded
			);
			$data = API::createItem("videoRecording", $itemData, $this, 'jsonData');
			break;
		}
		
		$this->assertEquals($dateAddedUTC, $data['dateAdded']);
	}
	
	
	public function testDateAddedNewItemSQL() {
		// In case this is ever extended to other objects
		$objectType = 'item';
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		$dateAdded = "2013-03-03 21:33:53";
		$dateAdded8601 = "2013-03-03T21:33:53Z";
		
		switch ($objectType) {
		case 'item':
			$itemData = array(
				"title" => "Test",
				"dateAdded" => $dateAdded
			);
			$data = API::createItem("videoRecording", $itemData, $this, 'jsonData');
			break;
		}
		
		$this->assertEquals($dateAdded8601, $data['dateAdded']);
	}
	
	
	public function testDateAddedExistingItem() {
		// In case this is ever extended to other objects
		$objectType = 'item';
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		switch ($objectType) {
		case 'item':
			$itemData = array(
				"title" => "Test"
			);
			$data = API::createItem("videoRecording", $itemData, $this, 'jsonData');
			break;
		}
		
		$objectKey = $data['key'];
		$originalDateAdded = $data['dateAdded'];
		
		// If date added hasn't changed, allow
		$data['title'] = "Test 2";
		$data['dateAdded'] = $originalDateAdded;
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/$objectKey",
			json_encode($data)
		);
		$this->assert204($response);
		$data = API::getItem($objectKey, $this, 'json')['data'];
		
		// And even if it's a different timezone
		$date = \DateTime::createFromFormat(\DateTime::ISO8601, $originalDateAdded);
		$date->setTimezone(new \DateTimeZone('America/New_York'));
		$newDateAdded = $date->format('c');
		
		$data['title'] = "Test 3";
		$data['dateAdded'] = $newDateAdded;
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/$objectKey",
			json_encode($data)
		);
		$this->assert204($response);
		$data = API::getItem($objectKey, $this, 'json')['data'];
		
		// But with a changed dateAdded, disallow
		$newDateAdded = "2013-03-03T21:33:53Z";
		$data['title'] = "Test 4";
		$data['dateAdded'] = $newDateAdded;
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/$objectKey",
			json_encode($data)
		);
		$this->assert400($response, "'dateAdded' cannot be modified for existing $objectTypePlural");
	}
	
	
	public function testDateModified() {
		// In case this is ever extended to other objects
		$objectType = 'item';
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		switch ($objectType) {
		case 'item':
			$itemData = array(
				"title" => "Test"
			);
			$json = API::createItem("videoRecording", $itemData, $this, 'jsonData');
			break;
		}
		
		$objectKey = $json['key'];
		$dateModified1 = $json['dateModified'];
		
		// Make sure we're in the next second
		sleep(1);
		
		//
		// If no explicit dateModified, use current timestamp
		//
		$json['title'] = "Test 2";
		unset($json['dateModified']);
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/$objectKey",
			json_encode($json)
		);
		$this->assert204($response);
		
		switch ($objectType) {
		case 'item':
			$json = API::getItem($objectKey, $this, 'json')['data'];
			break;
		}
		
		$dateModified2 = $json['dateModified'];
		$this->assertNotEquals($dateModified1, $dateModified2);
		
		// Make sure we're in the next second
		sleep(1);
		
		//
		// If existing dateModified, use current timestamp
		//
		$json['title'] = "Test 3";
		$json['dateModified'] = trim(preg_replace("/[TZ]/", " ", $dateModified2));
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/$objectKey",
			json_encode($json)
		);
		$this->assert204($response);
		
		switch ($objectType) {
		case 'item':
			$json = API::getItem($objectKey, $this, 'json')['data'];
			break;
		}
		
		$dateModified3 = $json['dateModified'];
		$this->assertNotEquals($dateModified2, $dateModified3);
		
		//
		// If explicit dateModified, use that
		//
		$newDateModified = "2013-03-03T21:33:53Z";
		$json['title'] = "Test 4";
		$json['dateModified'] = $newDateModified;
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/$objectKey",
			json_encode($json)
		);
		$this->assert204($response);
		
		switch ($objectType) {
		case 'item':
			$json = API::getItem($objectKey, $this, 'json')['data'];
			break;
		}
		$dateModified4 = $json['dateModified'];
		$this->assertEquals($newDateModified, $dateModified4);
	}
	
	
	public function testChangeItemType() {
		$json = API::getItemTemplate("book");
		$json->title = "Foo";
		$json->numPages = 100;
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json]),
			array("Content-Type: application/json")
		);
		$key = API::getFirstSuccessKeyFromResponse($response);
		$json1 = API::getItem($key, $this, 'json')['data'];
		$version = $json1['version'];
		
		$json2 = API::getItemTemplate("bookSection");
		
		foreach ($json2 as $field => &$val) {
			if ($field != "itemType" && isset($json1[$field])) {
				$val = $json1[$field];
			}
		}
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key",
			json_encode($json2),
			array(
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: $version"
			)
		);
		$this->assert204($response);
		$json = API::getItem($key, $this, 'json')['data'];
		$this->assertEquals("bookSection", $json['itemType']);
		$this->assertEquals("Foo", $json['title']);
		$this->assertArrayNotHasKey("numPages", $json);
	}
	
	
	//
	// PATCH
	//
	public function testModifyItemPartial() {
		$itemData = array(
			"title" => "Test"
		);
		$json = API::createItem("book", $itemData, $this, 'jsonData');
		$itemKey = $json['key'];
		$itemVersion = $json['version'];
		
		$patch = function ($context, $config, $itemKey, $itemVersion, &$itemData, $newData) {
			foreach ($newData as $field => $val) {
				$itemData[$field] = $val;
			}
			$response = API::userPatch(
				$config['userID'],
				"items/$itemKey?key=" . $config['apiKey'],
				json_encode($newData),
				array(
					"Content-Type: application/json",
					"If-Unmodified-Since-Version: $itemVersion"
				)
			);
			$context->assert204($response);
			$json = API::getItem($itemKey, $this, 'json')['data'];
			
			foreach ($itemData as $field => $val) {
				$context->assertEquals($val, $json[$field]);
			}
			$headerVersion = $response->getHeader("Last-Modified-Version");
			$context->assertGreaterThan($itemVersion, $headerVersion);
			$context->assertEquals($json['version'], $headerVersion);
			
			return $headerVersion;
		};
		
		$newData = array(
			"date" => "2013"
		);
		$itemVersion = $patch($this, self::$config, $itemKey, $itemVersion, $itemData, $newData);
		
		$newData = array(
			"title" => ""
		);
		$itemVersion = $patch($this, self::$config, $itemKey, $itemVersion, $itemData, $newData);
		
		$newData = array(
			"tags" => array(
				array(
					"tag" => "Foo"
				)
			)
		);
		$itemVersion = $patch($this, self::$config, $itemKey, $itemVersion, $itemData, $newData);
		
		$newData = array(
			"tags" => array()
		);
		$itemVersion = $patch($this, self::$config, $itemKey, $itemVersion, $itemData, $newData);
		
		$key = API::createCollection('Test', false, $this, 'key');
		$newData = array(
			"collections" => array($key)
		);
		$itemVersion = $patch($this, self::$config, $itemKey, $itemVersion, $itemData, $newData);
		
		$newData = array(
			"collections" => array()
		);
		$itemVersion = $patch($this, self::$config, $itemKey, $itemVersion, $itemData, $newData);
	}
	
	
	public function testNewComputerProgramItem() {
		$data = API::createItem("computerProgram", false, $this, 'jsonData');
		$key = $data['key'];
		$this->assertEquals("computerProgram", $data['itemType']);
		
		$version = "1.0";
		$data['versionNumber'] = $version;
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key",
			json_encode($data),
			[
				"Content-Type: application/json"
			]
		);
		$this->assert204($response);
		$json = API::getItem($key, $this, 'json');
		$this->assertEquals($version, $data['versionNumber']);
	}
	
	
	public function testNewInvalidBookItem() {
		$json = API::getItemTemplate("book");
		
		// Missing item type
		$json2 = clone $json;
		unset($json2->itemType);
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json2]),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject($response, "'itemType' property not provided");
		
		// contentType on non-attachment
		$json2 = clone $json;
		$json2->contentType = "text/html";
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json2]),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject($response, "'contentType' is valid only for attachment items");
		
		// more tests
	}
	
	
	public function testEditTopLevelNote() {
		$noteText = "Test";
		
		$json = API::createNoteItem($noteText, null, $this, 'jsonData');
		$noteText .= " Test";
		$json['note'] = $noteText;
		$response = API::userPut(
			self::$config['userID'],
			"items/{$json['key']}",
			json_encode($json)
		);
		$this->assert204($response);
		
		$response = API::userGet(
			self::$config['userID'],
			"items/{$json['key']}"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response)['data'];
		$this->assertEquals($noteText, $json['note']);
	}
	
	
	public function testEditChildNote() {
		$noteText = "Test";
		$key = API::createItem("book", [ "title" => "Test" ], $this, 'key');
		$json = API::createNoteItem($noteText, $key, $this, 'jsonData');
		$noteText .= " Test";
		$json['note'] = $noteText;
		$response = API::userPut(
			self::$config['userID'],
			"items/{$json['key']}",
			json_encode($json)
		);
		$this->assert204($response);
		
		$response = API::userGet(
			self::$config['userID'],
			"items/{$json['key']}"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response)['data'];
		$this->assertEquals($noteText, $json['note']);
	}
	
	
	public function testEditTitleWithCollectionInMultipleMode() {
		$collectionKey = API::createCollection('Test', false, $this, 'key');
		
		$json = API::createItem("book", [
			"title" => "A",
			"collections" => [
				$collectionKey
			]
		], $this, 'jsonData');
		
		$version = $json['version'];
		$json['title'] = "B";
		
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json])
		);
		$this->assert200ForObject($response);
		
		$json = API::getItem($json['key'], $this, 'json')['data'];
		$this->assertEquals("B", $json['title']);
		$this->assertGreaterThan($version, $json['version']);
	}
	
	
	public function testEditTitleWithTagInMultipleMode() {
		$tag1 = [
			"tag" => "foo",
			"type" => 1
		];
		$tag2 = [
			"tag" => "bar"
		];
		
		$json = API::createItem("book", [
			"title" => "A",
			"tags" => [$tag1]
		], $this, 'jsonData');
		
		$this->assertCount(1, $json['tags']);
		$this->assertEquals($tag1, $json['tags'][0]);
		
		$version = $json['version'];
		$json['title'] = "B";
		$json['tags'][] = $tag2;
		
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json])
		);
		$this->assert200ForObject($response);
		
		$json = API::getItem($json['key'], $this, 'json')['data'];
		$this->assertEquals("B", $json['title']);
		$this->assertGreaterThan($version, $json['version']);
		$this->assertCount(2, $json['tags']);
		$this->assertContains($tag1, $json['tags']);
		$this->assertContains($tag2, $json['tags']);
	}
	
	
	public function testNewTopLevelImportedFileAttachment() {
		$response = API::get("items/new?itemType=attachment&linkMode=imported_file");
		$json = json_decode($response->getBody());
		
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json]),
			array("Content-Type: application/json")
		);
		$this->assert200($response);
	}
	
	
	public function testNewInvalidTopLevelAttachment() {
		$linkModes = array("linked_url", "imported_url");
		foreach ($linkModes as $linkMode) {
			$response = API::get("items/new?itemType=attachment&linkMode=$linkMode");
			$json = json_decode($response->getBody());
			
			$response = API::userPost(
				self::$config['userID'],
				"items",
				json_encode([$json]),
				array("Content-Type: application/json")
			);
			$this->assert400ForObject($response, "Only file attachments and PDFs can be top-level items");
		}
	}
	
	
	public function testNewEmptyLinkAttachmentItemWithItemKey() {
		$key = API::createItem("book", false, $this, 'key');
		API::createAttachmentItem("linked_url", [], $key, $this, 'json');
		
		$response = API::get("items/new?itemType=attachment&linkMode=linked_url");
		$json = json_decode($response->getBody(), true);
		$json['parentItem'] = $key;
		require_once '../../model/Utilities.inc.php';
		require_once '../../model/ID.inc.php';
		$json['key'] = \Zotero_ID::getKey();
		$json['version'] = 0;
		
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json]),
			array("Content-Type: application/json")
		);
		$this->assert200ForObject($response);
	}
	
	
	public function testNewEmptyImportedURLAttachmentItem() {
		$key = API::createItem("book", false, $this, 'key');
		return API::createAttachmentItem("imported_url", [], $key, $this, 'jsonData');
	}
	
	
	public function testEditEmptyLinkAttachmentItem() {
		$key = API::createItem("book", false, $this, 'key');
		$json = API::createAttachmentItem("linked_url", [], $key, $this, 'jsonData');
		
		$key = $json['key'];
		$version = $json['version'];
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key",
			json_encode($json),
			array(
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: $version"
			)
		);
		$this->assert204($response);
		$json = API::getItem($key, $this, 'json')['data'];
		// Item shouldn't change
		$this->assertEquals($version, $json['version']);
		
		return $json;
	}
	
	
	/**
	 * @depends testNewEmptyImportedURLAttachmentItem
	 */
	public function testEditEmptyImportedURLAttachmentItem($json) {
		$key = $json['key'];
		$version = $json['version'];
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key",
			json_encode($json),
			array(
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: $version"
			)
		);
		$this->assert204($response);
		$json = API::getItem($key, $this, 'json')['data'];
		// Item shouldn't change
		$this->assertEquals($version, $json['version']);
		
		return $json;
	}
	
	
	/**
	 * @depends testEditEmptyLinkAttachmentItem
	 */
	public function testEditLinkAttachmentItem($json) {
		$key = $json['key'];
		$version = $json['version'];
		
		$contentType = "text/xml";
		$charset = "utf-8";
		
		$json['contentType'] = $contentType;
		$json['charset'] = $charset;
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key",
			json_encode($json),
			array(
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: $version"
			)
		);
		$this->assert204($response);
		$json = API::getItem($key, $this, 'json')['data'];
		$this->assertEquals($contentType, $json['contentType']);
		$this->assertEquals($charset, $json['charset']);
	}
	
	
	public function testEditAttachmentJSONDateModified() {
		$json = API::createAttachmentItem("linked_file", [], false, $this, 'jsonData');
		$modified = $json['dateModified'];
		$json['note'] = "Test";
		
		sleep(1);
		
		$response = API::userPut(
			self::$config['userID'],
			"items/{$json['key']}",
			json_encode($json),
			array("If-Unmodified-Since-Version: " . $json['version'])
		);
		$this->assert204($response);
		
		$json = API::getItem($json['key'], $this, 'json')['data'];
		$this->assertNotEquals($modified, $json['dateModified']);
	}
	
	
	public function testEditAttachmentAtomUpdatedTimestamp() {
		$xml = API::createAttachmentItem("linked_file", [], false, $this, 'atom');
		$data = API::parseDataFromAtomEntry($xml);
		$atomUpdated = (string) array_shift($xml->xpath('//atom:entry/atom:updated'));
		$json = json_decode($data['content'], true);
		$json['note'] = "Test";
		
		sleep(1);
		
		$response = API::userPut(
			self::$config['userID'],
			"items/{$data['key']}",
			json_encode($json),
			array("If-Unmodified-Since-Version: " . $data['version'])
		);
		$this->assert204($response);
		
		$xml = API::getItemXML($data['key']);
		$atomUpdated2 = (string) array_shift($xml->xpath('//atom:entry/atom:updated'));
		$this->assertNotEquals($atomUpdated2, $atomUpdated);
	}
	
	
	public function testNewAttachmentItemInvalidLinkMode() {
		$response = API::get("items/new?itemType=attachment&linkMode=linked_url");
		$json = json_decode($response->getBody());
		
		// Invalid linkMode
		$json->linkMode = "invalidName";
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json]),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject($response, "'invalidName' is not a valid linkMode");
		
		// Missing linkMode
		unset($json->linkMode);
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json]),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject($response, "'linkMode' property not provided");
	}
	
	
	/**
	 * @depends testNewEmptyBookItem
	 */
	public function testNewAttachmentItemMD5OnLinkedURL($json) {
		$parentKey = $json['key'];
		
		$response = API::get("items/new?itemType=attachment&linkMode=linked_url");
		$json = json_decode($response->getBody());
		$json->parentItem = $parentKey;
		
		$json->md5 = "c7487a750a97722ae1878ed46b215ebe";
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json]),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject($response, "'md5' is valid only for imported attachment items");
	}
	
	
	/**
	 * @depends testNewEmptyBookItem
	 */
	public function testNewAttachmentItemModTimeOnLinkedURL($json) {
		$parentKey = $json['key'];
		
		$response = API::get("items/new?itemType=attachment&linkMode=linked_url");
		$json = json_decode($response->getBody());
		$json->parentItem = $parentKey;
		
		$json->mtime = "1332807793000";
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json]),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject($response, "'mtime' is valid only for imported attachment items");
	}
	
	
	public function testNewEmptyImportedURLAttachmentItemGroup() {
		$key = API::groupCreateItem(
			self::$config['ownedPrivateGroupID'], "book", [], $this, 'key'
		);
		return API::groupCreateAttachmentItem(
			self::$config['ownedPrivateGroupID'], "imported_url", [], $key, $this, 'jsonData'
		);
	}
	
	
	/**
	 * @depends testNewEmptyImportedURLAttachmentItemGroup
	 */
	public function testEditImportedURLAttachmentItemGroup($json) {
		$key = $json['key'];
		$version = $json['version'];
		
		$props = array("contentType", "charset", "filename", "md5", "mtime");
		foreach ($props as $prop) {
			$json2 = $json;
			$json2[$prop] = "new" . ucwords($prop);
			$response = API::groupPut(
				self::$config['ownedPrivateGroupID'],
				"items/$key",
				json_encode($json2),
				array(
					"Content-Type: application/json",
					"If-Unmodified-Since-Version: $version"
				)
			);
			$this->assert400($response);
			$this->assertEquals("Cannot change '$prop' directly in group library", $response->getBody());
		}
	}
	
	
	public function testMappedCreatorTypes() {
		$json = [
			[
				'itemType' => 'presentation',
				'title' => 'Test',
				'creators' => [
					[
						"creatorType" => "author",
						"name" => "Foo"
					]
				]
			],
			[
				'itemType' => 'presentation',
				'title' => 'Test',
				'creators' => [
					[
						"creatorType" => "editor",
						"name" => "Foo"
					]
				]
			]
		];
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode($json)
		);
		// 'author' gets mapped automatically
		$this->assert200ForObject($response);
		// Others don't
		$this->assert400ForObject($response, false, 1);
	}
	
	
	public function testLibraryUser() {
		$json = API::createItem('book', false, $this, 'json');
		$this->assertEquals('user', $json['library']['type']);
		$this->assertEquals(self::$config['userID'], $json['library']['id']);
		$this->assertEquals(self::$config['username'], $json['library']['name']);
		$this->assertRegExp('%^https?://[^/]+/' . self::$config['username'] . '$%', $json['library']['links']['alternate']['href']);
		$this->assertEquals('text/html', $json['library']['links']['alternate']['type']);
	}
	
	
	public function testLibraryGroup() {
		$json = API::groupCreateItem(self::$config['ownedPrivateGroupID'], 'book', [], $this, 'json');
		$this->assertEquals('group', $json['library']['type']);
		$this->assertEquals(self::$config['ownedPrivateGroupID'], $json['library']['id']);
		$this->assertEquals(self::$config['ownedPrivateGroupName'], $json['library']['name']);
		$this->assertRegExp('%^https?://[^/]+/groups/[0-9]+$%', $json['library']['links']['alternate']['href']);
		$this->assertEquals('text/html', $json['library']['links']['alternate']['type']);
	}
	
	
	public function testNumChildrenJSON() {
		$json = API::createItem("book", false, $this, 'json');
		$this->assertEquals(0, $json['meta']['numChildren']);
		$key = $json['key'];
		
		API::createAttachmentItem("linked_url", [], $key, $this, 'key');
		
		$response = API::userGet(
			self::$config['userID'],
			"items/$key"
		);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals(1, $json['meta']['numChildren']);
		
		API::createNoteItem("Test", $key, $this, 'key');
		
		$response = API::userGet(
			self::$config['userID'],
			"items/$key"
		);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals(2, $json['meta']['numChildren']);
	}
	
	
	public function testNumChildrenAtom() {
		$xml = API::createItem("book", false, $this, 'atom');
		$this->assertEquals(0, (int) array_shift($xml->xpath('/atom:entry/zapi:numChildren')));
		$data = API::parseDataFromAtomEntry($xml);
		$key = $data['key'];
		
		API::createAttachmentItem("linked_url", [], $key, $this, 'key');
		
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:entry/zapi:numChildren')));
		
		API::createNoteItem("Test", $key, $this, 'key');
		
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(2, (int) array_shift($xml->xpath('/atom:entry/zapi:numChildren')));
	}
	
	
	public function testTop() {
		API::userClear(self::$config['userID']);
		
		$collectionKey = API::createCollection('Test', false, $this, 'key');
		$emptyCollectionKey = API::createCollection('Empty', false, $this, 'key');
		
		$parentTitle1 = "Parent Title";
		$childTitle1 = "This is a Test Title";
		$parentTitle2 = "Another Parent Title";
		$parentTitle3 = "Yet Another Parent Title";
		$noteText = "This is a sample note.";
		$parentTitleSearch = "title";
		$childTitleSearch = "test";
		$dates = ["2013", "January 3, 2010", ""];
		$orderedDates = [$dates[2], $dates[1], $dates[0]];
		$itemTypes = ["journalArticle", "newspaperArticle", "book"];
		
		$parentKeys = [];
		$childKeys = [];
		
		$parentKeys[] = API::createItem($itemTypes[0], [
			'title' => $parentTitle1,
			'date' => $dates[0],
			'collections' => [
				$collectionKey
			]
		], $this, 'key');
		$childKeys[] = API::createAttachmentItem("linked_url", [
			'title' => $childTitle1
		], $parentKeys[0], $this, 'key');
		
		$parentKeys[] = API::createItem($itemTypes[1], [
			'title' => $parentTitle2,
			'date' => $dates[1]
		], $this, 'key');
		$childKeys[] = API::createNoteItem($noteText, $parentKeys[1], $this, 'key');
		
		// Create item with deleted child that matches child title search
		$parentKeys[] = API::createItem($itemTypes[2], [
			'title' => $parentTitle3
		], $this, 'key');
		API::createAttachmentItem("linked_url", [
			'title' => $childTitle1,
			'deleted' => true
		], $parentKeys[sizeOf($parentKeys) - 1], $this, 'key');
		
		// Add deleted item with non-deleted child
		$deletedKey = API::createItem("book", [
			'title' => "This is a deleted item",
			'deleted' => true
		], $this, 'key');
		API::createNoteItem("This is a child note of a deleted item.", $deletedKey, $this, 'key');
		
		// /top, JSON
		$response = API::userGet(
			self::$config['userID'],
			"items/top"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($parentKeys), $response);
		$json = API::getJSONFromResponse($response);
		$done = [];
		foreach ($json as $item) {
			$this->assertContains($item['key'], $parentKeys);
			$this->assertNotContains($item['key'], $done);
			$done[] = $item['key'];
		}
		
		// /top, Atom
		$response = API::userGet(
			self::$config['userID'],
			"items/top?content=json"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($parentKeys), $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertCount(sizeOf($parentKeys), $xpath);
		foreach ($parentKeys as $parentKey) {
			$this->assertContains($parentKey, $xpath);
		}
		
		// /top, JSON, in collection
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertCount(1, $json);
		$this->assertEquals($parentKeys[0], $json[0]['key']);
		
		// /top, Atom, in collection
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top?content=json"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertCount(1, $xpath);
		$this->assertContains($parentKeys[0], $xpath);
		
		// /top, JSON, in empty collection
		$response = API::userGet(
			self::$config['userID'],
			"collections/$emptyCollectionKey/items/top"
		);
		$this->assert200($response);
		$this->assertNumResults(0, $response);
		$this->assertTotalResults(0, $response);
		
		// /top, keys
		$response = API::userGet(
			self::$config['userID'],
			"items/top?format=keys"
		);
		$this->assert200($response);
		$keys = explode("\n", trim($response->getBody()));
		$this->assertCount(sizeOf($parentKeys), $keys);
		foreach ($parentKeys as $parentKey) {
			$this->assertContains($parentKey, $keys);
		}
		
		// /top, keys, in collection
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top?format=keys"
		);
		$this->assert200($response);
		$this->assertEquals($parentKeys[0], trim($response->getBody()));
		
		// /top with itemKey for parent, JSON
		$response = API::userGet(
			self::$config['userID'],
			"items/top?itemKey=" . $parentKeys[0]
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($parentKeys[0], $json[0]['key']);
		
		// /top with itemKey for parent, Atom
		$response = API::userGet(
			self::$config['userID'],
			"items/top?content=json&itemKey=" . $parentKeys[0]
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertEquals($parentKeys[0], (string) array_shift($xpath));
		
		// /top with itemKey for parent, JSON, in collection
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top?itemKey=" . $parentKeys[0]
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($parentKeys[0], $json[0]['key']);
		
		// /top with itemKey for parent, Atom, in collection
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top?content=json&itemKey=" . $parentKeys[0]
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertEquals($parentKeys[0], (string) array_shift($xpath));
		
		// /top with itemKey for parent, keys
		$response = API::userGet(
			self::$config['userID'],
			"items/top?format=keys&itemKey=" . $parentKeys[0]
		);
		$this->assert200($response);
		$this->assertEquals($parentKeys[0], trim($response->getBody()));
		
		// /top with itemKey for parent, keys, in collection
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top?format=keys&itemKey=" . $parentKeys[0]
		);
		$this->assert200($response);
		$this->assertEquals($parentKeys[0], trim($response->getBody()));
		
		// /top with itemKey for child, JSON
		$response = API::userGet(
			self::$config['userID'],
			"items/top?itemKey=" . $childKeys[0]
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals($parentKeys[0], $json[0]['key']);
		
		// /top with itemKey for child, Atom
		$response = API::userGet(
			self::$config['userID'],
			"items/top?content=json&itemKey=" . $childKeys[0]
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertEquals($parentKeys[0], (string) array_shift($xpath));
		
		// /top with itemKey for child, keys
		$response = API::userGet(
			self::$config['userID'],
			"items/top?format=keys&itemKey=" . $childKeys[0]
		);
		$this->assert200($response);
		$this->assertEquals($parentKeys[0], trim($response->getBody()));
		
		// /top, Atom, with q for all items
		$response = API::userGet(
			self::$config['userID'],
			"items/top?q=$parentTitleSearch"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($parentKeys), $response);
		$json = API::getJSONFromResponse($response);
		$done = [];
		foreach ($json as $item) {
			$this->assertContains($item['key'], $parentKeys);
			$this->assertNotContains($item['key'], $done);
			$done[] = $item['key'];
		}
		
		// /top, Atom, with q for all items
		$response = API::userGet(
			self::$config['userID'],
			"items/top?content=json&q=$parentTitleSearch"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($parentKeys), $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertCount(sizeOf($parentKeys), $xpath);
		foreach ($parentKeys as $parentKey) {
			$this->assertContains($parentKey, $xpath);
		}
		
		// /top, JSON, in collection, with q for all items
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top?q=$parentTitleSearch"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertContains($parentKeys[0], $json[0]['key']);
		
		// /top, Atom, in collection, with q for all items
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top?content=json&q=$parentTitleSearch"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertCount(1, $xpath);
		$this->assertContains($parentKeys[0], $xpath);
		
		// /top, JSON, with q for child item
		$response = API::userGet(
			self::$config['userID'],
			"items/top?q=$childTitleSearch"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$json = API::getJSONFromResponse($response);
		$this->assertContains($parentKeys[0], $json[0]['key']);
		
		// /top, Atom, with q for child item
		$response = API::userGet(
			self::$config['userID'],
			"items/top?content=json&q=$childTitleSearch"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertCount(1, $xpath);
		$this->assertContains($parentKeys[0], $xpath);
		
		// /top, JSON, in collection, with q for child item
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top?q=$childTitleSearch"
		);
		$this->assert200($response);
		$this->assertNumResults(0, $response);
		// Not currently possible
		/*$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertCount(1, $xpath);
		$this->assertContains($parentKeys[0], $xpath);*/
		
		// /top, Atom, in collection, with q for child item
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top?content=json&q=$childTitleSearch"
		);
		$this->assert200($response);
		$this->assertNumResults(0, $response);
		// Not currently possible
		/*$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertCount(1, $xpath);
		$this->assertContains($parentKeys[0], $xpath);*/
		
		// /top, JSON, with q for all items, ordered by title
		$response = API::userGet(
			self::$config['userID'],
			"items/top?q=$parentTitleSearch"
				. "&order=title"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($parentKeys), $response);
		$json = API::getJSONFromResponse($response);
		$returnedTitles = [];
		foreach ($json as $item) {
			$returnedTitles[] = $item['data']['title'];
		}
		$orderedTitles = [$parentTitle1, $parentTitle2, $parentTitle3];
		sort($orderedTitles);
		$this->assertEquals($orderedTitles, $returnedTitles);
		
		// /top, Atom, with q for all items, ordered by title
		$response = API::userGet(
			self::$config['userID'],
			"items/top?content=json&q=$parentTitleSearch"
				. "&order=title"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($parentKeys), $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/atom:title');
		$this->assertCount(sizeOf($parentKeys), $xpath);
		$orderedTitles = [$parentTitle1, $parentTitle2, $parentTitle3];
		sort($orderedTitles);
		$orderedResults = array_map(function ($val) {
			return (string) $val;
		}, $xpath);
		$this->assertEquals($orderedTitles, $orderedResults);
		
		// /top, Atom, with q for all items, ordered by date asc
		$response = API::userGet(
			self::$config['userID'],
			"items/top?q=$parentTitleSearch"
				. "&order=date&sort=asc"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($parentKeys), $response);
		$json = API::getJSONFromResponse($response);
		$orderedResults = array_map(function ($val) {
			return $val['data']['date'];
		}, $json);
		$this->assertEquals($orderedDates, $orderedResults);
		
		// /top, Atom, with q for all items, ordered by date asc
		$response = API::userGet(
			self::$config['userID'],
			"items/top?content=json&q=$parentTitleSearch"
				. "&order=date&sort=asc"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($parentKeys), $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/atom:content');
		$this->assertCount(sizeOf($parentKeys), $xpath);
		$orderedResults = array_map(function ($val) {
			return json_decode($val)->date;
		}, $xpath);
		$this->assertEquals($orderedDates, $orderedResults);
		
		// /top, JSON, with q for all items, ordered by date desc
		$response = API::userGet(
			self::$config['userID'],
			"items/top?q=$parentTitleSearch"
				. "&order=date&sort=desc"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($parentKeys), $response);
		$json = API::getJSONFromResponse($response);
		$orderedDatesReverse = array_reverse($orderedDates);
		$orderedResults = array_map(function ($val) {
			return $val['data']['date'];
		}, $json);
		$this->assertEquals($orderedDatesReverse, $orderedResults);
		
		// /top, Atom, with q for all items, ordered by date desc
		$response = API::userGet(
			self::$config['userID'],
			"items/top?content=json&q=$parentTitleSearch"
				. "&order=date&sort=desc"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($parentKeys), $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/atom:content');
		$this->assertCount(sizeOf($parentKeys), $xpath);
		$orderedDatesReverse = array_reverse($orderedDates);
		$orderedResults = array_map(function ($val) {
			return json_decode($val)->date;
		}, $xpath);
		$this->assertEquals($orderedDatesReverse, $orderedResults);
		
		// /top, Atom, with q for all items, ordered by item type asc
		$response = API::userGet(
			self::$config['userID'],
			"items/top?q=$parentTitleSearch"
				. "&order=itemType"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($parentKeys), $response);
		$json = API::getJSONFromResponse($response);
		$orderedItemTypes = $itemTypes;
		sort($orderedItemTypes);
		$orderedResults = array_map(function ($val) {
			return $val['data']['itemType'];
		}, $json);
		$this->assertEquals($orderedItemTypes, $orderedResults);
		
		// /top, Atom, with q for all items, ordered by item type asc
		$response = API::userGet(
			self::$config['userID'],
			"items/top?content=json&q=$parentTitleSearch"
				. "&order=itemType"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($parentKeys), $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:itemType');
		$this->assertCount(sizeOf($parentKeys), $xpath);
		$orderedItemTypes = $itemTypes;
		sort($orderedItemTypes);
		$orderedResults = array_map(function ($val) {
			return (string) $val;
		}, $xpath);
		$this->assertEquals($orderedItemTypes, $orderedResults);
		
		// /top, Atom, with q for all items, ordered by item type desc
		$response = API::userGet(
			self::$config['userID'],
			"items/top?q=$parentTitleSearch"
				. "&order=itemType&sort=desc"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($parentKeys), $response);
		$json = API::getJSONFromResponse($response);
		$orderedItemTypes = $itemTypes;
		rsort($orderedItemTypes);
		$orderedResults = array_map(function ($val) {
			return $val['data']['itemType'];
		}, $json);
		$this->assertEquals($orderedItemTypes, $orderedResults);
		
		// /top, Atom, with q for all items, ordered by item type desc
		$response = API::userGet(
			self::$config['userID'],
			"items/top?content=json&q=$parentTitleSearch"
				. "&order=itemType&sort=desc"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($parentKeys), $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:itemType');
		$this->assertCount(sizeOf($parentKeys), $xpath);
		$orderedItemTypes = $itemTypes;
		rsort($orderedItemTypes);
		$orderedResults = array_map(function ($val) {
			return (string) $val;
		}, $xpath);
		$this->assertEquals($orderedItemTypes, $orderedResults);
	}
	
	
	public function testTrash() {
		API::userClear(self::$config['userID']);
		
		$key1 = API::createItem("book", false, $this, 'key');
		
		$key2 = API::createItem("book", [
			"deleted" => 1
		], $this, 'key');
		
		// Item should show up in trash
		$response = API::userGet(
			self::$config['userID'],
			"items/trash"
		);
		$json = API::getJSONFromResponse($response);
		$this->assertCount(1, $json);
		$this->assertEquals($key2, $json[0]['key']);
		
		// And not show up in main items
		$response = API::userGet(
			self::$config['userID'],
			"items"
		);
		$json = API::getJSONFromResponse($response);
		$this->assertCount(1, $json);
		$this->assertEquals($key1, $json[0]['key']);
	}
	
	
	public function testParentItem() {
		$json = API::createItem("book", false, $this, 'jsonData');
		$parentKey = $json['key'];
		$parentVersion = $json['version'];
		
		$json = API::createAttachmentItem("linked_url", [], $parentKey, $this, 'jsonData');
		$childKey = $json['key'];
		$childVersion = $json['version'];
		
		$this->assertArrayHasKey('parentItem', $json);
		$this->assertEquals($parentKey, $json['parentItem']);
		
		// Remove the parent, making the child a standalone attachment
		unset($json['parentItem']);
		
		// The parent item version should have been updated when a child
		// was added, so this should fail
		$response = API::userPut(
			self::$config['userID'],
			"items/$childKey",
			json_encode($json),
			array("If-Unmodified-Since-Version: " . $parentVersion)
		);
		$this->assert412($response);
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$childKey",
			json_encode($json),
			array("If-Unmodified-Since-Version: " . $childVersion)
		);
		$this->assert204($response);
		
		$json = API::getItem($childKey, $this, 'json')['data'];
		$this->assertArrayNotHasKey('parentItem', $json);
	}
	
	
	public function testParentItemPatch() {
		$json = API::createItem("book", false, $this, 'jsonData');
		$parentKey = $json['key'];
		$parentVersion = $json['version'];
		
		$json = API::createAttachmentItem("linked_url", [], $parentKey, $this, 'jsonData');
		$childKey = $json['key'];
		$childVersion = $json['version'];
		
		$this->assertArrayHasKey('parentItem', $json);
		$this->assertEquals($parentKey, $json['parentItem']);
		
		$json = array(
			'title' => 'Test'
		);
		
		// With PATCH, parent shouldn't be removed even though unspecified
		$response = API::userPatch(
			self::$config['userID'],
			"items/$childKey",
			json_encode($json),
			array("If-Unmodified-Since-Version: " . $childVersion)
		);
		$this->assert204($response);
		
		$json = API::getItem($childKey, $this, 'json')['data'];
		$this->assertArrayHasKey('parentItem', $json);
	}
	
	
	public function testUnicodeTitle() {
		$title = "Tést";
		
		$key = API::createItem("book", array("title" => $title), $this, 'key');
		
		// Test entry (JSON)
		$response = API::userGet(
			self::$config['userID'],
			"items/$key"
		);
		$this->assertContains('"title": "Tést"', $response->getBody());
		
		// Test feed (JSON)
		$response = API::userGet(
			self::$config['userID'],
			"items"
		);
		$this->assertContains('"title": "Tést"', $response->getBody());
		
		// Test entry (Atom)
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?content=json"
		);
		$this->assertContains('"title": "Tést"', $response->getBody());
		
		// Test feed (Atom)
		$response = API::userGet(
			self::$config['userID'],
			"items?content=json"
		);
		$this->assertContains('"title": "Tést"', $response->getBody());
	}
}
