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
		$xml['timestamp'] = time();
		return $xml;
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
		
		$sql = "INSERT INTO syncDownloadQueue
				(syncDownloadQueueID, processorHost, userID, sessionID, lastsync, version, objects)
				VALUES (?, INET_ATON(?), ?, ?, FROM_UNIXTIME(?), ?, ?)";
		Zotero_DB::query(
			$sql,
			array(
				$syncQueueID,
				gethostbyname(gethostname()),
				$userID,
				$sessionID,
				$lastsync,
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
		
		// Strip control characters in XML data
		$xmldata = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $xmldata);
		
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
				foreach ($affectedLibraries as $libraryID) {
					if (!Zotero_DB::valueQuery("SELECT COUNT(*) FROM libraries WHERE libraryID=?", $libraryID)) {
						throw new Exception("Library $libraryID does not exist", Z_ERROR_LIBRARY_ACCESS_DENIED);
					}
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
				UNIX_TIMESTAMP(lastsync) AS lastsync, version, added, objects, ipAddress
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
			if (Zotero_Sync::userIsWriteLocked($row['userID'])) {
				$lockError = true;
				throw new Exception("User is write locked");
			}
			
			$xml = self::getResponseXML($row['version']);
			$doc = new DOMDocument();
			$domResponse = dom_import_simplexml($xml);
			$domResponse = $doc->importNode($domResponse, true);
			$doc->appendChild($domResponse);
			
			self::processDownloadInternal($row['userID'], $row['lastsync'], $doc, $row['syncDownloadQueueID'], $syncProcessID);
		}
		catch (Exception $e) {
			$error = true;
			$code = $e->getCode();
			$msg = $e->getMessage();
		}
		
		Zotero_DB::beginTransaction();
		
		// Mark download as finished — NULL indicates success
		if (!$error) {
			$timestamp = $doc->documentElement->getAttribute('timestamp');
			
			$xmldata = $doc->saveXML();
			$size = strlen($xmldata);
			
			$sql = "UPDATE syncDownloadQueue SET finished=FROM_UNIXTIME(?), xmldata=? WHERE syncDownloadQueueID=?";
			Zotero_DB::query(
				$sql,
				array(
					$timestamp,
					$xmldata,
					$row['syncDownloadQueueID']
				)
			);
			
			StatsD::increment("sync.process.download.queued.success");
			StatsD::updateStats("sync.process.download.queued.size", $size);
			StatsD::timing("sync.process.download.process", round((microtime(true) - $startedTimestamp) * 1000));
			StatsD::timing("sync.process.download.total", max(0, time() - strtotime($row['added'])) * 1000);
			
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
				$lockError
				|| strpos($msg, "Lock wait timeout exceeded; try restarting transaction") !== false
				|| strpos($msg, "Deadlock found when trying to get lock; try restarting transaction") !== false
				|| strpos($msg, "Too many connections") !== false
				|| strpos($msg, "Can't connect to MySQL server") !==false
				|| $code == Z_ERROR_SHARD_UNAVAILABLE
		) {
			Z_Core::logError($e);
			$sql = "UPDATE syncDownloadQueue SET started=NULL, tries=tries+1 WHERE syncDownloadQueueID=?";
			Zotero_DB::query($sql, $row['syncDownloadQueueID']);
			$lockError = true;
			StatsD::increment("sync.process.download.queued.errorTemporary");
		}
		// Save error
		else {
			Z_Core::logError($e);
			$sql = "UPDATE syncDownloadQueue SET finished=?, errorCode=?,
						errorMessage=? WHERE syncDownloadQueueID=?";
			Zotero_DB::query(
				$sql,
				array(
					Zotero_DB::getTransactionTimestamp(),
					$e->getCode(),
					substr(serialize($e), 0, 65535),
					$row['syncDownloadQueueID']
				)
			);
			
			StatsD::increment("sync.process.download.queued.errorPermanent");
			
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
		if (Z_Core::probability(30)) {
			$sql = "DELETE FROM syncProcesses WHERE started < (NOW() - INTERVAL 180 MINUTE)";
			Zotero_DB::query($sql);
		}
		
		if (Z_Core::probability(30)) {
			$sql = "UPDATE syncUploadQueue SET started=NULL WHERE started IS NOT NULL AND errorCheck!=1 AND
						started < (NOW() - INTERVAL 12 MINUTE) AND finished IS NULL AND dataLength<250000";
			Zotero_DB::query($sql);
		}
		
		if (Z_Core::probability(30)) {
			$sql = "UPDATE syncUploadQueue SET tries=0 WHERE started IS NULL AND
					tries>=5 AND finished IS NULL";
			Zotero_DB::query($sql);
		}
		
		Zotero_DB::beginTransaction();
		
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
		list($started, $startedMS) = self::getTimestampParts($startedTimestamp);
		$sql = "UPDATE syncUploadQueue SET started=FROM_UNIXTIME(?), processorHost=INET_ATON(?) WHERE syncUploadQueueID=?";
		Zotero_DB::query($sql, array($started, $host, $row['syncUploadQueueID']));
		
		Zotero_DB::commit();
		Zotero_DB::close();
		
		$processData = array(
			"syncUploadQueueID" => $row['syncUploadQueueID'],
			"userID" => $row['userID'],
			"dataLength" => $row['dataLength']
		);
		Z_Core::$MC->set("syncUploadProcess_" . $syncProcessID, $processData, 86400);
		
		$error = false;
		$lockError = false;
		try {
			$xml = new SimpleXMLElement($row['xmldata']);
			$timestamp = self::processUploadInternal($row['userID'], $xml, $row['syncUploadQueueID'], $syncProcessID);
		}
		catch (Exception $e) {
			$error = true;
			$code = $e->getCode();
			$msg = $e->getMessage();
		}
		
		Zotero_DB::beginTransaction();
		
		// Mark upload as finished — NULL indicates success
		if (!$error) {
			$sql = "UPDATE syncUploadQueue SET finished=FROM_UNIXTIME(?) WHERE syncUploadQueueID=?";
			Zotero_DB::query(
				$sql,
				array(
					$timestamp,
					$row['syncUploadQueueID']
				)
			);
			
			StatsD::increment("sync.process.upload.success");
			StatsD::updateStats("sync.process.upload.size", $row['dataLength']);
			StatsD::timing("sync.process.upload.process", round((microtime(true) - $startedTimestamp) * 1000));
			StatsD::timing("sync.process.upload.total", max(0, time() - strtotime($row['added'])) * 1000);
			
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
				self::processPostWriteLog($row['syncUploadQueueID'], $row['userID'], $timestamp);
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
				|| $code == Z_ERROR_LIBRARY_TIMESTAMP_ALREADY_USED
				|| $code == Z_ERROR_SHARD_READ_ONLY
				|| $code == Z_ERROR_SHARD_UNAVAILABLE
		) {
			Z_Core::logError($e);
			$sql = "UPDATE syncUploadQueue SET started=NULL, tries=tries+1 WHERE syncUploadQueueID=?";
			Zotero_DB::query($sql, $row['syncUploadQueueID']);
			$lockError = true;
			StatsD::increment("sync.process.upload.errorTemporary");
		}
		// Save error
		else {
			// As of PHP 5.3.2 we can't serialize objects containing SimpleXMLElements,
			// and since the stack trace includes one, we have to catch this and
			// manually reconstruct an exception
			$serialized = serialize(
				new Exception(
					// Strip invalid \xa0 (due to note sanitizing and &nbsp;?)
					iconv("utf-8", "utf-8//IGNORE", $msg),
					$e->getCode()
				)
			);
			
			Z_Core::logError($e);
			$sql = "UPDATE syncUploadQueue SET finished=?, errorCode=?, errorMessage=? WHERE syncUploadQueueID=?";
			Zotero_DB::query(
				$sql,
				array(
					Zotero_DB::getTransactionTimestamp(),
					$e->getCode(),
					$serialized,
					$row['syncUploadQueueID']
				)
			);
			
			StatsD::increment("sync.process.upload.errorPermanent");
			
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
			$sql = "UPDATE syncUploadQueue SET started=NULL, errorCheck=0 WHERE started IS NOT NULL AND errorCheck=1 AND
						started < (NOW() - INTERVAL 15 MINUTE) AND finished IS NULL";
			Zotero_DB::query($sql);
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
			
			// Get long item data fields
			$node = Zotero_Items::getLongDataValueFromXML($doc); // returns DOMNode rather than value
			if ($node) {
				$libraryID = $node->parentNode->getAttribute('libraryID');
				$key = $node->parentNode->getAttribute('key');
				if ($libraryID) {
					$key = $libraryID . "/" . $key;
				}
				$fieldName = $node->getAttribute('name');
				$fieldName = Zotero_ItemFields::getLocalizedString(null, $fieldName);
				if ($fieldName) {
					$start = "$fieldName field";
				}
				else {
					$start = "Field";
				}
				throw new Exception(
					"=$start value '" . mb_substr($node->nodeValue, 0, 75)
					. "...' too long for item '$key'", Z_ERROR_FIELD_TOO_LONG
				);
			}
		}
		catch (Exception $e) {
			//Z_Core::logError($e);
			
			Zotero_DB::beginTransaction();
			
			$sql = "UPDATE syncUploadQueue SET syncProcessID=NULL, finished=?,
						errorCode=?, errorMessage=? WHERE syncUploadQueueID=?";
			Zotero_DB::query(
				$sql,
				array(
					Zotero_DB::getTransactionTimestamp(),
					$e->getCode(),
					serialize($e),
					$row['syncUploadQueueID']
				)
			);
			
			$sql = "DELETE FROM syncUploadQueueLocks WHERE syncUploadQueueID=?";
			Zotero_DB::query($sql, $row['syncUploadQueueID']);
			
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
	
	
	public static function processPostWriteLog($syncUploadQueueID, $userID, $timestamp) {
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
							$sql = "UPDATE syncDeleteLogIDs SET timestamp=FROM_UNIXTIME(?)
									WHERE libraryID=? AND objectType='group' AND id=?";
							$userLibraryID = Zotero_Users::getLibraryIDFromUserID($userID);
							$affected = Zotero_DB::query(
								$sql,
								array($timestamp, $userLibraryID, $entry['ids']),
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
							$sql = "UPDATE syncDeleteLogIDs SET timestamp=FROM_UNIXTIME(?)
									WHERE libraryID=? AND objectType='group' AND id=?";
							$affected = Zotero_DB::query(
								$sql,
								array($timestamp, $userLibraryID, $groupID),
								Zotero_Shards::getByLibraryID($userLibraryID)
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
						. " didn't change any rows"
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
	
	
	public static function getCachedDownload($userID, $lastsync, $apiVersion, $cacheKeyExtra="") {
		if (!$lastsync) {
			throw new Exception('$lastsync not provided');
		}
		
		require_once 'AWS-SDK/sdk.class.php';
		$s3 = new AmazonS3();
		
		$s3Key = $apiVersion . "/" . md5(
			Zotero_Users::getUpdateKey($userID)
			. "_" . $lastsync
			// Remove after 2.1 sync cutoff
			. ($apiVersion >= 9 ? "_" . $apiVersion : "")
			. "_" . self::$cacheVersion
			. (!empty($cacheKeyExtra) ? "_" . $cacheKeyExtra : "")
		);
		
		// Check S3 for file
		try {
			$response = $s3->get_object(
				Z_CONFIG::$S3_BUCKET_CACHE,
				$s3Key,
				array(
					'curlopts' => array(
						CURLOPT_FORBID_REUSE => true
					)
				)
			);
			if ($response->isOK()) {
				$xmldata = $response->body;
			}
			else if ($response->status == 404) {
				$xmldata = false;
			}
			else {
				throw new Exception($response->status . " " . $response->body);
			}
		}
		catch (Exception $e) {
			Z_Core::logError("Warning: '" . $e->getMessage() . "' getting cached download from S3");
			$xmldata = false;
		}
		
		// Update the last-used timestamp in S3
		if ($xmldata) {
			$response = $s3->update_object(Z_CONFIG::$S3_BUCKET_CACHE, $s3Key, array(
				'meta' => array(
					'last-used' => time()
				)
			));
		}
		
		return $xmldata;
	}
	
	
	public static function cacheDownload($userID, $updateKey, $lastsync, $apiVersion, $xmldata, $cacheKeyExtra="") {
		require_once 'AWS-SDK/sdk.class.php';
		$s3 = new AmazonS3();
		
		$s3Key = $apiVersion . "/" . md5(
			$updateKey . "_" . $lastsync
			// Remove after 2.1 sync cutoff
			. ($apiVersion >= 9 ? "_" . $apiVersion : "")
			. "_" . self::$cacheVersion
			. (!empty($cacheKeyExtra) ? "_" . $cacheKeyExtra : "")
		);
		
		// Add to S3
		$response = $s3->create_object(
			Z_CONFIG::$S3_BUCKET_CACHE,
			$s3Key,
			array(
				'body' => $xmldata
			)
		);
		if (!$response->isOK()) {
			throw new Exception($response->status . " " . $response->body);
		}
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
		$sql = "SELECT finished, xmldata, errorCode, errorMessage FROM syncDownloadQueue WHERE sessionID=?";
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
	 * If success, return array('timestamp' => "123456789")
	 * If error, return array('xmldata' => "<data ...", 'exception' => Exception)
	 */
	public static function getSessionUploadResult($sessionID) {
		Zotero_DB::beginTransaction();
		$sql = "SELECT UNIX_TIMESTAMP(finished) AS finished, xmldata, errorCode, errorMessage
				FROM syncUploadQueue WHERE sessionID=?";
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
	private static function processDownloadInternal($userID, $lastsync, DOMDocument $doc, $syncDownloadQueueID=null, $syncDownloadProcessID=null) {
		$apiVersion = (int) $doc->documentElement->getAttribute('version');
		
		if ($lastsync == 1) {
			StatsD::increment("sync.process.download.full");
		}
		
		// TEMP
		$cacheKeyExtra = (!empty($_POST['ft']) ? json_encode($_POST['ft']) : "")
			. (!empty($_POST['ftkeys']) ? json_encode($_POST['ftkeys']) : "");
		
		try {
			$cached = Zotero_Sync::getCachedDownload($userID, $lastsync, $apiVersion, $cacheKeyExtra);
			if ($cached) {
				$doc->loadXML($cached);
				StatsD::increment("sync.process.download.cache.hit");
				return;
			}
		}
		catch (Exception $e) {
			$msg = $e->getMessage();
			if (strpos($msg, "Too many connections") !== false) {
				$msg = "'Too many connections' from MySQL";
			}
			else {
				$msg = "'$msg'";
			}
			Z_Core::logError("Warning: $msg getting cached download");
			StatsD::increment("sync.process.download.cache.error");
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
		
		$userLibraryID = Zotero_Users::getLibraryIDFromUserID($userID);
		
		$updatedCreators = array();
		
		try {
			Zotero_DB::beginTransaction();
			
			// Blocks until any upload processes are done
			$updateTimes = Zotero_Libraries::getUserLibraryUpdateTimes($userID);
			
			$timestamp = Zotero_DB::getTransactionTimestampUnix();
			$doc->documentElement->setAttribute('timestamp', $timestamp);
			
			$doc->documentElement->setAttribute('userID', $userID);
			$doc->documentElement->setAttribute('defaultLibraryID', $userLibraryID);
			$updateKey = Zotero_Users::getUpdateKey($userID);
			$doc->documentElement->setAttribute('updateKey', $updateKey);
			
			// Get libraries with update times >= $timestamp
			$updatedLibraryIDs = array();
			foreach ($updateTimes as $libraryID=>$timestamp) {
				if ($timestamp >= $lastsync) {
					$updatedLibraryIDs[] = $libraryID;
				}
			}
			
			// Add new and updated groups
			$joinedGroups = Zotero_Groups::getJoined($userID, (int) $lastsync);
			$updatedIDs = array_unique(array_merge(
				$joinedGroups, Zotero_Groups::getUpdated($userID, (int) $lastsync)
			));
			if ($updatedIDs) {
				$node = $doc->createElement('groups');
				$showGroups = false;
				
				foreach ($updatedIDs as $id) {
					$group = new Zotero_Group;
					$group->id = $id;
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
			
			// If there's updated data in any library or
			// there are any new groups (in which case we need all their data)
			$hasData = $updatedLibraryIDs || $joinedGroups;
			if ($hasData) {
				foreach (Zotero_DataObjects::$objectTypes as $syncObject) {
					$Name = $syncObject['singular']; // 'Item'
					$Names = $syncObject['plural']; // 'Items'
					$name = strtolower($Name); // 'item'
					$names = strtolower($Names); // 'items'
					
					$className = 'Zotero_' . $Names;
					
					$updatedIDsByLibraryID = call_user_func(array($className, 'getUpdated'), $userID, $lastsync, $updatedLibraryIDs);
					if ($updatedIDsByLibraryID) {
						$node = $doc->createElement($names);
						foreach ($updatedIDsByLibraryID as $libraryID=>$ids) {
							if ($name == 'creator') {
								$updatedCreators[$libraryID] = $ids;
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
									if ($name == 'setting') {
										$obj->name = $id;
									}
									else {
										$obj->id = $id;
									}
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
									else if ($name == 'relation') {
										// Skip new-style related items
										if ($obj->predicate == 'dc:relation') {
											continue;
										}
										$xmlElement = call_user_func(array($className, "convert{$Name}ToXML"), $obj);
										if ($apiVersion <= 8) {
											unset($xmlElement['libraryID']);
										}
									}
									else if ($name == 'setting') {
										$xmlElement = call_user_func(array($className, "convert{$Name}ToXML"), $obj, $doc);
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
						if ($node->hasChildNodes()) {
							$updatedNode->appendChild($node);
						}
					}
				}
			}
			
			// Add full-text content if the client supports it
			if (isset($_POST['ft'])) {
				$libraries = Zotero_Libraries::getUserLibraries($userID);
				$fulltextNode = false;
				foreach ($libraries as $libraryID) {
					if (!empty($_POST['ftkeys']) && $_POST['ftkeys'] === 'all') {
						$ftlastsync = 1;
					}
					else {
						$ftlastsync = $lastsync;
					}
					if (!empty($_POST['ftkeys'][$libraryID])) {
						$keys = $_POST['ftkeys'][$libraryID];
					}
					else {
						$keys = [];
					}
					$data = Zotero_FullText::getNewerInLibraryByTime($libraryID, $ftlastsync, $keys);
					if ($data) {
						if (!$fulltextNode) {
							$fulltextNode = $doc->createElement('fulltexts');
						}
						$first = true;
						$chars = 0;
						$maxChars = 500000;
						foreach ($data as $itemData) {
							if ($_POST['ft']) {
								$empty = false;
								// If the current item would put us over 500K characters,
								// leave it empty, unless it's the first one
								$currentChars = strlen($itemData['content']);
								if (!$first && (($chars + $currentChars) > $maxChars)) {
									$empty = true;
								}
								else {
									$chars += $currentChars;
								}
							}
							// If full-text syncing is disabled, leave content empty
							else {
								$empty = true;
							}
							$first = false;
							$node = Zotero_FullText::itemDataToXML($itemData, $doc, $empty);
							$fulltextNode->appendChild($node);
						}
					}
				}
				if ($fulltextNode) {
					$updatedNode->appendChild($fulltextNode);
				}
			}
			
			// Get earliest timestamp
			$earliestModTime = Zotero_Users::getEarliestDataTimestamp($userID);
			$doc->documentElement->setAttribute('earliest', $earliestModTime ? $earliestModTime : 0);
			
			// Deleted objects
			$deletedKeys = $hasData ? self::getDeletedObjectKeys($userID, $lastsync, true) : false;
			$deletedIDs = self::getDeletedObjectIDs($userID, $lastsync, true);
			if ($deletedKeys || $deletedIDs) {
				$deletedNode = $doc->createElement('deleted');
				
				// Add deleted data objects
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
							if ($row['libraryID'] != $userLibraryID || $name == 'setting') {
								$node->setAttribute('libraryID', $row['libraryID']);
							}
							$node->setAttribute('key', $row['key']);
							$typeNode->appendChild($node);
						}
						$deletedNode->appendChild($typeNode);
					}
				}
				
				// Add deleted groups
				if ($deletedIDs) {
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
		
		// Cache response if response isn't empty
		try {
			if ($doc->documentElement->firstChild->hasChildNodes()) {
				self::cacheDownload($userID, $updateKey, $lastsync, $apiVersion, $doc->saveXML(), $cacheKeyExtra);
			}
		}
		catch (Exception $e) {
			Z_Core::logError("WARNING: " . $e);
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
		
		set_time_limit(5400);
		
		$profile = false;
		if ($profile) {
			$shardID = Zotero_Shards::getByUserID($userID);
			Zotero_DB::profileStart($shardID);
		}
		
		try {
			Zotero_DB::beginTransaction();
			
			// Mark libraries as updated
			foreach ($affectedLibraries as $libraryID) {
				Zotero_Libraries::updateVersion($libraryID);
			}
			$timestamp = Zotero_Libraries::updateTimestamps($affectedLibraries);
			Zotero_DB::registerTransactionTimestamp($timestamp);
			
			// Make sure no other upload sessions use this same timestamp
			// for any of these libraries, since we return >= 1 as the next
			// last sync time
			if (!Zotero_Libraries::setTimestampLock($affectedLibraries, $timestamp)) {
				throw new Exception("Library timestamp already used", Z_ERROR_LIBRARY_TIMESTAMP_ALREADY_USED);
			}
			
			$modifiedItems = array();
			
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
						$addedLibraryIDs[] = $creatorObj->libraryID;
						
						$changed = $creatorObj->save($userID);
						
						// If the creator changed, we need to update all linked items
						if ($changed) {
							$modifiedItems = array_merge(
								$modifiedItems,
								$creatorObj->getLinkedItems()
							);
						}
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
						throw new Exception("libraryID inserted into `creators` not found in `shardLibraries` ($addedLibraryID, $shardID)");
					}
				}
			}
			
			// Add/update items
			$savedItems = array();
			if ($xml->items) {
				$childItems = array();
				
				// DOM
				$xmlElements = dom_import_simplexml($xml->items);
				$xmlElements = $xmlElements->getElementsByTagName('item');
				foreach ($xmlElements as $xmlElement) {
					$libraryID = (int) $xmlElement->getAttribute('libraryID');
					$key = $xmlElement->getAttribute('key');
					
					if (isset($savedItems[$libraryID . "/" . $key])) {
						throw new Exception("Item $libraryID/$key already processed");
					}
					
					$itemObj = Zotero_Items::convertXMLToItem($xmlElement);
					
					if (!$itemObj->getSourceKey()) {
						try {
							$modified = $itemObj->save($userID);
							if ($modified) {
								$savedItems[$libraryID . "/" . $key] = true;
							}
						}
						catch (Exception $e) {
							if (strpos($e->getMessage(), 'libraryIDs_do_not_match') !== false) {
								throw new Exception($e->getMessage() . " ($key)");
							}
							throw ($e);
						}
					}
					else {
						$childItems[] = $itemObj;
					}
				}
				unset($xml->items);
				
				while ($childItem = array_shift($childItems)) {
					$libraryID = $childItem->libraryID;
					$key = $childItem->key;
					if (isset($savedItems[$libraryID . "/" . $key])) {
						throw new Exception("Item $libraryID/$key already processed");
					}
					
					$modified = $childItem->save($userID);
					if ($modified) {
						$savedItems[$libraryID . "/" . $key] = true;
					}
				}
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
				
				self::saveCollections($collections, $userID);
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
						$collection['obj']->setItems($ids);
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
					$searchObj->save($userID);
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
					// TEMP
					$tagItems = $xmlElement->getElementsByTagName('items');
					if ($tagItems->length && $tagItems->item(0)->nodeValue == "") {
						error_log("Skipping tag with no linked items");
						continue;
					}
					
					$libraryID = (int) $xmlElement->getAttribute('libraryID');
					$key = $xmlElement->getAttribute('key');
					
					$lk = $libraryID . "/" . $key;
					if (isset($keys[$lk])) {
						throw new Exception("Tag $lk already processed");
					}
					$keys[$lk] = true;
					
					$itemKeysToUpdate = array();
					$tagObj = Zotero_Tags::convertXMLToTag($xmlElement, $itemKeysToUpdate);
					
					// We need to update removed items, added items, and,
					// if the tag itself has changed, existing items
					$modifiedItems = array_merge(
						$modifiedItems,
						array_map(
							function ($key) use ($libraryID) {
								return $libraryID . "/" . $key;
							},
							$itemKeysToUpdate
						)
					);
					
					$tagObj->save($userID, true);
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
					$relationObj->save($userID);
				}
				unset($keys);
				unset($xml->relations);
			}
			
			// Add/update settings
			if ($xml->settings) {
				// DOM
				$xmlElements = dom_import_simplexml($xml->settings);
				$xmlElements = $xmlElements->getElementsByTagName('setting');
				foreach ($xmlElements as $xmlElement) {
					$settingObj = Zotero_Settings::convertXMLToSetting($xmlElement);
					$settingObj->save($userID);
				}
				unset($xml->settings);
			}
			
			if ($xml->fulltexts) {
				// DOM
				$xmlElements = dom_import_simplexml($xml->fulltexts);
				$xmlElements = $xmlElements->getElementsByTagName('fulltext');
				foreach ($xmlElements as $xmlElement) {
					Zotero_FullText::indexFromXML($xmlElement);
				}
				unset($xml->fulltexts);
			}
			
			// TODO: loop
			if ($xml->deleted) {
				// Delete collections
				if ($xml->deleted->collections) {
					Zotero_Collections::deleteFromXML($xml->deleted->collections, $userID);
				}
				
				// Delete items
				if ($xml->deleted->items) {
					Zotero_Items::deleteFromXML($xml->deleted->items, $userID);
				}
				
				// Delete creators
				if ($xml->deleted->creators) {
					Zotero_Creators::deleteFromXML($xml->deleted->creators, $userID);
				}
				
				// Delete saved searches
				if ($xml->deleted->searches) {
					Zotero_Searches::deleteFromXML($xml->deleted->searches, $userID);
				}
				
				// Delete tags
				if ($xml->deleted->tags) {
					$xmlElements = dom_import_simplexml($xml->deleted->tags);
					$xmlElements = $xmlElements->getElementsByTagName('tag');
					foreach ($xmlElements as $xmlElement) {
						$libraryID = (int) $xmlElement->getAttribute('libraryID');
						$key = $xmlElement->getAttribute('key');
						
						$tagObj = Zotero_Tags::getByLibraryAndKey($libraryID, $key);
						if (!$tagObj) {
							continue;
						}
						// We need to update all items on the deleted tag
						$modifiedItems = array_merge(
							$modifiedItems,
							array_map(
								function ($key) use ($libraryID) {
									return $libraryID . "/" . $key;
								},
								$tagObj->getLinkedItems(true)
							)
						);
					}
					
					Zotero_Tags::deleteFromXML($xml->deleted->tags, $userID);
				}
				
				// Delete relations
				if ($xml->deleted->relations) {
					Zotero_Relations::deleteFromXML($xml->deleted->relations, $userID);
				}
				
				// Delete relations
				if ($xml->deleted->settings) {
					Zotero_Settings::deleteFromXML($xml->deleted->settings, $userID);
				}
			}
			
			$toUpdate = array();
			foreach ($modifiedItems as $item) {
				// libraryID/key string
				if (is_string($item)) {
					if (isset($savedItems[$item])) {
						continue;
					}
					$savedItems[$item] = true;
					list($libraryID, $key) = explode("/", $item);
					$item = Zotero_Items::getByLibraryAndKey($libraryID, $key);
					if (!$item) {
						// Item was deleted
						continue;
					}
				}
				// Zotero_Item
				else {
					$lk = $item->libraryID . "/" . $item->key;
					if (isset($savedItems[$lk])) {
						continue;
					}
					$savedItems[$lk] = true;
				}
				$toUpdate[] = $item;
			}
			Zotero_Items::updateVersions($toUpdate, $userID);
			unset($savedItems);
			unset($modifiedItems);
			
			try {
				self::removeUploadProcess($processID);
			}
			catch (Exception $e) {
				if (strpos($e->getMessage(), 'MySQL server has gone away') !== false) {
					// Reconnect
					error_log("Reconnecting to MySQL master");
					Zotero_DB::close();
					self::removeUploadProcess($processID);
				}
				else {
					throw ($e);
				}
			}
			
			Zotero_DB::commit();
			
			if ($profile) {
				$shardID = Zotero_Shards::getByUserID($userID);
				Zotero_DB::profileEnd($shardID);
			}
			
			// Return timestamp + 1, to keep the next /updated call
			// (using >= timestamp) from returning this data
			return $timestamp + 1;
		}
		catch (Exception $e) {
			Zotero_DB::rollback(true);
			self::removeUploadProcess($processID);
			throw $e;
		}
	}
	
	
	public static function getTimestampParts($timestamp) {
		$float = explode('.', $timestamp);
		$timestamp = $float[0];
		$timestampMS = isset($float[1]) ? substr($float[1], 0, 5) : 0;
		if ($timestampMS > 65535) {
			$timestampMS = substr($float[1], 0, 4);
		}
		return array($timestamp, (int) $timestampMS);
	}
	
	
	
	/**
	 * Recursively save collections from the top down
	 */
	private static function saveCollections($collections, $userID) {
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
				$collection->save($userID);
				unset($toSave[$key]);
				continue;
			}
			$parentCollection = Zotero_Collections::getByLibraryAndKey($collection->libraryID, $parentKey);
			// Parent collection exists and doesn't need to be saved, so save
			if ($parentCollection && empty($toSave[$parentCollection->key])) {
				$collection->save($userID);
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
	
	
	private static function countDeletedObjectKeys($userID, $timestamp, $updatedLibraryIDs) {
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
		
		$shardLibraryIDs = array();
		
		// Personal library
		$shardID = Zotero_Shards::getByUserID($userID);
		$libraryID = Zotero_Users::getLibraryIDFromUserID($userID);
		if (in_array($libraryID, $updatedLibraryIDs)) {
			$shardLibraryIDs[$shardID] = array($libraryID);
		}
		
		// Group libraries
		$groupIDs = Zotero_Groups::getUserGroups($userID);
		if ($groupIDs) {
			// Separate groups into shards for querying
			foreach ($groupIDs as $groupID) {
				$libraryID = Zotero_Groups::getLibraryIDFromGroupID($groupID);
				// If library hasn't changed, skip
				if (!in_array($libraryID, $updatedLibraryIDs)) {
					continue;
				}
				$shardID = Zotero_Shards::getByLibraryID($libraryID);
				if (!isset($shardLibraryIDs[$shardID])) {
					$shardLibraryIDs[$shardID] = array();
				}
				$shardLibraryIDs[$shardID][] = $libraryID;
			}
		}
		
		// Send query at each shard
		$rows = array();
		$count = 0;
		foreach ($shardLibraryIDs as $shardID=>$libraryIDs) {
			$sql = "SELECT COUNT(*) FROM syncDeleteLogKeys WHERE libraryID IN ("
					. implode(', ', array_fill(0, sizeOf($libraryIDs), '?'))
					. ") "
					// API only
					. "AND objectType != 'tagName'";
			$params = $libraryIDs;
			if ($timestamp) {
				$sql .= " AND timestamp >= FROM_UNIXTIME(?)";
				$params[] = $timestamp;
			}
			$count += Zotero_DB::valueQuery($sql, $params, $shardID);
		}
		
		return $count;
	}
	
	
	/**
	 * @param	int		$userID		User id
	 * @param	int		$timestamp	Unix timestamp of last sync
	 * @return	mixed	Returns array of objects with properties
	 *					'libraryID', 'id', and 'rowType' ('key' or 'id'),
	 * 					FALSE if none, or -1 if last sync time is before start of log
	 */
	private static function getDeletedObjectKeys($userID, $timestamp, $includeAllUserObjects=false) {
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
			$sql = "SELECT libraryID, objectType, `key`, timestamp
					FROM syncDeleteLogKeys WHERE libraryID IN ("
					. implode(', ', array_fill(0, sizeOf($libraryIDs), '?'))
					. ")"
					// API only
					. " AND objectType != 'tagName'";
			$params = $libraryIDs;
			if ($timestamp) {
				$sql .= " AND timestamp >= FROM_UNIXTIME(?)";
				$params[] = $timestamp;
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
	
	
	private static function getDeletedObjectIDs($userID, $timestamp, $includeAllUserObjects=false) {
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
			$sql = "SELECT libraryID, objectType, id, timestamp
					FROM syncDeleteLogIDs WHERE libraryID IN ("
					. implode(', ', array_fill(0, sizeOf($libraryIDs), '?'))
					. ")";
			$params = $libraryIDs;
			if ($timestamp) {
				// Send any entries from before these were being properly sent
				if ($timestamp < 1260778500) {
					$sql .= " AND (timestamp >= FROM_UNIXTIME(?) OR timestamp BETWEEN 1257968068 AND FROM_UNIXTIME(?))";
					$params[] = $timestamp;
					$params[] = 1260778500;
				}
				else {
					$sql .= " AND timestamp >= FROM_UNIXTIME(?)";
					$params[] = $timestamp;
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
}
?>
