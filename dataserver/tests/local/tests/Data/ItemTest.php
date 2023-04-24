<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2010 Center for History and New Media
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

class ItemTests extends PHPUnit_Framework_TestCase {
	protected static $config;
	
	public static function setUpBeforeClass() {
		require("include/config.inc.php");
		self::$config = $config;
		self::$config['userLibraryID'] = Zotero_Users::getLibraryIDFromUserID($config['userID']);
	}
	
	public function setUp() {
		Zotero_Users::clearAllData(self::$config['userID']);
	}
	
	
	public function testSetItemType() {
		$itemTypeName = "book";
		$itemTypeID = Zotero_ItemTypes::getID($itemTypeName);
		
		$item = new Zotero_Item;
		$item->libraryID = self::$config['userLibraryID'];
		$item->itemTypeID = $itemTypeID;
		$item->save();
		$this->assertEquals($itemTypeID, $item->itemTypeID);
		
		$item = new Zotero_Item($itemTypeName);
		$item->libraryID = self::$config['userLibraryID'];
		$item->save();
		$this->assertEquals($itemTypeID, $item->itemTypeID);
		
		$item = new Zotero_Item($itemTypeID);
		$item->libraryID = self::$config['userLibraryID'];
		$item->save();
		$this->assertEquals($itemTypeID, $item->itemTypeID);
	}
	
	
	public function testSetItemKeyAfterConstructorItemType() {
		$item = new Zotero_Item(2);
		$item->libraryID = self::$config['userLibraryID'];
		try {
			$item->key = "AAAAAAAA";
		}
		catch (Exception $e) {
			$this->assertEquals("Cannot set key after item is already loaded", $e->getMessage());
			return;
		}
		
		$this->fail("Unexpected success setting item key after passing item type to Zotero.Item constructor");
	}
	
	
	public function testChangeItemTypeByLibraryAndKey() {
		$item = new Zotero_Item(2);
		$item->libraryID = self::$config['userLibraryID'];
		$item->save();
		$key = $item->key;
		$this->assertEquals(2, $item->itemTypeID);
		
		$item = Zotero_Items::getByLibraryAndKey($item->libraryID, $item->key);
		$item->itemTypeID = 3;
		$item->save();
		$this->assertEquals(3, $item->itemTypeID);
	}
	
	
	public function testChangeItemTypeByConstructor() {
		$item = new Zotero_Item(2);
		$item->libraryID = self::$config['userLibraryID'];
		$item->save();
		$key = $item->key;
		$this->assertEquals(2, $item->itemTypeID);
		
		$item = new Zotero_Item;
		$item->libraryID = self::$config['userLibraryID'];
		$item->key = $key;
		$item->itemTypeID = 3;
		$item->save();
		$this->assertEquals(3, $item->itemTypeID);
	}
	
	
	public function testItemVersionAfterSave() {
		$item = new Zotero_Item("book");
		$item->libraryID = self::$config['userLibraryID'];
		$item->save();
		$this->assertEquals(0, $item->itemVersion);
		
		$item->itemTypeID = 3;
		$item->save();
		$this->assertEquals(1, $item->itemVersion);
		
		$item->setField("title", "Foo");
		$item->save();
		$this->assertEquals(2, $item->itemVersion);
	}
	
	
	public function testNumAttachments() {
		$item = new Zotero_Item;
		$item->libraryID = self::$config['userLibraryID'];
		$item->itemTypeID = Zotero_ItemTypes::getID("book");
		$item->save();
		$this->assertEquals(0, $item->numAttachments());
		
		$attachmentItem = new Zotero_Item;
		$attachmentItem->libraryID = self::$config['userLibraryID'];
		$attachmentItem->itemTypeID = Zotero_ItemTypes::getID("attachment");
		$attachmentItem->setSource($item->id);
		$attachmentItem->save();
		$this->assertEquals(1, $item->numAttachments());
	}
}
