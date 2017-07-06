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
	private $publications = false;
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
		
		if (!$libraryID) {
			throw new Exception('libraryID not provided');
		}
		
		// TEMP: necessary?
		$libraryID = (int) $libraryID;
		
		// If requested permission is explicitly set
		//
		// This assumes that permissions can't be incorrectly set
		// (e.g., are properly removed when a user loses group access)
		if (!empty($this->permissions[$libraryID][$permission])) {
			return true;
		}
		
		$libraryType = Zotero_Libraries::getType($libraryID);
		switch ($libraryType) {
			case 'user':
				$userID = Zotero_Users::getUserIDFromLibraryID($libraryID);
				$privacy = $this->getUserPrivacy($userID);
				break;
			
			// TEMP
			case 'publications':
				return true;
			
			case 'group':
				$groupID = Zotero_Groups::getGroupIDFromLibraryID($libraryID);
				
				// If key has access to all groups, grant access if user
				// has read access to group
				if (!empty($this->permissions[0]['library'])) {
					$group = Zotero_Groups::get($groupID);
					
					// Only members have file access
					if ($permission == 'files') {
						return !!$group->getUserRole($this->userID);
					}
					
					if ($group->userCanRead($this->userID)) {
						return true;
					}
				}
				
				$privacy = $this->getGroupPrivacy($groupID);
				break;
			
			default:
				throw new Exception("Unsupported library type '$libraryType'");
		}
		
		switch ($permission) {
		case 'view':
			return $privacy['view'];
		
		case 'library':
			return $privacy['library'];
		
		case 'notes':
			return $privacy['notes'];
		
		default:
			return false;
		}
	}
	
	
	public function canAccessObject(Zotero_DataObject $obj) {
		if ($obj instanceof Zotero_Item && $this->publications && $obj->inPublications) {
			return true;
		}
		
		$scope = 'library';
		if ($obj instanceof Zotero_Item && $obj->isNote()) {
			$scope = 'notes';
		}
		return $this->canAccess($obj->libraryID, $scope);
	}
	
	
	/**
	 * This should be called after canAccess()
	 */
	public function canWrite($libraryID) {
		if ($this->super) {
			return true;
		}
		
		if ($libraryID === 0) {
			return false;
		}
		
		if (!$libraryID) {
			throw new Exception('libraryID not provided');
		}
		
		if (!empty($this->permissions[$libraryID]['write'])) {
			return true;
		}
		
		$libraryType = Zotero_Libraries::getType($libraryID);
		switch ($libraryType) {
			case 'user':
				return false;
			
			// Write permissions match key's write access to user library
			case 'publications':
				$userLibraryID = Zotero_Users::getLibraryIDFromUserID($this->userID);
				return $this->canWrite($userLibraryID);
			
			case 'group':
				$groupID = Zotero_Groups::getGroupIDFromLibraryID($libraryID);
				
				// If key has write access to all groups, grant access if user
				// has write access to group
				if (!empty($this->permissions[0]['write'])) {
					$group = Zotero_Groups::get($groupID);
					return $group->userCanEdit($this->userID);
				}
				
				return false;
			
			default:
				throw new Exception("Unsupported library type '$libraryType'");
		}
	}
	
	
	public function setPermission($libraryID, $permission, $enabled) {
		if ($this->super) {
			throw new Exception("Super-user permissions already set");
		}
		
		switch ($permission) {
			case 'view':
			case 'library':
			case 'notes':
			case 'files':
			case 'write':
				break;
			
			default:
				throw new Exception("Invalid permission '$permission'");
		}
		
		$this->permissions[$libraryID][$permission] = $enabled;
	}
	
	
	public function setAnonymous() {
		$this->anonymous = true;
	}
	
	public function setPublications() {
		$this->publications = true;
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
					$privacy['library'] = true;
					$privacy['notes'] = true;
					break;
					
				case 2:
					$privacy['library'] = false;
					$privacy['notes'] = false;
					break;
				
				default:
					throw new Exception("External requests disabled on dev site");
			}
			
			$this->userPrivacy[$userID] = $privacy;
			return $privacy;
		}
		
		$sql = "SELECT metaKey, metaValue FROM users_meta WHERE userID=? AND metaKey LIKE 'privacy_publish%'";
		try {
			$rows = Zotero_WWW_DB_2::query($sql, $userID);
		}
		catch (Exception $e) {
			Z_Core::logError("WARNING: $e -- retrying on primary");
			$rows = Zotero_WWW_DB_1::query($sql, $userID);
		}
		
		$privacy = array(
			'library' => false,
			'notes' => false
		);
		foreach ($rows as $row) {
			$privacy[strtolower(substr($row['metaKey'], 15))] = (bool) (int) $row['metaValue'];
		}
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
			$privacy['view'] = true;
			$privacy['library'] = $group->libraryReading == 'all';
			$privacy['notes'] = $group->libraryReading == 'all';
		}
		else {
			$privacy['view'] = false;
			$privacy['library'] = false;
			$privacy['notes'] = false;
		}
		
		$this->groupPrivacy[$groupID] = $privacy;
		
		return $privacy;
	}
	
	
	public function hasPermission($object, $userID=false) {
		return false;
	}
}
?>
