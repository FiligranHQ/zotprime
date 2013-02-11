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
		$xml = API::createAttachmentItem("linked_url", $key, $this, 'atom');
		return API::parseDataFromAtomEntry($xml);
	}
	
	
	public function testNewEmptyImportedURLAttachmentItem() {
		$key = API::createItem("book", false, $this, 'key');
		$xml = API::createAttachmentItem("imported_url", $key, $this, 'atom');
		return API::parseDataFromAtomEntry($xml);
	}
	
	
	public function testEditEmptyLinkAttachmentItem() {
		$key = API::createItem("book", false, $this, 'key');
		$xml = API::createAttachmentItem("linked_url", $key, $this, 'atom');
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
		$xml = API::createAttachmentItem("linked_file", false, $this);
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
			self::$config['ownedPrivateGroupID'], "imported_url", $key, $this
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
	
	
	public function testNumChildren() {
		$xml = API::createItem("book", false, $this);
		$this->assertEquals(0, (int) array_shift($xml->xpath('/atom:entry/zapi:numChildren')));
		$data = API::parseDataFromAtomEntry($xml);
		$key = $data['key'];
		
		API::createAttachmentItem("linked_url", $key, $this, 'key');
		
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
	
	
	public function testParentItem() {
		$xml = API::createItem("book", false, $this);
		$data = API::parseDataFromAtomEntry($xml);
		$json = json_decode($data['content'], true);
		$parentKey = $data['key'];
		$parentVersion = $data['version'];
		
		$xml = API::createAttachmentItem("linked_url", $parentKey, $this);
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
		
		$xml = API::createAttachmentItem("linked_url", $parentKey, $this);
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
}
