<?
class Zotero_Index {
	public static $queueingEnabled = true;
	
	public static function addItem($item) {
		self::addItems(array($item));
	}
	
	
	public static function addItems($items) {
		foreach ($items as $item) {
			$doc = $item->toMongoIndexDocument();
			$doc['ts'] = new MongoDate();
			Z_Core::$Mongo->update("searchItems", $doc["_id"], $doc, array("upsert"=>true, "safe"=>true));
		}
	}
	
	
	public static function removeItem($libraryID, $key) {
		Z_Core::$Mongo->remove("searchItems", "$libraryID/$key");
	}
	
	
	public static function removeItems($pairs) {
		$ids = array();
		foreach ($pairs as $pair) {
			$ids[] = $pair['libraryID'] . "/" . $pair['key'];
		}
		Z_Core::$Mongo->remove("searchItems", array("_id" => array('$in'=>$ids)));
	}
	
	
	public static function removeLibrary($libraryID) {
		$re = new MongoRegex('/^' . $libraryID . '\//');
		Z_Core::$Mongo->remove("searchItems", array("_id" => $re));
	}
	
	
	public static function removeLibraries($libraryIDs) {
		foreach ($libraryIDs as $libraryID) {
			self::removeLibrary($libraryID);
		}
	}
	
	
	public static function queueItem($libraryID, $key) {
		self::queueItems(array(array($libraryID, $key)));
	}
	
	
	public static function queueItems($pairs) {
		if (!self::$queueingEnabled) {
			return;
		}
		$sql = "INSERT IGNORE INTO indexQueue (libraryID, `key`) VALUES ";
		Zotero_DB::bulkInsert($sql, $pairs, 100);
	}
	
	
	public static function queueLibrary($libraryID) {
		if (!self::$queueingEnabled) {
			return;
		}
		$sql = "INSERT IGNORE INTO indexQueue (libraryID, `key`) VALUES (?,?)";
		Zotero_DB::query($sql, array($libraryID, ''));
	}
	
	
	public static function getQueuedItems($indexProcessID, $max=100) {
		Zotero_DB::beginTransaction();
		
		$sql = "CREATE TEMPORARY TABLE tmpKeys (
					libraryID INT UNSIGNED NOT NULL,
					`key` CHAR(8) NOT NULL,
					PRIMARY KEY (libraryID, `key`)
				)";
		Zotero_DB::query($sql);
		
		$sql = "INSERT INTO tmpKeys SELECT libraryID, `key` FROM indexQueue
				WHERE indexProcessID IS NULL ORDER BY added LIMIT $max FOR UPDATE";
		Zotero_DB::query($sql);
		
		$sql = "SELECT * FROM tmpKeys";
		$rows = Zotero_DB::query($sql);
		
		if ($rows) {
			$sql = "UPDATE tmpKeys JOIN indexQueue USING (libraryID, `key`) SET indexProcessID=?";
			Zotero_DB::query($sql, $indexProcessID);
		}
		else {
			$itemIDs = array();
		}
		
		$sql = "DROP TEMPORARY TABLE tmpKeys";
		Zotero_DB::query($sql);
		
		Zotero_DB::commit();
		
		return $rows;
	}
	
	
	public static function processFromQueue($indexProcessID) {
		// Update host field with the host processing the data
		$addr = gethostbyname(gethostname());
		
		$sql = "INSERT INTO indexProcesses (indexProcessID, processorHost) VALUES (?, INET_ATON(?))";
		Zotero_DB::query($sql, array($indexProcessID, $addr));
		
		$updateItems = array();
		$deletePairs = array();
		$deleteLibraries = array();
		
		$lkPairs = self::getQueuedItems($indexProcessID);
		foreach ($lkPairs as $pair) {
			// If key not specified, update/delete entire library
			if (!$pair['key']) {
				if (Zotero_Libraries::exists($pair['libraryID'])) {
					throw new Exception("Unimplemented");
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
			self::removeProcess($indexProcessID);
			return -2;
		}
		
		self::removeQueuedItems($indexProcessID);
		self::removeProcess($indexProcessID);
		return 1;
	}
	
	
	public static function removeQueuedItems($indexProcessID) {
		$sql = "DELETE FROM indexQueue WHERE indexProcessID=?";
		Zotero_DB::query($sql, $indexProcessID);
	}
	
	
	public static function countQueuedProcesses() {
		$sql = "SELECT COUNT(*) FROM indexQueue";
		return Zotero_DB::valueQuery($sql);
	}
	
	
	public static function getOldProcesses($host=null, $seconds=60) {
		$sql = "SELECT DISTINCT indexProcessID FROM indexProcesses
				WHERE started < NOW() - INTERVAL ? SECOND";
		$params = array($seconds);
		if ($host) {
			$sql .= " AND processorHost=INET_ATON(?)";
			$params[] = $host;
		}
		return Zotero_DB::columnQuery($sql, $params);
	}
	
	
	public static function removeProcess($indexProcessID) {
		$sql = "DELETE FROM indexProcesses WHERE indexProcessID=?";
		Zotero_DB::query($sql, $indexProcessID);
	}
}
?>
