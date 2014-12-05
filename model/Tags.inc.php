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

class Zotero_Tags extends Zotero_DataObjects {
	public static $maxLength = 255;
	
	protected static $ZDO_object = 'tag';
	
	protected static $primaryFields = array(
		'id' => 'tagID',
		'libraryID' => '',
		'key' => '',
		'name' => '',
		'type' => '',
		'dateAdded' => '',
		'dateModified' => '',
		'version' => ''
	);
	
	private static $tagsByID = array();
	private static $namesByHash = array();
	
	/*
	 * Returns a tag and type for a given tagID
	 */
	public static function get($libraryID, $tagID, $skipCheck=false) {
		if (!$libraryID) {
			throw new Exception("Library ID not provided");
		}
		
		if (!$tagID) {
			throw new Exception("Tag ID not provided");
		}
		
		if (isset(self::$tagsByID[$tagID])) {
			return self::$tagsByID[$tagID];
		}
		
		if (!$skipCheck) {
			$sql = 'SELECT COUNT(*) FROM tags WHERE tagID=?';
			$result = Zotero_DB::valueQuery($sql, $tagID, Zotero_Shards::getByLibraryID($libraryID));
			if (!$result) {
				return false;
			}
		}
		
		$tag = new Zotero_Tag;
		$tag->libraryID = $libraryID;
		$tag->id = $tagID;
		
		self::$tagsByID[$tagID] = $tag;
		return self::$tagsByID[$tagID];
	}
	
	
	/*
	 * Returns tagID for this tag
	 */
	public static function getID($libraryID, $name, $type, $caseInsensitive=false) {
		if (!$libraryID) {
			throw new Exception("Library ID not provided");
		}
		
		$name = trim($name);
		$type = (int) $type;
		
		// TODO: cache
		
		$sql = "SELECT tagID FROM tags WHERE ";
		if ($caseInsensitive) {
			$sql .= "LOWER(name)=?";
			$params = [strtolower($name)];
		}
		else {
			$sql .= "name=?";
			$params = [$name];
		}
		$sql .= " AND type=? AND libraryID=?";
		array_push($params, $type, $libraryID);
		$tagID = Zotero_DB::valueQuery($sql, $params, Zotero_Shards::getByLibraryID($libraryID));
		
		return $tagID;
	}
	
	
	/*
	 * Returns array of all tagIDs for this tag (of all types)
	 */
	public static function getIDs($libraryID, $name, $caseInsensitive=false) {
		$sql = "SELECT tagID FROM tags WHERE libraryID=? AND name";
		if ($caseInsensitive) {
			$sql .= " COLLATE utf8_general_ci ";
		}
		$sql .= "=?";
		$tagIDs = Zotero_DB::columnQuery($sql, array($libraryID, $name), Zotero_Shards::getByLibraryID($libraryID));
		if (!$tagIDs) {
			return array();
		}
		return $tagIDs;
	}
	
	
	public static function search($libraryID, $params) {
		$results = array('results' => array(), 'total' => 0);
		
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		
		$sql = "SELECT SQL_CALC_FOUND_ROWS DISTINCT tagID FROM tags ";
			. "JOIN itemTags USING (tagID) WHERE libraryID=? ";
		$sqlParams = array($libraryID);
		
		// Pass a list of tagIDs, for when the initial search is done via SQL
		$tagIDs = !empty($params['tagIDs']) ? $params['tagIDs'] : array();
		// Filter for specific tags with "?tag=foo || bar"
		$tagNames = !empty($params['tag']) ? explode(' || ', $params['tag']): array();
		
		if ($tagIDs) {
			$sql .= "AND tagID IN ("
					. implode(', ', array_fill(0, sizeOf($tagIDs), '?'))
					. ") ";
			$sqlParams = array_merge($sqlParams, $tagIDs);
		}
		
		if ($tagNames) {
			$sql .= "AND `name` IN ("
					. implode(', ', array_fill(0, sizeOf($tagNames), '?'))
					. ") ";
			$sqlParams = array_merge($sqlParams, $tagNames);
		}
		
		if (!empty($params['q'])) {
			if (!is_array($params['q'])) {
				$params['q'] = array($params['q']);
			}
			foreach ($params['q'] as $q) {
				$sql .= "AND name LIKE ? ";
				$sqlParams[] = "%$q%";
			}
		}
		
		$tagTypeSets = Zotero_API::getSearchParamValues($params, 'tagType');
		if ($tagTypeSets) {
			$positives = array();
			$negatives = array();
			
			foreach ($tagTypeSets as $set) {
				if ($set['negation']) {
					$negatives = array_merge($negatives, $set['values']);
				}
				else {
					$positives = array_merge($positives, $set['values']);
				}
			}
			
			if ($positives) {
				$sql .= "AND type IN (" . implode(',', array_fill(0, sizeOf($positives), '?')) . ") ";
				$sqlParams = array_merge($sqlParams, $positives);
			}
			
			if ($negatives) {
				$sql .= "AND type NOT IN (" . implode(',', array_fill(0, sizeOf($negatives), '?')) . ") ";
				$sqlParams = array_merge($sqlParams, $negatives);
			}
		}
		
		if (!empty($params['since'])) {
			$sql .= "AND version > ? ";
			$sqlParams[] = $params['since'];
		}
		
		if (!empty($params['sort'])) {
			$order = $params['sort'];
			if ($order == 'title') {
				// Force a case-insensitive sort
				$sql .= "ORDER BY name COLLATE utf8_unicode_ci ";
			}
			else if ($order == 'numItems') {
				$sql .= "GROUP BY tags.tagID ORDER BY COUNT(tags.tagID)";
			}
			else {
				$sql .= "ORDER BY $order ";
			}
			if (!empty($params['direction'])) {
				$sql .= " " . $params['direction'] . " ";
			}
		}
		
		if (!empty($params['limit'])) {
			$sql .= "LIMIT ?, ?";
			$sqlParams[] = $params['start'] ? $params['start'] : 0;
			$sqlParams[] = $params['limit'];
		}
		
		$ids = Zotero_DB::columnQuery($sql, $sqlParams, $shardID);
		
		if ($ids) {
			$results['total'] = Zotero_DB::valueQuery("SELECT FOUND_ROWS()", false, $shardID);
			
			$tags = array();
			foreach ($ids as $id) {
				$tags[] = Zotero_Tags::get($libraryID, $id);
			}
			$results['results'] = $tags;
		}
		
		return $results;
	}
	
	
	public static function cache(Zotero_Tag $tag) {
		if (isset($tagsByID[$tag->id])) {
			error_log("Tag $tag->id is already cached");
		}
		
		self::$tagsByID[$tag->id] = $tag;
	}
	
	
	/*public static function getDataValuesFromXML(DOMDocument $doc) {
		$xpath = new DOMXPath($doc);
		$attr = $xpath->evaluate('//tags/tag/@name');
		$vals = array();
		foreach ($attr as $a) {
			$vals[] = $a->value;
		}
		$vals = array_unique($vals);
		return $vals;
	}*/
	
	
	public static function getLongDataValueFromXML(DOMDocument $doc) {
		$xpath = new DOMXPath($doc);
		$attr = $xpath->evaluate('//tags/tag[string-length(@name) > ' . self::$maxLength . ']/@name');
		return $attr->length ? $attr->item(0)->value : false;
	}
	
	
	/**
	 * Converts a DOMElement item to a Zotero_Tag object
	 *
	 * @param	DOMElement			$xml		Tag data as DOMElement
	 * @param	int					$libraryID	Library ID
	 * @return	Zotero_Tag						Zotero tag object
	 */
	public static function convertXMLToTag(DOMElement $xml, &$itemKeysToUpdate) {
		$libraryID = (int) $xml->getAttribute('libraryID');
		$tag = self::getByLibraryAndKey($libraryID, $xml->getAttribute('key'));
		if (!$tag) {
			$tag = new Zotero_Tag;
			$tag->libraryID = $libraryID;
			$tag->key = $xml->getAttribute('key');
		}
		$tag->name = $xml->getAttribute('name');
		$type = (int) $xml->getAttribute('type');
		$tag->type = $type ? $type : 0;
		$tag->dateAdded = $xml->getAttribute('dateAdded');
		$tag->dateModified = $xml->getAttribute('dateModified');
		
		$dataChanged = $tag->hasChanged();
		
		$itemKeys = $xml->getElementsByTagName('items');
		$oldKeys = $tag->getLinkedItems(true);
		if ($itemKeys->length) {
			$newKeys = explode(' ', $itemKeys->item(0)->nodeValue);
		}
		else {
			$newKeys = array();
		}
		$addKeys = array_diff($newKeys, $oldKeys);
		$removeKeys = array_diff($oldKeys, $newKeys);
		
		// If the data has changed, all old and new items need to change
		if ($dataChanged) {
			$itemKeysToUpdate = array_merge($oldKeys, $addKeys);
		}
		// Otherwise, only update items that are being added or removed
		else {
			$itemKeysToUpdate = array_merge($addKeys, $removeKeys);
		}
		
		$tag->setLinkedItems($newKeys);
		return $tag;
	}
	
	
	/**
	 * Converts a Zotero_Tag object to a SimpleXMLElement item
	 *
	 * @param	object				$item		Zotero_Tag object
	 * @return	SimpleXMLElement				Tag data as SimpleXML element
	 */
	public static function convertTagToXML(Zotero_Tag $tag, $syncMode=false) {
		return $tag->toXML($syncMode);
	}
}
?>
