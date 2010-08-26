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

class Zotero_Sync {
	public static $defaultAPIVersion = 8;
	
	public static $validationError = '';
	
	// Don't bother error-checking uploads below this size
	private static $minErrorCheckSize = 5000;
	// Don't process uploads larger than this that haven't been error-checked
	private static $minErrorCheckRequiredSize = 30000;
	// Don't process uploads larger than this in smallestFirst mode
	private static $maxSmallestSize = 200000;
	
	public static function getResponseXML($version=null) {
		if (!$version) {
			$version = self::$defaultAPIVersion;
		}
		
		$xml = new SimpleXMLElement('<response/>');
		$xml['version'] = $version;
		
		// Generate a fixed timestamp for the response
		//
		// Responses that modify data need to override this with the DB transaction
		// timestamp if the client will use the returned timestamp for comparison purposes
		$time = microtime(true);
		$unixTimestamp = (int) $time;
		$timestampMS = (int) substr(strrchr($time, "."), 1);
		$timestamp = (float) ($unixTimestamp . '.' . $timestampMS);
		$xml['timestamp'] = $timestamp;
		return $xml;
	}
	
	/**
	 * Get a key to represent the current state of all of a user's libraries
	 */
	public static function getUpdateKey($userID) {
		$libraryIDs = Zotero_Libraries::getUserLibraries($userID);
		$parts = array();
		foreach ($libraryIDs as $libraryID) {
			$sql = "SELECT CONCAT(UNIX_TIMESTAMP(lastUpdated), '.', IFNULL(lastUpdatedMS, 0))
					FROM libraries WHERE libraryID=?";
			$timestamp = Zotero_DB::valueQuery($sql, $libraryID);
			$parts[] = $libraryID . ':' . $timestamp;
		}
		return md5(implode(',', $parts));
	}
	
	
	/**
	 * Check if any of a user's libraries are queued for writing
	 *
	 * Clients can still read (/updated) but cannot write (/upload) if this is true
	 */
	public static function userIsReadLocked($userID) {
		Zotero_DB::beginTransaction();
		
		$libraryIDs = Zotero_Libraries::getUserLibraries($userID);
		$sql = "SELECT COUNT(*) FROM syncQueueLocks WHERE libraryID IN (";
		$sql .= implode(', ', array_fill(0, sizeOf($libraryIDs), '?'));
		$sql .= ")";
		
		$locked = !!Zotero_DB::valueQuery($sql, $libraryIDs);
		
		Zotero_DB::commit();
		
		return $locked;
	}
	
	
	/**
	 * Check if any of a user's libraries are being written to
	 *
	 * Clients can't read (/updated) but can write (/upload) if this is true
	 */
	public static function userIsWriteLocked($userID) {
		Zotero_DB::beginTransaction();
		
		$libraryIDs = Zotero_Libraries::getUserLibraries($userID);
		
		$sql = "SELECT COUNT(*) FROM syncProcessLocks WHERE libraryID IN (";
		$sql .= implode(', ', array_fill(0, sizeOf($libraryIDs), '?'));
		$sql .= ")";
		
		$locked = !!Zotero_DB::valueQuery($sql, $libraryIDs);
		
		Zotero_DB::commit();
		
		return $locked;
	}
	
	
	/**
	 * Get all the libraryIDs referenced in upload XML
	 */
	public static function parseAffectedLibraries(SimpleXMLElement $xml) {
		set_time_limit(800);
		$nodes = $xml->xpath('//*[@libraryID]');
		$unique = array();
		foreach ($nodes as $node) {
			$unique[(int) $node['libraryID']] = true;
		}
		return array_keys($unique);
	}
	
	
	public static function queueDownload($userID, $sessionID, $lastsync, $version, $updatedObjects) {
		$syncQueueID = Zotero_ID::getBigInt();
		
		// If there's a completed process from this session, delete it, since it
		// seems the results aren't going to be picked up
		$sql = "DELETE FROM syncDownloadQueue WHERE sessionID=? AND finished IS NOT NULL";
		Zotero_DB::query($sql, $sessionID);
		
		$float = explode('.', $lastsync);
		$lastsync = $float[0];
		$lastsyncMS = isset($float[1]) ? substr($float[1], 0, 5) : 0;
		if ($lastsyncMS > 65535) {
			$lastsyncMS = substr($float[1], 0, 4);
		}
		
		$hostname = gethostname();
		$hostID = self::getHostID($hostname);
		if (!$hostID) {
			throw new Exception("Host ID not found for hostname '$hostname'");
		}
		
		$sql = "INSERT INTO syncDownloadQueue
				(syncDownloadQueueID, syncQueueHostID, userID, sessionID, lastsync, lastsyncMS, version, objects)
				VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), ?, ?, ?)";
		Zotero_DB::query(
			$sql,
			array(
				$syncQueueID,
				$hostID,
				$userID,
				$sessionID,
				$lastsync,
				$lastsyncMS,
				$version,
				$updatedObjects
			)
		);
		
		return $syncQueueID;
	}
	
	
	public static function queueUpload($userID, $sessionID, $xmldata, $affectedLibraries) {
		$syncQueueID = Zotero_ID::getBigInt();
		$length = strlen($xmldata);
		
		// If there's a completed process from this session, delete it, since it
		// seems the results aren't going to be picked up
		$sql = "DELETE FROM syncQueue WHERE sessionID=? AND finished IS NOT NULL";
		Zotero_DB::query($sql, $sessionID);
		
		$hostname = gethostname();
		$hostID = self::getHostID($hostname);
		if (!$hostID) {
			throw new Exception("Host ID not found for hostname '$hostname'");
		}
		
		Zotero_DB::beginTransaction();
		
		$sql = "INSERT INTO syncQueue
				(syncQueueID, syncQueueHostID, userID, sessionID, xmldata, dataLength, hasCreator)
				VALUES (?, ?, ?, ?, ?, ?, ?)";
		Zotero_DB::query(
			$sql,
			array(
				$syncQueueID,
				$hostID,
				$userID,
				$sessionID,
				$xmldata,
				$length,
				strpos($xmldata, '<creator') === false ? 0 : 1
			)
		);
		
		$sql = "INSERT INTO syncQueueLocks VALUES ";
		$sql .= implode(', ', array_fill(0, sizeOf($affectedLibraries), '(?,?)'));
		$params = array();
		foreach ($affectedLibraries as $libraryID) {
			$params[] = $syncQueueID;
			$params[] = $libraryID;
		}
		try {
			Zotero_DB::query($sql, $params);
		}
		catch (Exception $e) {
			$msg = $e->getMessage();
			if (strpos($msg, "Cannot add or update a child row: a foreign key constraint fails") !== false) {
				Zotero_DB::query("CREATE TEMPORARY TABLE tmpLibraryCheck (libraryID INTEGER UNSIGNED PRIMARY KEY)");
				foreach ($affectedLibraries as $libraryID) {
					Zotero_DB::query("INSERT INTO tmpLibraryCheck VALUES (?)", $libraryID);
				}
				$libraryID = Zotero_DB::valueQuery("SELECT * FROM tmpLibraryCheck WHERE libraryID NOT IN (SELECT libraryID FROM libraries) LIMIT 1");
				if ($libraryID) {
					throw new Exception("Library $libraryID does not exist", Z_ERROR_LIBRARY_ACCESS_DENIED);
				}
			}
			throw ($e);
		}
		
		Zotero_DB::commit();
		
		return $syncQueueID;
	}
	
	
	public static function processDownload($userID, $lastsync, DOMDocument $doc) {
		self::processDownloadInternal($userID, $lastsync, $doc);
	}
	
	
	public static function processUpload($userID, SimpleXMLElement $xml) {
		return self::processUploadInternal($userID, $xml);
	}
	
	
	public static function processDownloadFromQueue($syncProcessID) {
		Zotero_DB::beginTransaction();
		
		// Get a queued process
		$smallestFirst = Z_CONFIG::$SYNC_DOWNLOAD_SMALLEST_FIRST;
		if ($smallestFirst) {
			$sql = "SELECT syncDownloadQueueID, userID,
					CONCAT(UNIX_TIMESTAMP(lastsync), '.', lastsyncMS) AS lastsync,
					version FROM syncDownloadQueue WHERE started IS NULL
					ORDER BY tries > 4, ROUND(objects / 100), added LIMIT 1 FOR UPDATE";
			$row = Zotero_DB::rowQuery($sql);
		}
		// Oldest first
		else {
			$sql = "SELECT syncDownloadQueueID, userID,
					CONCAT(UNIX_TIMESTAMP(lastsync), '.', lastsyncMS) AS lastsync,
					version FROM syncDownloadQueue WHERE started IS NULL
					ORDER BY tries > 4, added LIMIT 1 FOR UPDATE";
			$row = Zotero_DB::rowQuery($sql);
		}
		
		// No pending processes
		if (!$row) {
			Zotero_DB::commit();
			return 0;
		}
		
		$sql = "UPDATE syncDownloadQueue SET started=NOW() WHERE syncDownloadQueueID=?";
		Zotero_DB::query($sql, $row['syncDownloadQueueID']);
		
		Zotero_DB::commit();
		
		$error = false;
		$lockError = false;
		
		try {
			$xml = self::getResponseXML($row['version']);
			$doc = new DOMDocument();
			$domResponse = dom_import_simplexml($xml);
			$domResponse = $doc->importNode($domResponse, true);
			$doc->appendChild($domResponse);
			self::processDownloadInternal($row['userID'], $row['lastsync'], $doc, $row['syncDownloadQueueID'], $syncProcessID);
		}
		catch (Exception $e) {
			$error = true;
			$msg = $e->getMessage();
		}
		
		Zotero_DB::beginTransaction();
		
		// Mark download as finished — NULL indicates success
		if (!$error) {
			$timestamp = $doc->documentElement->getAttribute('timestamp');
			list($timestamp, $timestampMS) = explode('.', $timestamp);
			
			$sql = "UPDATE syncDownloadQueue SET finished=FROM_UNIXTIME(?), finishedMS=?, xmldata=? WHERE syncDownloadQueueID=?";
			Zotero_DB::query(
				$sql,
				array(
					$timestamp,
					$timestampMS,
					$doc->saveXML(),
					$row['syncDownloadQueueID']
				)
			);
		}
		// Timeout error
		else if (strpos($msg, "Lock wait timeout exceeded; try restarting transaction") !== false
				|| strpos($msg, "Deadlock found when trying to get lock; try restarting transaction") !== false) {
			Z_Core::logError($e);
			$sql = "UPDATE syncDownloadQueue SET started=NULL, tries=tries+1 WHERE syncDownloadQueueID=?";
			Zotero_DB::query($sql, $row['syncDownloadQueueID']);
			$lockError = true;
		}
		// Save error
		else {
			Z_Core::logError($e);
			$sql = "UPDATE syncDownloadQueue SET finished=?, finishedMS=?,
						errorCode=?, errorMessage=? WHERE syncDownloadQueueID=?";
			Zotero_DB::query(
				$sql,
				array(
					Zotero_DB::getTransactionTimestamp(),
					Zotero_DB::getTransactionTimestampMS(),
					$e->getCode(),
					serialize($e),
					$row['syncDownloadQueueID']
				)
			);
		}
		
		Zotero_DB::commit();
		
		if ($lockError) {
			return -1;
		}
		else if ($error) {
			return -2;
		}
		
		return 1;
	}
	
	
	public static function processUploadFromQueue($syncProcessID) {
		Zotero_DB::beginTransaction();
		
		if (Z_Core::probability(30)) {
			$sql = "DELETE FROM syncProcesses WHERE started < (NOW() - INTERVAL 45 MINUTE)";
			$row = Zotero_DB::query($sql);
		}
		
		if (Z_Core::probability(30)) {
			$sql = "UPDATE syncQueue SET started=NULL WHERE started IS NOT NULL AND errorCheck!=1 AND
						started < (NOW() - INTERVAL 12 MINUTE) AND finished IS NULL AND dataLength<250000";
			$row = Zotero_DB::rowQuery($sql);
		}
		
		if (Z_Core::probability(30)) {
			$sql = "UPDATE syncQueue SET tries=0 WHERE started IS NULL AND
					tries>=5 AND finished IS NULL";
			$row = Zotero_DB::rowQuery($sql);
		}
		
		// Get a queued process
		$smallestFirst = Z_CONFIG::$SYNC_UPLOAD_SMALLEST_FIRST;
		$sortByQuota = !empty(Z_CONFIG::$SYNC_UPLOAD_SORT_BY_QUOTA);
		
		$sql = "SELECT syncQueue.* FROM syncQueue ";
		if ($sortByQuota) {
			$sql .= "LEFT JOIN storageAccounts USING (userID) ";
		}
		$sql .= "WHERE started IS NULL ";
		if (self::$minErrorCheckRequiredSize) {
			$sql .= "AND (errorCheck=2 OR dataLength<" . self::$minErrorCheckRequiredSize . ") ";
		}
		if ($smallestFirst && self::$maxSmallestSize) {
			$sql .= "AND dataLength<" . self::$maxSmallestSize . " ";
		}
		$sql .= "ORDER BY tries > 4, ";
		if ($sortByQuota) {
			$sql .= "quota DESC, ";
		}
		if ($smallestFirst) {
			//$sql .= "ROUND(dataLength / 1024 / 10), ";
			$sql .= "dataLength, ";
		}
		$sql .= "added LIMIT 1 FOR UPDATE";
		$row = Zotero_DB::rowQuery($sql);
		
		// No pending processes
		if (!$row) {
			Zotero_DB::commit();
			return 0;
		}
		
		// Update host id field with the host processing the data
		$hostname = gethostname();
		$hostID = self::getHostID($hostname);
		if (!$hostID) {
			throw new Exception("Host ID not found for hostname '$hostname'");
		}
		
		$startedTimestamp = microtime(true);
		if (strpos($startedTimestamp, '.') === false) {
			$startedTimestamp .= '.';
		}
		list($started, $startedMS) = explode('.', $startedTimestamp);
		$sql = "UPDATE syncQueue SET started=FROM_UNIXTIME(?), startedMS=?, syncQueueHostID=? WHERE syncQueueID=?";
		Zotero_DB::query($sql, array($started, $startedMS, $hostID, $row['syncQueueID']));
		
		Zotero_DB::commit();
		
		$error = false;
		$lockError = false;
		try {
			$xml = new SimpleXMLElement($row['xmldata']);
			$timestamp = self::processUploadInternal($row['userID'], $xml, $row['syncQueueID'], $syncProcessID);
			list($timestamp, $timestampMS) = explode('.', $timestamp);
		}
		
		catch (Exception $e) {
			$error = true;
			$msg = $e->getMessage();
		}
		
		Zotero_DB::beginTransaction();
		
		// Mark upload as finished — NULL indicates success
		if (!$error) {
			$sql = "UPDATE syncQueue SET finished=FROM_UNIXTIME(?), finishedMS=? WHERE syncQueueID=?";
			Zotero_DB::query(
				$sql,
				array(
					$timestamp,
					$timestampMS,
					$row['syncQueueID']
				)
			);
			
			try {
				$sql = "INSERT INTO syncUploadProcessLog
						(userID, dataLength, syncQueueHostID, processDuration, totalDuration, error)
						VALUES (?,?,?,?,?,?)";
				Zotero_DB::query(
					$sql,
					array(
						$row['userID'],
						$row['dataLength'],
						$hostID,
						round((float) microtime(true) - $startedTimestamp, 2),
						min(time() - strtotime($row['added']), 65535),
						0
					)
				);
			}
			catch (Exception $e) {
				Z_Core::logError($e);
			}
			
			try {
				self::processPostWriteLog($row['syncQueueID'], $row['userID'], $timestamp, $timestampMS);
			}
			catch (Exception $e) {
				Z_Core::logError($e);
			}
		}
		// Timeout error
		else if (strpos($msg, "Lock wait timeout exceeded; try restarting transaction") !== false
				|| strpos($msg, "Deadlock found when trying to get lock; try restarting transaction") !== false) {
			Z_Core::logError($e);
			$sql = "UPDATE syncQueue SET started=NULL, tries=tries+1 WHERE syncQueueID=?";
			Zotero_DB::query($sql, $row['syncQueueID']);
			$lockError = true;
		}
		// Save error
		else {
			// As of PHP 5.3.2 we can't serialize objects containing SimpleXMLElements,
			// and since the stack trace includes one, we have to catch this and
			// manually reconstruct an extension
			try {
				$serialized = serialize($e);
			}
			catch (Exception $e2) {
				$serialized = serialize(new Exception($msg, $e->getCode()));
			}
			
			Z_Core::logError($e);
			$sql = "UPDATE syncQueue SET finished=?, finishedMS=?, errorCode=?, errorMessage=? WHERE syncQueueID=?";
			Zotero_DB::query(
				$sql,
				array(
					Zotero_DB::getTransactionTimestamp(),
					Zotero_DB::getTransactionTimestampMS(),
					$e->getCode(),
					$serialized,
					$row['syncQueueID']
				)
			);
			
			try {
				$sql = "INSERT INTO syncUploadProcessLog
						(userID, dataLength, syncQueueHostID, processDuration, totalDuration, error)
						VALUES (?,?,?,?,?,?)";
				Zotero_DB::query(
					$sql,
					array(
						$row['userID'],
						$row['dataLength'],
						$row['syncQueueHostID'],
						round((float) microtime(true) - $startedTimestamp, 2),
						min(time() - strtotime($row['added']), 65535),
						1
					)
				);
			}
			catch (Exception $e) {
				Z_Core::logError($e);
			}
		}
		
		// Clear read locks
		$sql = "DELETE FROM syncQueueLocks WHERE syncQueueID=?";
		Zotero_DB::query($sql, $row['syncQueueID']);
		
		Zotero_DB::commit();
		
		if ($lockError) {
			return -1;
		}
		else if ($error) {
			return -2;
		}
		
		return 1;
	}
	
	
	public static function checkUploadForErrors($syncProcessID) {
		Zotero_DB::beginTransaction();
		
		if (Z_Core::probability(30)) {
			$sql = "UPDATE syncQueue SET started=NULL WHERE started IS NOT NULL AND errorCheck=1 AND
						started < (NOW() - INTERVAL 12 MINUTE) AND finished IS NULL AND dataLength<250000";
			$row = Zotero_DB::rowQuery($sql);
		}
		
		// Get a queued process that hasn't been error-checked and is large enough to warrant it
		$sql = "SELECT * FROM syncQueue WHERE started IS NULL AND errorCheck=0
				AND dataLength>=" . self::$minErrorCheckSize . " ORDER BY added LIMIT 1 FOR UPDATE";
		$row = Zotero_DB::rowQuery($sql);
		
		// No pending processes
		if (!$row) {
			Zotero_DB::commit();
			return 0;
		}
		
		$sql = "UPDATE syncQueue SET started=NOW(), errorCheck=1 WHERE syncQueueID=?";
		Zotero_DB::query($sql, array($row['syncQueueID']));
		
		// We track error processes as upload processes that just get reset back to
		// started=NULL on completion (but with errorCheck=2)
		self::addUploadProcess($row['userID'], null, $row['syncQueueID'], $syncProcessID);
		
		Zotero_DB::commit();
		
		try {
			$doc = new DOMDocument();
			$doc->loadXML($row['xmldata']);
			
			// Get long tags
			$value = Zotero_Tags::getLongDataValueFromXML($doc);
			if ($value) {
				throw new Exception("Tag '" . $value . "' too long", Z_ERROR_TAG_TOO_LONG);
			}
			
			// Get long collection names
			$value = Zotero_Collections::getLongDataValueFromXML($doc);
			if ($value) {
				throw new Exception("Collection '" . $value . "' too long", Z_ERROR_COLLECTION_TOO_LONG);
			}
			
			// Get long creator names
			$node = Zotero_Creators::getLongDataValueFromXML($doc); // returns DOMNode rather than value
			if ($node) {
				if ($node->nodeName == 'firstName') {
					throw new Exception("=First name '" . mb_substr($node->nodeValue, 0, 50) . "…' too long");
				}
				if ($node->nodeName == 'lastName') {
					throw new Exception("=Last name '" . mb_substr($node->nodeValue, 0, 50) . "…' too long");
				}
				if ($node->nodeName == 'name') {
					throw new Exception("=Name '" . mb_substr($node->nodeValue, 0, 50) . "…' too long");
				}
			}
			
			$node = Zotero_Items::getLongDataValueFromXML($doc); // returns DOMNode rather than value
			if ($node) {
				$fieldName = $node->getAttribute('name');
				$fieldName = Zotero_ItemFields::getLocalizedString(null, $fieldName);
				if ($fieldName) {
					$start = "'$fieldName' field";
				}
				else {
					$start = "Field";
				}
				throw new Exception("=$start value '" . mb_substr($node->nodeValue, 0, 50) . "...' too long");
			}
		}
		catch (Exception $e) {
			Z_Core::logError($e);
			
			Zotero_DB::beginTransaction();
			
			$sql = "UPDATE syncQueue SET syncProcessID=NULL, finished=?, finishedMS=?,
						errorCode=?, errorMessage=? WHERE syncQueueID=?";
			Zotero_DB::query(
				$sql,
				array(
					Zotero_DB::getTransactionTimestamp(),
					Zotero_DB::getTransactionTimestampMS(),
					$e->getCode(),
					serialize($e),
					$row['syncQueueID']
				)
			);
			
			self::removeUploadProcess($syncProcessID);
			
			Zotero_DB::commit();
			
			return -2;
		}
		
		Zotero_DB::beginTransaction();
		
		$sql = "UPDATE syncQueue SET syncProcessID=NULL, started=NULL, errorCheck=2 WHERE syncQueueID=?";
		Zotero_DB::query($sql, $row['syncQueueID']);
		
		self::removeUploadProcess($syncProcessID);
		
		Zotero_DB::commit();
		
		return 1;
	}
	
	
	public static function getUploadQueueIDByUserID($userID) {
		$sql = "SELECT syncQueueID FROM syncQueue WHERE userID=?";
		return Zotero_DB::valueQuery($sql, $userID);
	}
	
	
	public static function postWriteLog($syncUploadQueueID, $objectType, $id, $action) {
		$sql = "INSERT IGNORE INTO syncUploadQueuePostWriteLog VALUES (?,?,?,?)";
		Zotero_DB::query($sql, array($syncUploadQueueID, $objectType, $id, $action));
	}
	
	
	public static function processPostWriteLog($syncUploadQueueID, $userID, $timestamp, $timestampMS) {
		// Increase timestamp by a second past the time of the queued process
		$timestamp++;
		
		$sql = "SELECT * FROM syncUploadQueuePostWriteLog WHERE syncUploadQueueID=?";
		$entries = Zotero_DB::query($sql, $syncUploadQueueID);
		foreach ($entries as $entry) {
			switch ($entry['objectType']) {
				case 'group':
					switch ($entry['action']) {
						case 'update':
							$sql = "UPDATE groups SET dateModified=FROM_UNIXTIME(?) WHERE groupID=?";
							$affected = Zotero_DB::query($sql, array($timestamp, $entry['ids']));
							break;
						
						case 'delete':
							$sql = "UPDATE syncDeleteLogIDs SET timestamp=FROM_UNIXTIME(?), timestampMS=?
									WHERE libraryID=? AND objectType='group' AND id=?";
							$userLibraryID = Zotero_Users::getLibraryIDFromUserID($userID);
							$affected = Zotero_DB::query($sql, array($timestamp, $timestampMS, $userLibraryID, $groupID));
							break;
						
						default:
							throw new Exception("Unsupported action {$entry['action']} for type {$entry['objectType']}");
					}
					break;
				
				case 'groupUser':
					// If the affected user isn't the queued user, this isn't necessary
					list ($groupID, $groupUserID) = explode('-', $entry['ids']);
					if ($userID != $groupUserID) {
						throw new Exception("Upload user is not logged user");
					}
					
					switch ($entry['action']) {
						case 'update':
							$sql = "UPDATE groupUsers SET lastModified=FROM_UNIXTIME(?) WHERE groupID=? AND userID=?";
							$affected = Zotero_DB::query($sql, array($timestamp, $groupID, $userID));
							break;
						
						case 'delete':
							$userLibraryID = Zotero_Users::getLibraryIDFromUserID($userID);
							$sql = "UPDATE syncDeleteLogIDs SET timestamp=FROM_UNIXTIME(?), timestampMS=?
									WHERE libraryID=? AND objectType='group' AND id=?";
							$affected = Zotero_DB::query($sql, array($timestamp, $timestampMS, $userLibraryID, $groupID));
							break;
						
						default:
							throw new Exception("Unsupported action {$entry['action']} for type {$entry['objectType']}");
					}
					break;
				
				default:
					throw new Exception ("Unknown object type {$entry['objectType']}");
			}
			
			if ($affected == 0) {
				Z_Core::logError(
					"Post-queue write "
						. "{$entry['syncUploadQueueID']}/"
						. "{$entry['objectType']}/"
						. "{$entry['ids']}/"
						. "{$entry['action']}"
						. "didn't change any rows"
				);
			}
		}
	}
	
	
	/**
	 * Let the processor daemon know there's a queued process, etc.
	 */
	public static function notifyDownloadProcessor($signal="NEXT") {
		$addr = Z_CONFIG::$SYNC_PROCESSOR_BIND_ADDRESS;
		$port = Z_CONFIG::$SYNC_PROCESSOR_PORT_DOWNLOAD;
		self::notifyProcessor('download', $addr, $port, $signal);
	}
	
	
	/**
	 * Let the processor daemon know there's a queued process, etc.
	 */
	public static function notifyUploadProcessor($signal="NEXT") {
		$addr = Z_CONFIG::$SYNC_PROCESSOR_BIND_ADDRESS;
		$port = Z_CONFIG::$SYNC_PROCESSOR_PORT_UPLOAD;
		self::notifyProcessor('upload', $addr, $port, $signal);
	}
	
	
	/**
	 * Pass a message to the processor daemon
	 */
	public static function notifyErrorProcessor($signal="NEXT") {
		$addr = Z_CONFIG::$SYNC_PROCESSOR_BIND_ADDRESS;
		$port = Z_CONFIG::$SYNC_PROCESSOR_PORT_ERROR;
		self::notifyProcessor('error', $addr, $port, $signal);
	}
	
	
	public static function countQueuedDownloadProcesses() {
		$sql = "SELECT COUNT(*) FROM syncDownloadQueue WHERE started IS NULL";
		return Zotero_DB::valueQuery($sql);
	}
	
	
	public static function countQueuedUploadProcesses($errorCheck=false) {
		$sql = "SELECT COUNT(*) FROM syncQueue WHERE started IS NULL";
		// errorCheck=0 indicates that the upload has not been checked for errors
		if ($errorCheck) {
			$sql .= " AND errorCheck=0 AND dataLength>5000";
		}
		return Zotero_DB::valueQuery($sql);
	}
	
	
	public static function getOldDownloadProcesses($host=null, $seconds=60) {
		$sql = "SELECT syncDownloadProcessID FROM syncDownloadQueue
				LEFT JOIN syncQueueHosts USING (syncQueueHostID)
				WHERE started < NOW() - INTERVAL ? SECOND";
		$params = array($seconds);
		if ($host) {
			$sql .= " AND hostname=?";
			$params[] = $host;
		}
		return Zotero_DB::columnQuery($sql, $params);
	}
	
	
	public static function getOldUploadProcesses($host, $seconds=60) {
		$sql = "SELECT syncProcessID FROM syncQueue
				LEFT JOIN syncQueueHosts USING (syncQueueHostID)
				WHERE started < NOW() - INTERVAL ? SECOND AND errorCheck!=1";
		$params = array($seconds);
		if ($host) {
			$sql .= " AND hostname=?";
			$params[] = $host;
		}
		return Zotero_DB::columnQuery($sql, $params);
	}
	
	
	public static function getOldErrorProcesses($host, $seconds=60) {
		$sql = "SELECT syncProcessID FROM syncQueue
				LEFT JOIN syncQueueHosts USING (syncQueueHostID)
				WHERE started < NOW() - INTERVAL ? SECOND AND errorCheck=1";
		$params = array($seconds);
		if ($host) {
			$sql .= " AND hostname=?";
			$params[] = $host;
		}
		return Zotero_DB::columnQuery($sql, $params);
	}
	
	
	/**
	 * Remove process id from process in database
	 */
	public static function removeDownloadProcess($syncDownloadProcessID) {
		$sql = "UPDATE syncDownloadQueue SET syncDownloadProcessID=NULL
				WHERE syncDownloadProcessID=?";
		Zotero_DB::query($sql, $syncDownloadProcessID);
	}
	
	
	/**
	 * Remove upload process and locks from database
	 */
	public static function removeUploadProcess($syncProcessID) {
		$sql = "DELETE FROM syncProcesses WHERE syncProcessID=?";
		Zotero_DB::query($sql, $syncProcessID);
	}
	
	
	/**
	 * Remove process id from process in database
	 */
	public static function removeErrorProcess($syncErrorProcessID) {
		$sql = "UPDATE syncQueue SET syncProcessID=NULL, errorCheck=0 WHERE syncProcessID=?";
		Zotero_DB::query($sql, $syncErrorProcessID);
	}
	
	
	/**
	 * Get the result of a queued download process for a given sync session
	 *
	 * If still queued, return false
	 * If success, return "<data ..."
	 * If error, return array('timestamp' => "123456789.1234", 'exception' => Exception)
	 * If not queued, return -1
	 */
	public static function getSessionDownloadResult($sessionID) {
		Zotero_DB::beginTransaction();
		$sql = "SELECT CONCAT(UNIX_TIMESTAMP(finished), '.', finishedMS) AS finished,
				xmldata, errorCode, errorMessage FROM syncDownloadQueue WHERE sessionID=?";
		$row = Zotero_DB::rowQuery($sql, $sessionID);
		if (!$row) {
			Zotero_DB::commit();
			return -1;
		}
		
		if (is_null($row['finished'])) {
			Zotero_DB::commit();
			return false;
		}
		
		$sql = "DELETE FROM syncDownloadQueue WHERE sessionID=?";
		Zotero_DB::query($sql, $sessionID);
		Zotero_DB::commit();
		
		// Success
		if (is_null($row['errorCode'])) {
			return $row['xmldata'];
		}
		
		$e = @unserialize($row['errorMessage']);
		
		// In case it's not a valid exception for some reason, make one
		if (!($e instanceof Exception)) {
			$e = new Exception($row['errorMessage'], $row['errorCode']);
		}
		
		throw ($e);
	}
	
	
	/**
	 * Get the result of a queued process for a given sync session
	 *
	 * If no result, return false
	 * If success, return array('timestamp' => "123456789.1234")
	 * If error, return array('xmldata' => "<data ...", 'exception' => Exception)
	 */
	public static function getSessionUploadResult($sessionID) {
		Zotero_DB::beginTransaction();
		$sql = "SELECT CONCAT(UNIX_TIMESTAMP(finished), '.', finishedMS) AS finished,
				xmldata, errorCode, errorMessage FROM syncQueue WHERE sessionID=?";
		$row = Zotero_DB::rowQuery($sql, $sessionID);
		if (!$row) {
			Zotero_DB::commit();
			throw new Exception("Queued upload not found for session");
		}
		
		if (is_null($row['finished'])) {
			Zotero_DB::beginTransaction();
			return false;
		}
		
		$sql = "DELETE FROM syncQueue WHERE sessionID=?";
		Zotero_DB::query($sql, $sessionID);
		Zotero_DB::commit();
		
		// Success
		if (is_null($row['errorCode'])) {
			return array('timestamp' => $row['finished']);
		}
		
		$e = @unserialize($row['errorMessage']);
		
		// In case it's not a valid exception for some reason, make one
		//
		// TODO: This can probably be removed after the transition
		if (!($e instanceof Exception)) {
			$e = new Exception($row['errorMessage'], $row['errorCode']);
		}
		
		return array('timestamp' => $row['finished'], 'xmldata' => $row['xmldata'], 'exception' => $e);
	}
	
	
	//
	//
	// Private methods
	//
	//
	private static function processDownloadInternal($userID, $lastsync, DOMDocument $doc, $syncDownloadQueueID=null, $syncDownloadProcessID=null, $skipValidation=false) {
		set_time_limit(900);
		
		if ($syncDownloadQueueID) {
			self::addDownloadProcess($syncDownloadQueueID, $syncDownloadProcessID);
		}
		
		$updatedNode = $doc->createElement('updated');
		$doc->documentElement->appendChild($updatedNode);
		
		$apiVersion = (int) $doc->documentElement->getAttribute('version');
		$userLibraryID = Zotero_Users::getLibraryIDFromUserID($userID);
		
		$updatedCreators = array();
		
		try {
			Zotero_DB::beginTransaction();
			// Use the transaction timestamp
			$timestamp = Zotero_DB::getTransactionTimestampUnix() . '.' . Zotero_DB::getTransactionTimestampMS();
			$doc->documentElement->setAttribute('timestamp', $timestamp);
			
			$doc->documentElement->setAttribute('userID', $userID);
			$doc->documentElement->setAttribute('defaultLibraryID', $userLibraryID);
			$doc->documentElement->setAttribute('updateKey', self::getUpdateKey($userID));
			
			foreach (Zotero_DataObjects::$objectTypes as $syncObject) {
				$Name = $syncObject['singular']; // 'Item'
				$Names = $syncObject['plural']; // 'Items'
				$name = strtolower($Name); // 'item'
				$names = strtolower($Names); // 'items'
				
				$className = 'Zotero_' . $Names;
				
				$updatedIDs = call_user_func(array($className, 'getUpdated'), $userLibraryID, $lastsync, true);
				if ($updatedIDs) {
					// Pre-cache item pull
					if ($name == 'item') {
						Zotero_Items::get($updatedIDs);
						Zotero_Notes::cacheNotes($updatedIDs);
					}
					
					if ($name == 'creator') {
						$updatedCreators = $updatedIDs;
					}
					
					$node = $doc->createElement($names);
					$updatedNode->appendChild($node);
					foreach ($updatedIDs as $id) {
						if ($name == 'item') {
							$obj = call_user_func(array($className, 'get'), $id);
							$data = array('updatedCreators' => $updatedCreators);
							$xmlElement = Zotero_Items::convertItemToXML($obj, $data, $apiVersion);
						}
						else {
							$instanceClass = 'Zotero_' . $Name;
							$obj = new $instanceClass;
							if (method_exists($instanceClass, '__construct')) {
								$obj->__construct();
							}
							$obj->id = $id;
							if ($name == 'tag') {
								$xmlElement = call_user_func(array($className, "convert{$Name}ToXML"), $obj, true);
							}
							else {
								$xmlElement = call_user_func(array($className, "convert{$Name}ToXML"), $obj);
							}
						}
						
						if ($xmlElement['libraryID'] == $userLibraryID) {
							unset($xmlElement['libraryID']);
						}
						
						$newNode = dom_import_simplexml($xmlElement);
						$newNode = $doc->importNode($newNode, true);
						$node->appendChild($newNode);
					}
				}
			}
			
			
			// Add new groups
			$updatedIDs = array_unique(array_merge(
				Zotero_Groups::getJoined($userID, (int) $lastsync),
				Zotero_Groups::getUpdated($userID, (int) $lastsync)
			));
			if ($updatedIDs) {
				$node = $doc->createElement('groups');
				$showGroups = false;
				
				foreach ($updatedIDs as $id) {
					$group = new Zotero_Group;
					$group->id = $id;
					if (!$group->libraryEnabled) {
						continue;
					}
					$xmlElement = $group->toXML($userID);
					
					$newNode = dom_import_simplexml($xmlElement);
					$newNode = $doc->importNode($newNode, true);
					$node->appendChild($newNode);
					$showGroups = true;
				}
				
				if ($showGroups) {
					$updatedNode->appendChild($node);
				}
			}
			
			// Get earliest timestamp
			$earliestModTime = Zotero_Users::getEarliestDataTimestamp($userID);
			$doc->documentElement->setAttribute('earliest', $earliestModTime ? $earliestModTime : 0);
			
			// Deleted objects
			$deletedKeys = self::getDeletedObjectKeys($userLibraryID, $lastsync, true);
			$deletedIDs = self::getDeletedObjectIDs($userLibraryID, $lastsync, true);
			if ($deletedKeys || $deletedIDs) {
				$deletedNode = $doc->createElement('deleted');
				if ($deletedKeys) {
					foreach (Zotero_DataObjects::$objectTypes as $syncObject) {
						$Name = $syncObject['singular']; // 'Item'
						$Names = $syncObject['plural']; // 'Items'
						$name = strtolower($Name); // 'item'
						$names = strtolower($Names); // 'items'
						
						if (empty($deletedKeys[$names])) {
							continue;
						}
						
						$typeNode = $doc->createElement($names);
						
						foreach ($deletedKeys[$names] as $row) {
							$node = $doc->createElement($name);
							if ($row['libraryID'] != $userLibraryID) {
								$node->setAttribute('libraryID', $row['libraryID']);
							}
							$node->setAttribute('key', $row['key']);
							$typeNode->appendChild($node);
						}
						$deletedNode->appendChild($typeNode);
					}
				}
				
				if ($deletedIDs) {
					// Add deleted groups
					$name = "group";
					$names = "groups";
					
					$typeNode = $doc->createElement($names);
					$ids = $doc->createTextNode(implode(' ', $deletedIDs[$names]));
					$typeNode->appendChild($ids);
					$deletedNode->appendChild($typeNode);
				}
				
				$updatedNode->appendChild($deletedNode);
			}
			
			Zotero_DB::commit();
		}
		catch (Exception $e) {
			Zotero_DB::rollback();
			throw ($e);
		}
		
		if (!$skipValidation) {
			function relaxNGErrorHandler($errno, $errstr) {
				Zotero_Sync::$validationError = $errstr;
			}
			set_error_handler('relaxNGErrorHandler');
			$valid = $doc->relaxNGValidate(Z_ENV_MODEL_PATH . 'relax-ng/updated.rng');
			restore_error_handler();
			if (!$valid) {
				if ($syncDownloadQueueID) {
					self::removeDownloadProcess($syncDownloadProcessID);
				}
				throw new Exception(self::$validationError . "\n\nXML:\n\n" .  $doc->saveXML());
			}
		}
		
		if ($syncDownloadQueueID) {
			self::removeDownloadProcess($syncDownloadProcessID);
		}
	}
	
	
	private static function processUploadInternal($userID, SimpleXMLElement $xml, $syncQueueID=null, $syncProcessID=null) {
		$userLibraryID = Zotero_Users::getLibraryIDFromUserID($userID);
		$affectedLibraries = self::parseAffectedLibraries($xml);
		// Relations-only uploads don't have affected libraries
		if (!$affectedLibraries) {
			$affectedLibraries = array(Zotero_Users::getLibraryIDFromUserID($userID));
		}
		$processID = self::addUploadProcess($userID, $affectedLibraries, $syncQueueID, $syncProcessID);
		
		set_time_limit(1800);
		
		$profile = false;
		if ($profile) {
			$profiler = Zotero_DB::getProfiler();
			$profiler->setEnabled(true);
		}
		
		// Add tag values
		if ($xml->tags) {
			$domSXE = dom_import_simplexml($xml->tags);
			$doc = new DOMDocument();
			$domSXE = $doc->importNode($domSXE, true);
			$domSXE = $doc->appendChild($domSXE);
			$values = Zotero_Tags::getDataValuesFromXML($doc);
			try {
				Zotero_Tags::bulkInsertDataValues($values);
			}
			catch (Exception $e) {
				self::removeUploadProcess($processID);
				throw $e;
			}
		}
		
		// Add creator values
		if ($xml->creators) {
			$domSXE = dom_import_simplexml($xml->creators);
			$doc = new DOMDocument();
			$domSXE = $doc->importNode($domSXE, true);
			$domSXE = $doc->appendChild($domSXE);
			$valueObjs = Zotero_Creators::getDataValuesFromXML($doc);
			try {
				Zotero_Creators::bulkInsertDataValues($valueObjs);
			}
			catch (Exception $e) {
				self::removeUploadProcess($processID);
				throw $e;
			}
		}
		
		// Add item values
		if ($xml->items) {
			$domSXE = dom_import_simplexml($xml->items);
			$doc = new DOMDocument();
			$domSXE = $doc->importNode($domSXE, true);
			$domSXE = $doc->appendChild($domSXE);
			try {
				$node = Zotero_Items::getLongDataValueFromXML($doc);
				if ($node) {
					$fieldName = $node->getAttribute('name');
					$fieldName = Zotero_ItemFields::getLocalizedString(null, $fieldName);
					if ($fieldName) {
						$start = "'$fieldName' field";
					}
					else {
						$start = "Field";
					}
					throw new Exception("=$start value '" . mb_substr($node->nodeValue, 0, 50) . "...' too long");
				}
				
				$values = Zotero_Items::getDataValuesFromXML($doc);
				Zotero_Items::bulkInsertDataValues($values);
			}
			catch (Exception $e) {
				self::removeUploadProcess($processID);
				throw $e;
			}
		}
		
		try {
			Z_Core::$MC->begin();
			Zotero_DB::beginTransaction();
			
			// Serialize upload transactions
			//if ($xml->creators) {
				//Zotero_DB::query("UPDATE uploadLock SET semaphore=semaphore+1");
			//}
			
			// Add/update creators
			if ($xml->creators) {
				// DOM
				$keys = array();
				$xmlElements = dom_import_simplexml($xml->creators);
				$xmlElements = $xmlElements->getElementsByTagName('creator');
				Zotero_DB::query("SET foreign_key_checks = 0");
				$addedLibraryIDs = array();
				$addedCreatorDataIDs = array();
				foreach ($xmlElements as $xmlElement) {
					$key = $xmlElement->getAttribute('key');
					if (isset($keys[$key])) {
						throw new Exception("Creator $key already processed");
					}
					$keys[$key] = true;
					
					$creatorObj = Zotero_Creators::convertXMLToCreator($xmlElement);
					$creatorObj->save();
					$addedLibraryIDs[] = $creatorObj->libraryID;
					$addedCreatorDataIDs[] = $creatorObj->creatorDataID;
				}
				Zotero_DB::query("SET foreign_key_checks = 1");
				unset($keys);
				unset($xml->creators);
				
				//
				// Manual foreign keys checks
				//
				// libraryID
				$sql = "CREATE TEMPORARY TABLE tmpCreatorFKCheck (
							libraryID INT UNSIGNED NOT NULL, UNIQUE KEY (libraryID)
						)";
				Zotero_DB::query($sql);
				Zotero_DB::bulkInsert("INSERT IGNORE INTO tmpCreatorFKCheck VALUES ", $addedLibraryIDs, 100);
				$added = Zotero_DB::valueQuery("SELECT COUNT(*) FROM tmpCreatorFKCheck");
				$count = Zotero_DB::valueQuery("SELECT COUNT(*) FROM tmpCreatorFKCheck JOIN libraries USING (libraryID)");
				if ($count != $added) {
					$sql = "SELECT FK.libraryID FROM tmpCreatorFKCheck FK
							LEFT JOIN libraries L USING (libraryID)
							WHERE FK.libraryID IS NULL";
					$missing = Zotero_DB::columnQuery($sql);
					throw new Exception("libraryIDs inserted into `creators` not found in `libraries` (" . implode(",", $missing) . ")");
				}
				Zotero_DB::query("DROP TEMPORARY TABLE tmpCreatorFKCheck");
				
				// creatorDataID
				$sql = "CREATE TEMPORARY TABLE tmpCreatorFKCheck (
							creatorDataID INT UNSIGNED NOT NULL, UNIQUE KEY (creatorDataID)
						)";
				Zotero_DB::query($sql);
				Zotero_DB::bulkInsert("INSERT IGNORE INTO tmpCreatorFKCheck VALUES ", $addedCreatorDataIDs, 100);
				$added = Zotero_DB::valueQuery("SELECT COUNT(*) FROM tmpCreatorFKCheck");
				$count = Zotero_DB::valueQuery("SELECT COUNT(*) FROM tmpCreatorFKCheck JOIN creatorData USING (creatorDataID)");
				if ($count != $added) {
					$sql = "SELECT FK.creatorDataID FROM tmpCreatorFKCheck FK
							LEFT JOIN creatorData CD USING (creatorDataID)
							WHERE CD.creatorDataID IS NULL";
					$missing = Zotero_DB::columnQuery($sql);
					throw new Exception("creatorDataIDs inserted into `creators` not found in `creatorData` (" . implode(",", $missing) . ")  $added $count");
				}
				Zotero_DB::query("DROP TEMPORARY TABLE tmpCreatorFKCheck");
				
				// SimpleXML
				/*$keys = array();
				foreach ($xml->creators->creator as $xmlElement) {
					$key = (string) $xmlElement['key'];
					if (isset($keys[$key])) {
						throw new Exception("Search $key already processed");
					}
					$keys[$key] = true;
					
					$creatorObj = Zotero_Creators::convertXMLToCreator($xmlElement);
					$creatorObj->save();
				}
				unset($keys);
				unset($xml->creators);*/
			}
			
			// Add/update items
			if ($xml->items) {
				$childItems = array();
				$relatedItemsStore = array();
				
				// DOM
				$keys = array();
				$xmlElements = dom_import_simplexml($xml->items);
				$xmlElements = $xmlElements->getElementsByTagName('item');
				foreach ($xmlElements as $xmlElement) {
					$key = $xmlElement->getAttribute('key');
					if (isset($keys[$key])) {
						throw new Exception("Item $key already processed");
					}
					$keys[$key] = true;
					
					$missing = Zotero_Items::removeMissingRelatedItems($xmlElement);
					$itemObj = Zotero_Items::convertXMLToItem($xmlElement);
					
					if ($missing) {
						$relatedItemsStore[$itemObj->libraryID . '_' . $itemObj->key] = $missing;
					}
					
					if (!$itemObj->getSourceKey()) {
						try {
							$itemObj->save($userID);
						}
						catch (Exception $e) {
							if (strpos($e->getMessage(), 'libraryIDs_do_not_match') !== false) {
								throw new Exception($e->getMessage() . " (" . $itemObj->key . ")");
							}
							throw ($e);
						}
					}
					else {
						$childItems[] = $itemObj;
					}
				}
				unset($keys);
				unset($xml->items);
				
				// SimpleXML
				/*// Work around loop pointer reset bug in PHP 5.3RC2
				$elements = array();
				foreach ($xml->items->item as $xmlElement) {
					$elements[] = $xmlElement;
				}
				
				$size1 = sizeOf($xml->items->item);
				$size2 = sizeOf($elements);
				if ($size1 != $size2) {
					throw new Exception("Item array sizes differ ($size1 != $size2)", Z_ERROR_ARRAY_SIZE_MISMATCH);
				}
				
				$keys = array();
				while ($xmlElement = array_shift($elements)) {
					$key = (string) $xmlElement['key'];
					if (isset($keys[$key])) {
						throw new Exception("Item $key already processed");
					}
					$keys[$key] = true;
					
					$missing = Zotero_Items::removeMissingRelatedItems($xmlElement);
					$itemObj = Zotero_Items::convertXMLToItem($xmlElement);
					unset($xmlElement);
					
					if ($missing) {
						$relatedItemsStore[$itemObj->libraryID . '_' . $itemObj->key] = $missing;
					}
					
					if (!$itemObj->getSourceKey()) {
						try {
							$itemObj->save($userID);
						}
						catch (Exception $e) {
							if (strpos($e->getMessage(), 'libraryIDs_do_not_match') !== false) {
								throw new Exception($e->getMessage() . " (" . $itemObj->key . ")");
							}
							throw ($e);
						}
					}
					else {
						$childItems[] = $itemObj;
					}
				}
				unset($keys);
				unset($xml->items);*/
				
				while ($childItem = array_shift($childItems)) {
					$childItem->save($userID);
				}
				
				// Add back related items (which now exist)
				foreach ($relatedItemsStore as $itemLibraryKey=>$relset) {
					$lk = explode('_', $itemLibraryKey);
					$libraryID = $lk[0];
					$key = $lk[1];
					$item = Zotero_Items::getByLibraryAndKey($libraryID, $key);
					foreach ($relset as $relKey) {
						$relItem = Zotero_Items::getByLibraryAndKey($libraryID, $relKey);
						$item->addRelatedItem($relItem->id);
					}
					$item->save();
				}
				unset($relatedItemsStore);
			}
			
			// Add/update collections
			if ($xml->collections) {
				$collections = array();
				$collectionSets = array();
				
				// DOM
				// Build an array of unsaved collection objects and the keys of child items
				$keys = array();
				$xmlElements = dom_import_simplexml($xml->collections);
				$xmlElements = $xmlElements->getElementsByTagName('collection');
				foreach ($xmlElements as $xmlElement) {
					$key = $xmlElement->getAttribute('key');
					if (isset($keys[$key])) {
						throw new Exception("Collection $key already processed");
					}
					$keys[$key] = true;
					
					$collectionObj = Zotero_Collections::convertXMLToCollection($xmlElement);
					
					$xmlItems = $xmlElement->getElementsByTagName('items')->item(0);
					
					// Fix an error if there's leading or trailing whitespace,
					// which was possible in 2.0.3
					$xmlItems = trim($xmlItems->nodeValue);
					
					$arr = array(
						'obj' => $collectionObj,
						'items' => $xmlItems ? explode(' ', $xmlItems) : array()
					);
					$collections[] = $collectionObj;
					$collectionSets[] = $arr;
				}
				unset($keys);
				unset($xml->collections);
				
				// SimpleXML
				/*$elements = array();
				foreach ($xml->collections->collection as $xmlElement) {
					$elements[] = $xmlElement;
				}
				
				$size1 = sizeOf($xml->collections->collection);
				$size2 = sizeOf($elements);
				if ($size1 != $size2) {
					throw new Exception("Collection array sizes differ ($size1 != $size2)", Z_ERROR_ARRAY_SIZE_MISMATCH);
				}
				
				// Build an array of unsaved collection objects and the keys of child items
				$keys = array();
				while ($xmlElement = array_shift($elements)) {
					$key = (string) $xmlElement['key'];
					if (isset($keys[$key])) {
						throw new Exception("Collection $key already processed");
					}
					$keys[$key] = true;
					
					$collectionObj = Zotero_Collections::convertXMLToCollection($xmlElement);
					
					$arr = array(
						'obj' => $collectionObj,
						//'collections' => $xmlElement->collections ?
						//	explode(' ', $xmlElement->collections) : array(),
						'items' => $xmlElement->items ?
							explode(' ', $xmlElement->items) : array()
					);
					unset($xmlElement);
					$collections[] = $collectionObj;
					$collectionSets[] = $arr;
				}
				unset($keys);
				unset($xml->collections);
				*/
				
				self::saveCollections($collections);
				unset($collections);
				
				// Set child items
				foreach ($collectionSets as $collection) {
					// Child items
					if (isset($collection['items'])) {
						$ids = array();
						foreach ($collection['items'] as $key) {
							$item = Zotero_Items::getByLibraryAndKey($collection['obj']->libraryID, $key);
							if (!$item) {
								throw new Exception("Child item '$key' of collection {$collection['obj']->id} not found", Z_ERROR_ITEM_NOT_FOUND);
							}
							$ids[] = $item->id;
						}
						$collection['obj']->setChildItems($ids);
					}
				}
				unset($collectionSets);
			}
			
			// Add/update saved searches
			if ($xml->searches) {
				$searches = array();
				$keys = array();
				
				foreach ($xml->searches->search as $xmlElement) {
					$key = (string) $xmlElement['key'];
					if (isset($keys[$key])) {
						throw new Exception("Search $key already processed");
					}
					$keys[$key] = true;
					
					$searchObj = Zotero_Searches::convertXMLToSearch($xmlElement);
					$searchObj->save();
				}
				unset($xml->searches);
			}
			
			// Add/update tags
			if ($xml->tags) {
				$keys = array();
				
				// DOM
				$xmlElements = dom_import_simplexml($xml->tags);
				$xmlElements = $xmlElements->getElementsByTagName('tag');
				foreach ($xmlElements as $xmlElement) {
					$key = $xmlElement->getAttribute('key');
					if (isset($keys[$key])) {
						throw new Exception("Tag $key already processed");
					}
					$keys[$key] = true;
					
					$tagObj = Zotero_Tags::convertXMLToTag($xmlElement);
					$tagObj->save(true);
				}
				unset($keys);
				unset($xml->tags);
				
				// SimpleXML
				/*// Work around loop pointer reset bug in PHP 5.3RC2
				$elements = array();
				foreach ($xml->tags->tag as $xmlElement) {
					$elements[] = $xmlElement;
				}
				
				$size1 = sizeOf($xml->tags->tag);
				$size2 = sizeOf($elements);
				if ($size1 != $size2) {
					throw new Exception("Tag array sizes differ ($size1 != $size2)", Z_ERROR_ARRAY_SIZE_MISMATCH);
				}
				
				$keys = array();
				while ($xmlElement = array_shift($elements)) {
					$key = (string) $xmlElement['key'];
					if (isset($keys[$key])) {
						throw new Exception("Tag $key already processed");
					}
					$keys[$key] = true;
					
					$tagObj = Zotero_Tags::convertXMLToTag($xmlElement);
					unset($xmlElement);
					$tagObj->save(true);
				}
				unset ($keys);
				unset($xml->tags);*/
			}
			
			// Add/update relations
			if ($xml->relations) {
				// DOM
				$xmlElements = dom_import_simplexml($xml->relations);
				$xmlElements = $xmlElements->getElementsByTagName('relation');
				foreach ($xmlElements as $xmlElement) {
					$relationObj = Zotero_Relations::convertXMLToRelation($xmlElement, $userLibraryID);
					if ($relationObj->exists()) {
						continue;
					}
					$relationObj->save();
				}
				unset($keys);
				unset($xml->relations);
				
				// SimpleXML
				/*$keys = array();
				
				foreach ($xml->relations->relation as $xmlElement) {
					$key = (string) $xmlElement['key'];
					if (isset($keys[$key])) {
						throw new Exception("Relation $key already processed");
					}
					$keys[$key] = true;
					
					$relationObj = Zotero_Relations::convertXMLToRelation($xmlElement, $userLibraryID);
					if ($relationObj->exists()) {
						continue;
					}
					$relationObj->save();
				}
				unset($xml->relations);*/
			}
			
			// TODO: loop
			if ($xml->deleted) {
				// Delete collections
				if ($xml->deleted->collections) {
					Zotero_Collections::deleteFromXML($xml->deleted->collections);
				}
				
				// Delete items
				if ($xml->deleted->items) {
					Zotero_Items::deleteFromXML($xml->deleted->items);
				}
				
				// Delete creators
				if ($xml->deleted->creators) {
					Zotero_Creators::deleteFromXML($xml->deleted->creators);
				}
				
				// Delete saved searches
				if ($xml->deleted->searches) {
					Zotero_Searches::deleteFromXML($xml->deleted->searches);
				}
				
				// Delete tags
				if ($xml->deleted->tags) {
					Zotero_Tags::deleteFromXML($xml->deleted->tags);
				}
			}
			
			$timestampSQL = Zotero_DB::getTransactionTimestamp();
			$timestampUnix = Zotero_DB::getTransactionTimestampUnix();
			$timestampMS = Zotero_DB::getTransactionTimestampMS();
			
			// Update timestamps on affected libraries
			$sql = "UPDATE libraries SET lastUpdated=?, lastUpdatedMS=? WHERE libraryID IN ("
					. implode(',', array_fill(0, sizeOf($affectedLibraries), '?')) . ")";
			Zotero_DB::query(
				$sql,
				array_merge(array($timestampSQL, $timestampMS), $affectedLibraries)
			);
			
			self::removeUploadProcess($processID);
			
			Zotero_DB::commit();
			Z_Core::$MC->commit();
			//Zotero_DB::rollback();
			//Z_Core::$MC->rollback();
			//throw new Exception("Abort in $timestampUnix");
			
			// Profiling code
			if ($profile) {
				$totalTime    = $profiler->getTotalElapsedSecs();
				$queryCount   = $profiler->getTotalNumQueries();
				$longestTime  = 0;
				$longestQuery = null;
				
				ob_start();
				
				$queries = array();
				
				foreach ($profiler->getQueryProfiles() as $query) {
					$sql = str_replace("\t", "", str_replace("\n", "", $query->getQuery()));
					$hash = md5($sql);
					if (isset($queries[$hash])) {
						$queries[$hash]['count']++;
						$queries[$hash]['time'] += $query->getElapsedSecs();
					}
					else {
						$queries[$hash]['sql'] = $sql;
						$queries[$hash]['count'] = 1;
						$queries[$hash]['time'] = $query->getElapsedSecs();
					}
					if ($query->getElapsedSecs() > $longestTime) {
						$longestTime  = $query->getElapsedSecs();
						$longestQuery = $query->getQuery();
					}
				}
				
				foreach($queries as &$query) {
					//$query['avg'] = $query['time'] / $query['count'];
				}
				
				function cmp($a, $b) {
					if ($a['time'] == $b['time']) {
						return 0;
					}
					return ($a['time'] < $b['time']) ? -1 : 1;
				}
				usort($queries, "cmp");
				
				var_dump($queries);
				
				echo 'Executed ' . $queryCount . ' queries in ' . $totalTime . ' seconds' . "\n";
				echo 'Average query length: ' . $totalTime / $queryCount . ' seconds' . "\n";
				echo 'Queries per second: ' . $queryCount / $totalTime . "\n";
				echo 'Longest query length: ' . $longestTime . "\n";
				echo "Longest query: \n" . $longestQuery . "\n";
				
				$temp = ob_get_clean();
				file_put_contents("/tmp/profile_" . $syncProcessID, $temp);
				
				$profiler->setEnabled(false);
			}
			
			return $timestampUnix . '.' . $timestampMS;
		}
		catch (Exception $e) {
			Zotero_DB::rollback();
			Z_Core::$MC->rollback();
			self::removeUploadProcess($processID);
			throw $e;
		}
	}
	
	
	/**
	 * Recursively save collections from the top down
	 */
	private static function saveCollections($collections) {
		$originalLength = sizeOf($collections);
		$unsaved = array();
		
		$toSave = array();
		for ($i=0, $len=sizeOf($collections); $i<$len; $i++) {
			$toSave[$collections[$i]->key] = true;
		}
		
		for ($i=0; $i<sizeOf($collections); $i++) {
			$collection = $collections[$i];
			$key = $collection->key;
			
			$parentKey = $collection->parentKey;
			// Top-level collection, so save
			if (!$parentKey) {
				$collection->save();
				unset($toSave[$key]);
				continue;
			}
			$parentCollection = Zotero_Collections::getByLibraryAndKey($collection->libraryID, $parentKey);
			// Parent collection exists and doesn't need to be saved, so save
			if ($parentCollection && empty($toSave[$parentCollection->key])) {
				$collection->save();
				unset($toSave[$key]);
				continue;
			}
			// Add to unsaved list
			$unsaved[] = $collection;
			continue;
		}
		
		if ($unsaved) {
			if ($originalLength == sizeOf($unsaved)) {
				throw new Exception("Incomplete collection hierarchy cannot be saved", Z_ERROR_COLLECTION_NOT_FOUND);
			}
			
			self::saveCollections($unsaved);
		}
	}
	
	
	private static function notifyProcessor($processor, $addr, $port, $signal) {
		switch ($processor) {
			case 'download':
			case 'upload':
			case 'error':
				break;
			
			default:
				throw new Exception("Invalid processor '$processor'");
		}
		
		$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		// Enable broadcast
		if (preg_match('/\.255$/', $addr)) {
			socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
		}
		$success = socket_sendto($socket, $signal, strlen($signal), MSG_EOF, $addr, $port);
		if (!$success) {
			$code = socket_last_error($socket);
			throw new Exception(socket_strerror($code));
		}
	}
	
	
	private static function addDownloadProcess($syncDownloadQueueID, $syncDownloadProcessID) {
		$sql = "UPDATE syncDownloadQueue SET syncDownloadProcessID=? WHERE syncDownloadQueueID=?";
		Zotero_DB::query($sql, array($syncDownloadProcessID, $syncDownloadQueueID));
	}
	
	/**
	 * Add sync process and associated locks to database
	 */
	private static function addUploadProcess($userID, $libraryIDs, $syncQueueID=null, $syncProcessID=null) {
		Zotero_DB::beginTransaction();
		
		$syncProcessID = $syncProcessID ? $syncProcessID : Zotero_ID::getBigInt();
		$sql = "INSERT INTO syncProcesses (syncProcessID, userID) VALUES (?, ?)";
		Zotero_DB::query($sql, array($syncProcessID, $userID));
		
		if ($libraryIDs) {
			$sql = "INSERT INTO syncProcessLocks VALUES ";
			$sql .= implode(', ', array_fill(0, sizeOf($libraryIDs), '(?,?)'));
			$params = array();
			foreach ($libraryIDs as $libraryID) {
				$params[] = $syncProcessID;
				$params[] = $libraryID;
			}
			Zotero_DB::query($sql, $params);
		}
		
		// Record the process id in the queue entry, if given
		if ($syncQueueID) {
			$sql = "UPDATE syncQueue SET syncProcessID=? WHERE syncQueueID=?";
			Zotero_DB::query($sql, array($syncProcessID, $syncQueueID));
		}
		
		Zotero_DB::commit();
		
		return $syncProcessID;
	}
	
	
	private static function getHostID($hostname) {
		$cacheKey = "syncQueueHostID_" . md5($hostname);
		$hostID = Z_Core::$MC->get($cacheKey);
		if ($hostID) {
			return $hostID;
		}
		$sql = "SELECT syncQueueHostID FROM syncQueueHosts WHERE hostname=?";
		$hostID = Zotero_DB::valueQuery($sql, $hostname);
		if ($hostID) {
			Z_Core::$MC->set($cacheKey, $hostID);
		}
		return $hostID;
	}
	
	
	/**
	 * @param	int		$userID		User id
	 * @param	int		$lastsync	Unix timestamp of last sync
	 * @return	mixed	Returns array of objects with properties
	 *					'libraryID', 'id', and 'rowType' ('key' or 'id'),
	 * 					FALSE if none, or -1 if last sync time is before start of log
	 */
	private static function getDeletedObjectKeys($libraryID, $lastsync, $includeAllUserObjects=false) {
		/*
		$sql = "SELECT version FROM version WHERE schema='syncdeletelog'";
		$syncLogStart = Zotero_DB::valueQuery($sql);
		if (!$syncLogStart) {
			throw ('Sync log start time not found');
		}
		*/
		
		/*
		// Last sync time is before start of log
		if ($lastSyncDate && new Date($syncLogStart * 1000) > $lastSyncDate) {
			return -1;
		}
		*/
		
		// A subquery here was very slow in MySQL 5.1.33 but should work in MySQL 6
		
		/*
		$sql = "SELECT DISTINCT libraryID, objectType, `key`, timestamp FROM syncDeleteLogKeys WHERE ";
		$params = array($libraryID);
		if ($includeAllUserObjects) {
			$sql .= "(libraryID=? OR libraryID IN
						(SELECT libraryID FROM groups WHERE groupID IN (";
			$userID = Zotero_Users::getUserIDFromLibraryID($libraryID);
			$groupIDs = Zotero_Groups::getUserGroups($userID);
			if ($groupIDs) {
				$params = array_merge($params, $groupIDs);
				$q = array();
				for ($i=0; $i<sizeOf($groupIDs); $i++) {
					$q[] = '?';
				}
				$sql .= implode(',', $q);
			}
			$sql .= "))) ";
		}
		else {
			$sql .= "libraryID=? ";
		}
		if ($lastsync) {
			$params[] = $lastsync;
			$sql .= " AND timestamp>?";
		}
		$sql .= " ORDER BY timestamp";
		*/
		
		if (strpos($lastsync, '.') === false) {
			$lastsync .= '.';
		}
		list($timestamp, $timestampMS) = explode(".", $lastsync);
		
		$fields = "libraryID, objectType, `key`, timestamp, timestampMS";
		$sql = "SELECT $fields FROM syncDeleteLogKeys WHERE libraryID=?";
		$params = array($libraryID);
		if ($timestamp) {
			$sql .= " AND CONCAT(UNIX_TIMESTAMP(timestamp), '.', IFNULL(timestampMS, 0)) > ?";
			$params[] = $timestamp . '.' . ($timestampMS ? $timestampMS : 0);
		}
		if ($includeAllUserObjects) {
			$userID = Zotero_Users::getUserIDFromLibraryID($libraryID);
			$groupIDs = Zotero_Groups::getUserGroups($userID);
		}
		else {
			$groupIDs = array();
		}
		if ($groupIDs) {
			$sql .= " UNION SELECT $fields FROM syncDeleteLogKeys JOIN groups USING (libraryID)
						WHERE groupID IN (";
			$params = array_merge($params, $groupIDs);
			$q = array();
			for ($i=0; $i<sizeOf($groupIDs); $i++) {
				$q[] = '?';
			}
			$sql .= implode(',', $q) . ")";
			if ($timestamp) {
				$sql .= " AND CONCAT(UNIX_TIMESTAMP(timestamp), '.', IFNULL(timestampMS, 0)) > ?";
				$params[] = $timestamp . '.' . ($timestampMS ? $timestampMS : 0);
			}
		}
		$sql .= " ORDER BY CONCAT(timestamp, '.', IFNULL(timestampMS, 0))";
		$rows = Zotero_DB::query($sql, $params);
		if (!$rows) {
			return false;
		}
		
		$deletedIDs = array();
		foreach (Zotero_DataObjects::$objectTypes as $syncObject) {
			$deletedIDs[strtolower($syncObject['plural'])] = array();
		}
		foreach ($rows as $row) {
			$type = strtolower(Zotero_DataObjects::$objectTypes[$row['objectType']]['plural']);
			$deletedIDs[$type][] = array(
				'libraryID' => $row['libraryID'],
				'key' => $row['key']
			);
		}
		
		return $deletedIDs;
	}
	
	
	private static function getDeletedObjectIDs($libraryID, $lastsync, $includeAllUserObjects=false) {
		/*
		$sql = "SELECT version FROM version WHERE schema='syncdeletelog'";
		$syncLogStart = Zotero_DB::valueQuery($sql);
		if (!$syncLogStart) {
			throw ('Sync log start time not found');
		}
		*/
		
		/*
		// Last sync time is before start of log
		if ($lastSyncDate && new Date($syncLogStart * 1000) > $lastSyncDate) {
			return -1;
		}
		*/
		
		// A subquery here was very slow in MySQL 5.1.33 but should work in MySQL 6
		/*
		$sql = "SELECT DISTINCT libraryID, objectType, id, timestamp
					FROM syncDeleteLogIDs WHERE ";
		$params = array($libraryID);
		if ($includeAllUserObjects) {
			$sql .= "(libraryID=? OR libraryID IN
						(SELECT libraryID FROM groups WHERE groupID IN (";
			$userID = Zotero_Users::getUserIDFromLibraryID($libraryID);
			$groupIDs = Zotero_Groups::getUserGroups($userID);
			if ($groupIDs) {
				$params = array_merge($params, $groupIDs);
				$q = array();
				for ($i=0; $i<sizeOf($groupIDs); $i++) {
					$q[] = '?';
				}
				$sql .= implode(',', $q);
			}
			$sql .= "))) ";
		}
		else {
			$sql .= "libraryID=? ";
		}
		if ($lastsync) {
			$params[] = $lastsync;
			$sql .= " AND timestamp>?";
		}
		$sql .= " ORDER BY timestamp";
		*/
		
		if (strpos($lastsync, '.') === false) {
			$lastsync .= '.';
		}
		list($timestamp, $timestampMS) = explode(".", $lastsync);
		$timestampMS = (int) $timestampMS;
		
		$fields = "libraryID, objectType, id, timestamp, timestampMS";
		$sql = "SELECT $fields FROM syncDeleteLogIDs WHERE libraryID=?";
		$params = array($libraryID);
		if ($timestamp) {
			// Send any entries from before these were being properly sent
			if ($timestamp < 1260778500) {
				$sql .= " AND (CONCAT(UNIX_TIMESTAMP(timestamp), '.', IFNULL(timestampMS, 0)) > ?
							OR CONCAT(UNIX_TIMESTAMP(timestamp), '.', IFNULL(timestampMS, 0)) BETWEEN 1257968068 AND ?)";
				$params[] = $timestamp . '.' . ($timestampMS ? $timestampMS : 0);
				$params[] = 1260778500;
			}
			else {
				$sql .= " AND CONCAT(UNIX_TIMESTAMP(timestamp), '.', IFNULL(timestampMS, 0)) > ?";
				$params[] = $timestamp . '.' . ($timestampMS ? $timestampMS : 0);
			}
		}
		if ($includeAllUserObjects) {
			$userID = Zotero_Users::getUserIDFromLibraryID($libraryID);
			$groupIDs = Zotero_Groups::getUserGroups($userID);
		}
		else {
			$groupIDs = array();
		}
		if ($groupIDs) {
			$sql .= " UNION SELECT $fields FROM syncDeleteLogIDs JOIN groups USING (libraryID)
						WHERE groupID IN (";
			$params = array_merge($params, $groupIDs);
			$q = array();
			for ($i=0; $i<sizeOf($groupIDs); $i++) {
				$q[] = '?';
			}
			$sql .= implode(',', $q) . ")";
			if ($timestamp) {
				// Send any entries from before these were being properly sent
				if ($timestamp < 1260778500) {
					$sql .= " AND (CONCAT(UNIX_TIMESTAMP(timestamp), '.', IFNULL(timestampMS, 0)) > ?
								OR CONCAT(UNIX_TIMESTAMP(timestamp), '.', IFNULL(timestampMS, 0)) BETWEEN 1257968068 AND ?)";
					$params[] = $timestamp . '.' . ($timestampMS ? $timestampMS : 0);
					$params[] = 1260778500;
				}
				else {
					$sql .= " AND CONCAT(UNIX_TIMESTAMP(timestamp), '.', IFNULL(timestampMS, 0)) > ?";
					$params[] = $timestamp . '.' . ($timestampMS ? $timestampMS : 0);
				}
			}
		}
		$sql .= " ORDER BY timestamp";
		
		$rows = Zotero_DB::query($sql, $params);
		if (!$rows) {
			return false;
		}
		
		$deletedIDs = array(
			'groups' => array()
		);
		
		foreach ($rows as $row) {
			$type = $row['objectType'] . 's';
			$deletedIDs[$type][] = $row['id'];
		}
		
		return $deletedIDs;
	}
}
?>
