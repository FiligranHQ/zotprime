<?php
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2015 Center for History and New Media
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
use API3 as API, HTTP, Z_Tests;
require_once 'APITests.inc.php';
require_once 'include/api3.inc.php';

class PublicationsTests extends APITests {
	private static $toDelete = [];
	
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		
		$s3Client = Z_Tests::$AWS->createS3();
		foreach (self::$toDelete as $file) {
			try {
				$s3Client->deleteObject([
					'Bucket' => self::$config['s3Bucket'],
					'Key' => $file
				]);
			}
			catch (\Aws\S3\Exception\S3Exception $e) {
				if ($e->getAwsErrorCode() == 'NoSuchKey') {
					echo "\n$file not found on S3 to delete\n";
				}
				else {
					throw $e;
				}
			}
		}
		
		API::userClear(self::$config['userID']);
	}
	
	public function setUp() {
		parent::setUp();
		
		API::userClear(self::$config['userID']);
		
		// Default to anonymous requests
		API::useAPIKey("");
	}
	
	
	//
	// Test read requests for empty publications list
	//
	public function test_should_return_no_results_for_empty_publications_list() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/items");
		$this->assert200($response);
		$this->assertNoResults($response);
		$this->assertInternalType("numeric", $response->getHeader('Last-Modified-Version'));
	}
	
	
	public function test_should_return_no_results_for_empty_publications_list_with_key() {
		API::useAPIKey(self::$config['apiKey']);
		$response = API::get("users/" . self::$config['userID'] . "/publications/items");
		$this->assert200($response);
		$this->assertNoResults($response);
		$this->assertInternalType("numeric", $response->getHeader('Last-Modified-Version'));
	}
	
	
	public function test_should_return_no_atom_results_for_empty_publications_list() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/items?format=atom");
		$this->assert200($response);
		$this->assertNoResults($response);
		$this->assertInternalType("numeric", $response->getHeader('Last-Modified-Version'));
	}
	
	
	public function test_should_return_304_for_request_with_etag() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/items");
		$this->assert200($response);
		$etag = $response->getHeader("ETag");
		$this->assertNotNull($etag);
		
		// Repeat request with ETag
		$response = API::get(
			"users/" . self::$config['userID'] . "/publications/items",
			[
				"If-None-Match: $etag"
			]
		);
		$this->assert304($response);
		$this->assertEquals($etag, $response->getHeader("ETag"));
	}
	
	
	// Disabled until after integrated My Publications upgrade
	/*public function test_should_return_404_for_settings_request() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/settings");
		$this->assert404($response);
	}*/
	public function test_should_return_200_for_settings_request_with_no_items() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/settings");
		$this->assert200($response);
		$this->assertNoResults($response);
	}
	public function test_should_return_400_for_settings_request_with_items() {
		API::useAPIKey(self::$config['apiKey']);
		$response = API::createItem("book", ['inPublications' => true], $this, 'response');
		$this->assert200ForObject($response);
		
		$response = API::get("users/" . self::$config['userID'] . "/publications/settings");
		$this->assert400($response);
	}
	
	// Disabled until after integrated My Publications upgrade
	/*public function test_should_return_404_for_deleted_request() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/deleted?since=0");
		$this->assert404($response);
	}*/
	public function test_should_return_200_for_deleted_request() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/deleted?since=0");
		$this->assert200($response);
	}
	
	public function test_should_return_404_for_collections_request() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/collections");
		$this->assert404($response);
	}
	
	
	public function test_should_return_404_for_searches_request() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/searches");
		$this->assert404($response);
	}
	
	
	public function test_should_return_403_for_anonymous_write() {
		$json = API::getItemTemplate("book");
		$response = API::userPost(
			self::$config['userID'],
			"publications/items",
			json_encode($json)
		);
		$this->assert403($response);
	}
	
	
	public function test_should_return_405_for_authenticated_write() {
		API::useAPIKey(self::$config['apiKey']);
		$json = API::getItemTemplate("book");
		$response = API::userPost(
			self::$config['userID'],
			"publications/items",
			json_encode($json)
		);
		$this->assert405($response);
	}
	
	
	public function test_should_return_404_for_anonymous_request_for_item_not_in_publications() {
		// Create item
		API::useAPIKey(self::$config['apiKey']);
		$key = API::createItem("book", [], $this, 'key');
		
		// Fetch anonymously
		API::useAPIKey();
		$response = API::get("users/" . self::$config['userID'] . "/publications/items/$key");
		$this->assert404($response);
	}
	
	
	public function test_should_return_404_for_authenticated_request_for_item_not_in_publications() {
		// Create item
		API::useAPIKey(self::$config['apiKey']);
		$key = API::createItem("book", [], $this, 'key');
		
		// Fetch anonymously
		$response = API::get("users/" . self::$config['userID'] . "/publications/items/$key");
		$this->assert404($response);
	}
	
	
	public function test_should_trigger_notification_on_publications_topic() {
		// Create item
		API::useAPIKey(self::$config['apiKey']);
		$response = API::createItem("book", ['inPublications' => true], $this, 'response');
		
		// Test notification for publications topic (in addition to regular library)
		$this->assertCountNotifications(2, $response);
		$this->assertHasNotification([
			'event' => 'topicUpdated',
			'topic' => '/users/' . self::$config['userID']
		], $response);
		$this->assertHasNotification([
			'event' => 'topicUpdated',
			'topic' => '/users/' . self::$config['userID'] . '/publications'
		], $response);
		
		$json = API::getJSONFromResponse($response);
	}
	
	
	public function test_should_show_item_for_anonymous_single_object_request() {
		// Create item
		API::useAPIKey(self::$config['apiKey']);
		$itemKey = API::createItem("book", ['inPublications' => true], $this, 'key');
		
		// Read item anonymously
		API::useAPIKey("");
		
		// JSON
		$response = API::userGet(
			self::$config['userID'],
			"publications/items/$itemKey"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals(self::$config['username'], $json['library']['name']);
		$this->assertEquals("user", $json['library']['type']);
		
		// Atom
		$response = API::userGet(
			self::$config['userID'],
			"publications/items/$itemKey?format=atom"
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(self::$config['username'], (string) $xml->author->name);
	}
	
	
	public function test_should_show_item_for_anonymous_multi_object_request() {
		// Create item
		API::useAPIKey(self::$config['apiKey']);
		$itemKey = API::createItem("book", ['inPublications' => true], $this, 'key');
		
		// Read item anonymously
		API::useAPIKey("");
		
		// JSON
		$response = API::userGet(
			self::$config['userID'],
			"publications/items"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertContains($itemKey, array_map(function ($item) {
			return $item['key'];
		}, $json));
		
		// Atom
		$response = API::userGet(
			self::$config['userID'],
			"publications/items?format=atom"
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$xpath = $xml->xpath('//atom:entry/zapi:key');
		$this->assertContains($itemKey, $xpath);
	}
	
	
	public function test_shouldnt_show_child_item_not_in_publications() {
		// Create parent item
		API::useAPIKey(self::$config['apiKey']);
		$parentItemKey = API::createItem("book", ['title' => 'A', 'inPublications' => true], $this, 'key');
		
		// Create shown child attachment
		$json1 = API::getItemTemplate("attachment&linkMode=imported_file");
		$json1->title = 'B';
		$json1->parentItem = $parentItemKey;
		$json1->inPublications = true;
		// Create hidden child attachment
		$json2 = API::getItemTemplate("attachment&linkMode=imported_file");
		$json2->title = 'C';
		$json2->parentItem = $parentItemKey;
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json1, $json2])
		);
		$this->assert200($response);
		
		// Anonymous read
		API::useAPIKey("");
		
		$response = API::userGet(
			self::$config['userID'],
			"publications/items"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertCount(2, $json);
		$titles = array_map(function ($item) {
			return $item['data']['title'];
		}, $json);
		$this->assertContains('A', $titles);
		$this->assertContains('B', $titles);
		$this->assertNotContains('C', $titles);
	}
	
	
	public function test_shouldnt_show_child_item_not_in_publications_for_item_children_request() {
		// Create parent item
		API::useAPIKey(self::$config['apiKey']);
		$parentItemKey = API::createItem("book", ['title' => 'A', 'inPublications' => true], $this, 'key');
		
		// Create shown child attachment
		$json1 = API::getItemTemplate("attachment&linkMode=imported_file");
		$json1->title = 'B';
		$json1->parentItem = $parentItemKey;
		$json1->inPublications = true;
		// Create hidden child attachment
		$json2 = API::getItemTemplate("attachment&linkMode=imported_file");
		$json2->title = 'C';
		$json2->parentItem = $parentItemKey;
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json1, $json2])
		);
		$this->assert200($response);
		
		// Anonymous read
		API::useAPIKey("");
		
		$response = API::userGet(
			self::$config['userID'],
			"publications/items/$parentItemKey/children"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertCount(1, $json);
		$titles = array_map(function ($item) {
			return $item['data']['title'];
		}, $json);
		$this->assertContains('B', $titles);
	}
	
	
	public function test_shouldnt_include_hidden_child_items_in_numChildren() {
		// Create parent item
		API::useAPIKey(self::$config['apiKey']);
		$parentItemKey = API::createItem("book", ['inPublications' => true], $this, 'key');
		
		// Create shown child attachment
		$json1 = API::getItemTemplate("attachment&linkMode=imported_file");
		$json1->title = 'A';
		$json1->parentItem = $parentItemKey;
		$json1->inPublications = true;
		// Create shown child note
		$json2 = API::getItemTemplate("note");
		$json2->note = 'B';
		$json2->parentItem = $parentItemKey;
		$json2->inPublications = true;
		// Create hidden child attachment
		$json3 = API::getItemTemplate("attachment&linkMode=imported_file");
		$json3->title = 'C';
		$json3->parentItem = $parentItemKey;
		// Create deleted child attachment
		$json4 = API::getItemTemplate("note");
		$json4->note = 'D';
		$json4->parentItem = $parentItemKey;
		$json4->inPublications = true;
		$json4->deleted = true;
		// Create hidden deleted child attachment
		$json5 = API::getItemTemplate("attachment&linkMode=imported_file");
		$json5->title = 'E';
		$json5->parentItem = $parentItemKey;
		$json5->deleted = true;
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json1, $json2, $json3, $json4, $json5])
		);
		$this->assert200($response);
		
		// Anonymous read
		API::useAPIKey("");
		
		// JSON
		$response = API::userGet(
			self::$config['userID'],
			"publications/items/$parentItemKey"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertEquals(2, $json['meta']['numChildren']);
		
		$response = API::userGet(
			self::$config['userID'],
			"publications/items/$parentItemKey/children"
		);
		
		// Atom
		$response = API::userGet(
			self::$config['userID'],
			"publications/items/$parentItemKey?format=atom"
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(2, (int) array_shift($xml->xpath('/atom:entry/zapi:numChildren')));
	}
	
	
	public function test_should_include_download_details() {
		$file = "work/file";
		$fileContents = self::getRandomUnicodeString();
		$contentType = "text/html";
		$charset = "utf-8";
		file_put_contents($file, $fileContents);
		$hash = md5_file($file);
		$filename = "test_" . $fileContents;
		$mtime = filemtime($file) * 1000;
		$size = filesize($file);
		
		$parentItemKey = API::createItem("book", ['title' => 'A', 'inPublications' => true], $this, 'key');
		$json = API::createAttachmentItem("imported_file", [
			'parentItem' => $parentItemKey,
			'inPublications' => true,
			'contentType' => $contentType,
			'charset' => $charset
		], false, $this, 'jsonData');
		$key = $json['key'];
		$originalVersion = $json['version'];
		
		//
		// Get upload authorization
		//
		API::useAPIKey(self::$config['apiKey']);
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			$this->implodeParams([
				"md5" => $hash,
				"mtime" => $mtime,
				"filename" => $filename,
				"filesize" => $size
			]),
			[
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			]
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		
		self::$toDelete[] = "$hash";
		
		//
		// Upload to S3
		//
		$response = HTTP::post(
			$json['url'],
			$json['prefix'] . $fileContents . $json['suffix'],
			[
				"Content-Type: {$json['contentType']}"
			]
		);
		$this->assert201($response);
		
		//
		// Register upload
		//
		$response = API::userPost(
			self::$config['userID'],
			"items/$key/file",
			"upload=" . $json['uploadKey'],
			[
				"Content-Type: application/x-www-form-urlencoded",
				"If-None-Match: *"
			]
		);
		$this->assert204($response);
		$newVersion = $response->getHeader('Last-Modified-Version');
		$this->assertGreaterThan($originalVersion, $newVersion);
		
		// Anonymous read
		API::useAPIKey("");
		
		// Verify attachment item metadata (JSON)
		$response = API::userGet(
			self::$config['userID'],
			"publications/items/$key"
		);
		$json = API::getJSONFromResponse($response);
		$jsonData = $json['data'];
		$this->assertEquals($hash, $jsonData['md5']);
		$this->assertEquals($mtime, $jsonData['mtime']);
		$this->assertEquals($filename, $jsonData['filename']);
		$this->assertEquals($contentType, $jsonData['contentType']);
		$this->assertEquals($charset, $jsonData['charset']);
		
		// Verify download details (JSON)
		$this->assertRegExp(
			"%https?://[^/]+/users/" . self::$config['userID'] . "/publications/items/$key/file/view%",
			$json['links']['enclosure']['href']
		);
		
		// Verify attachment item metadata (Atom)
		$response = API::userGet(
			self::$config['userID'],
			"publications/items/$key?format=atom"
		);
		$xml = API::getXMLFromResponse($response);
		$href = (string) array_shift($xml->xpath('//atom:entry/atom:link[@rel="enclosure"]'))['href'];
		
		// Verify download details (JSON)
		$this->assertRegExp(
			"%https?://[^/]+/users/" . self::$config['userID'] . "/publications/items/$key/file/view%",
			$href
		);
	}
	
	
	public function test_shouldnt_show_child_items_in_top_mode() {
		// Create parent item
		API::useAPIKey(self::$config['apiKey']);
		$parentItemKey = API::createItem("book", ['title' => 'A', 'inPublications' => true], $this, 'key');
		
		// Create shown child attachment
		$json1 = API::getItemTemplate("attachment&linkMode=imported_file");
		$json1->title = 'B';
		$json1->parentItem = $parentItemKey;
		$json1->inPublications = true;
		// Create hidden child attachment
		$json2 = API::getItemTemplate("attachment&linkMode=imported_file");
		$json2->title = 'C';
		$json2->parentItem = $parentItemKey;
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json1, $json2])
		);
		$this->assert200($response);
		
		// Anonymous read
		API::useAPIKey("");
		
		$response = API::userGet(
			self::$config['userID'],
			"publications/items/top"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertCount(1, $json);
		$titles = array_map(function ($item) {
			return $item['data']['title'];
		}, $json);
		$this->assertContains('A', $titles);
	}
	
	
	public function test_shouldnt_show_trashed_item() {
		API::useAPIKey(self::$config['apiKey']);
		$itemKey = API::createItem("book", ['inPublications' => true, 'deleted' => true], $this, 'key');
		
		$response = API::userGet(
			self::$config['userID'],
			"publications/items/$itemKey"
		);
		$this->assert404($response);
	}
	
	
	public function test_shouldnt_show_restricted_properties() {
		API::useAPIKey(self::$config['apiKey']);
		$itemKey = API::createItem("book", [ 'inPublications' => true ], $this, 'key');
		
		// JSON
		$response = API::userGet(
			self::$config['userID'],
			"publications/items/$itemKey"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertArrayNotHasKey('inPublications', $json['data']);
		$this->assertArrayNotHasKey('collections', $json['data']);
		$this->assertArrayNotHasKey('relations', $json['data']);
		$this->assertArrayNotHasKey('tags', $json['data']);
		$this->assertArrayNotHasKey('dateAdded', $json['data']);
		$this->assertArrayNotHasKey('dateModified', $json['data']);
		
		// Atom
		$response = API::userGet(
			self::$config['userID'],
			"publications/items/$itemKey?format=atom&content=html,json"
		);
		$this->assert200($response);
		
		// HTML in Atom
		$html = API::getContentFromAtomResponse($response, 'html');
		$this->assertCount(0, $html->xpath('//html:tr[@class="publications"]'));
		
		// JSON in Atom
		$json = API::getContentFromAtomResponse($response, 'json');
		$this->assertArrayNotHasKey('inPublications', $json);
		$this->assertArrayNotHasKey('collections', $json);
		$this->assertArrayNotHasKey('relations', $json);
		$this->assertArrayNotHasKey('tags', $json);
		$this->assertArrayNotHasKey('dateAdded', $json);
		$this->assertArrayNotHasKey('dateModified', $json);
	}
	
	
	public function test_shouldnt_show_trashed_item_in_versions_response() {
		API::useAPIKey(self::$config['apiKey']);
		$itemKey1 = API::createItem("book", ['inPublications' => true], $this, 'key');
		$itemKey2 = API::createItem("book", ['inPublications' => true, 'deleted' => true], $this, 'key');
		
		$response = API::userGet(
			self::$config['userID'],
			"publications/items?format=versions"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertArrayHasKey($itemKey1, $json);
		$this->assertArrayNotHasKey($itemKey2, $json);
		
		// Shouldn't show with includeTrashed=1 here
		$response = API::userGet(
			self::$config['userID'],
			"publications/items?format=versions&includeTrashed=1"
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$this->assertArrayHasKey($itemKey1, $json);
		$this->assertArrayNotHasKey($itemKey2, $json);
	}
	
	
	public function test_should_show_publications_urls_in_json_response_for_single_object_request() {
		API::useAPIKey(self::$config['apiKey']);
		$itemKey = API::createItem("book", ['inPublications' => true], $this, 'key');
		
		$response = API::get("users/" . self::$config['userID'] . "/publications/items/$itemKey");
		$json = API::getJSONFromResponse($response);
		
		// rel="self"
		$this->assertRegExp(
			"%https?://[^/]+/users/" . self::$config['userID'] . "/publications/items/$itemKey%",
			$json['links']['self']['href']
		);
	}
	
	
	public function test_should_show_publications_urls_in_json_response_for_multi_object_request() {
		API::useAPIKey(self::$config['apiKey']);
		$itemKey1 = API::createItem("book", ['inPublications' => true], $this, 'key');
		$itemKey2 = API::createItem("book", ['inPublications' => true], $this, 'key');
		
		$response = API::get("users/" . self::$config['userID'] . "/publications/items?limit=1");
		$json = API::getJSONFromResponse($response);
		
		// Parse Link header
		$links = API::parseLinkHeader($response);
		
		// Entry rel="self"
		$this->assertRegExp(
			"%https?://[^/]+/users/" . self::$config['userID'] . "/publications/items/($itemKey1|$itemKey2)%",
			$json[0]['links']['self']['href']
		);
		
		// rel="next"
		$this->assertRegExp(
			"%https?://[^/]+/users/" . self::$config['userID'] . "/publications/items%",
			$links['next']
		);
		
		// TODO: rel="alternate" (what should this be?)
	}
	
	
	public function test_should_show_publications_urls_in_atom_response_for_single_object_request() {
		API::useAPIKey(self::$config['apiKey']);
		$itemKey = API::createItem("book", ['inPublications' => true], $this, 'key');
		
		$response = API::get("users/" . self::$config['userID'] . "/publications/items/$itemKey?format=atom");
		$xml = API::getXMLFromResponse($response);
		
		// id
		$this->assertRegExp(
			"%http://[^/]+/users/" . self::$config['userID'] . "/items/$itemKey%",
			(string) ($xml->xpath('//atom:id')[0])
		);
		
		// rel="self"
		$this->assertRegExp(
			"%https?://[^/]+/users/" . self::$config['userID'] . "/publications/items/$itemKey\?format=atom%",
			(string) ($xml->xpath('//atom:link[@rel="self"]')[0]['href'])
		);
		
		// TODO: rel="alternate"
	}
	
	public function test_should_show_publications_urls_in_atom_response_for_multi_object_request() {
		$response = API::get("users/" . self::$config['userID'] . "/publications/items?format=atom");
		$xml = API::getXMLFromResponse($response);
		
		// id
		$this->assertRegExp(
			"%http://[^/]+/users/" . self::$config['userID'] . "/publications/items%",
			(string) ($xml->xpath('//atom:id')[0])
		);
		
		$this->assertRegExp(
			"%https?://[^/]+/users/" . self::$config['userID'] . "/publications/items\?format=atom%",
			(string) ($xml->xpath('//atom:link[@rel="self"]')[0]['href'])
		);
		
		// rel="first"
		$this->assertRegExp(
			"%https?://[^/]+/users/" . self::$config['userID'] . "/publications/items\?format=atom%",
			(string) ($xml->xpath('//atom:link[@rel="first"]')[0]['href'])
		);
		
		// TODO: rel="alternate" (what should this be?)
	}
	
	
	public function testTopLevelAttachmentAndNote() {
		$msg = "Top-level notes and attachments cannot be added to My Publications";
		
		// Attachment
		API::useAPIKey(self::$config['apiKey']);
		$json = API::getItemTemplate("attachment&linkMode=imported_file");
		$json->inPublications = true;
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json])
		);
		$this->assert400ForObject($response, $msg, 0);
		
		// Note
		API::useAPIKey(self::$config['apiKey']);
		$json = API::getItemTemplate("note");
		$json->inPublications = true;
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json])
		);
		$this->assert400ForObject($response, $msg, 0);
	}
	
	
	public function testLinkedFileAttachment() {
		$msg = "Linked-file attachments cannot be added to My Publications";
		
		// Create top-level item
		API::useAPIKey(self::$config['apiKey']);
		$json = API::getItemTemplate("book");
		$json->inPublications = true;
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json])
		);
		$this->assert200($response);
		$json = API::getJSONFromResponse($response);
		$itemKey = $json['successful'][0]['key'];
		
		$json = API::getItemTemplate("attachment&linkMode=linked_file");
		$json->inPublications = true;
		$json->parentItem = $itemKey;
		API::useAPIKey(self::$config['apiKey']);
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json]),
			["Content-Type: application/json"]
		);
		$this->assert400ForObject($response, $msg, 0);
	}
	
	
	public function test_shouldnt_remove_inPublications_on_PATCH_without_property() {
		API::useAPIKey(self::$config['apiKey']);
		$json = API::getItemTemplate("book");
		$json->inPublications = true;
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json])
		);
		
		$this->assert200($response);
		$key = API::getJSONFromResponse($response)['successful'][0]['key'];
		$version = $response->getHeader("Last-Modified-Version");
		
		$json = [
			"key" => $key,
			"version" => $version,
			"title" => "Test"
		];
		$response = API::userPost(
			self::$config['userID'],
			"items",
			json_encode([$json]),
			["Content-Type: application/json"]
		);
		$this->assert200ForObject($response);
		$json = API::getJSONFromResponse($response);
		$this->assertTrue($json['successful'][0]['data']['inPublications']);
	}
	
	
	private function implodeParams($params, $exclude=array()) {
		$parts = array();
		foreach ($params as $key => $val) {
			if (in_array($key, $exclude)) {
				continue;
			}
			$parts[] = $key . "=" . urlencode($val);
		}
		return implode("&", $parts);
	}
	
	private function getRandomUnicodeString() {
		return "Âéìøü 这是一个测试。 " . uniqid();
	}
}
