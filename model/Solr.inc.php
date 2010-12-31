<?
class Zotero_Solr {
	private static $commitWithin = 5000;
	
	public static function addItem($item, $commitWithin=false) {
		self::addItems(array($item), $commitWithin);
	}
	
	
	public static function addItems($items, $commitWithin=false) {
		// TEMP
		return;
		
		$docs = array();
		foreach ($items as $item) {
			$docs[] = $item->toSolrDocument();
		}
		$updateResponse = Z_Core::$Solr->addDocuments(
			$docs,
			false,
			$commitWithin === false ? self::$commitWithin : $commitWithin
		);
	}
	
	
	public static function removeItem($libraryID, $key) {
		// TEMP
		return;
		
		$uri = self::getItemURI($libraryID, $key);
		Z_Core::$Solr->deleteById($uri);
	}
	
	
	public static function removeItems($pairs) {
		// TEMP
		return;
		
		$uris = array();
		foreach ($pairs as $pair) {
			$uris[] = self::getItemURI($pair['libraryID'], $pair['key']);
		}
		Z_Core::$Solr->deleteByIds($uris);
	}
	
	
	public static function removeLibrary($libraryID) {
		// TEMP
		return;
		
		Z_Core::$Solr->deleteByQuery("libraryID:" . (int) $libraryID);
	}
	
	
	public static function removeLibraries($libraryIDs) {
		foreach ($libraryIDs as $libraryID) {
			self::removeLibrary($libraryID);
		}
	}
	
	
	public static function getItemURI($libraryID, $key) {
		$uri = Zotero_Atom::getLibraryURI($libraryID) . "/items/$key";
		return str_replace(Zotero_Atom::getBaseURI(), '', $uri);
	}
	
	
	public static function queueItem($libraryID, $key) {
		self::queueItems(array(array($libraryID, $key)));
	}
	
	
	public static function queueItems($pairs) {
		// TEMP
		return;
		
		$sql = "INSERT IGNORE INTO solrQueue (libraryID, `key`) VALUES ";
		Zotero_DB::bulkInsert($sql, $pairs, 100);
	}
	
	
	public static function queueLibrary($libraryID) {
		// TEMP
		return;
		
		$sql = "INSERT IGNORE INTO solrQueue (libraryID, `key`) VALUES (?,?)";
		Zotero_DB::query($sql, array($libraryID, ''));
	}
	
	
	public static function getQueuedItems($solrProcessID, $max=100) {
		Zotero_DB::beginTransaction();
		
		$sql = "CREATE TEMPORARY TABLE tmpKeys (
					libraryID INT UNSIGNED NOT NULL,
					`key` CHAR(8) NOT NULL,
					PRIMARY KEY (libraryID, `key`)
				)";
		Zotero_DB::query($sql);
		
		$sql = "INSERT INTO tmpKeys SELECT libraryID, `key` FROM solrQueue
				WHERE solrProcessID IS NULL ORDER BY added LIMIT $max FOR UPDATE";
		Zotero_DB::query($sql);
		
		$sql = "SELECT * FROM tmpKeys";
		$rows = Zotero_DB::query($sql);
		
		if ($rows) {
			$sql = "UPDATE tmpKeys JOIN solrQueue USING (libraryID, `key`) SET solrProcessID=?";
			Zotero_DB::query($sql, $solrProcessID);
		}
		else {
			$itemIDs = array();
		}
		
		$sql = "DROP TEMPORARY TABLE tmpKeys";
		Zotero_DB::query($sql);
		
		Zotero_DB::commit();
		
		return $rows;
	}
	
	
	public static function processFromQueue($solrProcessID) {
		// Update host field with the host processing the data
		$addr = gethostbyname(gethostname());
		
		$sql = "INSERT INTO solrProcesses (solrProcessID, processorHost) VALUES (?, INET_ATON(?))";
		Zotero_DB::query($sql, array($solrProcessID, $addr));
		
		$updateItems = array();
		$deletePairs = array();
		$deleteLibraries = array();
		
		$lkPairs = self::getQueuedItems($solrProcessID);
		foreach ($lkPairs as $pair) {
			// If key not specified, update/delete entire library
			if (!$pair['key']) {
				if (Zotero_Libraries::exists($pair['libraryID'])) {
					// For now, ignore existing library
					//Z_Core::logError("");
					continue;
				}
				
				// Delete by query
				$deleteLibraries[] = $pair['libraryID'];
				continue;
			}
			
			$item = Zotero_Items::getByLibraryAndKey($pair['libraryID'], $pair['key']);
			if (!$item) {
				$deletePairs[] = $pair;
				continue;
			}
			$updateItems[] = $item;
		}
		
		try {
			if ($updateItems) {
				self::addItems($updateItems);
			}
			if ($deletePairs) {
				self::removeItems($deletePairs);
			}
			if ($deleteLibraries) {
				self::removeLibraries($deleteLibraries);
			}
		}
		catch (Exception $e) {
			Z_Core::logError($e);
			self::removeProcess($solrProcessID);
			return -2;
		}
		
		self::removeQueuedItems($solrProcessID);
		self::removeProcess($solrProcessID);
		return 1;
	}
	
	
	public static function removeQueuedItems($solrProcessID) {
		$sql = "DELETE FROM solrQueue WHERE solrProcessID=?";
		Zotero_DB::query($sql, $solrProcessID);
	}
	
	
	public static function countQueuedProcesses() {
		$sql = "SELECT COUNT(*) FROM solrQueue";
		return Zotero_DB::valueQuery($sql);
	}
	
	
	public static function getOldProcesses($host=null, $seconds=60) {
		$sql = "SELECT DISTINCT solrProcessID FROM solrProcesses
				WHERE started < NOW() - INTERVAL ? SECOND";
		$params = array($seconds);
		if ($host) {
			$sql .= " AND processorHost=INET_ATON(?)";
			$params[] = $host;
		}
		return Zotero_DB::columnQuery($sql, $params);
	}
	
	
	public static function removeProcess($solrProcessID) {
		$sql = "DELETE FROM solrProcesses WHERE solrProcessID=?";
		Zotero_DB::query($sql, $solrProcessID);
	}
}
?>
