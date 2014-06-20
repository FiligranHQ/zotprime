<?php
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

class AtomTests extends APITests {
	private static $items;
	
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
		
		// Create test data
		$key = API::createItem("book", array(
			"title" => "Title",
			"creators" => array(
				array(
					"creatorType" => "author",
					"firstName" => "First",
					"lastName" => "Last"
				)
			)
		), null, 'key');
		self::$items[$key] = '<content xmlns:zapi="http://zotero.org/ns/api" type="application/xml"><zapi:subcontent zapi:type="bib"><div xmlns="http://www.w3.org/1999/xhtml" class="csl-bib-body" style="line-height: 1.35; padding-left: 2em; text-indent:-2em;"><div class="csl-entry">Last, First. <i>Title</i>, n.d.</div></div></zapi:subcontent><zapi:subcontent zapi:type="json">'
			. \Zotero_Utilities::formatJSON(json_decode('{"key":"","version":0,"itemType":"book","title":"Title","creators":[{"creatorType":"author","firstName":"First","lastName":"Last"}],"abstractNote":"","series":"","seriesNumber":"","volume":"","numberOfVolumes":"","edition":"","place":"","publisher":"","date":"","numPages":"","language":"","ISBN":"","shortTitle":"","url":"","accessDate":"","archive":"","archiveLocation":"","libraryCatalog":"","callNumber":"","rights":"","extra":"","dateAdded":"","dateModified":"","tags":[],"collections":[],"relations":{}}'))
			. '</zapi:subcontent></content>';
		
		$key = API::createItem("book", array(
			"title" => "Title 2",
			"creators" => array(
				array(
					"creatorType" => "author",
					"firstName" => "First",
					"lastName" => "Last"
				),
				array(
					"creatorType" => "editor",
					"firstName" => "Ed",
					"lastName" => "McEditor"
				)
			)
		), null, 'key');
		self::$items[$key] = '<content xmlns:zapi="http://zotero.org/ns/api" type="application/xml"><zapi:subcontent zapi:type="bib"><div xmlns="http://www.w3.org/1999/xhtml" class="csl-bib-body" style="line-height: 1.35; padding-left: 2em; text-indent:-2em;"><div class="csl-entry">Last, First. <i>Title 2</i>. Edited by Ed McEditor, n.d.</div></div></zapi:subcontent><zapi:subcontent zapi:type="json">'
			. \Zotero_Utilities::formatJSON(json_decode('{"key":"","version":0,"itemType":"book","title":"Title 2","creators":[{"creatorType":"author","firstName":"First","lastName":"Last"},{"creatorType":"editor","firstName":"Ed","lastName":"McEditor"}],"abstractNote":"","series":"","seriesNumber":"","volume":"","numberOfVolumes":"","edition":"","place":"","publisher":"","date":"","numPages":"","language":"","ISBN":"","shortTitle":"","url":"","accessDate":"","archive":"","archiveLocation":"","libraryCatalog":"","callNumber":"","rights":"","extra":"","dateAdded":"","dateModified":"","tags":[],"collections":[],"relations":{}}'))
			. '</zapi:subcontent></content>';
	}
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testFeedURIs() {
		$userID = self::$config['userID'];
		
		$response = API::userGet(
			$userID,
			"items?format=atom"
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$links = $xml->xpath('/atom:feed/atom:link');
		$this->assertEquals(self::$config['apiURLPrefix'] . "users/$userID/items?format=atom", (string) $links[0]['href']);
		
		// 'order'/'sort' should turn into 'sort'/'direction'
		$response = API::userGet(
			$userID,
			"items?format=atom&order=dateModified&sort=asc"
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$links = $xml->xpath('/atom:feed/atom:link');
		$this->assertEquals(self::$config['apiURLPrefix'] . "users/$userID/items?direction=asc&format=atom&sort=dateModified", (string) $links[0]['href']);
	}
	
	
	public function testTotalResults() {
		$response = API::userHead(
			self::$config['userID'],
			"items?format=atom"
		);
		$this->assert200($response);
		$this->assertTotalResults(sizeOf(self::$items), $response);
		
		$response = API::userGet(
			self::$config['userID'],
			"items?format=atom"
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$this->assertTotalResults(sizeOf(self::$items), $response);
		// Make sure there's no totalResults tag
		$this->assertCount(0, $xml->xpath('/atom:feed/zapi:totalResults'));
	}
	
	
	public function testMultiContent() {
		$keys = array_keys(self::$items);
		$keyStr = implode(',', $keys);
		
		$response = API::userGet(
			self::$config['userID'],
			"items?itemKey=$keyStr&content=bib,json"
		);
		$this->assert200($response);
		$xml = API::getXMLFromResponse($response);
		$this->assertTotalResults(sizeOf($keys), $response);
		
		$entries = $xml->xpath('//atom:entry');
		foreach ($entries as $entry) {
			$key = (string) $entry->children("http://zotero.org/ns/api")->key;
			$content = $entry->content->asXML();
			
			// Add namespace prefix (from <entry>)
			$content = str_replace('<content ', '<content xmlns:zapi="http://zotero.org/ns/api" ', $content);
			// Strip variable key and version
			$content = preg_replace(
				'%"key": "[A-Z0-9]{8}",(\s+)"version": [0-9]+%',
				'"key": "",$1"version": 0',
				$content
			);
			
			// Strip dateAdded/dateModified
			$iso8601Pattern = '[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z';
			$content = preg_replace(
				'/"dateAdded": "' . $iso8601Pattern . '",(\s+)"dateModified": "' . $iso8601Pattern . '"/',
				'"dateAdded": "",$1"dateModified": ""',
				$content
			);
			
			$this->assertXmlStringEqualsXmlString(self::$items[$key], $content);
		}
	}
	
	
	public function testMultiContentCached() {
		self::testMultiContent();
	}
}
?>
