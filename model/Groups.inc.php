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
	private static $groups = array();
	
	public static function get($groupID, $skipExistsCheck=false) {
		if (!$groupID) {
			throw new Exception('$groupID not set');
		}
		
		if (isset(self::$groups[$groupID])) {
			$group = self::$groups[$groupID];
			if ($group->erased) {
				unset(self::$groups[$groupID]);
				return false;
			}
			return $group;
		}
		
		$group = new Zotero_Group;
		$group->id = $groupID;
		if (!$skipExistsCheck && !$group->exists()) {
			return false;
		}
		
		self::$groups[$groupID] = $group;
		
		return self::$groups[$groupID];
	}
	
	
	
	public static function getAllAdvanced($userID=false, $params=array(), $permissions=null) {
		$buffer = 20;
		$maxTimes = 3;
		
		$groups = array();
		$start = !empty($params['start']) ? $params['start'] : 0;
		$limit = !empty($params['limit']) ? $params['limit'] + $buffer : false;
		$totalResults = null;
		
		$times = 0;
		while (true) {
			if ($times > 0) {
				Z_Core::logError('Getting more groups in Zotero_Groups::getAllAdvanced()');
			}
			
			$sql = "SELECT SQL_CALC_FOUND_ROWS G.groupID, GUO.userID AS ownerUserID FROM groups G
					JOIN groupUsers GUO ON (G.groupID=GUO.groupID AND GUO.role='owner') ";
			$sqlParams = array();
			
			if ($userID) {
				$sql .= "JOIN groupUsers GUA ON (G.groupID=GUA.groupID) WHERE GUA.userID=? ";
				$sqlParams[] = $userID;
			}
			else {
				// Don't include groups that have never had items
				$sql .= "JOIN libraries L ON (G.libraryID=L.libraryID)
						WHERE L.lastUpdated != '0000-00-00 00:00:00'";
			}
			
			if (!empty($params['q'])) {
				if (!is_array($params['q'])) {
					$params['q'] = array($params['q']);
				}
				foreach ($params['q'] as $q) {
					$field = explode(":", $q);
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
			
			// Set limit higher than the actual limit, in case some groups are
			// removed during access checks
			//
			// Actual limiting is done below
			if ($limit) {
				$sql .= "LIMIT ?, ?";
				$sqlParams[] = $start;
				$sqlParams[] = $limit;
			}
			
			$rows = Zotero_DB::query($sql, $sqlParams);
			if (!$rows) {
				break;
			}
			
			if (!$totalResults) {
				$foundRows = Zotero_DB::valueQuery("SELECT FOUND_ROWS()");
				$totalResults = $foundRows;
			}
			
			// Include only groups with non-banned owners
			$owners = array();
			foreach ($rows as $row) {
				$owners[] = $row['ownerUserID'];
			}
			$owners = Zotero_Users::getValidUsers($owners);
			$ids = array();
			foreach ($rows as $row) {
				if (!in_array($row['ownerUserID'], $owners)) {
					$totalResults--;
					continue;
				}
				$ids[] = $row['groupID'];
			}
			
			$batchStartPos = sizeOf($groups);
			
			foreach ($ids as $id) {
				$group = Zotero_Groups::get($id, true);
				$groups[] = $group;
			}
			
			// Remove groups that can't be accessed
			if ($permissions) {
				for ($i=$batchStartPos; $i<sizeOf($groups); $i++) {
					$libraryID = (int) $groups[$i]->libraryID; // TEMP: casting shouldn't be necessary
					if (!$permissions->canAccess($libraryID)) {
						array_splice($groups, $i, 1);
						$i--;
						$totalResults--;
					}
				}
			}
			
			$times++;
			if ($times == $maxTimes) {
				Z_Core::logError('Too many queries in Zotero_Groups::getAllAdvanced()');
				break;
			}
			
			if (empty($params['limit'])) {
				break;
			}
			
			// If we have enough groups to fill the limit, stop
			if (sizeOf($groups) > $params['limit']) {
				break;
			}
			
			// If there no more rows, stop
			if ($start + sizeOf($rows) == $foundRows) {
				break;
			}
			
			// This shouldn't happen
			if ($start + sizeOf($rows) > $foundRows) {
				Z_Core::logError('More rows than $foundRows in Zotero_Groups::getAllAdvanced()');
			}
			
			$start = $start + sizeOf($rows);
			// Get number we still need plus the buffer or all remaining, whichever is lower
			$limit = min($params['limit'] - sizeOf($groups) + $buffer, $foundRows - $start);
		}
		
		// TODO: generate previous start value
		
		if (!$groups) {
			return array('groups' => array(), 'totalResults' => 0);
		}
		
		// Fake limiting -- we can't just use SQL limit because
		// some groups might be inaccessible
		if (!empty($params['limit'])) {
			$groups = array_slice(
				$groups,
				0,
				$params['limit']
			);
		}
		
		$results = array('groups' => $groups, 'totalResults' => $totalResults);
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
	
	
	public static function getUserOwnedGroups($userID) {
		$sql = "SELECT G.groupID FROM groups G
				JOIN groupUsers GU ON (G.groupID=GU.groupID AND role='owner')
				WHERE userID=?";
		$groups = Zotero_DB::columnQuery($sql, $userID);
		if (!$groups) {
			return array();
		}
		return $groups;
	}
	
	
	public static function getUserOwnedGroupLibraries($userID) {
		$groups = self::getUserOwnedGroups($userID);
		$libraries = array();
		foreach ($groups as $group) {
			$libraries[] = Zotero_Groups::getLibraryIDFromGroupID($group);
		}
		return $libraries;
	}
	
	
	/**
	 * Returns shardIDs of all shards storing groups this user belongs to
	 */
	public static function getUserGroupShards($userID) {
		$groupIDs = self::getUserGroups($userID);
		if (!$groupIDs) {
			return array();
		}
		$shardIDs = array();
		foreach ($groupIDs as $groupID) {
			$shardID = Zotero_Shards::getByGroupID($groupID);
			$shardIDs[$shardID] = true;
		}
		return array_keys($shardIDs);
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
