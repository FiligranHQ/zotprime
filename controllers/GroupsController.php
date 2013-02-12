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

require('ApiController.php');

class GroupsController extends ApiController {
	public function groups() {
		$groupID = $this->objectGroupID;
		
		//
		// Add a group
		//
		if ($this->method == 'POST') {
			if (!$this->permissions->isSuper()) {
				$this->e403();
			}
			
			if ($groupID) {
				$this->e400("POST requests cannot end with a groupID (did you mean PUT?)");
			}
			
			try {
				$group = @new SimpleXMLElement($this->body);
			}
			catch (Exception $e) {
				$this->e400("$this->method data is not valid XML");
			}
			
			if ((int) $group['id']) {
				$this->e400("POST requests cannot contain a groupID in '" . $this->body . "'");
			}
			
			$fields = $this->getFieldsFromGroupXML($group);
			
			Zotero_DB::beginTransaction();
			
			try {
				$group = new Zotero_Group;
				foreach ($fields as $field=>$val) {
					$group->$field = $val;
				}
				$group->save();
			}
			catch (Exception $e) {
				if (strpos($e->getMessage(), "Invalid") === 0) {
					$this->e400($e->getMessage() . " in " . $this->body . "'");
				}
				
				switch ($e->getCode()) {
					case Z_ERROR_GROUP_NAME_UNAVAILABLE:
						$this->e400($e->getMessage());
					
					default:
						$this->handleException($e);
				}
			}
			
			$this->queryParams['content'] = array('full');
			$this->responseXML = $group->toAtom($this->queryParams);
			
			Zotero_DB::commit();
			
			$url = Zotero_API::getGroupURI($group);
			$this->responseCode = 201;
			header("Location: " . $url, false, 201);
			$this->end();
		}
		
		//
		// Update a group
		//
		if ($this->method == 'PUT') {
			if (!$this->permissions->isSuper()) {
				$this->e403();
			}
			
			if (!$groupID) {
				$this->e400("PUT requests must end with a groupID (did you mean POST?)");
			}
			
			try {
				$group = @new SimpleXMLElement($this->body);
			}
			catch (Exception $e) {
				$this->e400("$this->method data is not valid XML");
			}
			
			$fields = $this->getFieldsFromGroupXML($group);
			
			// Group id is optional, but, if it's there, make sure it matches
			$id = (string) $group['id'];
			if ($id && $id != $groupID) {
				$this->e400("Group ID $id does not match group ID $groupID from URI");
			}
			
			Zotero_DB::beginTransaction();
			
			try {
				$group = Zotero_Groups::get($groupID);
				if (!$group) {
					$this->e404("Group $groupID does not exist");
				}
				foreach ($fields as $field=>$val) {
					$group->$field = $val;
				}
				
				if ($this->ifUnmodifiedSince
						&& strtotime($group->dateModified) > $this->ifUnmodifiedSince) {
					$this->e412();
				}
				
				$group->save();
			}
			catch (Exception $e) {
				if (strpos($e->getMessage(), "Invalid") === 0) {
					$this->e400($e->getMessage() . " in " . $this->body . "'");
				}
				else if ($e->getCode() == Z_ERROR_GROUP_DESCRIPTION_TOO_LONG) {
					$this->e400($e->getMessage());
				}
				$this->handleException($e);
			}
			
			$this->queryParams['content'] = array('full');
			$this->responseXML = $group->toAtom($this->queryParams);
			
			Zotero_DB::commit();
			
			$this->end();
		}
		
		
		//
		// Delete a group
		//
		if ($this->method == 'DELETE') {
			if (!$this->permissions->isSuper()) {
				$this->e403();
			}
			
			if (!$groupID) {
				$this->e400("DELETE requests must end with a groupID");
			}
			
			Zotero_DB::beginTransaction();
			
			$group = Zotero_Groups::get($groupID);
			if (!$group) {
				$this->e404("Group $groupID does not exist");
			}
			$group->erase();
			Zotero_DB::commit();
			
			header("HTTP/1.1 204 No Content");
			exit;
		}
		
		
		//
		// View one or more groups
		//
		
		// Single group
		if ($groupID) {
			$group = Zotero_Groups::get($groupID);
			if (!$this->permissions->canAccess($this->objectLibraryID)) {
				$this->e403();
			}
			if (!$group) {
				$this->e404("Group not found");
			}
			header("ETag: " . $group->etag);
			$this->responseXML = $group->toAtom($this->queryParams);
		}
		// Multiple groups
		else {
			if ($this->objectUserID) {
				$title = Zotero_Users::getUsername($this->objectUserID) . "’s Groups";
			}
			else {
				// For now, only root can do unrestricted group searches
				if (!$this->permissions->isSuper()) {
					$this->e403();
				}
				
				$title = "Groups";
			}
			
			try {
				$results = Zotero_Groups::getAllAdvanced($this->objectUserID, $this->queryParams, $this->permissions);
			}
			catch (Exception $e) {
				switch ($e->getCode()) {
					case Z_ERROR_INVALID_GROUP_TYPE:
						$this->e400($e->getMessage());
				}
				throw ($e);
			}
			
			$groups = $results['groups'];
			$totalResults = $results['totalResults'];
			
			switch ($this->queryParams['format']) {
				case 'atom':
					$this->responseXML = Zotero_Atom::createAtomFeed(
						$title,
						$this->uri,
						$groups,
						$totalResults,
						$this->queryParams,
						$this->permissions
					);
					break;
				
				case 'etags':
					$json = array();
					foreach ($groups as $group) {
						$json[$group->id] = $group->etag;
					}
					if ($this->queryParams['pprint']) {
						header("Content-Type: text/plain");
					}
					else {
						header("Content-Type: application/json");
					}
					echo json_encode($json);
					break;
			}
		}
		
		$this->end();
	}
	
	
	public function groupUsers() {
		// For now, only allow root and user access
		if (!$this->permissions->isSuper()) {
			$this->e403();
		}
		
		$groupID = $this->scopeObjectID;
		$userID = $this->objectID;
		
		$group = Zotero_Groups::get($groupID);
		if (!$group) {
			$this->e404("Group $groupID does not exist");
		}
		
		// Add multiple users to group
		if ($this->method == 'POST') {
			if ($userID) {
				$this->e400("POST requests cannot end with a userID (did you mean PUT?)");
			}
			
			// Body can contain multiple <user> blocks, so stuff in root element
			try {
				$xml = @new SimpleXMLElement("<root>" . $this->body . "</root>");
			}
			catch (Exception $e) {
				$this->e400("$this->method data is not valid XML");
			}
			
			$addedUserIDs = array();
			
			Zotero_DB::beginTransaction();
			
			foreach ($xml->user as $user) {
				$id = (int) $user['id'];
				$role = (string) $user['role'];
				
				if (!$id) {
					$this->e400("User ID not provided in '" . $user->asXML() . "'");
				}
				
				if (!$role) {
					$this->e400("Role not provided in '" . $user->asXML() . "'");
				}
				
				try {
					$added = $group->addUser($id, $role);
				}
				catch (Exception $e) {
					if (strpos($e->getMessage(), "Invalid role") === 0) {
						$this->e400("Invalid role '$role' in " . $user->asXML() . "'");
					}
					$this->handleException($e);
				}
				
				if ($added) {
					$addedUserIDs[] = $id;
				}
			}
			
			// Response after adding
			$entries = array();
			foreach ($addedUserIDs as $addedUserID) {
				$entries[] = $group->memberToAtom($addedUserID);
			}
			
			$title = "Users added to group '$group->name'";
			$this->responseXML = Zotero_Atom::createAtomFeed(
				$title,
				$this->uri,
				$entries,
				null,
				$this->queryParams,
				$this->permissions
			);
			
			Zotero_DB::commit();
			
			$this->end();
		}
		
		// Add a single user to group
		if ($this->method == 'PUT') {
			if (!$userID) {
				$this->e400("PUT requests must end with a userID (did you mean POST?)");
			}
			
			try {
				$user = @new SimpleXMLElement($this->body);
			}
			catch (Exception $e) {
				$this->e400("$this->method data is not valid XML");
			}
			
			$id = (int) $user['id'];
			$role = (string) $user['role'];
			
			// User id is optional, but, if it's there, make sure it matches
			if ($id && $id != $userID) {
				$this->e400("User ID $id does not match user ID $userID from URI");
			}
			
			if (!$role) {
				$this->e400("Role not provided in '$this->body'");
			}
			
			Zotero_DB::beginTransaction();
			
			$changedUserIDs = array();
			
			try {
				if ($role == 'owner') {
					if ($userID != $group->ownerUserID) {
						$changedUserIDs[] = $group->ownerUserID;
						$group->ownerUserID = $userID;
						$group->save();
						$changedUserIDs[] = $userID;
					}
				}
				else {
					if ($group->hasUser($userID)) {
						try {
							$updated = $group->updateUser($userID, $role);
						}
						catch (Exception $e) {
							switch ($e->getCode()) {
								case Z_ERROR_CANNOT_DELETE_GROUP_OWNER:
									$this->e400($e->getMessage());
								
								default:
									$this->handleException($e);
							}
						}
						if ($updated) {
							$changedUsersIDs[] = $userID;
						}
					}
					else {
						$added = $group->addUser($userID, $role);
						if ($added) {
							$changedUserIDs[] = $userID;
						}
					}
				}
			}
			catch (Exception $e) {
				if (strpos($e->getMessage(), "Invalid role") === 0) {
					$this->e400("Invalid role '$role' in '$this->body'");
				}
				$this->handleException($e);
			}
			
			// Response after adding
			$entries = array();
			foreach ($changedUserIDs as $changedUserID) {
				$entries[] = $group->memberToAtom($changedUserID);
			}
			
			$title = "Users changed in group '$group->name'";
			$this->responseXML = Zotero_Atom::createAtomFeed(
				$title,
				$this->uri,
				$entries,
				null,
				$this->queryParams,
				$this->permissions
			);
			
			Zotero_DB::commit();
			
			$this->end();
		}
		
		
		if ($this->method == 'DELETE') {
			if (!$userID) {
				$this->e400("DELETE requests must end with a userID");
			}
			
			Zotero_DB::beginTransaction();
			
			try {
				$group->removeUser($userID);
			}
			catch (Exception $e) {
				switch ($e->getCode()) {
					case Z_ERROR_CANNOT_DELETE_GROUP_OWNER:
						$this->e400($e->getMessage());
					
					case Z_ERROR_USER_NOT_GROUP_MEMBER:
						$this->e404($e->getMessage());
					
					default:
						$this->handleException($e);
				}
			}
			
			Zotero_DB::commit();
			
			header("HTTP/1.1 204 No Content");
			exit;
		}
		
		// Single user
		if ($userID) {
			$this->responseXML = $group->memberToAtom($userID);
			$this->end();
		}
		
		// Multiple users
		$title = "Members of '$group->name'";
		
		$entries = array();
		$memberIDs = array_merge(
			array($group->ownerUserID),
			$group->getAdmins(),
			$group->getMembers()
		);
		foreach ($memberIDs as $userID) {
			$entries[] = $group->memberToAtom($userID);
		}
		$totalResults = sizeOf($entries);
		
		$this->responseXML = Zotero_Atom::createAtomFeed(
			$title,
			$this->uri,
			$entries,
			$totalResults,
			$this->queryParams,
			$this->permissions
		);
		
		$this->end();
	}
	
	
	protected function getFieldsFromGroupXML(SimpleXMLElement $group) {
		$fields = array();
		$fields['ownerUserID'] = (int) $group['owner'];
		$fields['name'] = (string) $group['name'];
		$fields['type'] = (string) $group['type'];
		if (isset($group['libraryEnabled'])) {
			$fields['libraryEnabled'] = (bool) (int) $group['libraryEnabled'];
			
			if ($fields['libraryEnabled']) {
				$fields['libraryEditing'] = (string) $group['libraryEditing'];
				$fields['libraryReading'] = (string) $group['libraryReading'];
				$fields['fileEditing'] = (string) $group['fileEditing'];
			}
		}
		else {
			$this->e400("libraryEnabled not specified");
		}
		$fields['description'] = (string) $group->description;
		$fields['url'] = (string) $group->url;
		$fields['hasImage'] = (bool) (int) $group['hasImage'];
		
		return $fields;
	}
}
