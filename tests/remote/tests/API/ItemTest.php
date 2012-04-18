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
		$xml = API::createItem("book", $this);
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		
		$data = API::parseDataFromItemEntry($xml);
		
		$json = json_decode($data['content']);
		$this->assertEquals("book", (string) $json->itemType);
		
		return $data;
	}
	
	
	/**
	 * @depends testNewEmptyBookItem
	 */
	public function testEditBookItem($newItemData) {
		$key = $newItemData['key'];
		$etag = $newItemData['etag'];
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
				"If-Match: $etag"
			)
		);
		$this->assert200($response);
		
		$xml = API::getXMLFromResponse($response);
		$json = json_decode(array_shift($xml->xpath('/atom:entry/atom:content')));
		
		$this->assertEquals($newTitle, $json->title);
		$this->assertEquals($numPages, $json->numPages);
		$this->assertEquals($creatorType, $json->creators[0]->creatorType);
		$this->assertEquals($firstName, $json->creators[0]->firstName);
		$this->assertEquals($lastName, $json->creators[0]->lastName);
		
		return API::parseDataFromItemEntry($xml);
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
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromItemEntry($xml);
		$key = $data['key'];
		$etag = $data['etag'];
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
				"If-Match: $etag"
			)
		);
		$this->assert200($response);
		$json = json_decode(API::getContentFromResponse($response));
		$this->assertEquals("bookSection", $json->itemType);
		$this->assertEquals("Foo", $json->title);
		$this->assertObjectNotHasAttribute("numPages", $json);
	}
	
	
	public function testNewEmptyBookItemWithEmptyAttachmentItem() {
		$json = API::getItemTemplate("book");
		
		$response = API::get("items/new?itemType=attachment&linkMode=imported_url");
		$json->attachments[] = json_decode($response->getBody());
		
		$response = API::userPost(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert201($response);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(1, (int) array_shift($xml->xpath('//atom:entry/zapi:numChildren')));
	}
	
	
	public function testNewComputerProgramItem() {
		$xml = API::createItem("computerProgram", $this);
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		
		$data = API::parseDataFromItemEntry($xml);
		
		$json = json_decode($data['content']);
		$this->assertEquals("computerProgram", (string) $json->itemType);
		
		$version = "1.0";
		$json->version = $version;
		
		$response = API::userPut(
			self::$config['userID'],
			"items/{$data['key']}?key=" . self::$config['apiKey'],
			json_encode($json),
			array(
				"Content-Type: application/json",
				"If-Match: {$data['etag']}"
			)
		);
		$this->assert200($response);
		
		$xml = API::getXMLFromResponse($response);
		$json = json_decode(array_shift($xml->xpath('/atom:entry/atom:content')));
		
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
		$this->assert400($response);
		$this->assertEquals("'itemType' property not provided", $response->getBody());
		
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
		$this->assert400($response);
		$this->assertEquals("'contentType' is valid only for attachment items", $response->getBody());
		
		// more tests
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
		$this->assert201($response);
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
			$this->assert400($response);
			$this->assertEquals("Only file attachments and PDFs can be top-level items", $response->getBody());
		}
	}
	
	
	public function testNewEmptyLinkAttachmentItem() {
		$xml = API::createItem("book", $this);
		$data = API::parseDataFromItemEntry($xml);
		
		$xml = API::createAttachmentItem("linked_url", $data['key'], $this);
		return API::parseDataFromItemEntry($xml);
	}
	
	
	public function testNewEmptyImportedURLAttachmentItem() {
		$xml = API::createItem("book", $this);
		$data = API::parseDataFromItemEntry($xml);
		
		$xml = API::createAttachmentItem("imported_url", $data['key'], $this);
		return API::parseDataFromItemEntry($xml);
	}
	
	
	/**
	 * @depends testNewEmptyLinkAttachmentItem
	 */
	public function testEditEmptyLinkAttachmentItem($newItemData) {
		$key = $newItemData['key'];
		$etag = $newItemData['etag'];
		$json = json_decode($newItemData['content']);
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'],
			json_encode($json),
			array(
				"Content-Type: application/json",
				"If-Match: $etag"
			)
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$newETag = (string) array_shift($xml->xpath('/atom:entry/atom:content/@zapi:etag'));
		// Item shouldn't change
		$this->assertEquals($etag, $newETag);
		
		return $newItemData;
	}
	
	
	/**
	 * @depends testNewEmptyImportedURLAttachmentItem
	 */
	public function testEditEmptyImportedURLAttachmentItem($newItemData) {
		$key = $newItemData['key'];
		$etag = $newItemData['etag'];
		$json = json_decode($newItemData['content']);
		
		$response = API::userPut(
			self::$config['userID'],
			"items/$key?key=" . self::$config['apiKey'],
			json_encode($json),
			array(
				"Content-Type: application/json",
				"If-Match: $etag"
			)
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$newETag = (string) array_shift($xml->xpath('/atom:entry/atom:content/@zapi:etag'));
		// Item shouldn't change
		$this->assertEquals($etag, $newETag);
		
		return $newItemData;
	}
	
	
	/**
	 * @depends testEditEmptyLinkAttachmentItem
	 */
	public function testEditLinkAttachmentItem($newItemData) {
		$key = $newItemData['key'];
		$etag = $newItemData['etag'];
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
				"If-Match: $etag"
			)
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$data = API::parseDataFromItemEntry($xml);
		$json = json_decode($data['content']);
		$this->assertEquals($contentType, $json->contentType);
		$this->assertEquals($charset, $json->charset);
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
		$this->assert400($response);
		$this->assertEquals("'invalidName' is not a valid linkMode", $response->getBody());
		
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
		$this->assert400($response);
		$this->assertEquals("'linkMode' property not provided", $response->getBody());
	}
	
	
	/**
	 * @depends testNewEmptyBookItem
	 */
	public function testNewAttachmentItemMD5OnLinkedURL($newItemData) {
		$parentKey = $newItemData['key'];
		
		$response = API::get("items/new?itemType=attachment&linkMode=linked_url");
		$json = json_decode($response->getBody());
		
		$json->md5 = "c7487a750a97722ae1878ed46b215ebe";
		$response = API::userPost(
			self::$config['userID'],
			"items/$parentKey/children?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400($response);
		$this->assertEquals("'md5' is valid only for imported attachment items", $response->getBody());
	}
	
	
	/**
	 * @depends testNewEmptyBookItem
	 */
	public function testNewAttachmentItemModTimeOnLinkedURL($newItemData) {
		$parentKey = $newItemData['key'];
		
		$response = API::get("items/new?itemType=attachment&linkMode=linked_url");
		$json = json_decode($response->getBody());
		
		$json->mtime = "1332807793000";
		$response = API::userPost(
			self::$config['userID'],
			"items/$parentKey/children?key=" . self::$config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400($response);
		$this->assertEquals("'mtime' is valid only for imported attachment items", $response->getBody());
	}
	
	
	public function testNewEmptyImportedURLAttachmentItemGroup() {
		$xml = API::groupCreateItem(
			self::$config['ownedPrivateGroupID'], "book", $this
		);
		$data = API::parseDataFromItemEntry($xml);
		
		$xml = API::groupCreateAttachmentItem(
			self::$config['ownedPrivateGroupID'], "imported_url", $data['key'], $this
		);
		return API::parseDataFromItemEntry($xml);
	}
	
	
	/**
	 * @depends testNewEmptyImportedURLAttachmentItemGroup
	 */
	public function testEditImportedURLAttachmentItemGroup($newItemData) {
		$key = $newItemData['key'];
		$etag = $newItemData['etag'];
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
					"If-Match: $etag"
				)
			);
			$this->assert400($response);
			$this->assertEquals("Cannot change '$prop' directly in group library", $response->getBody());
		}
	}
}
