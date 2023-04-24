<?
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
require_once 'include/bootstrap.inc.php';

class NotifierTests extends PHPUnit_Framework_TestCase {
	public function testNotify() {
		$event = "modify";
		$type = "item";
		$libraryKey = "4/DDDDDDDD";
		
		$mock = $this->getMock('stdClass', array('notify'));
		$mock->expects($this->once())
				->method('notify')
				->with(
					$event,
					$type,
					array($libraryKey)
				);
		
		$hash = Zotero_Notifier::registerObserver($mock, $type);
		$this->assertEquals(2, strlen($hash));
		
		Zotero_Notifier::trigger($event, $type, $libraryKey);
		
		Zotero_Notifier::unregisterObserver($hash);
	}
	
	
	public function testNotifyMultipleLibraryKeys() {
		$event = "add";
		$type = "item";
		$libraryKeys = array("1/ABCD2345", "1/BCDE3456", "1/CDEF4567");
		
		$mock = $this->getMock('stdClass', array('notify'));
		$mock->expects($this->once())
				->method('notify')
				->with(
					$event,
					$type,
					$libraryKeys
				);
		
		$hash = Zotero_Notifier::registerObserver($mock, $type);
		
		Zotero_Notifier::trigger($event, $type, $libraryKeys);
		
		Zotero_Notifier::unregisterObserver($hash);
	}
	
	
	public function testSkipType() {
		$mock = $this->getMock('stdClass', array('notify'));
		$mock->expects($this->never())->method('notify');
		
		$hash = Zotero_Notifier::registerObserver($mock, "item");
		Zotero_Notifier::trigger("add", "collection", "2/ABABABAB");
		
		Zotero_Notifier::unregisterObserver($hash);
	}
	
	
	public function testUnregisterObserver() {
		$mock = $this->getMock('stdClass', array('notify'));
		$mock->expects($this->never())->method('notify');
		
		$hash = Zotero_Notifier::registerObserver($mock, "item");
		Zotero_Notifier::unregisterObserver($hash);
		
		Zotero_Notifier::trigger("add", "item", "3/CADACADA");
	}
	
	
	public function testQueue() {
		$event = "add";
		$type = "item";
		$keys = array("1/AAAAAAAA", "1/BBBBBBBB");
		
		$mock = $this->getMock('stdClass', array('notify'));
		$mock->expects($this->once())
				->method('notify')
				->with(
					$event,
					$type,
					array($keys[0], $keys[1])
				);
		
		$hash = Zotero_Notifier::registerObserver($mock, $type);
		
		Zotero_Notifier::begin();
		Zotero_Notifier::trigger($event, $type, $keys[0]);
		Zotero_Notifier::trigger($event, $type, $keys[1]);
		Zotero_Notifier::commit();
		
		Zotero_Notifier::unregisterObserver($hash);
	}
	
	
	public function testReset() {
		$event = "add";
		$type = "item";
		
		$mock = $this->getMock('stdClass', array('notify'));
		$mock->expects($this->never())->method('notify');
		
		$hash = Zotero_Notifier::registerObserver($mock, $type);
		
		Zotero_Notifier::begin();
		Zotero_Notifier::trigger($event, $type, "1/AAAAAAAA");
		Zotero_Notifier::trigger($event, $type, "1/BBBBBBBB");
		Zotero_Notifier::reset();
		Zotero_Notifier::commit();
		
		Zotero_Notifier::unregisterObserver($hash);
	}
}
