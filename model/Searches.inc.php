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

class Zotero_Searches extends Zotero_DataObjects {
	protected static $ZDO_object = 'search';
	protected static $ZDO_objects = 'searches';
	protected static $ZDO_table = 'savedSearches';
	
	/**
	 * Converts a SimpleXMLElement item to a Zotero_Search object
	 *
	 * @param	SimpleXMLElement	$xml		Search data as SimpleXML element
	 * @return	Zotero_Search					Zotero search object
	 */
	public static function convertXMLToSearch(SimpleXMLElement $xml) {
		$search = new Zotero_Search;
		$search->libraryID = (int) $xml['libraryID'];
		$search->key = (string) $xml['key'];
		$search->name = (string) $xml['name'];
		$search->dateAdded = (string) $xml['dateAdded'];
		$search->dateModified = (string) $xml['dateModified'];
		
		$conditionID = -1;
		
		// Search conditions
		foreach($xml->condition as $condition) {
			$conditionID = (int) $condition['id'];
			$name = (string) $condition['condition'];
			$mode = (string) $condition['mode'];
			$operator = (string) $condition['operator'];
			$value = (string) $condition['value'];
			$required = (bool) $condition['required'];
			
			if ($search->getSearchCondition($conditionID)) {
				$search->updateCondition(
					$conditionID,
					$name,
					$mode,
					$operator,
					$value,
					$required
				);
			}
			else {
				$newID = $search->addCondition(
					$name,
					$mode,
					$operator,
					$value,
					$required
				);
				
				if ($newID != $conditionID) {
					trigger_error("Search condition ids not contiguous", E_USER_ERROR);
				}
			}
		}
		
		$conditionID++;
		while ($search->getSearchCondition($conditionID)) {
			$search->removeCondition($conditionID);
			$conditionID++;
		}
		
		return $search;
	}
	
	
	/**
	 * Converts a Zotero_Search object to a SimpleXMLElement item
	 *
	 * @param	object				$item		Zotero_Search object
	 * @return	SimpleXMLElement					Search data as SimpleXML element
	 */
	public static function convertSearchToXML(Zotero_Search $search) {
		$xml = new SimpleXMLElement('<search/>');
		$xml['libraryID'] = $search->libraryID;
		$xml['key'] = $search->key;
		$xml['name'] = $search->name;
		$xml['dateAdded'] = $search->dateAdded;
		$xml['dateModified'] = $search->dateModified;
		
		$conditions = $search->getSearchConditions();
		
		if ($conditions) {
			foreach($conditions as $condition) {
				$c = $xml->addChild('condition');
				$c['id'] = $condition['id'];
				$c['condition'] = $condition['condition'];
				if ($condition['mode']) {
					$c['mode'] = $condition['mode'];
				}
				$c['operator'] = $condition['operator'];
				$c['value'] = $condition['value'];
				if ($condition['required']) {
					$c['required'] = "1";
				}
			}
		}
		
		return $xml;
	}
}
?>
