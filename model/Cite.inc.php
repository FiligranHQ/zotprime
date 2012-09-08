<?php
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

class Zotero_Cite {
	private static $citePaperJournalArticleURL = false;
	
	
	public static function getCitationFromCiteServer($item, array $queryParams) {
		$json = self::getJSONFromItems(array($item));
		$response = self::makeRequest($queryParams, 'citation', $json);
		$response = self::processCitationResponse($response);
		if ($response) {
			$key = self::getCacheKey('citation', $item, $queryParams);
			Z_Core::$MC->set($key, $response);
		}
		return $response;
	}
	
	
	public static function getBibliographyFromCitationServer($items, array $queryParams) {
		$json = self::getJSONFromItems($items);
		$response = self::makeRequest($queryParams, 'bibliography', $json);
		$response = self::processBibliographyResponse($response);
		if ($response && sizeOf($items) == 1) {
			$key = self::getCacheKey('bib', $items[0], $queryParams);
			Z_Core::$MC->set($key, $response);
		}
		return $response;
	}
	
	
	public static function multiGetFromMemcached($mode, $items, array $queryParams) {
		$keys = array();
		foreach ($items as $item) {
			$keys[] = self::getCacheKey($mode, $item, $queryParams);
		}
		$results = Z_Core::$MC->get($keys);
		
		$response = array();
		foreach ($results as $key => $val) {
			$lk = self::extractLibraryKeyFromCacheKey($key);
			$response[$lk] = $val;
		}
		return $response;
	}
	
	
	public static function multiGetFromCiteServer($mode, $sets, array $queryParams) {
		require_once("../include/RollingCurl.inc.php");
		
		$t = microtime(true);
		
		$setIDs = array();
		$data = array();
		
		$requestCallback = function ($response, $info) use ($mode, &$setIDs, &$data) {
			if ($info['http_code'] != 200) {
				error_log("WARNING: HTTP {$info['http_code']} from citeserver $mode request: " . $response);
				return;
			}
			
			$response = json_decode($response);
			if (!$response) {
				error_log("WARNING: Invalid response from citeserver $mode request: " . $response);
				return;
			}
			
			$str = parse_url($info['url']);
			parse_str($str['query']);
			
			if ($mode == 'citation') {
				$data[$setIDs[$setID]] = Zotero_Cite::processCitationResponse($response);
			}
			else if ($mode == 'bib') {
				$data[$setIDs[$setID]] = Zotero_Cite::processBibliographyResponse($response);
			}
		};
		
		$origURLPath = self::buildURLPath($queryParams, $mode);
		
		$rc = new RollingCurl($requestCallback);
		// Number of simultaneous requests
		$rc->window_size = 20;
		foreach ($sets as $key => $items) {
			$json = self::getJSONFromItems($items);
			
			$server = "http://"
				. Z_CONFIG::$CITATION_SERVERS[array_rand(Z_CONFIG::$CITATION_SERVERS)];
			
			// Include array position in URL so that the callback can figure
			// out what request this was
			$url = $server . $origURLPath . "&setID=" . $key;
			// TODO: support multiple items per set, if necessary
			if (!($items instanceof Zotero_Item)) {
				throw new Exception("items is not a Zotero_Item");
			}
			$setIDs[$key] = $items->libraryID . "/" . $items->key;
			
			$request = new RollingCurlRequest($url);
			$request->options = array(
				CURLOPT_POST => 1,
				CURLOPT_POSTFIELDS => $json,
				CURLOPT_HTTPHEADER => array("Expect:"),
				CURLOPT_CONNECTTIMEOUT => 1,
				CURLOPT_TIMEOUT => 4,
				CURLOPT_HEADER => 0, // do not return HTTP headers
				CURLOPT_RETURNTRANSFER => 1
			); 
			$rc->add($request);
		}
		$rc->execute();
		
		error_log(sizeOf($sets) . " $mode requests in " . round(microtime(true) - $t, 3));
		
		return $data;
	}
	
	
	//
	// Ported from cite.js in the Zotero client
	//
	
	/**
	 * Mappings for names
	 * Note that this is the reverse of the text variable map, since all mappings should be one to one
	 * and it makes the code cleaner
	 */
	private static $zoteroNameMap = array(
		"author" => "author",
		"editor" => "editor",
		"translator" => "translator",
		"seriesEditor" => "collection-editor",
		"bookAuthor" => "container-author"
	);
	
	/**
	 * Mappings for text variables
	 */
	private static $zoteroFieldMap = array(
		"title" => array("title"),
		"container-title" => array("publicationTitle",  "reporter", "code"), /* reporter and code should move to SQL mapping tables */
		"collection-title" => array("seriesTitle", "series"),
		"collection-number" => array("seriesNumber"),
		"publisher" => array("publisher", "distributor"), /* distributor should move to SQL mapping tables */
		"publisher-place" => array("place"),
		"authority" => array("court"),
		"page" => array("pages"),
		"volume" => array("volume"),
		"issue" => array("issue"),
		"number-of-volumes" => array("numberOfVolumes"),
		"number-of-pages" => array("numPages"),
		"edition" => array("edition"),
		"version" => array("version"),
		"section" => array("section"),
		"genre" => array("type", "artworkSize"), /* artworkSize should move to SQL mapping tables, or added as a CSL variable */
		"medium" => array("medium"),
		"archive" => array("archive"),
		"archive_location" => array("archiveLocation"),
		"event" => array("meetingName", "conferenceName"), /* these should be mapped to the same base field in SQL mapping tables */
		"event-place" => array("place"),
		"abstract" => array("abstractNote"),
		"URL" => array("url"),
		"DOI" => array("DOI"),
		"ISBN" => array("ISBN"),
		"call-number" => array("callNumber"),
		"note" => array("extra"),
		"number" => array("number"),
		"references" => array("history"),
		"shortTitle" => array("shortTitle"),
		"journalAbbreviation" => array("journalAbbreviation")
	);
	
	private static $zoteroDateMap = array(
		"issued" => "date",
		"accessed" => "accessDate"
	);
	
	private static $zoteroTypeMap = array(
		'book' => "book",
		'bookSection' => "chapter",
		'journalArticle' => "article-journal",
		'magazineArticle' => "article-magazine",
		'newspaperArticle' => "article-newspaper",
		'thesis' => "thesis",
		'encyclopediaArticle' => "entry-encyclopedia",
		'dictionaryEntry' => "entry-dictionary",
		'conferencePaper' => "paper-conference",
		'letter' => "personal_communication",
		'manuscript' => "manuscript",
		'interview' => "interview",
		'film' => "motion_picture",
		'artwork' => "graphic",
		'webpage' => "webpage",
		'report' => "report",
		'bill' => "bill",
		'case' => "legal_case",
		'hearing' => "bill",				// ??
		'patent' => "patent",
		'statute' => "bill",				// ??
		'email' => "personal_communication",
		'map' => "map",
		'blogPost' => "webpage",
		'instantMessage' => "personal_communication",
		'forumPost' => "webpage",
		'audioRecording' => "song",		// ??
		'presentation' => "speech",
		'videoRecording' => "motion_picture",
		'tvBroadcast' => "broadcast",
		'radioBroadcast' => "broadcast",
		'podcast' => "song",			// ??
		'computerProgram' => "book"		// ??
	);
	
	private static $quotedRegexp = '/^".+"$/';
	
	public static function retrieveItem($zoteroItem) {
		if (!$zoteroItem) {
			throw new Exception("Zotero item not provided");
		}
		
		// don't return URL or accessed information for journal articles if a
		// pages field exists
		$itemType = Zotero_ItemTypes::getName($zoteroItem->itemTypeID);
		$cslType = isset(self::$zoteroTypeMap[$itemType]) ? self::$zoteroTypeMap[$itemType] : false;
		if (!$cslType) $cslType = "article";
		$ignoreURL = (($zoteroItem->getField("accessDate", true, true, true) || $zoteroItem->getField("url", true, true, true)) &&
				in_array($itemType, array("journalArticle", "newspaperArticle", "magazineArticle"))
				&& $zoteroItem->getField("pages", false, false, true)
				&& self::$citePaperJournalArticleURL);
		
		$cslItem = array(
			'id' => $zoteroItem->libraryID . "/" . $zoteroItem->key,
			'type' => $cslType
		);
		
		// get all text variables (there must be a better way)
		// TODO: does citeproc-js permit short forms?
		foreach (self::$zoteroFieldMap as $variable=>$fields) {
			if ($variable == "URL" && $ignoreURL) continue;
			
			foreach($fields as $field) {
				$value = $zoteroItem->getField($field, false, true, true);
				if ($value !== "") {
					// Strip enclosing quotes
					if (preg_match(self::$quotedRegexp, $value)) {
						$value = substr($value, 1, strlen($value)-2);
					}
					$cslItem[$variable] = $value;
					break;
				}
			}
		}
		
		// separate name variables
		$authorID = Zotero_CreatorTypes::getPrimaryIDForType($zoteroItem->itemTypeID);
		$creators = $zoteroItem->getCreators();
		foreach ($creators as $creator) {
			if ($creator['creatorTypeID'] == $authorID) {
				$creatorType = "author";
			}
			else {
				$creatorType = Zotero_CreatorTypes::getName($creator['creatorTypeID']);
			}
			
			$creatorType = isset(self::$zoteroNameMap[$creatorType]) ? self::$zoteroNameMap[$creatorType] : false;
			if (!$creatorType) continue;
			
			$nameObj = array('family' => $creator['ref']->lastName, 'given' => $creator['ref']->firstName);
			
			if (isset($cslItem[$creatorType])) {
				$cslItem[$creatorType][] = $nameObj;
			}
			else {
				$cslItem[$creatorType] = array($nameObj);
			}
		}
		
		// get date variables
		foreach (self::$zoteroDateMap as $key=>$val) {
			$date = $zoteroItem->getField($val, false, true, true);
			if ($date) {
				if (Zotero_Date::isSQLDateTime($date)) {
					$date = substr($date, 0, 10);
				}
				$cslItem[$key] = array("raw" => $date);
				continue;
				
				
				$date = Zotero_Date::strToDate($date);
				
				if (!empty($date['part']) && !$date['month']) {
					// if there's a part but no month, interpret literally
					$cslItem[$variable] = array("literal" => $date['part']);
				}
				else {
					// otherwise, use date-parts
					$dateParts = array();
					if ($date['year']) {
						$dateParts[] = $date['year'];
						if ($date['month']) {
							$dateParts[] = $date['month'] + 1; // Mimics JS
							if ($date['day']) {
								$dateParts[] = $date['day'];
							}
						}
					}
					$cslItem[$key] = array("date-parts" => array($dateParts));
				}
			}
		}
		
		return $cslItem;
	}
	
	
	public static function getJSONFromItems($items, $asArray=false) {
		// Allow a single item to be passed
		if ($items instanceof Zotero_Item) {
			$items = array($items);
		}
		
		$cslItems = array();
		foreach ($items as $item) {
			$cslItems[] = $item->toCSLItem();
		}
		
		$json = array(
			"items" => $cslItems
		);
		
		if ($asArray) {
			return $json;
		}
		
		return json_encode($json);
	}
	
	
	private static function getCacheKey($mode, $item, array $queryParams) {
		$lk = $item->libraryID . "/" . $item->key;
		return $mode . "_" . $lk . "_"
				. md5($item->etag . json_encode($queryParams))
				. "_" . Z_CONFIG::$CACHE_VERSION_BIB;
	}
	
	
	private static function extractLibraryKeyFromCacheKey($cacheKey) {
		preg_match('"[^_]+_([^_]+)_"', $cacheKey, $matches);
		return $matches[1];
	}
	
	
	private static function buildURLPath(array $queryParams, $mode) {
		$url = "/?responseformat=json";
		foreach ($queryParams as $param => $value) {
			switch ($param) {
			case 'style':
				if (!is_string($value) || !preg_match('/^[a-zA-Z0-9\-]+$/', $value)) {
					throw new Exception("Invalid style", Z_ERROR_CITESERVER_INVALID_STYLE);
				}
				$url .= "&" . $param . "=" . urlencode($value);
				break;
				
			case 'linkwrap':
				$url .= "&" . $param . "=" . ($value ? "1" : "0");
				break;
			}
		}
		if ($mode == 'citation') {
			$url .= "&citations=1&bibliography=0";
		}
		return $url;
	}
	
	
	private static function makeRequest(array $queryParams, $mode, $json) {
		$servers = Z_CONFIG::$CITATION_SERVERS;
		// Try servers in a random order
		shuffle($servers);
		
		foreach ($servers as $server) {
			$url = "http://" . $server . self::buildURLPath($queryParams, $mode);
			
			$start = microtime(true);
			
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array("Expect:"));
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 4);
			curl_setopt($ch, CURLOPT_HEADER, 0); // do not return HTTP headers
			curl_setopt($ch, CURLOPT_RETURNTRANSFER , 1);
			$response = curl_exec($ch);
			
			$time = microtime(true) - $start;
			error_log("Bib request took " . round($time, 3));
			
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			
			if ($code == 404) {
				throw new Exception("Invalid style", Z_ERROR_CITESERVER_INVALID_STYLE);
			}
			
			// If no response, try another server
			if (!$response) {
				continue;
			}
			
			break;
		}
		
		if (!$response) {
			throw new Exception("Error generating $mode");
		}
		
		$response = json_decode($response);
		if (!$response) {
			throw new Exception("Error generating $mode -- invalid response");
		}
		
		return $response;
	}
	
	
	public static function processCitationResponse($response) {
		if (strpos($response->citations[0][1], "[CSL STYLE ERROR: ") !== false) {
			return false;
		}
		return "<span>" . $response->citations[0][1] . "</span>";
	}
	
	
	public static function processBibliographyResponse($response, $css='inline') {
		//
		// Ported from Zotero.Cite.makeFormattedBibliography() in Zotero client
		//
		$bib = $response->bibliography;
		$html = $bib[0]->bibstart . implode("", $bib[1]) . $bib[0]->bibend;
		
		if ($css == "none") {
			return $html;
		}
		
		$sfa = "second-field-align";
		
		//if (!empty($_GET['citedebug'])) {
		//	echo "<!--\n";
		//	echo("maxoffset: " . $bib[0]->maxoffset . "\n");
		//	echo("entryspacing: " . $bib[0]->entryspacing . "\n");
		//	echo("linespacing: " . $bib[0]->linespacing . "\n");
		//	echo("hangingindent: " . (isset($bib[0]->hangingindent) ? $bib[0]->hangingindent : "false") . "\n");
		//	echo("second-field-align: " . $bib[0]->$sfa . "\n");
		//	echo "-->\n\n";
		//}
		
		// Validate input
		if (!is_numeric($bib[0]->maxoffset)) throw new Exception("Invalid maxoffset");
		if (!is_numeric($bib[0]->entryspacing)) throw new Exception("Invalid entryspacing");
		if (!is_numeric($bib[0]->linespacing)) throw new Exception("Invalid linespacing");
		
		$maxOffset = (int) $bib[0]->maxoffset;
		$entrySpacing = (int) $bib[0]->entryspacing;
		$lineSpacing = (int) $bib[0]->linespacing;
		$hangingIndent = !empty($bib[0]->hangingindent) ? (int) $bib[0]->hangingindent : 0;
		$secondFieldAlign = !empty($bib[0]->$sfa); // 'flush' and 'margin' are the same for HTML
		
		$xml = new SimpleXMLElement($html);
		
		$multiField = !!$xml->xpath("//div[@class = 'csl-left-margin']");
		
		// One of the characters is usually a period, so we can adjust this down a bit
		$maxOffset = max(1, $maxOffset - 2);
		
		// Force a minimum line height
		if ($lineSpacing <= 1.35) $lineSpacing = 1.35;
		
		$xml['style'] .= "line-height: " . $lineSpacing . "; ";
		
		if ($hangingIndent) {
			if ($multiField && !$secondFieldAlign) {
				throw new Exception("second-field-align=false and hangingindent=true combination is not currently supported");
			}
			// If only one field, apply hanging indent on root
			else if (!$multiField) {
				$xml['style'] .= "padding-left: {$hangingIndent}em; text-indent:-{$hangingIndent}em;";
			}
		}
		
		$leftMarginDivs = $xml->xpath("//div[@class = 'csl-left-margin']");
		$clearEntries = sizeOf($leftMarginDivs) > 0;
		
		// csl-entry
		$divs = $xml->xpath("//div[@class = 'csl-entry']");
		$num = sizeOf($divs);
		$i = 0;
		foreach ($divs as $div) {
			$first = $i == 0;
			$last = $i == $num - 1;
			
			if ($clearEntries) {
				$div['style'] .= "clear: left; ";
			}
			
			if ($entrySpacing) {
				if (!$last) {
					$div['style'] .= "margin-bottom: " . $entrySpacing . "em;";
				}
			}
			
			$i++;
		}
		
		// Padding on the label column, which we need to include when
		// calculating offset of right column
		$rightPadding = .5;
		
		// div.csl-left-margin
		foreach ($leftMarginDivs as $div) {
			$div['style'] = "float: left; padding-right: " . $rightPadding . "em; ";
			
			// Right-align the labels if aligning second line, since it looks
			// better and we don't need the second line of text to align with
			// the left edge of the label
			if ($secondFieldAlign) {
				$div['style'] .= "text-align: right; width: " . $maxOffset . "em;";
			}
		}
		
		// div.csl-right-inline
		foreach ($xml->xpath("//div[@class = 'csl-right-inline']") as $div) {
			$div['style'] .= "margin: 0 .4em 0 " . ($secondFieldAlign ? $maxOffset + $rightPadding : "0") . "em;";
			
			if ($hangingIndent) {
				$div['style'] .= "padding-left: {$hangingIndent}em; text-indent:-{$hangingIndent}em;";
			}
		}
		
		// div.csl-indent
		foreach ($xml->xpath("//div[@class = 'csl-indent']") as $div) {
			$div['style'] = "margin: .5em 0 0 2em; padding: 0 0 .2em .5em; border-left: 5px solid #ccc;";
		}
		
		return $xml->asXML();
	}
	
	
	/*Zotero.Cite.System.getAbbreviations = function() {
		return {};
	}*/
}
?>
