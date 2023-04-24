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

class Zotero_Atom {
	// Set up namespaces
	public static $nsAtom = "http://www.w3.org/2005/Atom";
	public static $nsXHTML = "http://www.w3.org/1999/xhtml";
	public static $nsZoteroAPI = "http://zotero.org/ns/api";
	public static $nsZoteroTransfer = "http://zotero.org/ns/transfer";
	
	
	public static function createAtomFeed($action, $title, $url, $entries, $totalResults=null,
			$queryParams=[], Zotero_Permissions $permissions=null, $fixedValues=array()) {
		if ($queryParams) {
			$nonDefaultParams = Zotero_API::getNonDefaultParams($action, $queryParams);
		}
		else {
			$nonDefaultParams = [];
		}
		
		$feed = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<feed xmlns="' . Zotero_Atom::$nsAtom . '" '
			. 'xmlns:zapi="' . Zotero_Atom::$nsZoteroAPI . '"/>';
		$xml = new SimpleXMLElement($feed);
		
		$xml->title = $title;
		
		$path = parse_url($url, PHP_URL_PATH);
		
		$xml->id = Zotero_URI::getBaseURI()
			. substr($path, 1)
			. Zotero_API::buildQueryString(
				$queryParams['v'],
				$action,
				$nonDefaultParams,
				['format', 'v']
			);
		
		// API version isn't included in URLs (as with the API key)
		//
		// It could alternatively be made a private parameter so that it didn't appear
		// in the Link header either, but for now it's still there.
		$excludeParams = ['v'];
		
		$links = Zotero_API::buildLinks(
			$action,
			$path,
			$totalResults,
			$queryParams,
			$nonDefaultParams,
			$excludeParams
		);
		
		$link = $xml->addChild("link");
		$link['rel'] = "self";
		$link['type'] = "application/atom+xml";
		$link['href'] = $links['self'];
		
		$link = $xml->addChild("link");
		$link['rel'] = "first";
		$link['type'] = "application/atom+xml";
		$link['href'] = $links['first'];
		
		if (isset($links['next'])) {
			$link = $xml->addChild("link");
			$link['rel'] = "next";
			$link['type'] = "application/atom+xml";
			$link['href'] = $links['next'];
		}
		
		$link = $xml->addChild("link");
		$link['rel'] = "last";
		$link['type'] = "application/atom+xml";
		$link['href'] = $links['last'];
		
		// Generate alternate URI
		$link = $xml->addChild("link");
		$link['rel'] = "alternate";
		$link['type'] = "text/html";
		$link['href'] = $links['alternate'];
		
		if ($queryParams['v'] < 3) {
			$xml->addChild(
				"zapi:totalResults",
				is_numeric($totalResults) ? $totalResults : sizeOf($entries),
				self::$nsZoteroAPI
			);
		}
		
		if ($queryParams['v'] < 2) {
			$xml->addChild("zapi:apiVersion", 1, self::$nsZoteroAPI);
		}
		
		$latestUpdated = '';
		
		// Check memcached for bib data
		$sharedData = array();
		if ($entries && $entries[0] instanceof Zotero_Item) {
			if (in_array('citation', $queryParams['content'])) {
				$sharedData["citation"] = Zotero_Cite::multiGetFromMemcached("citation", $entries, $queryParams);
			}
			if (in_array('bib', $queryParams['content'])) {
				$sharedData["bib"] = Zotero_Cite::multiGetFromMemcached("bib", $entries, $queryParams);
			}
		}
		
		$xmlEntries = array();
		foreach ($entries as $entry) {
			if ($entry->dateModified > $latestUpdated) {
				$latestUpdated = $entry->dateModified;
			}
			
			if ($entry instanceof SimpleXMLElement) {
				$xmlEntries[] = $entry;
			}
			else if ($entry instanceof Zotero_Collection) {
				$entry = Zotero_Collections::convertCollectionToAtom($entry, $queryParams);
				$xmlEntries[] = $entry;
			}
			else if ($entry instanceof Zotero_Item) {
				$entry = Zotero_Items::convertItemToAtom($entry, $queryParams, $permissions, $sharedData);
				$xmlEntries[] = $entry;
			}
			else if ($entry instanceof Zotero_Search) {
				$entry = $entry->toAtom($queryParams);
				$xmlEntries[] = $entry;
			}
			else if ($entry instanceof Zotero_Tag) {
				$xmlEntries[] = $entry->toAtom(
					$queryParams,
					isset($fixedValues[$entry->id]) ? $fixedValues[$entry->id] : null
				);
			}
			else if ($entry instanceof Zotero_Group) {
				$entry = $entry->toAtom($queryParams);
				$xmlEntries[] = $entry;
			}
		}
		
		if ($latestUpdated) {
			$xml->updated = Zotero_Date::sqlToISO8601($latestUpdated);
		}
		else {
			$xml->updated = str_replace("+00:00", "Z", date('c'));
		}
		
		// Import object XML nodes into document
		$doc = dom_import_simplexml($xml);
		foreach ($xmlEntries as $xmlEntry) {
			$subNode = dom_import_simplexml($xmlEntry);
			$importedNode = $doc->ownerDocument->importNode($subNode, true);
			$doc->appendChild($importedNode);
		}
		
		return $xml;
	}
	
	
	public static function addHTMLRow($html, $fieldName, $displayName, $value, $includeEmpty=false) {
		if (!$includeEmpty && ($value === '' || $value === false)) {
			return;
		}
		
		$tr = $html->addChild('tr');
		if ($fieldName) {
			$tr->addAttribute('class', $fieldName);
		}
		$th = $tr->addChild('th', $displayName);
		$th['style'] = 'text-align: right';
		$td = $tr->addChild('td', htmlspecialchars($value));
		return $tr;
	}
}
?>
