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

class Zotero_Groups {
	public static function get($groupID) {
		$group = new Zotero_Group;
		$group->id = $groupID;
		if (!$group->exists()) {
			return false;
		}
		return $group;
	}
	
	
	public static function getAllAdvanced($userID=false, $params=array()) {
		$results = array('groups' => array(), 'total' => 0);
		
		$sql = "SELECT SQL_CALC_FOUND_ROWS groupID FROM groups WHERE 1 ";
		$sqlParams = array();
		if ($userID) {
			$sql .= "AND groupID IN (SELECT groupID FROM groupUsers WHERE userID=?) ";
			$sqlParams[] = $userID;
		}
		
		if (!empty($params['q'])) {
			if (!is_array($params['q'])) {
				$params['q'] = array($params['q']);
			}
			foreach ($params['q'] as $q) {
				$field = split(":", $q);
				if (sizeOf($field) == 2) {
					switch ($field[0]) {
						case 'slug':
							break;
						
						default:
							throw new Exception("Cannot search by group field '{$field[0]}'", Z_ERROR_INVALID_GROUP_TYPE);
					}
					
					$sql .= "AND " . $field[0];
					// If first character is '-', negate
					$sql .= ($field[0][0] == '-' ? '!' : '');
					$sql .= "=? ";
					$sqlParams[] = $field[1];
				}
				else {
					$sql .= "AND name LIKE ? ";
					$sqlParams[] = "%$q%";
				}
			}
		}
		
		if (!empty($params['fq'])) {
			if (!is_array($params['fq'])) {
				$params['fq'] = array($params['fq']);
			}
			foreach ($params['fq'] as $fq) {
				$facet = explode(":", $fq);
				if (sizeOf($facet) == 2 && preg_match('/-?GroupType/', $facet[0])) {
					switch ($facet[1]) {
						case 'PublicOpen':
						case 'PublicClosed':
						case 'Private':
							break;
						
						default:
							throw new Exception("Invalid group type '{$facet[1]}'", Z_ERROR_INVALID_GROUP_TYPE);
					}
					
					$sql .= "AND type";
					// If first character is '-', negate
					$sql .= ($facet[0][0] == '-' ? '!' : '');
					$sql .= "=? ";
					$sqlParams[] = $facet[1];
				}
			}
		}
		
		if (!empty($params['order'])) {
			$order = $params['order'];
			if ($order == 'title') {
				$order = 'name';
			}
			$sql .= "ORDER BY $order";
			if (!empty($params['sort'])) {
				$sql .= " " . $params['sort'] . " ";
			}
		}
		
		if (!empty($params['limit'])) {
			$sql .= "LIMIT ?, ?";
			$sqlParams[] = $params['start'] ? $params['start'] : 0;
			$sqlParams[] = $params['limit'];
		}
		$ids = Zotero_DB::columnQuery($sql, $sqlParams);
		
		if ($ids) {
			$results['total'] = Zotero_DB::valueQuery("SELECT FOUND_ROWS()");
			
			$groups = array();
			foreach ($ids as $id) {
				$group = Zotero_Groups::get($id);
				$groups[] = $group;
			}
			$results['groups'] = $groups;
		}
		
		return $results;
	}
	
	
	/**
	 * Returns groupIDs of groups a user has joined since |timestamp|
	 *
	 * @param	int			$libraryID		Library ID
	 * @param	string		$timestamp		Unix timestamp of last sync time
	 * @return	array						An array of groupIDs
	 */
	public static function getJoined($userID, $timestamp) {
		$sql = "SELECT groupID FROM groupUsers WHERE userID=? AND joined>FROM_UNIXTIME(?)";
		$groupIDs = Zotero_DB::columnQuery($sql, array($userID, $timestamp));
		return $groupIDs ? $groupIDs : array();
	}
	
	
	/**
	 * Returns groupIDs of groups the user is a member of that have been updated since |timestamp|
	 *
	 * @param	int			$libraryID		Library ID
	 * @param	string		$timestamp		Unix timestamp of last sync time
	 * @return	array						An array of groupIDs
	 */
	public static function getUpdated($userID, $timestamp) {
		$sql = "SELECT groupID FROM groups G NATURAL JOIN groupUsers GU WHERE userID=?
				AND (G.dateModified>FROM_UNIXTIME(?) OR GU.lastUpdated>FROM_UNIXTIME(?))";
		$groupIDs = Zotero_DB::columnQuery($sql, array($userID, $timestamp, $timestamp));
		return $groupIDs ? $groupIDs : array();
	}
	
	
	public static function exist($groupIDs) {
		$sql = "SELECT groupID FROM groups WHERE groupID IN ("
			. implode(', ', array_fill(0, sizeOf($groupIDs), '?')) . ")";
		$exist = Zotero_DB::columnQuery($sql, $groupIDs);
		return $exist ? $exist : array();
	}
	
	
	public static function publicNameExists($name) {
		$slug = Zotero_Utilities::slugify($name);
		$sql = "SELECT groupID FROM groups WHERE (name=? OR slug=?) AND
					type IN ('PublicOpen', 'PublicClosed')";
		$groupID = Zotero_DB::valueQuery($sql, array($name, $slug));
		return $groupID ? $groupID : false;
	}
	
	
	public static function getLibraryIDFromGroupID($groupID) {
		$cacheKey = 'groupLibraryID_' . $groupID;
		$libraryID = Z_Core::$MC->get($cacheKey);
		if ($libraryID) {
			return $libraryID;
		}
		$sql = "SELECT libraryID FROM groups WHERE groupID=?";
		$libraryID = Zotero_DB::valueQuery($sql, $groupID);
		if (!$libraryID) {
			trigger_error("Group $groupID does not exist", E_USER_ERROR);
		}
		Z_Core::$MC->set($cacheKey, $libraryID);
		return $libraryID;
	}
	
	
	public static function getGroupIDFromLibraryID($libraryID) {
		$cacheKey = 'libraryGroupID_' . $libraryID;
		$groupID = Z_Core::$MC->get($cacheKey);
		if ($groupID) {
			return $groupID;
		}
		$sql = "SELECT groupID FROM groups WHERE libraryID=?";
		$groupID = Zotero_DB::valueQuery($sql, $libraryID);
		if (!$groupID) {
			trigger_error("Group with libraryID $libraryID does not exist", E_USER_ERROR);
		}
		Z_Core::$MC->set($cacheKey, $groupID);
		return $groupID;
	}
	
	
	public static function getUserGroups($userID) {
		$sql = "SELECT groupID FROM groupUsers WHERE userID=?";
		$groups = Zotero_DB::columnQuery($sql, $userID);
		if (!$groups) {
			return array();
		}
		return $groups;
	}
	
	
	public static function getUserGroupLibraries($userID) {
		$sql = "SELECT libraryID FROM groupUsers JOIN groups USING (groupID) WHERE userID=?";
		$libraryIDs = Zotero_DB::columnQuery($sql, $userID);
		if (!$libraryIDs) {
			return array();
		}
		return $libraryIDs;
	}
}
?>
