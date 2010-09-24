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
	
	public static function get($relationID) {
		$relation = new Zotero_Relation;
		$relation->id = $relationID;
		return $relation;
	}
	
	
	/**
	 * Converts a DOMElement item to a Zotero_Relation object
	 *
	 * @param	DOMElement			$xml		Relation data as DOM element
	 * @param	Integer				$libraryID
	 * @return	Zotero_Relation					Zotero relation object
	 */
	public static function convertXMLToRelation(DOMElement $xml, $libraryID) {
		$relation = new Zotero_Relation;
		$relation->libraryID = $libraryID;
		$relation->subject = $xml->getElementsByTagName('subject')->item(0)->nodeValue;
		$relation->predicate = $xml->getElementsByTagName('predicate')->item(0)->nodeValue;
		$relation->object = $xml->getElementsByTagName('object')->item(0)->nodeValue;
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
}
?>
