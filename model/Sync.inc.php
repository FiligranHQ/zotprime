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
	
	// This needs to be incremented any time there's a change to the sync response
	private static $cacheVersion = 1;
	
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
		$sql = "SELECT COUNT(*) FROM syncUploadQueueLocks WHERE libraryID IN (";
		$sql .= implode(', ', array_fill(0, sizeOf($libraryIDs), '?'));
		$sql .= ")";
		
		$locked = !!Zotero_DB::valueQuery($sql, $libraryIDs);
		
		Zotero_DB::commit();
		
		return $locked;
	}
	
	
	/**
	 * Check if any of a user's libraries are being written to
	 *
	 * Clients can't read (/updated) or write (/upload) if this is true
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
	public static function parseAffectedLibraries($xmlstr) {
		preg_match_all('/<[^>]+ libraryID="([0-9]+)"/', $xmlstr, $matches);
		$unique = array_values(array_unique($matches[1]));
		array_walk($unique, function (&$a) {
			$a = (int) $a;
		});
		return $unique;
	}
	
	
	public static function queueDownload($userID, $sessionID, $lastsync, $version, $updatedObjects) {
		$syncQueueID = Zotero_ID::getBigInt();
		
		// If there's a completed process from this session, delete it, since it
		// seems the results aren't going to be picked up
		$sql = "DELETE FROM syncDownloadQueue WHERE sessionID=? AND finished IS NOT NULL";
		Zotero_DB::query($sql, $sessionID);
		
		list($lastsync, $lastsyncMS) = self::getTimestampParts($lastsync);
		
		$sql = "INSERT INTO syncDownloadQueue
				(syncDownloadQueueID, processorHost, userID, sessionID, lastsync, lastsyncMS, version, objects)
				VALUES (?, INET_ATON(?), ?, ?, FROM_UNIXTIME(?), ?, ?, ?)";
		Zotero_DB::query(
			$sql,
			array(
				$syncQueueID,
				gethostbyname(gethostname()),
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
		$sql = "DELETE FROM syncUploadQueue WHERE sessionID=? AND finished IS NOT NULL";
		Zotero_DB::query($sql, $sessionID);
		
		Zotero_DB::beginTransaction();
		
		$sql = "INSERT INTO syncUploadQueue
				(syncUploadQueueID, processorHost, userID, sessionID, xmldata, dataLength, hasCreator)
				VALUES (?, INET_ATON(?), ?, ?, ?, ?, ?)";
		Zotero_DB::query(
			$sql,
			array(
				$syncQueueID,
				gethostbyname(gethostname()),
				$userID,
				$sessionID,
				$xmldata,
				$length,
				strpos($xmldata, '<creator') === false ? 0 : 1
			)
		);
		
		$sql = "INSERT INTO syncUploadQueueLocks VALUES ";
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
				$sql = "SELECT libraryID FROM tmpLibraryCheck
						LEFT JOIN libraries USING (libraryID)
						WHERE libraries.libraryID IS NULL LIMIT 1";
				$libraryID = Zotero_DB::valueQuery($sql);
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
		$sql = "SELECT syncDownloadQueueID, SDQ.userID,
				CONCAT(UNIX_TIMESTAMP(lastsync), '.', lastsyncMS) AS lastsync,
				version, added, objects, ipAddress
				FROM syncDownloadQueue SDQ JOIN sessions USING (sessionID)
				WHERE started IS NULL ORDER BY tries > 4, ";
		if ($smallestFirst) {
			$sql .= "ROUND(objects / 100), ";
		}
		$sql .= "added LIMIT 1 FOR UPDATE";
		$row = Zotero_DB::rowQuery($sql);
		
		// No pending processes
		if (!$row) {
			Zotero_DB::commit();
			return 0;
		}
		
		$host = gethostbyname(gethostname());
		$startedTimestamp = microtime(true);
		
		$sql = "UPDATE syncDownloadQueue SET started=FROM_UNIXTIME(?), processorHost=INET_ATON(?) WHERE syncDownloadQueueID=?";
		Zotero_DB::query($sql, array(round($startedTimestamp), $host, $row['syncDownloadQueueID']));
		
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
			
			self::logDownload(
				$row['userID'],
				round($row['lastsync']),
				$row['objects'],
				$row['ipAddress'],
				$host,
				round((float) microtime(true) - $startedTimestamp, 2),
				max(0, min(time() - strtotime($row['added']), 65535)),
				0
			);
		}
		// Timeout/connection error
		else if (
			strpos($msg, "Lock wait timeout exceeded; try restarting transaction") !== false
				|| strpos($msg, "Deadlock found when trying to get lock; try restarting transaction") !== false
				|| strpos($msg, "Too many connections") !== false
				|| strpos($msg, "Can't connect to MySQL server") !==false
				|| strpos($msg, "MongoCursorTimeoutException") !== false
		) {
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
					substr(serialize($e), 0, 65535),
					$row['syncDownloadQueueID']
				)
			);
			
			self::logDownload(
				$row['userID'],
				$row['lastsync'],
				$row['objects'],
				$row['ipAddress'],
				$host,
				round((float) microtime(true) - $startedTimestamp, 2),
				max(0, min(time() - strtotime($row['added']), 65535)),
				1
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
			$sql = "UPDATE syncUploadQueue SET started=NULL WHERE started IS NOT NULL AND errorCheck!=1 AND
						started < (NOW() - INTERVAL 12 MINUTE) AND finished IS NULL AND dataLength<250000";
			$row = Zotero_DB::rowQuery($sql);
		}
		
		if (Z_Core::probability(30)) {
			$sql = "UPDATE syncUploadQueue SET tries=0 WHERE started IS NULL AND
					tries>=5 AND finished IS NULL";
			$row = Zotero_DB::rowQuery($sql);
		}
		
		// Get a queued process
		$smallestFirst = Z_CONFIG::$SYNC_UPLOAD_SMALLEST_FIRST;
		$sortByQuota = !empty(Z_CONFIG::$SYNC_UPLOAD_SORT_BY_QUOTA);
		
		$sql = "SELECT syncUploadQueue.* FROM syncUploadQueue ";
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
		
		$host = gethostbyname(gethostname());
		
		$startedTimestamp = microtime(true);
		if (strpos($startedTimestamp, '.') === false) {
			$startedTimestamp .= '.';
		}
		list($started, $startedMS) = explode('.', $startedTimestamp);
		$sql = "UPDATE syncUploadQueue SET started=FROM_UNIXTIME(?), startedMS=?, processorHost=INET_ATON(?) WHERE syncUploadQueueID=?";
		Zotero_DB::query($sql, array($started, $startedMS, $host, $row['syncUploadQueueID']));
		
		Zotero_DB::commit();
		
		$error = false;
		$lockError = false;
		try {
			$xml = new SimpleXMLElement($row['xmldata']);
			$timestamp = self::processUploadInternal($row['userID'], $xml, $row['syncUploadQueueID'], $syncProcessID);
			list($timestamp, $timestampMS) = explode('.', $timestamp);
		}
		
		catch (Exception $e) {
			$error = true;
			$msg = $e->getMessage();
		}
		
		Zotero_DB::beginTransaction();
		
		// Mark upload as finished — NULL indicates success
		if (!$error) {
			$sql = "UPDATE syncUploadQueue SET finished=FROM_UNIXTIME(?), finishedMS=? WHERE syncUploadQueueID=?";
			Zotero_DB::query(
				$sql,
				array(
					$timestamp,
					$timestampMS,
					$row['syncUploadQueueID']
				)
			);
			
			try {
				$sql = "INSERT INTO syncUploadProcessLog
						(userID, dataLength, processorHost, processDuration, totalDuration, error)
						VALUES (?,?,INET_ATON(?),?,?,?)";
				Zotero_DB::query(
					$sql,
					array(
						$row['userID'],
						$row['dataLength'],
						$host,
						round((float) microtime(true) - $startedTimestamp, 2),
						max(0, min(time() - strtotime($row['added']), 65535)),
						0
					)
				);
			}
			catch (Exception $e) {
				Z_Core::logError($e);
			}
			
			try {
				self::processPostWriteLog($row['syncUploadQueueID'], $row['userID'], $timestamp, $timestampMS);
			}
			catch (Exception $e) {
				Z_Core::logError($e);
			}
			
			try {
				// Index new items
				Zotero_Processors::notifyProcessors('index');
			}
			catch (Exception $e) {
				Z_Core::logError($e);
			}
		}
		// Timeout/connection error
		else if (
			strpos($msg, "Lock wait timeout exceeded; try restarting transaction") !== false
				|| strpos($msg, "Deadlock found when trying to get lock; try restarting transaction") !== false
				|| strpos($msg, "Too many connections") !== false
				|| strpos($msg, "Can't connect to MySQL server") !==false
				|| strpos($msg, "MongoCursorTimeoutException") !== false
		) {
			Z_Core::logError($e);
			$sql = "UPDATE syncUploadQueue SET started=NULL, tries=tries+1 WHERE syncUploadQueueID=?";
			Zotero_DB::query($sql, $row['syncUploadQueueID']);
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
			$sql = "UPDATE syncUploadQueue SET finished=?, finishedMS=?, errorCode=?, errorMessage=? WHERE syncUploadQueueID=?";
			Zotero_DB::query(
				$sql,
				array(
					Zotero_DB::getTransactionTimestamp(),
					Zotero_DB::getTransactionTimestampMS(),
					$e->getCode(),
					$serialized,
					$row['syncUploadQueueID']
				)
			);
			
			try {
				$sql = "INSERT INTO syncUploadProcessLog
						(userID, dataLength, processorHost, processDuration, totalDuration, error)
						VALUES (?,?,INET_ATON(?),?,?,?)";
				Zotero_DB::query(
					$sql,
					array(
						$row['userID'],
						$row['dataLength'],
						$host,
						round((float) microtime(true) - $startedTimestamp, 2),
						max(0, min(time() - strtotime($row['added']), 65535)),
						1
					)
				);
			}
			catch (Exception $e) {
				Z_Core::logError($e);
			}
		}
		
		// Clear read locks
		$sql = "DELETE FROM syncUploadQueueLocks WHERE syncUploadQueueID=?";
		Zotero_DB::query($sql, $row['syncUploadQueueID']);
		
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
			$sql = "UPDATE syncUploadQueue SET started=NULL WHERE started IS NOT NULL AND errorCheck=1 AND
						started < (NOW() - INTERVAL 12 MINUTE) AND finished IS NULL AND dataLength<250000";
			$row = Zotero_DB::rowQuery($sql);
		}
		
		// Get a queued process that hasn't been error-checked and is large enough to warrant it
		$sql = "SELECT * FROM syncUploadQueue WHERE started IS NULL AND errorCheck=0
				AND dataLength>=" . self::$minErrorCheckSize . " ORDER BY added LIMIT 1 FOR UPDATE";
		$row = Zotero_DB::rowQuery($sql);
		
		// No pending processes
		if (!$row) {
			Zotero_DB::commit();
			return 0;
		}
		
		$sql = "UPDATE syncUploadQueue SET started=NOW(), errorCheck=1 WHERE syncUploadQueueID=?";
		Zotero_DB::query($sql, array($row['syncUploadQueueID']));
		
		// We track error processes as upload processes that just get reset back to
		// started=NULL on completion (but with errorCheck=2)
		self::addUploadProcess($row['userID'], null, $row['syncUploadQueueID'], $syncProcessID);
		
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
			//Z_Core::logError($e);
			
			Zotero_DB::beginTransaction();
			
			$sql = "UPDATE syncUploadQueue SET syncProcessID=NULL, finished=?, finishedMS=?,
						errorCode=?, errorMessage=? WHERE syncUploadQueueID=?";
			Zotero_DB::query(
				$sql,
				array(
					Zotero_DB::getTransactionTimestamp(),
					Zotero_DB::getTransactionTimestampMS(),
					$e->getCode(),
					serialize($e),
					$row['syncUploadQueueID']
				)
			);
			
			self::removeUploadProcess($syncProcessID);
			
			Zotero_DB::commit();
			
			return -2;
		}
		
		Zotero_DB::beginTransaction();
		
		$sql = "UPDATE syncUploadQueue SET syncProcessID=NULL, started=NULL, errorCheck=2 WHERE syncUploadQueueID=?";
		Zotero_DB::query($sql, $row['syncUploadQueueID']);
		
		self::removeUploadProcess($syncProcessID);
		
		Zotero_DB::commit();
		
		return 1;
	}
	
	
	public static function getUploadQueueIDByUserID($userID) {
		$sql = "SELECT syncUploadQueueID FROM syncUploadQueue WHERE userID=?";
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
							$affected = Zotero_DB::query(
								$sql,
								array($timestamp, $timestampMS, $userLibraryID, $groupID),
								Zotero_Shards::getByLibraryID($userLibraryID)
							);
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
							$sql = "UPDATE groupUsers SET lastUpdated=FROM_UNIXTIME(?) WHERE groupID=? AND userID=?";
							$affected = Zotero_DB::query($sql, array($timestamp, $groupID, $userID));
							break;
						
						case 'delete':
							$userLibraryID = Zotero_Users::getLibraryIDFromUserID($userID);
							$sql = "UPDATE syncDeleteLogIDs SET timestamp=FROM_UNIXTIME(?), timestampMS=?
									WHERE libraryID=? AND objectType='group' AND id=?";
							$affected = Zotero_DB::query(
								$sql,
								array($timestamp, $timestampMS, $userLibraryID, $groupID),
								Zotero_Shards::getByLibraryID($this->libraryID)
							);
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
	
	
	public static function countQueuedDownloadProcesses() {
		$sql = "SELECT COUNT(*) FROM syncDownloadQueue WHERE started IS NULL";
		return Zotero_DB::valueQuery($sql);
	}
	
	
	public static function countQueuedUploadProcesses($errorCheck=false) {
		$sql = "SELECT COUNT(*) FROM syncUploadQueue WHERE started IS NULL";
		// errorCheck=0 indicates that the upload has not been checked for errors
		if ($errorCheck) {
			$sql .= " AND errorCheck=0 AND dataLength>5000";
		}
		return Zotero_DB::valueQuery($sql);
	}
	
	
	public static function getOldDownloadProcesses($host=null, $seconds=60) {
		$sql = "SELECT syncDownloadProcessID FROM syncDownloadQueue
				WHERE started < NOW() - INTERVAL ? SECOND";
		$params = array($seconds);
		if ($host) {
			$sql .= " AND processorHost=INET_ATON(?)";
			$params[] = $host;
		}
		return Zotero_DB::columnQuery($sql, $params);
	}
	
	
	public static function getOldUploadProcesses($host, $seconds=60) {
		$sql = "SELECT syncProcessID FROM syncUploadQueue
				WHERE started < NOW() - INTERVAL ? SECOND AND errorCheck!=1";
		$params = array($seconds);
		if ($host) {
			$sql .= " AND processorHost=INET_ATON(?)";
			$params[] = $host;
		}
		return Zotero_DB::columnQuery($sql, $params);
	}
	
	
	public static function getOldErrorProcesses($host, $seconds=60) {
		$sql = "SELECT syncProcessID FROM syncUploadQueue
				WHERE started < NOW() - INTERVAL ? SECOND AND errorCheck=1";
		$params = array($seconds);
		if ($host) {
			$sql .= " AND processorHost=INET_ATON(?)";
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
	 * Purge error process from database and reset errorCheck to 0
	 *
	 * This is called only after an error check is orphaned
	 */
	public static function purgeErrorProcess($syncErrorProcessID) {
		Zotero_DB::beginTransaction();
		
		self::removeUploadProcess($syncErrorProcessID);
		
		$sql = "UPDATE syncUploadQueue SET errorCheck=0 WHERE syncProcessID=?";
		Zotero_DB::query($sql, $syncErrorProcessID);
		
		Zotero_DB::commit();
	}
	
	
	public static function getCachedDownload($userID, $lastsync) {
		if (!$lastsync) {
			throw new Exception('$lastsync not provided');
		}
		
		$lastsync = implode('.', self::getTimestampParts($lastsync));
		
		$key = md5(self::getUpdateKey($userID) . "_" . $lastsync . "_" . self::$cacheVersion);
		$xmldata = Z_Core::$Mongo->valueQuery("syncDownloadCache", $key, "xmldata");
		if ($xmldata) {
			// Update the last-used timestamp
			Z_Core::$Mongo->update(
				"syncDownloadCache",
				array("_id" => $key), array('$set' => array("lastUsed" => new MongoDate()))
			);
		}
		return $xmldata;
	}
	
	
	public static function cacheDownload($userID, $lastsync, $xmldata) {
		$key = md5(self::getUpdateKey($userID) . "_" . $lastsync . "_" . self::$cacheVersion);
		$doc = array(
			"_id" => $key,
			"userID" => $userID,
			"lastsync" => $lastsync,
			"xmldata" => $xmldata,
			"lastUsed" => new MongoDate()
		);
		Z_Core::$Mongo->insert("syncDownloadCache", $doc);
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
			// Every two minutes, update lastCheck
			if (!Z_Core::$MC->get("syncDownloadLastCheck_$sessionID")) {
				$sql = "UPDATE syncDownloadQueue SET lastCheck=NOW() WHERE sessionID=?";
				Zotero_DB::query($sql, $sessionID);
				
				Z_Core::$MC->set("syncDownloadLastCheck_$sessionID", true, 120);
			}
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
				xmldata, errorCode, errorMessage FROM syncUploadQueue WHERE sessionID=?";
		$row = Zotero_DB::rowQuery($sql, $sessionID);
		if (!$row) {
			Zotero_DB::commit();
			throw new Exception("Queued upload not found for session");
		}
		
		if (is_null($row['finished'])) {
			Zotero_DB::beginTransaction();
			return false;
		}
		
		$sql = "DELETE FROM syncUploadQueue WHERE sessionID=?";
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
	
	
	public static function logDownload($userID, $lastsync, $object, $ipAddress, $host, $processDuration, $totalDuration, $error) {
		try {
			if (is_numeric($ipAddress)) {
				$ipParam = "?";
			}
			else {
				$ipParam = "INET_ATON(?)";
			}
			
			$sql = "INSERT INTO syncDownloadProcessLog
					(userID, lastsync, objects, ipAddress, processorHost, processDuration, totalDuration, error)
					VALUES (?,FROM_UNIXTIME(?),?,$ipParam,INET_ATON(?),?,?,?)";
			Zotero_DB::query(
				$sql,
				array(
					$userID,
					$lastsync,
					$object,
					$ipAddress,
					$host,
					$processDuration,
					$totalDuration,
					$error
				)
			);
		}
		catch (Exception $e) {
			Z_Core::logError($e);
		}
	}
	
	
	//
	//
	// Private methods
	//
	//
	private static function processDownloadInternal($userID, $lastsync, DOMDocument $doc, $syncDownloadQueueID=null, $syncDownloadProcessID=null, $skipValidation=false) {
		try {
			$cached = Zotero_Sync::getCachedDownload($userID, $lastsync);
			if ($cached) {
				$doc->loadXML($cached);
				return;
			}
		}
		catch (Exception $e) {
			Z_Core::logError($e);
		}
		
		set_time_limit(1800);
		
		$profile = false;
		if ($profile) {
			$shardID = Zotero_Shards::getByUserID($userID);
			Zotero_DB::profileStart(0);
		}
		
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
				
				$updatedIDsByLibraryID = call_user_func(array($className, 'getUpdated'), $userLibraryID, $lastsync, true);
				if ($updatedIDsByLibraryID) {
					$node = $doc->createElement($names);
					$updatedNode->appendChild($node);
					foreach ($updatedIDsByLibraryID as $libraryID=>$ids) {
						if ($name == 'creator') {
							$updatedCreators[$libraryID] = $ids;
						}
						
						// Pre-cache item pull
						if ($name == 'item') {
							Zotero_Items::get($libraryID, $ids);
							Zotero_Notes::cacheNotes($libraryID, $ids);
						}
						
						foreach ($ids as $id) {
							if ($name == 'item') {
								$obj = call_user_func(array($className, 'get'), $libraryID, $id);
								$data = array(
									'updatedCreators' => isset($updatedCreators[$libraryID]) ? $updatedCreators[$libraryID] : array()
								);
								$xmlElement = Zotero_Items::convertItemToXML($obj, $data, $apiVersion);
							}
							else {
								$instanceClass = 'Zotero_' . $Name;
								$obj = new $instanceClass;
								if (method_exists($instanceClass, '__construct')) {
									$obj->__construct();
								}
								$obj->libraryID = $libraryID;
								$obj->id = $id;
								if ($name == 'tag') {
									$xmlElement = call_user_func(array($className, "convert{$Name}ToXML"), $obj, true);
								}
								else if ($name == 'creator') {
									$xmlElement = call_user_func(array($className, "convert{$Name}ToXML"), $obj, $doc);
									if ($xmlElement->getAttribute('libraryID') == $userLibraryID) {
										$xmlElement->removeAttribute('libraryID');
									}
									$node->appendChild($xmlElement);
								}
								else {
									$xmlElement = call_user_func(array($className, "convert{$Name}ToXML"), $obj);
								}
							}
							
							if ($xmlElement instanceof SimpleXMLElement) {
								if ($xmlElement['libraryID'] == $userLibraryID) {
									unset($xmlElement['libraryID']);
								}
								
								$newNode = dom_import_simplexml($xmlElement);
								$newNode = $doc->importNode($newNode, true);
								$node->appendChild($newNode);
							}
						}
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
			$deletedKeys = self::getDeletedObjectKeys($userID, $lastsync, true);
			$deletedIDs = self::getDeletedObjectIDs($userID, $lastsync, true);
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
			Zotero_DB::rollback(true);
			if ($syncDownloadQueueID) {
				self::removeDownloadProcess($syncDownloadProcessID);
			}
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
			
			// Cache response in Mongo if response isn't empty
			try {
				if ($doc->documentElement->firstChild->hasChildNodes()) {
					self::cacheDownload($userID, $lastsync, $doc->saveXML());
				}
			}
			catch (Exception $e) {
				Z_Core::logError($e);
			}
		}
		
		if ($syncDownloadQueueID) {
			self::removeDownloadProcess($syncDownloadProcessID);
		}
		
		if ($profile) {
			$shardID = Zotero_Shards::getByUserID($userID);
			Zotero_DB::profileEnd(0);
		}
	}
	
	
	private static function processUploadInternal($userID, SimpleXMLElement $xml, $syncQueueID=null, $syncProcessID=null) {
		$userLibraryID = Zotero_Users::getLibraryIDFromUserID($userID);
		$affectedLibraries = self::parseAffectedLibraries($xml->asXML());
		// Relations-only uploads don't have affected libraries
		if (!$affectedLibraries) {
			$affectedLibraries = array(Zotero_Users::getLibraryIDFromUserID($userID));
		}
		$processID = self::addUploadProcess($userID, $affectedLibraries, $syncQueueID, $syncProcessID);
		
		set_time_limit(1800);
		
		$profile = false;
		if ($profile) {
			$shardID = Zotero_Shards::getByUserID($userID);
			Zotero_DB::profileStart($shardID);
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
			
			// Add/update creators
			if ($xml->creators) {
				// DOM
				$keys = array();
				$xmlElements = dom_import_simplexml($xml->creators);
				$xmlElements = $xmlElements->getElementsByTagName('creator');
				Zotero_DB::query("SET foreign_key_checks = 0");
				try {
					$addedLibraryIDs = array();
					$addedCreatorDataHashes = array();
					foreach ($xmlElements as $xmlElement) {
						$key = $xmlElement->getAttribute('key');
						if (isset($keys[$key])) {
							throw new Exception("Creator $key already processed");
						}
						$keys[$key] = true;
						
						$creatorObj = Zotero_Creators::convertXMLToCreator($xmlElement);
						$creatorObj->save();
						$addedLibraryIDs[] = $creatorObj->libraryID;
						$addedCreatorDataHashes[] = $creatorObj->creatorDataHash;
					}
				}
				catch (Exception $e) {
					Zotero_DB::query("SET foreign_key_checks = 1");
					throw ($e);
				}
				Zotero_DB::query("SET foreign_key_checks = 1");
				unset($keys);
				unset($xml->creators);
				
				//
				// Manual foreign key checks
				//
				// libraryID
				foreach ($addedLibraryIDs as $addedLibraryID) {
					$shardID = Zotero_Shards::getByLibraryID($addedLibraryID);
					$sql = "SELECT COUNT(*) FROM shardLibraries WHERE libraryID=?";
					if (!Zotero_DB::valueQuery($sql, $addedLibraryID, $shardID)) {
						throw new Exception("libraryID inserted into `creators` not found in `shardLibraries` ($libraryID, $shardID)");
					}
				}
				
				// creatorDataHash
				$addedCreatorDataHashes = array_unique($addedCreatorDataHashes);
				$cursor = Z_Core::$Mongo->find("creatorData", array("_id" => array('$in' => $addedCreatorDataHashes)), array("_id"));
				$hashes = array();
				while ($cursor->hasNext()) {
					$arr = $cursor->getNext();
					$hashes[] = $arr['_id'];
				}
				$added = sizeOf($addedCreatorDataHashes);
				$count = sizeOf($hashes);
				if ($count != $added) {
					$missing = array_diff($addedCreatorDataHashes, $hashes);
					throw new Exception("creatorDataHashes inserted into `creators` not found in `creatorData` (" . implode(",", $missing) . ") $added $count");
				}
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
					if ($xmlItems) {
						$xmlItems = trim($xmlItems->nodeValue);
					}
					
					$arr = array(
						'obj' => $collectionObj,
						'items' => $xmlItems ? explode(' ', $xmlItems) : array()
					);
					$collections[] = $collectionObj;
					$collectionSets[] = $arr;
				}
				unset($keys);
				unset($xml->collections);
				
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
			
			if ($profile) {
				$shardID = Zotero_Shards::getByUserID($userID);
				Zotero_DB::profileEnd($shardID);
			}
			
			return $timestampUnix . '.' . $timestampMS;
		}
		catch (Exception $e) {
			Z_Core::$MC->rollback();
			Zotero_DB::rollback(true);
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
		try {
			Zotero_DB::query($sql, array($syncProcessID, $userID));
		}
		catch (Exception $e) {
			$sql = "SELECT CONCAT(syncProcessID,' ',userID,' ',started) FROM syncProcesses WHERE userID=?";
			$val = Zotero_DB::valueQuery($sql, $userID);
			Z_Core::logError($val);
		}
		
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
			$sql = "UPDATE syncUploadQueue SET syncProcessID=? WHERE syncUploadQueueID=?";
			Zotero_DB::query($sql, array($syncProcessID, $syncQueueID));
		}
		
		Zotero_DB::commit();
		
		return $syncProcessID;
	}
	
	
	/**
	 * @param	int		$userID		User id
	 * @param	int		$lastsync	Unix timestamp of last sync
	 * @return	mixed	Returns array of objects with properties
	 *					'libraryID', 'id', and 'rowType' ('key' or 'id'),
	 * 					FALSE if none, or -1 if last sync time is before start of log
	 */
	private static function getDeletedObjectKeys($userID, $lastsync, $includeAllUserObjects=false) {
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
		
		if (strpos($lastsync, '.') === false) {
			$lastsync .= '.';
		}
		list($timestamp, $timestampMS) = explode(".", $lastsync);
		
		// Personal library
		$shardID = Zotero_Shards::getByUserID($userID);
		$libraryID = Zotero_Users::getLibraryIDFromUserID($userID);
		$shardLibraryIDs[$shardID] = array($libraryID);
		
		// Group libraries
		if ($includeAllUserObjects) {
			$groupIDs = Zotero_Groups::getUserGroups($userID);
			if ($groupIDs) {
				// Separate groups into shards for querying
				foreach ($groupIDs as $groupID) {
					$libraryID = Zotero_Groups::getLibraryIDFromGroupID($groupID);
					$shardID = Zotero_Shards::getByLibraryID($libraryID);
					if (!isset($shardLibraryIDs[$shardID])) {
						$shardLibraryIDs[$shardID] = array();
					}
					$shardLibraryIDs[$shardID][] = $libraryID;
				}
			}
		}
		
		// Send query at each shard
		$rows = array();
		foreach ($shardLibraryIDs as $shardID=>$libraryIDs) {
			$sql = "SELECT libraryID, objectType, `key`, timestamp, timestampMS
					FROM syncDeleteLogKeys WHERE libraryID IN ("
					. implode(', ', array_fill(0, sizeOf($libraryIDs), '?'))
					. ")";
			$params = $libraryIDs;
			if ($timestamp) {
				$sql .= " AND CONCAT(UNIX_TIMESTAMP(timestamp), '.', IFNULL(timestampMS, 0)) > ?";
				$params[] = $timestamp . '.' . ($timestampMS ? $timestampMS : 0);
			}
			$sql .= " ORDER BY CONCAT(timestamp, '.', IFNULL(timestampMS, 0))";
			$shardRows = Zotero_DB::query($sql, $params, $shardID);
			if ($shardRows) {
				$rows = array_merge($rows, $shardRows);
			}
		}
		
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
	
	
	private static function getDeletedObjectIDs($userID, $lastsync, $includeAllUserObjects=false) {
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
		
		if (strpos($lastsync, '.') === false) {
			$lastsync .= '.';
		}
		list($timestamp, $timestampMS) = explode(".", $lastsync);
		$timestampMS = (int) $timestampMS;
		
		// Personal library
		$shardID = Zotero_Shards::getByUserID($userID);
		$libraryID = Zotero_Users::getLibraryIDFromUserID($userID);
		$shardLibraryIDs[$shardID] = array($libraryID);
		
		// Group libraries
		if ($includeAllUserObjects) {
			$groupIDs = Zotero_Groups::getUserGroups($userID);
			if ($groupIDs) {
				// Separate groups into shards for querying
				foreach ($groupIDs as $groupID) {
					$libraryID = Zotero_Groups::getLibraryIDFromGroupID($groupID);
					$shardID = Zotero_Shards::getByLibraryID($libraryID);
					if (!isset($shardLibraryIDs[$shardID])) {
						$shardLibraryIDs[$shardID] = array();
					}
					$shardLibraryIDs[$shardID][] = $libraryID;
				}
			}
		}
		
		// Send query at each shard
		$rows = array();
		foreach ($shardLibraryIDs as $shardID=>$libraryIDs) {
			$sql = "SELECT libraryID, objectType, id, timestamp, timestampMS
					FROM syncDeleteLogIDs WHERE libraryID IN ("
					. implode(', ', array_fill(0, sizeOf($libraryIDs), '?'))
					. ")";
			$params = $libraryIDs;
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
			$sql .= " ORDER BY timestamp";
			
			$shardRows = Zotero_DB::query($sql, $params, $shardID);
			if ($shardRows) {
				$rows = array_merge($rows, $shardRows);
			}
		}
		
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
	
	
	private static function getTimestampParts($timestamp) {
		$float = explode('.', $timestamp);
		$timestamp = $float[0];
		$timestampMS = isset($float[1]) ? substr($float[1], 0, 5) : 0;
		if ($timestampMS > 65535) {
			$timestampMS = substr($float[1], 0, 4);
		}
		return array($timestamp, $timestampMS);
	}
}
?>
