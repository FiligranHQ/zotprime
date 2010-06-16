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

class Zotero_Permissions {
	private $super = false;
	private $anonymous = false;
	private $userID = null;
	private $permissions = array();
	private $userPrivacy = array();
	private $groupPrivacy = array();
	
	
	public function __construct($userID=null) {
		$this->userID = $userID;
	}
	
	
	public function canAccess($libraryID, $permission='library') {
		if ($this->super) {
			return true;
		}
		
		// If requested permission is explicitly set
		if (!empty($this->permissions[$libraryID][$permission])) {
			return true;
		}
		
		// Implicit permission based on user's access to library
		$libraryType = Zotero_Libraries::getType($libraryID);
		switch ($libraryType) {
			case 'user':
				$userID = Zotero_Users::getUserIDFromLibraryID($libraryID);
				// Allow user access to their library
				if ($this->userID == $userID) {
					return true;
				}
				$privacy = $this->getUserPrivacy($userID);
				break;
			
			case 'group':
				$groupID = Zotero_Groups::getGroupIDFromLibraryID($libraryID);
				// Allow user access to readable group libraries
				/*if ($this->userID) {
					$group = Zotero_Groups::get($groupID);
					return $group->userCanRead($this->userID);
				}*/
				$privacy = $this->getGroupPrivacy($groupID);
				if ($permission == 'group' && $privacy['publishGroup']) {
					return true;
				}
				break;
			
			default:
				throw new Exception("Unsupported library type '$libraryType'");
		}
		
		if (!$this->anonymous) {
			return false;
		}
		
		// For anonymous access, check privileges
		switch ($permission) {
			case 'library':
				return $privacy['publishLibrary'];
			
			case 'notes':
				return $privacy['publishNotes'];
			
			default:
				return false;
		}
	}
	
	
	public function setPermission($libraryID, $permission, $enabled) {
		if ($this->super) {
			throw new Exception("Super-user permissions already set");
		}
		
		switch ($permission) {
			case 'library':
			case 'notes':
			case 'files':
			case 'group':
				break;
			
			default:
				throw new Exception("Invalid permission '$permission'");
		}
		
		$this->permissions[$libraryID][$permission] = $enabled;
	}
	
	
	public function setAnonymous() {
		$this->anonymous = true;
	}
	
	public function setUser($userID) {
		$this->userID = $userID;
	}
	
	public function setSuper() {
		$this->super = true;
	}
	
	public function isSuper() {
		return $this->super;
	}
	
	
	private function getUserPrivacy($userID) {
		if (isset($this->userPrivacy[$userID])) {
			return $this->userPrivacy[$userID];
		}
		
		if (Z_ENV_DEV_SITE) {
			// Hard-coded test values
			$privacy = array();
			
			switch ($userID) {
				case 1:
					$privacy['publishLibrary'] = true;
					$privacy['publishNotes'] = true;
					break;
					
				case 2:
					$privacy['publishLibrary'] = false;
					$privacy['publishNotes'] = false;
					break;
				
				default:
					throw new Exception("External requests disabled on dev site");
			}
			
			$this->userPrivacy[$userID] = $privacy;
			return $privacy;
		}
		// TODO: centralize base URL
		else {
			// TEMP
			if (!empty(Z_CONFIG::$API_BASE_URI_WWW)) {
				$url = Z_CONFIG::$API_BASE_URI_WWW;
			}
			else {
				$url = Z_CONFIG::$API_BASE_URI;
			}
			
			$url .= "users/$userID";
		}
		
		$xml = @file_get_contents($url);
		if (!$xml) {
			trigger_error("User $userID doesn't exist", E_USER_ERROR);
		}
		
		$xml = new SimpleXMLElement($xml);
		$zapiContent = $xml->content->children(Zotero_Atom::$nsZoteroAPI);
		$privacy = array();
		$privacy['publishLibrary'] = (bool) (int) $zapiContent->privacy->publishLibrary;
		$privacy['publishNotes'] = (bool) (int) $zapiContent->privacy->publishNotes;
		
		$this->userPrivacy[$userID] = $privacy;
		
		return $privacy;
	}
	
	
	private function getGroupPrivacy($groupID) {
		if (isset($this->groupPrivacy[$groupID])) {
			return $this->groupPrivacy[$groupID];
		}
		
		$group = Zotero_Groups::get($groupID);
		if (!$group) {
			throw new Exception("Group $groupID doesn't exist");
		}
		$privacy = array();
		if ($group->isPublic()) {
			$privacy['publishGroup'] = true;
			$privacy['publishLibrary'] = true;
			$privacy['publishNotes'] = true;
		}
		else {
			$privacy['publishGroup'] = false;
			$privacy['publishLibrary'] = false;
			$privacy['publishNotes'] = false;
		}
		
		$this->groupPrivacy[$groupID] = $privacy;
		
		return $privacy;
	}
	
	
	public function hasPermission($object, $userID=false) {
		return false;
	}
}
?>
