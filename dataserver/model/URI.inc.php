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
	
	public static function getBaseWWWURI() {
		return Z_CONFIG::$WWW_BASE_URI;
	}
	
	public static function getLibraryURI($libraryID, $www=false, $useSlug=false) {
		$libraryType = Zotero_Libraries::getType($libraryID);
		switch ($libraryType) {
			case 'user':
				$id = Zotero_Users::getUserIDFromLibraryID($libraryID);
				return self::getUserURI($id, $www, $useSlug);
			
			// TEMP
			case 'publications':
				$id = Zotero_Users::getUserIDFromLibraryID($libraryID);
				return self::getUserURI($id, $www, $useSlug) . "/publications";
			
			case 'group':
				$id = Zotero_Groups::getGroupIDFromLibraryID($libraryID);
				$group = Zotero_Groups::get($id);
				return self::getGroupURI($group, $www, $useSlug);
			
			default:
				throw new Exception("Invalid library type '$libraryType'");
		}
	}
	
	public static function getUserURI($userID, $www=false, $useSlug=false) {
		if ($www) {
			$username = Zotero_Users::getUsername($userID);
			return self::getBaseWWWURI() . Zotero_Utilities::slugify($username);
		}
		if ($useSlug) {
			$username = Zotero_Users::getUsername($userID);
			return self::getBaseURI() . Zotero_Utilities::slugify($username);
		}
		return self::getBaseURI() . "users/$userID";
	}
	
	public static function getItemURI(Zotero_Item $item, $www=false, $useSlug=false) {
		if (!$item->libraryID) {
			throw new Exception("Can't get URI for unsaved item");
		}
		return self::getLibraryURI($item->libraryID, $www, $useSlug) . "/items/$item->key";
	}
	
	public static function getGroupURI(Zotero_Group $group, $www=false, $useSlug=false) {
		if ($www) {
			$slug = $group->slug;
			if (!$slug) {
				$slug = $group->id;
			}
			return self::getBaseWWWURI() . "groups/$slug";
		}
		if ($useSlug) {
			$id = $group->slug;
			if ($id === null) {
				$id = $group->id;
			}
		}
		else {
			$id = $group->id;
		}
		return self::getBaseURI() . "groups/" . $id;
	}
	
	public static function getGroupUserURI(Zotero_Group $group, $userID) {
		return self::getGroupURI($group) . "/users/$userID";
	}
	
	public static function getGroupItemURI(Zotero_Group $group, Zotero_Item $item) {
		return self::getGroupURI($group) . "/items/$item->key";
	}
	
	public static function getCollectionURI(Zotero_Collection $collection, $www=false) {
		return self::getLibraryURI($collection->libraryID, true) . "/collections/$collection->key";
	}
	
	public static function getCreatorURI(Zotero_Creator $creator) {
		return self::getLibraryURI($creator->libraryID) . "/creators/$creator->key";
	}
	
	public static function getSearchURI(Zotero_Search $search) {
		return self::getLibraryURI($search->libraryID) . "/searches/$search->key";
	}
	
	public static function getTagURI(Zotero_Tag $tag) {
		return self::getLibraryURI($tag->libraryID) . "/tags/" . urlencode($tag->name);
	}
	
	public static function getURIItem($itemURI) {
		return self::getURIObject($itemURI, 'item');
	}
	
	
	public static function getURICollection($collectionURI) {
		return self::getURIObject($collectionURI, 'collection');
	}
	
	
	public static function getURILibrary($libraryURI) {
		return self::getURIObject($libraryURI, "library");
	}
	
	
	private static function getURIObject($objectURI, $type) {
		$Types = ucwords($type) . 's';
		$types = strtolower($Types);
		
		$libraryType = null;
		
		$baseURI = self::getBaseURI();
		
		// If not found, try global URI
		if (strpos($objectURI, $baseURI) !== 0) {
			throw new Exception("Invalid base URI '$objectURI'");
		}
		$objectURI = substr($objectURI, strlen($baseURI));
		$typeRE = "/^(users|groups)\/([0-9]+)(?:\/|$)/";
		if (!preg_match($typeRE, $objectURI, $matches)) {
			throw new Exception("Invalid library URI '$objectURI'");
		}
		$libraryType = substr($matches[1], 0, -1);
		$id = $matches[2];
		$objectURI = preg_replace($typeRE, '', $objectURI);
		
		if ($libraryType == 'user') {
			if (!Zotero_Users::exists($id)) {
				return false;
			}
			$libraryID = Zotero_Users::getLibraryIDFromUserID($id);
		}
		else if ($libraryType == 'group') {
			if (!Zotero_Groups::get($id)) {
				return false;
			}
			$libraryID = Zotero_Groups::getLibraryIDFromGroupID($id);
		}
		else {
			throw new Exception("Invalid library type $libraryType");
		}
		
		if ($type === 'library') {
			return $libraryID;
		}
		else {
			// TODO: objectID-based URI?
			if (!preg_match('/' . $types . "\/([A-Z0-9]{8})/", $objectURI, $matches)) {
				throw new Exception("Invalid object URI '$objectURI'");
			}
			$objectKey = $matches[1];
			return call_user_func(array("Zotero_$Types", "getByLibraryAndKey"), $libraryID, $objectKey);
		}
	}
}
?>
