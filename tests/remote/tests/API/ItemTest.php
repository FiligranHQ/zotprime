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
		$xml = API::createItem("book", false, $this);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		$this->assertEquals("book", (string) $json->itemType);
		return $data;
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
		
		$xml = API::getItemXML($json['success'], $this);
		$contents = $xml->xpath('/atom:feed/atom:entry/atom:content');
		
		$content = json_decode(array_shift($contents));
		$this->assertEquals("A", $content->title);
		$content = json_decode(array_shift($contents));
		$this->assertEquals("B", $content->title);
		$content = json_decode(array_shift($contents));
		$this->assertEquals("C", $content->title);
	}
	
	
	/**
	 * @depends testNewEmptyBookItem
	 */
	public function testEditBookItem($newItemData) {
		$key = $newItemData['key'];
		$version = $newItemData['version'];
		$json = json_decode($newItemData['content']);
		
		$newTitle = "New Title";
		$numPages = 100;
		$creatorType = "author";
		$firstName = "Firstname";
		$lastName = "Lastname";
		
		$json->title = $newTitle;
		$json->numPages = $numPages;
		$json->creators[] = array(
			'creatorType' => $creatorType,
			'firstName' => $firstName,
			'lastName' => $lastName
		);
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'],
			json_encode($json),
			array(
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: $version"
			)
		);
		$this->assert204($response);
		$xml = API::getItemXML($key);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		
		$this->assertEquals($newTitle, $json->title);
		$this->assertEquals($numPages, $json->numPages);
		$this->assertEquals($creatorType, $json->creators[0]->creatorType);
		$this->assertEquals($firstName, $json->creators[0]->firstName);
		$this->assertEquals($lastName, $json->creators[0]->lastName);
		
		return API::parseDataFromAtomEntry($xml);
	}
	
	
	public function testDateAdded() {
		// In case this is ever extended to other objects
		$objectType = 'item';
		$objectTypePlural = API::getPluralObjectType($objectType);
		
		switch ($objectType) {
		case 'item':
			$itemData = array(
				"title" => "Test"
			);
			$xml = API::createItem("videoRecording", $itemData, $this, 'atom');
			break;
		}
		
		$newDateAdded = "2013-03-03 21:33:53";
		
		$data = API::parseDataFromAtomEntry($xml);
		$objectKey = $data['key'];
		$json = json_decode($data['content'], true);
		
		$json['title'] = "Test 2";
		$json['dateAdded'] = $newDateAdded;
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey'],
			json_encode($json)
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
			$xml = API::createItem("videoRecording", $itemData, $this, 'atom');
			break;
		}
		
		$data = API::parseDataFromAtomEntry($xml);
		$objectKey = $data['key'];
		$json = json_decode($data['content'], true);
		$dateModified1 = (string) array_shift($xml->xpath('//atom:entry/atom:updated'));
		
		// Make sure we're in the next second
		sleep(1);
		
		//
		// If no explicit dateModified, use current timestamp
		//
		$json['title'] = "Test 2";
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey'],
			json_encode($json)
		);
		$this->assert204($response);
		
		switch ($objectType) {
		case 'item':
			$xml = API::getItemXML($objectKey);
			break;
		}
		
		$dateModified2 = (string) array_shift($xml->xpath('//atom:entry/atom:updated'));
		$this->assertNotEquals($dateModified1, $dateModified2);
		$json = json_decode(API::parseDataFromAtomEntry($xml)['content'], true);
		
		// Make sure we're in the next second
		sleep(1);
		
		//
		// If existing dateModified, use current timestamp
		//
		$json['title'] = "Test 3";
		$json['dateModified'] = trim(preg_replace("/[TZ]/", " ", $dateModified2));
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey'],
			json_encode($json)
		);
		$this->assert204($response);
		
		switch ($objectType) {
		case 'item':
			$xml = API::getItemXML($objectKey);
			break;
		}
		
		$dateModified3 = (string) array_shift($xml->xpath('//atom:entry/atom:updated'));
		$this->assertNotEquals($dateModified2, $dateModified3);
		$json = json_decode(API::parseDataFromAtomEntry($xml)['content'], true);
		
		//
		// If explicit dateModified, use that
		//
		$newDateModified = "2013-03-03 21:33:53";
		$json['title'] = "Test 4";
		$json['dateModified'] = $newDateModified;
		$response = API::userPut(
			self::$config['userID'],
			"$objectTypePlural/$objectKey?key=" . self::$config['apiKey'],
			json_encode($json)
		);
		$this->assert204($response);
		
		switch ($objectType) {
		case 'item':
			$xml = API::getItemXML($objectKey);
			break;
		}
		$dateModified4 = (string) array_shift($xml->xpath('//atom:entry/atom:updated'));
		$this->assertEquals($newDateModified, trim(preg_replace("/[TZ]/", " ", $dateModified4)));
	}
	
	
	public function testChangeItemType() {
		$json = API::getItemTemplate("book");
		$json->title = "Foo";
		$json->numPages = 100;
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$key = API::getFirstSuccessKeyFromResponse($response);
		$xml = API::getItemXML($key, $this);
		$data = API::parseDataFromAtomEntry($xml);
		$version = $data['version'];
		$json1 = json_decode($data['content']);
		
		$json2 = API::getItemTemplate("bookSection");
		unset($json2->attachments);
		unset($json2->notes);
		
		foreach ($json2 as $field => &$val) {
			if ($field != "itemType" && isset($json1->$field)) {
				$val = $json1->$field;
			}
		}
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'],
			json_encode($json2),
			array(
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: $version"
			)
		);
		$this->assert204($response);
		$xml = API::getItemXML($key);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		$this->assertEquals("bookSection", $json->itemType);
		$this->assertEquals("Foo", $json->title);
		$this->assertObjectNotHasAttribute("numPages", $json);
	}
	
	
	//
	// PATCH
	//
	public function testModifyItemPartial() {
		$itemData = array(
			"title" => "Test"
		);
		$xml = API::createItem("book", $itemData, $this, 'atom');
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		$itemVersion = $json->itemVersion;
		
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
			$xml = API::getItemXML($itemKey);
			$data = API::parseDataFromAtomEntry($xml);
			$json = json_decode($data['content'], true);
			
			foreach ($itemData as $field => $val) {
				$context->assertEquals($val, $json[$field]);
			}
			$headerVersion = $response->getHeader("Last-Modified-Version");
			$context->assertGreaterThan($itemVersion, $headerVersion);
			$context->assertEquals($json['itemVersion'], $headerVersion);
			
			return $headerVersion;
		};
		
		$newData = array(
			"date" => "2013"
		);
		$itemVersion = $patch($this, self::$config, $data['key'], $itemVersion, $itemData, $newData);
		
		$newData = array(
			"title" => ""
		);
		$itemVersion = $patch($this, self::$config, $data['key'], $itemVersion, $itemData, $newData);
		
		$newData = array(
			"tags" => array(
				array(
					"tag" => "Foo"
				)
			)
		);
		$itemVersion = $patch($this, self::$config, $data['key'], $itemVersion, $itemData, $newData);
		
		$newData = array(
			"tags" => array()
		);
		$itemVersion = $patch($this, self::$config, $data['key'], $itemVersion, $itemData, $newData);
		
		$key = API::createCollection('Test', false, $this, 'key');
		$newData = array(
			"collections" => array($key)
		);
		$itemVersion = $patch($this, self::$config, $data['key'], $itemVersion, $itemData, $newData);
		
		$newData = array(
			"collections" => array()
		);
		$itemVersion = $patch($this, self::$config, $data['key'], $itemVersion, $itemData, $newData);
	}
	
	
	public function testNewComputerProgramItem() {
		$xml = API::createItem("computerProgram", false, $this);
		$data = API::parseDataFromAtomEntry($xml);
		$key = $data['key'];
		$json = json_decode($data['content']);
		$this->assertEquals("computerProgram", (string) $json->itemType);
		
		$version = "1.0";
		$json->version = $version;
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'],
			json_encode($json),
			array(
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: {$data['version']}"
			)
		);
		$this->assert204($response);
		$xml = API::getItemXML($key);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		
		$this->assertEquals($version, $json->version);
	}
	
	
	public function testNewInvalidBookItem() {
		$json = API::getItemTemplate("book");
		
		// Missing item type
		$json2 = clone $json;
		unset($json2->itemType);
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json2)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject($response, "'itemType' property not provided");
		
		// contentType on non-attachment
		$json2 = clone $json;
		$json2->contentType = "text/html";
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json2)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject($response, "'contentType' is valid only for attachment items");
		
		// more tests
	}
	
	
	public function testEditTopLevelNote() {
		$xml = API::createNoteItem("Test", null, $this, 'atom');
		$data = API::parseDataFromAtomEntry($xml);
		$response = API::userPut(
			self::$config['userID'],
			"items/{$data['key']}?key=" . self::$config['apiKey'],
			$data['content']
		);
		$this->assert204($response);
	}
	
	
	public function testEditTitleWithCollectionInMultipleMode() {
		$collectionKey = API::createCollection('Test', false, $this, 'key');
		
		$xml = API::createItem("book", [
			"title" => "A",
			"collections" => [
				$collectionKey
			]
		], $this, 'atom');
		
		$data = API::parseDataFromAtomEntry($xml);
		$data = json_decode($data['content'], true);
		$version = $data['itemVersion'];
		$data['title'] = "B";
		
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode([
				"items" => [$data]
			])
		);
		$this->assert200ForObject($response);
		
		$xml = API::getItemXML($data['itemKey']);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$this->assertEquals("B", $json['title']);
		$this->assertGreaterThan($version, $json['itemVersion']);
	}
	
	
	public function testNewTopLevelImportedFileAttachment() {
		$response = API::get("items/new?itemType=attachment&linkMode=imported_file");
		$json = json_decode($response->getBody());
		
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
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
				"items?key=" . self::$config['apiKey'],
				json_encode(array(
					"items" => array($json)
				)),
				array("Content-Type: application/json")
			);
			$this->assert400ForObject($response, "Only file attachments and PDFs can be top-level items");
		}
	}
	
	
	public function testNewEmptyLinkAttachmentItem() {
		$key = API::createItem("book", false, $this, 'key');
		$xml = API::createAttachmentItem("linked_url", [], $key, $this, 'atom');
		return API::parseDataFromAtomEntry($xml);
	}
	
	
	public function testNewEmptyLinkAttachmentItemWithItemKey() {
		$key = API::createItem("book", false, $this, 'key');
		$xml = API::createAttachmentItem("linked_url", [], $key, $this, 'atom');
		
		$response = API::get("items/new?itemType=attachment&linkMode=linked_url");
		$json = json_decode($response->getBody());
		$json->parentItem = $key;
		require_once '../../model/Utilities.inc.php';
		require_once '../../model/ID.inc.php';
		$json->itemKey = Zotero_ID::getKey();
		$json->itemVersion = 0;
		
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert200ForObject($response);
	}
	
	
	public function testNewEmptyImportedURLAttachmentItem() {
		$key = API::createItem("book", false, $this, 'key');
		$xml = API::createAttachmentItem("imported_url", [], $key, $this, 'atom');
		return API::parseDataFromAtomEntry($xml);
	}
	
	
	public function testEditEmptyLinkAttachmentItem() {
		$key = API::createItem("book", false, $this, 'key');
		$xml = API::createAttachmentItem("linked_url", [], $key, $this, 'atom');
		$data = API::parseDataFromAtomEntry($xml);
		
		$key = $data['key'];
		$version = $data['version'];
		$json = json_decode($data['content']);
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'],
			json_encode($json),
			array(
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: $version"
			)
		);
		$this->assert204($response);
		$xml = API::getItemXML($key);
		$data = API::parseDataFromAtomEntry($xml);
		// Item shouldn't change
		$this->assertEquals($version, $data['version']);
		
		return $data;
	}
	
	
	/**
	 * @depends testNewEmptyImportedURLAttachmentItem
	 */
	public function testEditEmptyImportedURLAttachmentItem($newItemData) {
		$key = $newItemData['key'];
		$version = $newItemData['version'];
		$json = json_decode($newItemData['content']);
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'],
			json_encode($json),
			array(
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: $version"
			)
		);
		$this->assert204($response);
		$xml = API::getItemXML($key);
		$data = API::parseDataFromAtomEntry($xml);
		// Item shouldn't change
		$this->assertEquals($version, $data['version']);
		
		return $newItemData;
	}
	
	
	/**
	 * @depends testEditEmptyLinkAttachmentItem
	 */
	public function testEditLinkAttachmentItem($newItemData) {
		$key = $newItemData['key'];
		$version = $newItemData['version'];
		$json = json_decode($newItemData['content']);
		
		$contentType = "text/xml";
		$charset = "utf-8";
		
		$json->contentType = $contentType;
		$json->charset = $charset;
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'],
			json_encode($json),
			array(
				"Content-Type: application/json",
				"If-Unmodified-Since-Version: $version"
			)
		);
		$this->assert204($response);
		$xml = API::getItemXML($key);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		$this->assertEquals($contentType, $json->contentType);
		$this->assertEquals($charset, $json->charset);
	}
	
	
	public function testEditAttachmentUpdatedTimestamp() {
		$xml = API::createAttachmentItem("linked_file", [], false, $this);
		$data = API::parseDataFromAtomEntry($xml);
		$atomUpdated = (string) array_shift($xml->xpath('//atom:entry/atom:updated'));
		$json = json_decode($data['content'], true);
		$json['note'] = "Test";
		
		sleep(1);
		
		$response = API::userPut(
			self::$config['userID'],
			"items/{$data['key']}?key=" . self::$config['apiKey'],
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
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject($response, "'invalidName' is not a valid linkMode");
		
		// Missing linkMode
		unset($json->linkMode);
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject($response, "'linkMode' property not provided");
	}
	
	
	/**
	 * @depends testNewEmptyBookItem
	 */
	public function testNewAttachmentItemMD5OnLinkedURL($newItemData) {
		$parentKey = $newItemData['key'];
		
		$response = API::get("items/new?itemType=attachment&linkMode=linked_url");
		$json = json_decode($response->getBody());
		$json->parentItem = $parentKey;
		
		$json->md5 = "c7487a750a97722ae1878ed46b215ebe";
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject($response, "'md5' is valid only for imported attachment items");
	}
	
	
	/**
	 * @depends testNewEmptyBookItem
	 */
	public function testNewAttachmentItemModTimeOnLinkedURL($newItemData) {
		$parentKey = $newItemData['key'];
		
		$response = API::get("items/new?itemType=attachment&linkMode=linked_url");
		$json = json_decode($response->getBody());
		$json->parentItem = $parentKey;
		
		$json->mtime = "1332807793000";
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400ForObject($response, "'mtime' is valid only for imported attachment items");
	}
	
	
	public function testNewEmptyImportedURLAttachmentItemGroup() {
		$key = API::groupCreateItem(
			self::$config['ownedPrivateGroupID'], "book", $this, 'key'
		);
		$xml = API::groupCreateAttachmentItem(
			self::$config['ownedPrivateGroupID'], "imported_url", [], $key, $this
		);
		return API::parseDataFromAtomEntry($xml);
	}
	
	
	/**
	 * @depends testNewEmptyImportedURLAttachmentItemGroup
	 */
	public function testEditImportedURLAttachmentItemGroup($newItemData) {
		$key = $newItemData['key'];
		$version = $newItemData['version'];
		$json = json_decode($newItemData['content']);
		
		$props = array("contentType", "charset", "filename", "md5", "mtime");
		foreach ($props as $prop) {
			$json2 = clone $json;
			$json2->$prop = "new" . ucwords($prop);
			$response = API::groupPut(
				self::$config['ownedPrivateGroupID'],
				"items/$key?key=" . self::$config['apiKey'],
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
		$json = array(
			"items" => array(
				array(
					'itemType' => 'presentation',
					'title' => 'Test',
					'creators' => array(
						array(
							"creatorType" => "author",
							"name" => "Foo"
						)
					)
				),
				array(
					'itemType' => 'presentation',
					'title' => 'Test',
					'creators' => array(
						array(
							"creatorType" => "editor",
							"name" => "Foo"
						)
					)
				)
			)
		);
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode($json)
		);
		// 'author' gets mapped automatically
		$this->assert200ForObject($response);
		// Others don't
		$this->assert400ForObject($response, false, 1);
	}
	
	
	public function testNumChildren() {
		$xml = API::createItem("book", false, $this);
		$this->assertEquals(0, (int) array_shift($xml->xpath('/atom:entry/zapi:numChildren')));
		$data = API::parseDataFromAtomEntry($xml);
		$key = $data['key'];
		
		API::createAttachmentItem("linked_url", [], $key, $this, 'key');
		
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:entry/zapi:numChildren')));
		
		API::createNoteItem("Test", $key, $this, 'key');
		
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(2, (int) array_shift($xml->xpath('/atom:entry/zapi:numChildren')));
	}
	
	
	public function testTop() {
		API::userClear(self::$config['userID']);
		
		$collectionKey = API::createCollection('Test', false, $this, 'key');
		
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
		
		// /top, Atom
		$response = API::userGet(
			self::$config['userID'],
			"items/top?key=" . self::$config['apiKey'] . "&content=json"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($parentKeys), $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertCount(sizeOf($parentKeys), $xpath);
		foreach ($parentKeys as $parentKey) {
			$this->assertContains($parentKey, $xpath);
		}
		
		// /top, Atom, in collection
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top?key=" . self::$config['apiKey'] . "&content=json"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertCount(1, $xpath);
		$this->assertContains($parentKeys[0], $xpath);
		
		// /top, keys
		$response = API::userGet(
			self::$config['userID'],
			"items/top?key=" . self::$config['apiKey'] . "&format=keys"
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
			"collections/$collectionKey/items/top?key=" . self::$config['apiKey'] . "&format=keys"
		);
		$this->assert200($response);
		$this->assertEquals($parentKeys[0], trim($response->getBody()));
		
		// /top with itemKey for parent, Atom
		$response = API::userGet(
			self::$config['userID'],
			"items/top?key=" . self::$config['apiKey'] . "&content=json&itemKey=" . $parentKeys[0]
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertEquals($parentKeys[0], (string) array_shift($xpath));
		
		// /top with itemKey for parent, Atom, in collection
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top?key=" . self::$config['apiKey']
				. "&content=json&itemKey=" . $parentKeys[0]
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertEquals($parentKeys[0], (string) array_shift($xpath));
		
		// /top with itemKey for parent, keys
		$response = API::userGet(
			self::$config['userID'],
			"items/top?key=" . self::$config['apiKey'] . "&format=keys&itemKey=" . $parentKeys[0]
		);
		$this->assert200($response);
		$this->assertEquals($parentKeys[0], trim($response->getBody()));
		
		// /top with itemKey for parent, keys, in collection
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top?key=" . self::$config['apiKey']
				. "&format=keys&itemKey=" . $parentKeys[0]
		);
		$this->assert200($response);
		$this->assertEquals($parentKeys[0], trim($response->getBody()));
		
		// /top with itemKey for child, Atom
		$response = API::userGet(
			self::$config['userID'],
			"items/top?key=" . self::$config['apiKey'] . "&content=json&itemKey=" . $childKeys[0]
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertEquals($parentKeys[0], (string) array_shift($xpath));
		
		// /top with itemKey for child, keys
		$response = API::userGet(
			self::$config['userID'],
			"items/top?key=" . self::$config['apiKey'] . "&format=keys&itemKey=" . $childKeys[0]
		);
		$this->assert200($response);
		$this->assertEquals($parentKeys[0], trim($response->getBody()));
		
		// /top, Atom, with q for all items
		$response = API::userGet(
			self::$config['userID'],
			"items/top?key=" . self::$config['apiKey'] . "&content=json&q=$parentTitleSearch"
		);
		$this->assert200($response);
		$this->assertNumResults(sizeOf($parentKeys), $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertCount(sizeOf($parentKeys), $xpath);
		foreach ($parentKeys as $parentKey) {
			$this->assertContains($parentKey, $xpath);
		}
		
		// /top, Atom, in collection, with q for all items
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top?key=" . self::$config['apiKey']
				. "&content=json&q=$parentTitleSearch"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertCount(1, $xpath);
		$this->assertContains($parentKeys[0], $xpath);
		
		// /top, Atom, with q for child item
		$response = API::userGet(
			self::$config['userID'],
			"items/top?key=" . self::$config['apiKey'] . "&content=json&q=$childTitleSearch"
		);
		$this->assert200($response);
		$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertCount(1, $xpath);
		$this->assertContains($parentKeys[0], $xpath);
		
		// /top, Atom, in collection, with q for child item
		$response = API::userGet(
			self::$config['userID'],
			"collections/$collectionKey/items/top?key=" . self::$config['apiKey']
				. "&content=json&q=$childTitleSearch"
		);
		$this->assert200($response);
		$this->assertNumResults(0, $response);
		// Not currently possible
		/*$this->assertNumResults(1, $response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertCount(1, $xpath);
		$this->assertContains($parentKeys[0], $xpath);*/
		
		// /top, Atom, with q for all items, ordered by title
		$response = API::userGet(
			self::$config['userID'],
			"items/top?key=" . self::$config['apiKey'] . "&content=json&q=$parentTitleSearch"
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
			"items/top?key=" . self::$config['apiKey'] . "&content=json&q=$parentTitleSearch"
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
		
		// /top, Atom, with q for all items, ordered by date desc
		$response = API::userGet(
			self::$config['userID'],
			"items/top?key=" . self::$config['apiKey'] . "&content=json&q=$parentTitleSearch"
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
			"items/top?key=" . self::$config['apiKey'] . "&content=json&q=$parentTitleSearch"
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
			"items/top?key=" . self::$config['apiKey'] . "&content=json&q=$parentTitleSearch"
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
	
	
	public function testParentItem() {
		$xml = API::createItem("book", false, $this);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$parentKey = $data['key'];
		$parentVersion = $data['version'];
		
		$xml = API::createAttachmentItem("linked_url", [], $parentKey, $this);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$childKey = $data['key'];
		$childVersion = $data['version'];
		
		$this->assertArrayHasKey('parentItem', $json);
		$this->assertEquals($parentKey, $json['parentItem']);
		
		// Remove the parent, making the child a standalone attachment
		unset($json['parentItem']);
		
		// The parent item version should have been updated when a child
		// was added, so this should fail
		$response = API::userPut(
			self::$config['userID'],
			"items/$childKey?key=" . self::$config['apiKey'],
			json_encode($json),
			array("If-Unmodified-Since-Version: " . $parentVersion)
		);
		$this->assert412($response);
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$childKey?key=" . self::$config['apiKey'],
			json_encode($json),
			array("If-Unmodified-Since-Version: " . $childVersion)
		);
		$this->assert204($response);
		
		$xml = API::getItemXML($childKey);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$this->assertArrayNotHasKey('parentItem', $json);
	}
	
	
	public function testParentItemPatch() {
		$xml = API::createItem("book", false, $this);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$parentKey = $data['key'];
		$parentVersion = $data['version'];
		
		$xml = API::createAttachmentItem("linked_url", [], $parentKey, $this);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$childKey = $data['key'];
		$childVersion = $data['version'];
		
		$this->assertArrayHasKey('parentItem', $json);
		$this->assertEquals($parentKey, $json['parentItem']);
		
		$json = array(
			'title' => 'Test'
		);
		
		// With PATCH, parent shouldn't be removed even though unspecified
		$response = API::userPatch(
			self::$config['userID'],
			"items/$childKey?key=" . self::$config['apiKey'],
			json_encode($json),
			array("If-Unmodified-Since-Version: " . $childVersion)
		);
		$this->assert204($response);
		
		$xml = API::getItemXML($childKey);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$this->assertArrayHasKey('parentItem', $json);
	}
	
	
	public function testDate() {
		$date = "Sept 18, 2012";
		
		$xml = API::createItem("book", array(
			"date" => $date
		), $this);
		$data = API::parseDataFromAtomEntry($xml);
		$key = $data['key'];
		
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content']);
		$this->assertEquals($date, $json->date);
	}
	
	
	public function testUnicodeTitle() {
		$title = "Tést";
		
		$xml = API::createItem("book", array("title" => $title), $this);
		$data = API::parseDataFromAtomEntry($xml);
		$key = $data['key'];
		
		// Test entry
		$response = API::userGet(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'] . "&content=json"
		);
		$this->assertContains('"title":"Tést"', $response->getBody());
		
		// Test feed
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'] . "&content=json"
		);
		$this->assertContains('"title":"Tést"', $response->getBody());
	}
}
