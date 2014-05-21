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

class Zotero_Group {
	private $id;
	private $libraryID;
	private $ownerUserID;
	private $name;
	private $type;
	private $libraryEditing;
	private $libraryReading;
	private $fileEditing;
	private $description = "";
	private $url = "";
	private $hasImage = false;
	private $dateAdded;
	private $dateModified;
	private $version;
	
	private $loaded = false;
	private $changed = array();
	private $erased = false;
	
	
	public function __get($field) {
		if ($this->erased) {
			throw new Exception("Cannot access field '$field' of deleted group $this->id");
		}
		
		if (($this->id || $this->libraryID) && !$this->loaded) {
			$this->load();
		}
		
		switch ($field) {
			case 'id':
			case 'libraryID':
			case 'name':
			case 'type':
			case 'ownerUserID':
			case 'libraryEditing':
			case 'libraryReading':
			case 'fileEditing':
			case 'description':
			case 'url':
			case 'hasImage':
			case 'dateAdded':
			case 'dateModified':
			case 'version':
			case 'erased':
				break;
			
			case 'slug':
				if ($this->isPublic()) {
					return Zotero_Utilities::slugify($this->name);
				}
				return null;
			
			case 'etag':
				return $this->getETag();
			
			default:
				throw new Exception("Invalid group field '$field'");
		}
		
		return $this->$field;
	}
	
	
	public function __set($field, $value) {
		switch ($field) {
			// Set id and libraryID without loading
			case 'id':
			case 'libraryID':
				if ($this->loaded) {
					throw new Exception("Cannot set $field after group is already loaded");
				}
				$this->$field = $value;
				return;
			
			case 'name':
			case 'description':
			case 'ownerUserID':
				break;
			
			case 'hasImage':
				if (!is_bool($value)) {
					throw new Exception("hasImage must be a bool (was " . gettype($value) . ")");
				}
				break;
			
			case 'type':
				switch ($value) {
					case "PublicOpen":
					case "PublicClosed":
					case "Private":
						break;
						
					default:
						throw new Exception("Invalid group type '$value'");
				}
				break;
				
			case 'libraryEditing':
			case 'fileEditing':
				if ($field == 'fileEditing') {
					if (!$this->type) {
						throw new Exception("Group type must be set before fileEditing");
					}
					
					if ($value != 'none' && $this->type == 'PublicOpen') {
						throw new Exception("fileEditing cannot be enabled for PublicOpen group");
					}
				}
				
				switch ($value) {
					case "admins":
					case "members":
					case "none":
						break;
						
					default:
						throw new Exception("Invalid $field value '$value'");
				}
				break;
			
			case 'libraryReading':
				switch ($value) {
					case "members":
					case "all":
						break;
						
					default:
						throw new Exception("Invalid $field value '$value'");
				}
				break;
			
			case 'url':
				// TODO: validate URL
				break;
			
			default:
				throw new Exception("Invalid group field '$field'");
		}
		
		if ($this->id || $this->libraryID) {
			if (!$this->loaded) {
				$this->load();
			}
		}
		else {
			$this->loaded = true;
		}
		
		if ($this->$field == $value) {
			Z_Core::debug("Group $this->id $field value ($value) has not changed", 4);
			return;
		}
		$this->$field = $value;
		$this->changed[$field] = true;
	}
	
	
	public function exists() {
		return $this->__get('id') && $this->__get('libraryID');
	}
	
	
	public function isPublic() {
		return $this->type == 'PublicOpen' || $this->type == 'PublicClosed';
	}
	
	
	public function hasUser($userID) {
		if (!$this->exists()) {
			return array();
		}
		
		$sql = "SELECT COUNT(*) FROM groupUsers WHERE groupID=? AND userID=?";
		return !!Zotero_DB::valueQuery($sql, array($this->id, $userID));
	}
	
	
	public function getUsers() {
		if (!$this->exists()) {
			return array();
		}
		
		$sql = "SELECT userID FROM groupUsers WHERE groupID=?";
		$ids = Zotero_DB::columnQuery($sql, $this->id);
		if (!$ids) {
			// Shouldn't happen
			throw new Exception("Group has no users");
		}
		return $ids;
	}
	
	
	/**
	 * Returns group admins
	 *
	 * @return {Integer[]}	Array of userIDs
	 */
	public function getAdmins() {
		if (!$this->exists()) {
			return array();
		}
		
		$sql = "SELECT userID FROM groupUsers WHERE groupID=? AND role='admin'";
		$ids = Zotero_DB::columnQuery($sql, $this->id);
		if (!$ids) {
			return array();
		}
		return $ids;
	}
	
	
	/**
	 * Returns group members
	 *
	 * @return {Integer[]}	Array of userIDs
	 */
	public function getMembers() {
		if (!$this->exists()) {
			return array();
		}
		
		$sql = "SELECT userID FROM groupUsers WHERE groupID=? AND role='member'";
		$ids = Zotero_DB::columnQuery($sql, $this->id);
		if (!$ids) {
			return array();
		}
		
		$ids = Zotero_Users::getValidUsers($ids);
		
		return $ids;
	}
	
	
	public function getUserData($userID) {
		if (!$this->exists()) {
			throw new Exception("Group hasn't been saved");
		}
		
		$sql = "SELECT role, joined, lastUpdated FROM groupUsers WHERE groupID=? AND userID=?";
		$row = Zotero_DB::rowQuery($sql, array($this->id, $userID));
		if (!$row) {
			return false;
		}
		return $row;
	}
	
	
	public function getUserRole($userID) {
		$data = $this->getUserData($userID);
		if (!$data) {
			return false;
		}
		return $data['role'];
	}
	
	
	public function addUser($userID, $role='member') {
		if (!$this->exists()) {
			throw new Exception("Group hasn't been saved");
		}
		
		switch ($role) {
			case 'admin':
			case 'member':
				break;
				
			default:
				throw new Exception("Invalid role '$role' adding user $userID to group $this->id");
		}
		
		Zotero_DB::beginTransaction();
		
		if (!Zotero_Users::exists($userID)) {
			Zotero_Users::addFromWWW($userID);
		}
		
		$sql = "INSERT IGNORE INTO groupUsers (groupID, userID, role, joined)
					VALUES (?, ?, ?, CURRENT_TIMESTAMP)";
		$added = Zotero_DB::query($sql, array($this->id, $userID, $role));
		
		// Delete any record of this user losing access to the group
		if ($added) {
			$libraryID = Zotero_Users::getLibraryIDFromUserID($userID);
			$sql = "DELETE FROM syncDeleteLogIDs WHERE libraryID=? AND objectType='group' AND id=?";
			Zotero_DB::query($sql, array($libraryID, $this->id), Zotero_Shards::getByLibraryID($libraryID));
		}
		
		// If group is locked by a sync, flag for later timestamp update
		// once the sync is done so that the uploading user gets the change
		try {
			if ($syncUploadQueueID = Zotero_Sync::getUploadQueueIDByUserID($userID)) {
				Zotero_Sync::postWriteLog($syncUploadQueueID, 'groupUser', $this->id . '-' . $userID, 'update');
			}
		}
		catch (Exception $e) {
			Z_Core::logError($e);
		}
		
		Zotero_DB::commit();
		
		return $added;
	}
	
	
	public function updateUser($userID, $role='member') {
		if (!$this->exists()) {
			throw new Exception("Group hasn't been saved");
		}
		
		switch ($role) {
			case 'admin':
			case 'member':
				break;
				
			default:
				throw new Exception("Invalid role '$role' updating user $userID in group $this->id");
		}
		
		Zotero_DB::beginTransaction();
		
		$oldRole = $this->getUserRole($userID);
		if ($oldRole == $role) {
			Z_Core::debug("Role hasn't changed for user $userID in group $this->id");
			Zotero_DB::commit();
			return;
		}
		if ($oldRole == 'owner') {
			throw new Exception("Cannot change group owner to $role for group $this->id", Z_ERROR_CANNOT_DELETE_GROUP_OWNER);
		}
		
		$sql = "UPDATE groupUsers SET role=?, lastUpdated=CURRENT_TIMESTAMP
					WHERE groupID=? AND userID=?";
		$updated = Zotero_DB::query($sql, array($role, $this->id, $userID));
		
		// If group is locked by a sync, flag for later timestamp update
		// once the sync is done so that the uploading user gets the change
		try {
			if ($syncUploadQueueID = Zotero_Sync::getUploadQueueIDByUserID($userID)) {
				Zotero_Sync::postWriteLog($syncUploadQueueID, 'groupUser', $this->id . '-' . $userID, 'update');
			}
		}
		catch (Exception $e) {
			Z_Core::logError($e);
		}
		
		Zotero_DB::commit();
		
		return $updated;
	}
	
	
	public function removeUser($userID) {
		if (!$this->exists()) {
			throw new Exception("Group hasn't been saved");
		}
		
		Zotero_DB::beginTransaction();
		
		if (!$this->hasUser($userID)) {
			throw new Exception("User $userID is not a member of group $groupID", Z_ERROR_USER_NOT_GROUP_MEMBER);
		}
		
		$role = $this->getUserRole($userID);
		if ($role == 'owner') {
			throw new Exception("Cannot delete owner of group $this->id", Z_ERROR_CANNOT_DELETE_GROUP_OWNER);
		}
		
		// Remove group from permissions the user has granted
		$sql = "DELETE KP FROM keyPermissions KP JOIN `keys` USING (keyID) WHERE userID=? AND libraryID=?";
		Zotero_DB::query($sql, array($userID, $this->libraryID));
		
		$sql = "DELETE FROM groupUsers WHERE groupID=? AND userID=?";
		Zotero_DB::query($sql, array($this->id, $userID));
		
		// A group user removal is logged as a deletion of the group from the user's personal library
		$sql = "REPLACE INTO syncDeleteLogIDs (libraryID, objectType, id) VALUES (?, 'group', ?)";
		$libraryID = Zotero_Users::getLibraryIDFromUserID($userID);
		Zotero_DB::query($sql, array($libraryID, $this->id), Zotero_Shards::getByLibraryID($libraryID));
		
		// If group is locked by a sync, flag for later timestamp update
		// once the sync is done so that the uploading user gets the change
		try {
			if ($syncUploadQueueID = Zotero_Sync::getUploadQueueIDByUserID($userID)) {
				Zotero_Sync::postWriteLog($syncUploadQueueID, 'groupUser', $this->id . '-' . $userID, 'delete');
			}
		}
		catch (Exception $e) {
			Z_Core::logError($e);
		}
		
		Zotero_DB::commit();
	}
	
	
	public function userCanRead($userID) {
		if (($this->id || $this->libraryID) && !$this->loaded) {
			$this->load();
		}
		
		// All members can read
		$role = $this->getUserRole($userID);
		if ($role) {
			return true;
		}
		
		return $this->isPublic() && $this->libraryReading == 'all';
	}
	
	
	public function userCanEdit($userID) {
		if (($this->id || $this->libraryID) && !$this->loaded) {
			$this->load();
		}
		
		$role = $this->getUserRole($userID);
		switch ($role) {
			case 'owner':
			case 'admin':
				return true;
			
			case 'member':
				if ($this->libraryEditing == 'members') {
					return true;
				}
		}
		return false;
	}
	
	
	public function userCanEditFiles($userID) {
		if (($this->id || $this->libraryID) && !$this->loaded) {
			$this->load();
		}
		
		if ($this->fileEditing == 'none') {
			return false;
		}
		
		$role = $this->getUserRole($userID);
		switch ($role) {
			case 'owner':
			case 'admin':
				return true;
			
			case 'member':
				if ($this->fileEditing == 'members') {
					return true;
				}
		}
		return false;
	}
	
	
	/**
	 * Returns group items
	 *
	 * @return {Integer[]}	Array of itemIDs
	 */
	public function getItems($asIDs=false) {
		$sql = "SELECT itemID FROM items WHERE libraryID=?";
		$ids = Zotero_DB::columnQuery($sql, $this->libraryID, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$ids) {
			return array();
		}
		
		if ($asIDs) {
			return $ids;
		}
		
		return Zotero_Items::get($this->libraryID, $ids);
	}
	
	
	/**
	 * Returns the number of items in the group
	 */
	public function numItems() {
		if (!$this->loaded) {
			$this->load();
		}
		
		$sql = "SELECT COUNT(*) FROM items WHERE libraryID=?";
		return Zotero_DB::valueQuery($sql, $this->libraryID, Zotero_Shards::getByLibraryID($this->libraryID));
	}
	
	
	public function save() {
		if (!$this->loaded) {
			Z_Core::debug("Not saving unloaded group $this->id");
			return;
		}
		
		if (empty($this->changed)) {
			Z_Core::debug("Group $this->id has not changed", 4);
			return;
		}
		
		if (!$this->ownerUserID) {
			throw new Exception("Cannot save group without owner");
		}
		
		if (!$this->name) {
			throw new Exception("Cannot save group without name");
		}
		
		if (mb_strlen($this->description) > 1024) {
			throw new Exception("Group description too long", Z_ERROR_GROUP_DESCRIPTION_TOO_LONG);
		}
		
		Zotero_DB::beginTransaction();
		
		$libraryID = $this->libraryID;
		if (!$libraryID) {
			$shardID = Zotero_Shards::getNextShard();
			$libraryID = Zotero_Libraries::add('group', $shardID);
			if (!$libraryID) {
				throw new Exception('libraryID not available after Zotero_Libraries::add()');
			}
		}
		
		$fields = array(
			'name',
			'slug',
			'type',
			'description',
			'url',
			'hasImage'
		);
		
		if ($this->isPublic()) {
			$existing = Zotero_Groups::publicNameExists($this->name);
			if ($existing && $existing != $this->id) {
				throw new Exception("Public group with name '$this->name' already exists", Z_ERROR_PUBLIC_GROUP_EXISTS);
			}
		}
		
		$fields = array_merge($fields, array('libraryEditing', 'libraryReading', 'fileEditing'));
		
		$sql = "INSERT INTO groups
					(groupID, libraryID, " . implode(", ", $fields) . ", dateModified)
					VALUES (?, ?, " . implode(", ", array_fill(0, sizeOf($fields), "?")) . ", CURRENT_TIMESTAMP)";
		$params = array($this->id, $libraryID);
		foreach ($fields as $field) {
			if (is_bool($this->$field)) {
				$params[] = (int) $this->$field;
			}
			else {
				$params[] = $this->$field;
			}
		}
		$sql .= " ON DUPLICATE KEY UPDATE ";
		$q = array();
		foreach ($fields as $field) {
			$q[] = "$field=?";
			if (is_bool($this->$field)) {
				$params[] = (int) $this->$field;
			}
			else {
				$params[] = $this->$field;
			}
		}
		$sql .= implode(", ", $q) . ", "
			. "dateModified=CURRENT_TIMESTAMP, "
			. "version=IF(version = 255, 1, version + 1)";
		$insertID = Zotero_DB::query($sql, $params);
		
		if (!$this->id) {
			if (!$insertID) {
				throw new Exception("Group id not available after INSERT");
			}
			$this->id = $insertID;
		}
		
		if (!$this->libraryID) {
			$this->libraryID = $libraryID;
		}
		
		// If creating group or changing owner
		if (!empty($this->changed['ownerUserID'])) {
			$sql = "SELECT userID FROM groupUsers WHERE groupID=? AND role='owner'";
			$currentOwner = Zotero_DB::valueQuery($sql, $this->id);
			
			// Move existing owner out of the way, if there is one
			if ($currentOwner) {
				$sql = "UPDATE groupUsers SET role='admin' WHERE groupID=? AND userID=?";
				Zotero_DB::query($sql, array($this->id, $currentOwner));
			}
			
			// Make sure new owner exists in DB
			if (!Zotero_Users::exists($this->ownerUserID)) {
				Zotero_Users::addFromWWW($this->ownerUserID);
			}
			
			// Add new owner to group
			$sql = "INSERT INTO groupUsers (groupID, userID, role, joined) VALUES
					(?, ?, 'owner', CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE
					role='owner', lastUpdated=CURRENT_TIMESTAMP";
			Zotero_DB::query($sql, array($this->id, $this->ownerUserID));
			
			// Delete any record of this user losing access to the group
			$libraryID = Zotero_Users::getLibraryIDFromUserID($this->ownerUserID);
			$sql = "DELETE FROM syncDeleteLogIDs WHERE libraryID=? AND objectType='group' AND id=?";
			Zotero_DB::query($sql, array($libraryID, $this->id), Zotero_Shards::getByLibraryID($this->libraryID));
		}
		
		// If any of the group's users have a queued upload, flag group for a timestamp
		// update once the sync is done so that the uploading user gets the change
		try {
			$userIDs = self::getUsers();
			foreach ($userIDs as $userID) {
				if ($syncUploadQueueID = Zotero_Sync::getUploadQueueIDByUserID($userID)) {
					Zotero_Sync::postWriteLog($syncUploadQueueID, 'group', $this->id, 'update');
				}
			}
		}
		catch (Exception $e) {
			Z_Core::logError($e);
		}
		
		Zotero_DB::commit();
		
		$this->load();
		
		return $libraryID;
	}
	
	
	public function erase() {
		if (!$this->loaded) {
			Z_Core::debug("Not deleting unloaded group $this->id");
			return;
		}
		
		Zotero_DB::beginTransaction();
		
		$userIDs = self::getUsers();
		
		$this->logGroupLibraryRemoval();
		
		Zotero_Libraries::deleteCachedData($this->libraryID);
		
		$sql = "DELETE FROM shardLibraries WHERE libraryID=?";
		$deleted = Zotero_DB::query($sql, $this->libraryID, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$deleted) {
			throw new Exception("Group not deleted");
		}
		
		$sql = "DELETE FROM libraries WHERE libraryID=?";
		$deleted = Zotero_DB::query($sql, $this->libraryID);
		if (!$deleted) {
			throw new Exception("Group not deleted");
		}
		
		// Delete key permissions for this library, and then delete any keys
		// that had no other permissions
		$sql = "SELECT keyID FROM keyPermissions WHERE libraryID=?";
		$keyIDs = Zotero_DB::columnQuery($sql, $this->libraryID);
		if ($keyIDs) {
			$sql = "DELETE FROM keyPermissions WHERE libraryID=?";
			Zotero_DB::query($sql, $this->libraryID);
			
			$sql = "DELETE K FROM `keys` K LEFT JOIN keyPermissions KP USING (keyID)
					WHERE keyID IN ("
				. implode(', ', array_fill(0, sizeOf($keyIDs), '?'))
				. ") AND KP.keyID IS NULL";
			Zotero_DB::query($sql, $keyIDs);
		}
		
		// If group is locked by a sync, flag group for a timestamp update
		// once the sync is done so that the uploading user gets the change
		try {
			foreach ($userIDs as $userID) {
				if ($syncUploadQueueID = Zotero_Sync::getUploadQueueIDByUserID($userID)) {
					Zotero_Sync::postWriteLog($syncUploadQueueID, 'group', $this->id, 'delete');
				}
			}
		}
		catch (Exception $e) {
			Z_Core::logError($e);
		}
		
		Zotero_DB::commit();
		
		$this->erased = true;
	}
	
	
	/**
	 * Converts group to a SimpleXMLElement item
	 *
	 * @return	SimpleXMLElement				Group data as SimpleXML element
	 */
	public function toHTML() {
		if (($this->id || $this->libraryID) && !$this->loaded) {
			$this->load();
		}
		
		$html = new SimpleXMLElement("<table/>");
		
		$tr = Zotero_Atom::addHTMLRow(
			$html,
			'owner',
			"Owner",
			"",
			true
		);
		$tr->td->a = Zotero_Users::getUsername($this->ownerUserID);
		$tr->td->a['href'] = Zotero_URI::getUserURI($this->ownerUserID);
		
		Zotero_Atom::addHTMLRow($html, '', "Type", preg_replace('/([a-z])([A-Z])/', '$1 $2', $this->type));
		
		Zotero_Atom::addHTMLRow($html, '', "Description", $this->description);
		Zotero_Atom::addHTMLRow($html, '', "URL", $this->url);
		
		Zotero_Atom::addHTMLRow($html, '', "Library Reading", ucwords($this->libraryReading));
		Zotero_Atom::addHTMLRow($html, '', "Library Editing", ucwords($this->libraryEditing));
		Zotero_Atom::addHTMLRow($html, '', "File Editing", ucwords($this->fileEditing));
		
		$admins = $this->getAdmins();
		if ($admins) {
			$tr = Zotero_Atom::addHTMLRow($html, '', "Admins", '', true);
			$ul = $tr->td->addChild('ul');
			foreach ($admins as $admin) {
				$li = $ul->addChild('li');
				$li->a = Zotero_Users::getUsername($admin);
				$li->a['href'] = Zotero_URI::getUserURI($admin);
			}
		}
		
		return $html;
	}
	
	
	/**
	 * Converts group to a JSON object
	 */
	public function toJSON($asArray=false, $includeEmpty=false) {
		if (($this->id || $this->libraryID) && !$this->loaded) {
			$this->load();
		}
		
		$arr = array();
		$arr['name'] = $this->name;
		$arr['owner'] = $this->ownerUserID;
		$arr['type'] = $this->type;
		
		if ($this->description || $includeEmpty) {
			$arr['description'] = $this->description;
		}
		
		if ($this->url || $includeEmpty) {
			$arr['url'] = $this->url;
		}
		if ($this->hasImage) {
			$arr['hasImage'] = 1;
		}
		
		$arr['libraryEditing'] = $this->libraryEditing;
		$arr['libraryReading'] = $this->libraryReading;
		$arr['fileEditing'] = $this->fileEditing;
		
		$admins = $this->getAdmins();
		if ($admins) {
			$arr['admins'] = $admins;
		}
		
		$members = $this->getMembers();
		if ($members) {
			$arr['members'] = $members;
		}
		
		if ($asArray) {
			return $arr;
		}
		
		return Zotero_Utilities::formatJSON($json);
	}
	
	
	/**
	 * Converts group to a SimpleXMLElement item
	 *
	 * @return	SimpleXMLElement				Group data as SimpleXML element
	 */
	public function toXML($userID=false) {
		if (($this->id || $this->libraryID) && !$this->loaded) {
			$this->load();
		}
		
		$syncMode = !!$userID;
		
		$xml = '<group';
		if (!$syncMode) {
			$xml .= ' xmlns="' . Zotero_Atom::$nsZoteroTransfer . '"';
		}
		$xml .= '/>';
		$xml = new SimpleXMLElement($xml);
		
		$xml['id'] = $this->id;
		if ($syncMode) {
			$xml['libraryID'] = $this->libraryID;
		}
		else {
			$xml['owner'] = $this->ownerUserID;
			$xml['type'] = $this->type;
		}
		$xml['name'] = $this->name;
		if ($syncMode) {
			$xml['editable'] = (int) $this->userCanEdit($userID);
			$xml['filesEditable'] = (int) $this->userCanEditFiles($userID);
		}
		else {
			$xml['libraryEditing'] = $this->libraryEditing;
			$xml['libraryReading'] = $this->libraryReading;
			$xml['fileEditing'] = $this->fileEditing;
		}
		if ($this->description) {
			$xml->description = $this->description;
		}
		if (!$syncMode && $this->url) {
			$xml->url = $this->url;
		}
		if (!$syncMode && $this->hasImage) {
			$xml['hasImage'] = 1;
		}
		
		if (!$syncMode) {
			$admins = $this->getAdmins();
			if ($admins) {
				$xml->admins = implode(' ', $admins);
			}
			
			$members = $this->getMembers();
			if ($members) {
				$xml->members = implode(' ', $members);
			}
		}
		
		return $xml;
	}
	
	
	public function toAtom($queryParams) {
		if (!empty($queryParams['content'])) {
			$content = $queryParams['content'];
		}
		else {
			$content = array('none');
		}
		// TEMP: multi-format support
		$content = $content[0];
		
		if (!$this->loaded) {
			$this->load();
		}
		
		$xml = new SimpleXMLElement(
			'<?xml version="1.0" encoding="UTF-8"?>'
			. '<entry xmlns="' . Zotero_Atom::$nsAtom . '" '
			. 'xmlns:zapi="' . Zotero_Atom::$nsZoteroAPI . '" '
			. 'xmlns:zxfer="' . Zotero_Atom::$nsZoteroTransfer . '"/>'
		);
		
		$title = $this->name ? $this->name : '[Untitled]';
		$xml->title = $title;
		
		$author = $xml->addChild('author');
		$ownerLibraryID = Zotero_Users::getLibraryIDFromUserID($this->ownerUserID);
		$author->name = Zotero_Users::getUsername($this->ownerUserID);
		$author->uri = Zotero_URI::getLibraryURI($ownerLibraryID);
		
		$xml->id = Zotero_URI::getGroupURI($this);
		
		$xml->published = Zotero_Date::sqlToISO8601($this->dateAdded);
		$xml->updated = Zotero_Date::sqlToISO8601($this->dateModified);
		
		$link = $xml->addChild("link");
		$link['rel'] = "self";
		$link['type'] = "application/atom+xml";
		$link['href'] = Zotero_API::getGroupURI($this);
		
		$link = $xml->addChild('link');
		$link['rel'] = 'alternate';
		$link['type'] = 'text/html';
		$link['href'] = Zotero_URI::getGroupURI($this);
		
		$xml->addChild(
			'zapi:groupID',
			$this->id,
			Zotero_Atom::$nsZoteroAPI
		);
		
		$xml->addChild(
			'zapi:numItems',
			$this->numItems(),
			Zotero_Atom::$nsZoteroAPI
		);
		
		if ($content == 'html') {
			$xml->content['type'] = 'html';
			$htmlXML = $this->toHTML();
			$xml->content->div = '';
			$xml->content->div['xmlns'] = Zotero_Atom::$nsXHTML;
			$fNode = dom_import_simplexml($xml->content->div);
			$subNode = dom_import_simplexml($htmlXML);
			$importedNode = $fNode->ownerDocument->importNode($subNode, true);
			$fNode->appendChild($importedNode);
		}
		else if ($content == 'json') {
			$xml->content['type'] = 'application/json';
			$xml->content['etag'] = $this->etag;
			// Deprecated
			if ($queryParams['apiVersion'] < 2) {
				$xml->content->addAttribute(
					"zapi:etag",
					$this->etag,
					Zotero_Atom::$nsZoteroAPI
				);
			}
			$xml->content = $this->toJSON(false, true);
		}
		else if ($content == 'full') {
			$xml->content['type'] = 'application/xml';
			$fullXML = $this->toXML();
			$fNode = dom_import_simplexml($xml->content);
			$subNode = dom_import_simplexml($fullXML);
			$importedNode = $fNode->ownerDocument->importNode($subNode, true);
			$fNode->appendChild($importedNode);
		}
		
		error_log('=====');
		error_log($content);
		error_log($xml->asXML());
		
		return $xml;
	}
	
	
	public function memberToAtom($userID) {
		if (!is_int($userID)) {
			throw new Exception("userID must be an integer (was " . gettype($userID) . ")");
		}
		
		if (!$this->loaded) {
			$this->load();
		}
		
		$groupUserData = $this->getUserData($userID);
		if (!$groupUserData) {
			throw new Exception("User $userID is not a member of group $this->id", Z_ERROR_USER_NOT_GROUP_MEMBER);
		}
		
		$xml = new SimpleXMLElement(
			'<?xml version="1.0" encoding="UTF-8"?>'
			. '<entry xmlns="' . Zotero_Atom::$nsAtom . '" '
			. 'xmlns:zapi="' . Zotero_Atom::$nsZoteroAPI . '" '
			. 'xmlns:xfer="' . Zotero_Atom::$nsZoteroTransfer . '"/>'
		);
		
		// If we know the username, provide that
		// TODO: get and cache full names
		if (Zotero_Users::exists($userID)) {
			$title = Zotero_Users::getUsername($userID);
		}
		else {
			$title = "User $userID";
		}
		$xml->title = $title;
		
		$author = $xml->addChild('author');
		$author->name = "Zotero";
		$author->uri = "http://zotero.org";
		
		$xml->id = Zotero_URI::getGroupUserURI($this, $userID);
		
		$xml->published = Zotero_Date::sqlToISO8601($groupUserData['joined']);
		$xml->updated = Zotero_Date::sqlToISO8601($groupUserData['lastUpdated']);
		
		$link = $xml->addChild("link");
		$link['rel'] = "self";
		$link['type'] = "application/atom+xml";
		$link['href'] = Zotero_API::getGroupUserURI($this, $userID);
		
		$link = $xml->addChild('link');
		$link['rel'] = 'alternate';
		$link['type'] = 'text/html';
		$link['href'] = Zotero_URI::getGroupUserURI($this, $userID);
		
		$xml->content['type'] = 'application/xml';
		
		$userXML = new SimpleXMLElement(
			'<user xmlns="' . Zotero_Atom::$nsZoteroTransfer . '"/>'
		);
		// This method of adding the element seems to be necessary to get the
		// namespace prefix to show up
		$fNode = dom_import_simplexml($xml->content);
		$subNode = dom_import_simplexml($userXML);
		$importedNode = $fNode->ownerDocument->importNode($subNode, true);
		$fNode->appendChild($importedNode);
		
		$xml->content->user['id'] = $userID;
		$xml->content->user['role'] = $groupUserData['role'];
		
		return $xml;
	}
	
	
	public function itemToAtom($itemID) {
		if (!is_int($itemID)) {
			throw new Exception("itemID must be an integer (was " . gettype($itemID) . ")");
		}
		
		if (!$this->loaded) {
			$this->load();
		}
		
		//$groupUserData = $this->getUserData($itemID);
		$item = Zotero_Items::get($this->libraryID, $itemID);
		if (!$item) {
			throw new Exception("Item $itemID doesn't exist");
		}
		
		$xml = new SimpleXMLElement(
			'<?xml version="1.0" encoding="UTF-8"?>'
			. '<entry xmlns="' . Zotero_Atom::$nsAtom . '" '
			. 'xmlns:zapi="' . Zotero_Atom::$nsZoteroAPI . '" '
			. 'xmlns:xfer="' . Zotero_Atom::$nsZoteroTransfer . '"/>'
		);
		
		$title = $item->getDisplayTitle(true);
		$title = $title ? $title : '[Untitled]';
		// Strip HTML from note titles
		if ($item->isNote()) {
			// Clean and strip HTML, giving us an HTML-encoded plaintext string
			$title = strip_tags($GLOBALS['HTMLPurifier']->purify($title));
			// Unencode plaintext string
			$title = html_entity_decode($title);
		}
		$xml->title = $title;
		
		$author = $xml->addChild('author');
		$author->name = Zotero_Libraries::getName($item->libraryID);
		$author->uri = Zotero_URI::getLibraryURI($item->libraryID);
		
		$xml->id = Zotero_URI::getItemURI($item);
		
		$xml->published = Zotero_Date::sqlToISO8601($item->dateAdded);
		$xml->updated = Zotero_Date::sqlToISO8601($item->dateModified);
		
		$link = $xml->addChild("link");
		$link['rel'] = "self";
		$link['type'] = "application/atom+xml";
		$link['href'] = Zotero_API::getItemURI($item);
		
		$link = $xml->addChild('link');
		$link['rel'] = 'alternate';
		$link['type'] = 'text/html';
		$link['href'] = Zotero_URI::getItemURI($item);
		
		$xml->content['type'] = 'application/xml';
		
		$itemXML = new SimpleXMLElement(
			'<item xmlns="' . Zotero_Atom::$nsZoteroTransfer . '"/>'
		);
		// This method of adding the element seems to be necessary to get the
		// namespace prefix to show up
		$fNode = dom_import_simplexml($xml->content);
		$subNode = dom_import_simplexml($itemXML);
		$importedNode = $fNode->ownerDocument->importNode($subNode, true);
		$fNode->appendChild($importedNode);
		
		$xml->content->item['id'] = $itemID;
		
		return $xml;
	}
	
	
	private function load() {
		$sql = "SELECT * FROM groups WHERE groupID=?";
		$row = Zotero_DB::rowQuery($sql, $this->id);
		if (!$row) {
			return false;
		}
		
		foreach ($row as $field=>$value) {
			switch ($field) {
				case 'groupID':
				case 'slug':
				// TEMP
				case 'version':
					continue 2;
			}
			
			$this->$field = $value;
		}
		
		$sql = "SELECT userID FROM groupUsers WHERE groupID=? AND role='owner'";
		$userID = Zotero_DB::valueQuery($sql, $this->id);
		if (!$userID) {
			throw new Exception("Group $this->id doesn't have an owner");
		}
		$this->ownerUserID = $userID;
		
		$this->loaded = true;
		$this->changed = array();
	}
	
	
	private function getETag() {
		if (!$this->loaded) {
			$this->load();
		}
		return md5($this->dateModified . $this->version);
	}
	
	
	private function logGroupLibraryRemoval() {
		$users = $this->getUsers();
		
		$usersByShard = array();
		foreach ($users as $userID) {
			$shardID = Zotero_Shards::getByUserID($userID);
			if (!isset($usersByShard[$shardID])) {
				$usersByShard[$shardID] = array();
			}
			$usersByShard[$shardID][] = $userID;
		}
		
		foreach ($usersByShard as $shardID=>$userIDs) {
			// Add to delete log for all group members
			$sql = "REPLACE INTO syncDeleteLogIDs (libraryID, objectType, id) VALUES ";
			$params = array();
			$sets = array();
			foreach ($userIDs as $userID) {
				$libraryID = Zotero_Users::getLibraryIDFromUserID($userID);
				$sets[] = "(?,?,?)";
				$params = array_merge($params, array($libraryID, 'group', $this->id));
			}
			$sql .= implode(",", $sets);
			Zotero_DB::query($sql, $params, $shardID);
		}
	}
}
?>
