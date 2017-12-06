<?php
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2017 Roy Rosenzweig Center for History and New Media
                     George Mason University, Fairfax, Virginia, USA
                     https://www.zotero.org
    
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
require_once 'include/bootstrap.inc.php';

class DateTests extends PHPUnit_Framework_TestCase {
	public function test_retrieveItem_should_use_first_year_from_range() {
		$item = new Zotero_Item('book');
		$item->setField('date', '2011-2012');
		$cslItem = Zotero_Cite::retrieveItem($item);
		// "issued": {
		//     "date-parts": [
		//         ["2011"]
		//     ],
		//     "season": "2012"
		// }
		$this->assertArrayHasKey('issued', $cslItem);                             
		$this->assertArrayHasKey('date-parts', $cslItem['issued']);
		$this->assertCount(1, $cslItem['issued']['date-parts']);
		$this->assertCount(1, $cslItem['issued']['date-parts'][0]);
		$this->assertEquals("2011", $cslItem['issued']['date-parts'][0][0]);
		$this->assertArrayHasKey('season', $cslItem['issued']);
		$this->assertEquals('2012', $cslItem['issued']['season']);
	}
}                                                                                        
