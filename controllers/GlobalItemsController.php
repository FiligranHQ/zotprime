<?php
/*
    ***** BEGIN LICENSE BLOCK *****

    This file is part of the Zotero Data Server.

    Copyright © 2017 Center for History and New Media
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

require('ApiController.php');

class GlobalItemsController extends ApiController {
	public function globalItems() {
		$this->allowMethods(['GET']);
		$params = [];
		if (!empty($_GET['q'])) {
			if (strlen($_GET['q']) < 3) {
				$this->e400("Query string must be at least 3 characters length");
			}
			$params['q'] = $_GET['q'];
		}
		else if (!empty($_GET['doi'])) {
			$params['doi'] = $_GET['doi'];
		}
		else if (!empty($_GET['isbn'])) {
			$params['isbn'] = $_GET['isbn'];
		}
		else {
			$this->e400("One of the following query parameters must be used: q, doi, isbn, url");
		}
		
		$params['start'] = $this->queryParams['start'];
		$params['limit'] = $this->queryParams['limit'];
		
		$result = Zotero_GlobalItems::getGlobalItems($params);
		for ($i = 0, $len = sizeOf($result['data']); $i < $len; $i++) {
			unset($result['data'][$i]['libraryItems']);
			unset($result['data'][$i]['meta']['instanceCount']);
		}
		
		header('Content-Type: application/json');
		header('Total-Results: ' . $result['totalResults']);
		echo Zotero_Utilities::formatJSON($result['data']);
		$this->end();
	}
}
