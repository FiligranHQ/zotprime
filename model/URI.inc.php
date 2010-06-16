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

class Zotero_URI {
	public static function getBaseURI() {
		return Z_CONFIG::$BASE_URI;
	}
	
	public static function getLibraryURI($libraryID, $skipNames=false) {
		$libraryType = Zotero_Libraries::getType($libraryID);
		switch ($libraryType) {
			case 'user':
				$id = Zotero_Users::getUserIDFromLibraryID($libraryID);
				return self::getUserURI($id, $skipNames);
			
			case 'group':
				$id = Zotero_Groups::getGroupIDFromLibraryID($libraryID);
				$group = Zotero_Groups::get($id);
				return self::getGroupURI($group, $skipNames);
		}
	}
	
	public static function getUserURI($userID, $skipNames=false) {
		if ($skipNames) {
			return self::getBaseURI() . "users/$userID";
		}
		$username = Zotero_Users::getUsername($userID);
		return self::getBaseURI() . Zotero_Utilities::slugify($username);
	}
	
	public static function getItemURI(Zotero_Item $item, $skipNames=false) {
		return self::getLibraryURI($item->libraryID, $skipNames) . "/items/$item->id";
	}
	
	public static function getGroupURI(Zotero_Group $group, $skipNames=false) {
		if ($skipNames) {
			$slug = $group->id;
		}
		else {
			$slug = $group->slug;
			if (!$slug) {
				$slug = $group->id;
			}
		}
		return self::getBaseURI() . "groups/$slug";
	}
	
	public static function getGroupUserURI(Zotero_Group $group, $userID) {
		return self::getGroupURI($group) . "/users/$userID";
	}
	
	public static function getGroupItemURI(Zotero_Group $group, Zotero_Item $item) {
		return self::getGroupURI($group) . "/items/$item->id";
	}
	
	public static function getCollectionURI(Zotero_Collection $collection) {
		return self::getLibraryURI($collection->libraryID) . "/collections/$collection->id";
	}
	
	public static function getCreatorURI(Zotero_Creator $creator) {
		return self::getLibraryURI($creator->libraryID) . "/creators/$creator->id";
	}
	
	public static function getTagURI(Zotero_Tag $tag) {
		return self::getLibraryURI($tag->libraryID) . "/tags/" . urlencode($tag->name);
	}
}
?>
