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

class Zotero_Relations extends Zotero_DataObjects {
	protected static $ZDO_object = 'relation';
	
	protected static $primaryFields = array(
		'id' => 'relationID',
		'libraryID' => '',
		'key' => '',
		'subject' => '',
		'predicate' => '',
		'object' => ''
	);
	
	public static $relatedItemPredicate = 'dc:relation';
	public static $linkedObjectPredicate = 'owl:sameAs';
	public static $deletedItemPredicate = 'dc:replaces';
	
	private static $namespaces = array(
		"dc" => 'http://purl.org/dc/elements/1.1/',
		"owl" => 'http://www.w3.org/2002/07/owl#'
	);
	
	public static function get($libraryID, $relationID, $existsCheck=false) {
		$relation = new Zotero_Relation;
		$relation->libraryID = $libraryID;
		$relation->id = $relationID;
		return $relation;
	}
	
	
	/**
	 * @return Zotero_Relation[]
	 */
	public static function getByURIs($libraryID, $subject=false, $predicate=false, $object=false) {
		if ($predicate) {
			$predicate = implode(':', self::_getPrefixAndValue($predicate));
		}
		
		if (!is_int($libraryID)) {
			throw new Exception('$libraryID must be an integer');
		}
		
		if (!$subject && !$predicate && !$object) {
			throw new Exception("No values provided");
		}
		
		$sql = "SELECT relationID FROM relations WHERE libraryID=?";
		$params = array($libraryID);
		if ($subject) {
			$sql .= " AND subject=?";
			$params[] = $subject;
		}
		if ($predicate) {
			$sql .= " AND predicate=?";
			$params[] = $predicate;
		}
		if ($object) {
			$sql .= " AND object=?";
			$params[] = $object;
		}
		$rows = Zotero_DB::columnQuery(
			$sql, $params, Zotero_Shards::getByLibraryID($libraryID)
		);
		if (!$rows) {
			return array();
		}
		
		$toReturn = array();
		foreach ($rows as $id) {
			$relation = new Zotero_Relation;
			$relation->libraryID = $libraryID;
			$relation->id = $id;
			$toReturn[] = $relation;
		}
		return $toReturn;
	}
	
	
	public static function getSubject($libraryID, $subject=false, $predicate=false, $object=false) {
		$subjects = array();
		$relations = self::getByURIs($libraryID, $subject, $predicate, $object);
		foreach ($relations as $relation) {
			$subjects[] = $relation->subject;
		}
		return $subjects;
	}
	
	
	public static function getObject($libraryID, $subject=false, $predicate=false, $object=false) {
		$objects = array();
		$relations = self::getByURIs($libraryID, $subject, $predicate, $object);
		foreach ($relations as $relation) {
			$objects[] = $relation->object;
		}
		return $objects;
	}
	
	
	public static function makeKey($subject, $predicate, $object) {
		return md5($subject . "_" . $predicate . "_" . $object);
	}
	
	
	public static function add($libraryID, $subject, $predicate, $object) {
		$predicate = implode(':', self::_getPrefixAndValue($predicate));
		
		$relation = new Zotero_Relation;
		$relation->libraryID = $libraryID;
		$relation->subject = $subject;
		$relation->predicate = $predicate;
		$relation->object = $object;
		$relation->save();
	}
	
	
	public static function eraseByURIPrefix($libraryID, $prefix, $ignorePredicates=false) {
		Zotero_DB.beginTransaction();
		
		$prefix = $prefix . '%';
		$sql = "SELECT relationID FROM relations WHERE libraryID=? AND "
			. "(subject LIKE ? OR object LIKE ?)";
		$params = array($libraryID, $prefix, $prefix);
		if ($ignorePredicates) {
			foreach ($ignorePredicates as $ignorePredicate) {
				$sql .= " AND predicate != ?";
				$params[] = $ignorePredicate;
			}
		}
		$ids = Zotero_DB::columnQuery(
			$sql, $params, Zotero_Shards::getByLibraryID($libraryID)
		);
		
		foreach ($ids as $id) {
			$relation = self::get($libraryID, $id);
			Zotero_Relations::delete($libraryID, $relation->key);
		}
		
		Zotero_DB::commit();
	}
	
	
	/**
	 * Delete any relations that have the URI as either the subject
	 * or the object
	 */
	public static function eraseByURI($libraryID, $uri, $ignorePredicates=false) {
		Zotero_DB::beginTransaction();
		
		$sql = "SELECT relationID FROM relations "
			. "WHERE libraryID=? AND (subject=? OR object=?)";
		$params = array($libraryID, $uri, $uri);
		if ($ignorePredicates) {
			foreach ($ignorePredicates as $ignorePredicate) {
				$sql .= " AND predicate != ?";
				$params[] = $ignorePredicate;
			}
		}
		$ids = Zotero_DB::columnQuery(
			$sql, $params, Zotero_Shards::getByLibraryID($libraryID)
		);
		
		if ($ids) {
			foreach ($ids as $id) {
				$relation = self::get($libraryID, $id);
				Zotero_Relations::delete($libraryID, $relation->key);
			}
		}
		
		Zotero_DB::commit();
	}
	
	
	public static function purge($libraryID) {
		$sql = "SELECT subject FROM relations "
			. "WHERE libraryID=? AND predicate!=? "
			. "UNION "
			. "SELECT object FROM relations "
			. "WHERE libraryID=? AND predicate!=?";
		$uris = Zotero.DB.columnQuery(
			$sql,
			array(
				$libraryID,
				self::$deletedItemPredicate,
				$libraryID,
				self::$deletedItemPredicate
			),
			Zotero_Shards::getByLibraryID($libraryID)
		);
		if ($uris) {
			$prefix = Zotero_URI::getBaseURI();
			Zotero_DB::beginTransaction();
			foreach ($uris as $uri) {
				// Skip URIs that don't begin with the default prefix,
				// since they don't correspond to local items
				if (strpos($uri, $prefix) === false) {
					continue;
				}
				if (preg_match('/\/items\//', $uri) && !Zotero_URI::getURIItem($uri)) {
					self::eraseByURI($uri);
				}
				if (preg_match('/\/collections\//', $uri) && !Zotero_URI::getURICollection($uri)) {
					self::eraseByURI($uri);
				}
			}
			Zotero_DB::commit();
		}
	}
	
	
	/**
	 * Converts a DOMElement item to a Zotero_Relation object
	 *
	 * @param	DOMElement			$xml		Relation data as DOM element
	 * @param	Integer				$libraryID
	 * @return	Zotero_Relation					Zotero relation object
	 */
	public static function convertXMLToRelation(DOMElement $xml, $userLibraryID) {
		$relation = new Zotero_Relation;
		$libraryID = $xml->getAttribute('libraryID');
		if ($libraryID) {
			$relation->libraryID = $libraryID;
		}
		else {
			$relation->libraryID = $userLibraryID;
		}
		
		$subject = $xml->getElementsByTagName('subject')->item(0)->nodeValue;
		$predicate = $xml->getElementsByTagName('predicate')->item(0)->nodeValue;
		$object = $xml->getElementsByTagName('object')->item(0)->nodeValue;
		
		if ($predicate == 'dc:isReplacedBy') {
			$relation->subject = $object;
			$relation->predicate = 'dc:replaces';
			$relation->object = $subject;
		}
		else {
			$relation->subject = $subject;
			$relation->predicate = $predicate;
			$relation->object = $object;
		}
		
		return $relation;
	}
	
	
	/**
	 * Converts a Zotero_Relation object to a SimpleXMLElement item
	 *
	 * @param	object				$item		Zotero_Relation object
	 * @return	SimpleXMLElement				Relation data as SimpleXML element
	 */
	public static function convertRelationToXML(Zotero_Relation $relation) {
		return $relation->toXML();
	}
	
	
	private static function _getPrefixAndValue($uri) {
		$parts = explode(':', $uri);
		if (isset($parts[1])) {
			if (!self::$namespaces[$parts[0]]) {
				throw ("Invalid prefix '{$parts[0]}'");
			}
			return $parts;
		}
		
		foreach (self::$namespaces as $prefix => $val) {
			if (strpos($uri, $val) === 0) {
				$value = substr($uri, strlen($val) - 1);
				return array($prefix, $value);
			}
		}
		throw new Exception("Invalid namespace in URI '$uri'");
	}
}
?>
