<?php
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

require_once 'APITests.inc.php';
require_once 'include/api.inc.php';

class GeneralTests extends APITests {
	public function testAPIVersion() {
		$minVersion = 1;
		$maxVersion = 2;
		$defaultVersion = 1;
		
		for ($i = $minVersion; $i <= $maxVersion; $i++) {
			API::useAPIVersion($i);
			$response = API::userGet(
				self::$config['userID'],
				"items?key=" . self::$config['apiKey'] . "&format=keys&limit=1"
			);
			if ($i == 1) {
				$this->assertEquals(1, $response->getHeader("Zotero-API-Version"));
			}
			else {
				$this->assertEquals($i, $response->getHeader("Zotero-API-Version"));
			}
		}
		
		// Default
		API::useAPIVersion(false);
		$response = API::userGet(
			self::$config['userID'],
			"items?key=" . self::$config['apiKey'] . "&format=keys&limit=1"
		);
		$this->assertEquals($defaultVersion, $response->getHeader("Zotero-API-Version"));
	}
}
