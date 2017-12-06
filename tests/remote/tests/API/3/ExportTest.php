<?php
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2014 Center for History and New Media
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

class ExportTests extends APITests {
	private static $items;
	private static $multiResponses = [];
	private static $formats = ['bibtex', 'ris', 'csljson'];
	
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		API::userClear(self::$config['userID']);
		
		// Create test data
		$key = API::createItem("book", array(
			"title" => "Title",
			"date" => "January 1, 2014",
			"creators" => array(
				array(
					"creatorType" => "author",
					"firstName" => "First",
					"lastName" => "Last"
				)
			)
		), null, 'key');
		self::$items[$key] = [
			'bibtex' => "\n@book{last_title_2014,\n	title = {Title},\n	author = {Last, First},\n	month = jan,\n	year = {2014}\n}",
			'ris' => "TY  - BOOK\r\nTI  - Title\r\nAU  - Last, First\r\nDA  - 2014/01/01/\r\nPY  - 2014\r\nER  - \r\n\r\n",
			'csljson' => [
				'id' => self::$config['libraryID'] . "/$key",
				'type' => 'book',
				'title' => 'Title',
				'author' => [
					[
						'family' => 'Last',
						'given' => 'First'
					]
				],
				'issued' => [
					'date-parts' => [
						['2014', 1, 1]
					]
				]
			]
		];
		
		$key = API::createItem("book", array(
			"title" => "Title 2",
			"date" => "June 24, 2014",
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
		self::$items[$key] = [
			'bibtex' => "\n@book{last_title_2014,\n	title = {Title 2},\n	author = {Last, First},\n	editor = {McEditor, Ed},\n	month = jun,\n	year = {2014}\n}",
			'ris' => "TY  - BOOK\r\nTI  - Title 2\r\nAU  - Last, First\r\nA3  - McEditor, Ed\r\nDA  - 2014/06/24/\r\nPY  - 2014\r\nER  - \r\n\r\n",
			'csljson' => [
				'id' => self::$config['libraryID'] . "/$key",
				'type' => 'book',
				'title' => 'Title 2',
				'author' => [
					[
						'family' => 'Last',
						'given' => 'First'
					]
				],
				'editor' => [
					[
						'family' => 'McEditor',
						'given' => 'Ed'
					]
				],
				'issued' => [
					'date-parts' => [
						['2014', 6, 24]
					]
				]
			]
		];
		
		self::$multiResponses = [
			'bibtex' => [
				"contentType" => "application/x-bibtex",
				"content" => "\n@book{last_title_2014,\n	title = {Title 2},\n	author = {Last, First},\n	editor = {McEditor, Ed},\n	month = jun,\n	year = {2014}\n}\n\n@book{last_title_2014-1,\n	title = {Title},\n	author = {Last, First},\n	month = jan,\n	year = {2014}\n}",
			],
			'ris' => [
				"contentType" => "application/x-research-info-systems",
				"content" => "TY  - BOOK\r\nTI  - Title 2\r\nAU  - Last, First\r\nA3  - McEditor, Ed\r\nDA  - 2014/06/24/\r\nPY  - 2014\r\nER  - \r\n\r\nTY  - BOOK\r\nTI  - Title\r\nAU  - Last, First\r\nDA  - 2014/01/01/\r\nPY  - 2014\r\nER  - \r\n\r\n"
			],
			'csljson' => [
				"contentType" => "application/vnd.citationstyles.csl+json",
				"content" => [
					'items' => [
						self::$items[array_keys(self::$items)[1]]['csljson'],
						self::$items[array_keys(self::$items)[0]]['csljson']
					]
				]
			]
		];
	}
	
	
	public static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		API::userClear(self::$config['userID']);
	}
	
	
	public function testExportInclude() {
		foreach (self::$formats as $format) {
			$response = API::userGet(
				self::$config['userID'],
				"items?include=$format"
			);
			$this->assert200($response);
			$json = API::getJSONFromResponse($response);
			foreach ($json as $obj) {
				$this->assertEquals(self::$items[$obj['key']][$format], $obj[$format]);
			}
		}
	}
	
	
	public function testExportFormatSingle() {
		foreach (self::$formats as $format) {
			foreach (self::$items as $key => $expected) {
				$response = API::userGet(
					self::$config['userID'],
					"items/$key?format=$format"
				);
				$this->assert200($response);
				$body = $response->getBody();
				if (is_array($expected[$format])) {
					$body = json_decode($body, true);
				}
				// TODO: Remove in APIv4
				if ($format == 'csljson') {
					$body = $body['items'][0];
				}
				$this->assertEquals($expected[$format], $body);
			}
		}
	}
	
	
	public function testExportFormatMultiple() {
		foreach (self::$formats as $format) {
			$response = API::userGet(
				self::$config['userID'],
				"items?format=$format"
			);
			$this->assert200($response);
			$this->assertContentType(self::$multiResponses[$format]['contentType'], $response);
			$body = $response->getBody();
			if (is_array(self::$multiResponses[$format]['content'])) {
				$body = json_decode($body, true);
			}
			$this->assertEquals(self::$multiResponses[$format]['content'], $body);
		}
	}
}
