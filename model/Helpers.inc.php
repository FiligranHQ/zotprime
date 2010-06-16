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

class Zotero_Helpers {
	public static function renderItemsMetadataTable(Zotero_Item $item, $asSimpleXML=false) {
		$html = new SimpleXMLElement('<table/>');
		
		/*
		// Title
		$tr = $html->addChild('tr');
		$tr->addAttribute('class', 'title');
		$tr->addChild('th', Zotero_ItemFields::getLocalizedString(false, 'title'));
		$tr->addChild('td', htmlspecialchars($item->getDisplayTitle(true)));
		*/
		
		// Item type
		$tr = $html->addChild('tr');
		$tr->addAttribute('class', 'itemType');
		$th = $tr->addChild('th', Zotero_ItemFields::getLocalizedString(false, 'itemType'));
		$th['style'] = 'text-align: right';
		$tr->addChild('td', htmlspecialchars(
			Zotero_ItemTypes::getLocalizedString($item->itemTypeID)
		));
		
		// Creators
		$creators = $item->getCreators();
		if ($creators) {
			$displayText = '';
			foreach ($creators as $creator) {
				// Two fields
				if ($creator['ref']->fieldMode == 0) {
					$displayText = $creator['ref']->firstName . ' ' . $creator['ref']->lastName;
				}
				// Single field
				else if ($creator['ref']->fieldMode == 1) {
					$displayText = $creator['ref']->lastName;
				}
				else {
					// TODO
				}
				
				$tr = $html->addChild('tr');
				$tr->addAttribute('class', 'creator');
				$th = $tr->addChild('th', Zotero_CreatorTypes::getLocalizedString($creator['creatorTypeID']));
				$th['style'] = 'text-align: right';
				$tr->addChild('td', htmlspecialchars(trim($displayText)));
			}
		}
		
		//$primaryFields = Zotero_Items::$primaryFields;
		$primaryFields = array();
		$fields = array_merge($primaryFields, $item->getUsedFields());
		
		foreach ($fields as $field) {
			if (in_array($field, $primaryFields)) {
				$fieldName = $field;
			}
			else {
				$fieldName = Zotero_ItemFields::getName($field);
			}
			
			// Skip certain fields
			switch ($fieldName) {
				case '':
				case 'userID':
				case 'libraryID':
				case 'key':
				case 'itemTypeID':
				case 'itemID':
				case 'title':
				//case 'firstCreator':
				//case 'numAttachments':
				//case 'numNotes':
				case 'serverDateModified':
					// 'continue' apparently is the same as 'break' in PHP's switch
					continue 2;
			}
			
			$localizedFieldName = Zotero_ItemFields::getLocalizedString(false, $field);
			
			$value = $item->getField($field);
			$value = trim($value);
			
			// Skip empty fields
			if (!$value) {
				continue;
			}
			
			$fieldText = '';
			
			// Shorten long URLs manually until Firefox wraps at ?
			// (like Safari) or supports the CSS3 word-wrap property
			if (false && preg_match("'https?://'", $value)) {
				$fieldText = $value;
				
				$firstSpace = strpos($value, ' ');
				// Break up long uninterrupted string
				if (($firstSpace === false && strlen($value) > 29) || $firstSpace > 29) {
					$stripped = false;
					
					/*
					// Strip query string for sites we know don't need it
					for each(var re in _noQueryStringSites) {
						if (re.test($field)){
							var pos = $field.indexOf('?');
							if (pos != -1) {
								fieldText = $field.substr(0, pos);
								stripped = true;
							}
							break;
						}
					}
					*/
					
					if (!$stripped) {
						// Add a line-break after the ? of long URLs
						//$fieldText = str_replace($field.replace('?', "?<ZOTEROBREAK/>");
						
						// Strip query string variables from the end while the
						// query string is longer than the main part
						$pos = strpos($fieldText, '?');
						if ($pos !== false) {
							while ($pos < (strlen($fieldText) / 2)) {
								$lastAmp = strrpos($fieldText, '&');
								if ($lastAmp === false) {
									break;
								}
								$fieldText = substr($fieldText, 0, $lastAmp);
								$shortened = true;
							}
							// Append '&...' to the end
							if ($shortened) {
								 $fieldText .= "&…";
							}
						}
					}
				}
				
				if ($field == 'url') {
					$linkContainer = new SimpleXMLElement("<container/>");
					$linkContainer->a = $value;
					$linkContainer->a['href'] = $fieldText;
				}
			}
			// Remove SQL date from multipart dates
			// (e.g. '2006-00-00 Summer 2006' becomes 'Summer 2006')
			else if ($fieldName == 'date') {
				$fieldText = htmlspecialchars($value);
			}
			// Convert dates to local format
			else if ($fieldName == 'accessDate' || $fieldName == 'dateAdded' || $fieldName == 'dateModified') {
				//$date = Zotero.Date.sqlToDate($field, true)
				$date = $value;
				//fieldText = escapeXML(date.toLocaleString());
				$fieldText = htmlspecialchars($date);
			}
			else {
				$fieldText = htmlspecialchars($value);
			}
			
			$tr = $html->addChild('tr');
			$tr->addAttribute('class', $fieldName);
			$th = $tr->addChild('th', $localizedFieldName);
			$th['style'] = 'text-align: right';
			if (isset($linkContainer)) {
				$td = $tr->addChild('td');
				$trNode = dom_import_simplexml($td);
				$linkNode = dom_import_simplexml($linkContainer->a);
				$importedNode = $trNode->ownerDocument->importNode($linkNode, true);
				$trNode->appendChild($importedNode);
				unset($linkContainer);
			}
			else {
				$tr->addChild('td', $fieldText);
			}
		}
		
		if ($item->isNote() || $item->isAttachment()) {
			$note = $item->getNote();
			if ($note) {
				$tr = $html->addChild('tr');
				$tr->addAttribute('class', 'note');
				$th = $tr->addChild('th', 'Note');
				$th['style'] = 'text-align: right';
				
				try {
					$noteXML = @new SimpleXMLElement($note);
					$td = $tr->addChild('td');
					$trNode = dom_import_simplexml($td);
					$linkNode = dom_import_simplexml($noteXML);
					$importedNode = $trNode->ownerDocument->importNode($linkNode, true);
					$trNode->appendChild($importedNode);
					unset($noteXML);
				}
				catch (Exception $e) {
					// Store non-HTML notes as <pre>
					$tr->td->pre = $item->getNote($note);
				}
			}
		}
		
		if ($asSimpleXML) {
			return $html;
		}
		
		return str_replace('<?xml version="1.0"?>', '', $html->asXML());
	}
}
?>
