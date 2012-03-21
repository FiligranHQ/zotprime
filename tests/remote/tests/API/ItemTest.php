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

require_once 'include.php';
require_once 'include/api.inc.php';
require_once 'include/sync.inc.php';

class ItemTests extends APITests {
	public static function setUpBeforeClass() {
		Sync::clear();
	}
	
	public static function tearDownAfterClass() {
		Sync::clear();
	}
	
	
	public function testNewEmptyBookItem() {
		$response = API::get("items/new?itemType=book");
		$json = json_decode($response->getBody());
		$json->creators[0]->firstName = "Firstname";
		$json->creators[0]->lastName = "Lastname";
		
		$response = API::userPost(
			$this->fixture->config['userID'],
			"items?key=" . $this->fixture->config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert201($response);
		$xml = API::getXMLFromResponse($response);
		$this->assertEquals(1, (int) array_shift($xml->xpath('/atom:feed/zapi:totalResults')));
		
		$json = json_decode(array_shift($xml->xpath('/atom:feed/atom:entry/atom:content')));
		$this->assertEquals("book", (string) $json->itemType);
		
		return $xml;
	}
	
	
	/**
	 * @depends testNewEmptyBookItem
	 */
	public function testEditBookItem($newItemXML) {
		$key = (string) array_shift($newItemXML->xpath('/atom:feed/atom:entry/zapi:key'));
		$etag = (string) array_shift($newItemXML->xpath('/atom:feed/atom:entry/atom:content/@zapi:etag'));
		$json = json_decode(array_shift($newItemXML->xpath('/atom:feed/atom:entry/atom:content')));
		
		$newTitle = "New Title";
		$json->title = $newTitle;
		
		//sleep(1);
		
		$response = API::userPut(
			$this->fixture->config['userID'],
			"items/$key?key=" . $this->fixture->config['apiKey'],
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
	}
	
	
	public function testNewInvalidItem() {
		$response = API::get("items/new?itemType=book");
		$json = json_decode($response->getBody());
		
		$json2 = $json;
		unset($json2->itemType);
		
		$response = API::userPost(
			$this->fixture->config['userID'],
			"items?key=" . $this->fixture->config['apiKey'],
			json_encode(array(
				"items" => array($json2)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400($response);
		
		// more tests
	}
	
	
	public function testNewEmptyLinkAttachmentItem() {
		$response = API::get("items/new?itemType=attachment&linkMode=linked_url");
		$json = json_decode($response->getBody());
		
		$response = API::userPost(
			$this->fixture->config['userID'],
			"items?key=" . $this->fixture->config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert201($response);
		return API::getXMLFromResponse($response);
	}
	
	
	/**
	 * @depends testNewEmptyLinkAttachmentItem
	 */
	public function testEditLinkAttachmentItem($newItemXML) {
		$key = (string) array_shift($newItemXML->xpath('/atom:feed/atom:entry/zapi:key'));
		$etag = (string) array_shift($newItemXML->xpath('/atom:feed/atom:entry/atom:content/@zapi:etag'));
		$json = json_decode(array_shift($newItemXML->xpath('/atom:feed/atom:entry/atom:content')));
		
		
	}
	
	
	public function testNewAttachmentItemInvalidLinkMode() {
		$response = API::get("items/new?itemType=attachment&linkMode=linked_url");
		$json = json_decode($response->getBody());
		$json->linkMode = "invalidName";
		
		$response = API::userPost(
			$this->fixture->config['userID'],
			"items?key=" . $this->fixture->config['apiKey'],
			json_encode(array(
				"items" => array($json)
			)),
			array("Content-Type: application/json")
		);
		$this->assert400($response);
	}
}
