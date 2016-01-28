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

require_once('Zend/Db/Profiler.php');
require_once('Zend/Db/Adapter/Mysqli.php');
require_once('Zend/Db/Statement/Mysqli.php');
require_once('Zend/Db/Statement/Mysqli/Exception.php');

/**
*
* Singleton class for DB access
*
**/
class Zotero_DB {
	public static $queryStats;
	
	protected static $instances;
	
	protected $connections = [];
	protected $replicaConnections = [];
	private $profilerEnabled = false;
	
	private $readOnly = false;
	private $transactionLevel = 0;
	private $transactionTimestamp;
	private $transactionTimestampMS;
	private $transactionTimestampUnix;
	private $transactionConnections = [];
	private $transactionRollback = false;
	
	private $testFailureCounts = [];
	
	private $callbacks = array(
		'begin' => array(),
		'commit' => array(),
		'rollback' => array()
	);
	
	protected $db = 'master';
	
	protected function __construct() {
		// Set up main link
		$auth = Zotero_DBConnectAuth($this->db);
		$conn = new Zotero_DB_Connection;
		$conn->shardID = 0;
		$conn->host = $auth['host'];
		$conn->link = new Zend_Db_Adapter_Mysqli([
			'host'     => $auth['host'],
			'port'     => $auth['port'],
			'username' => $auth['user'],
			'password' => $auth['pass'],
			'dbname'   => $auth['db'],
			'charset'  => !empty($auth['charset']) ? $auth['charset'] : 'utf8',
			'driver_options' => array(
				"MYSQLI_OPT_CONNECT_TIMEOUT" => 5
			)
		]);
		$this->connections[0] = $conn;
	}
	
	
	protected function getShardConnection($shardID, array $options = []) {
		if (!is_numeric($shardID)) {
			throw new Exception('$shardID must be an integer');
		}
		
		$isWriteQuery = !empty($options['isWriteQuery']);
		$lastLinkFailed = !empty($options['lastLinkFailed']);
		$writeInReadMode = !empty($options['writeInReadMode']);
		
		// TEMP
		if (get_called_class() == 'Zotero_FullText_DB') {
			$linkID = "FT" . $shardID;
		}
		else {
			$linkID = $shardID;
		}
		
		if ($this->readOnly && $isWriteQuery && !$writeInReadMode) {
			throw new Exception("Cannot get link for writing in read-only mode");
		}
		
		// Master queries always use the cached link that was created at class construction
		if ($shardID == 0) {
			//error_log($this->connections[$linkID]->link->getConnection()->host_info);
			return $this->connections[$linkID];
		}
		
		// For read-only mode and read queries, use a cached link if available. Since this is
		// done before checking the latest shard info, it's possible for subsequent read queries
		// in a request to go through even if the shard was since disabled, but that's generally
		// not a big deal, and new requests will check the shard info again and throw.
		//
		// Read-only mode
		if ($this->readOnly && !$writeInReadMode) {
			// Use a cached replica link if available.
			if (isset($this->replicaConnections[$linkID])) {
				// If the last link failed, try the next one. If no more, that's fatal.
				if ($lastLinkFailed) {
					$lastHost = $this->replicaConnections[$linkID][0]->host;
					if (sizeOf($this->replicaConnections[$linkID]) == 1) {
						throw new Exception("Read failed from replica $lastHost -- no more replica connections", Z_ERROR_SHARD_UNAVAILABLE);
					}
					error_log("WARNING: Read failed from replica $lastHost -- retrying on another replica");
					array_shift($this->replicaConnections[$linkID]);
				}
				//error_log($this->replicaConnections[$linkID][0]->link->getConnection()->host_info);
				return $this->replicaConnections[$linkID][0];
			}
		}
		// Read queries in read/write mode
		//
		// Use a cached link if available
		else if (!$isWriteQuery && isset($this->connections[$linkID])) {
			//error_log($this->connections[$linkID]->link->getConnection()->host_info);
			return $this->connections[$linkID];
		}
		
		$shardInfo = Zotero_Shards::getShardInfo($shardID);
		if (!$shardInfo) {
			throw new Exception("Invalid shard $shardID");
		}
		
		if ($shardInfo['state'] == 'down') {
			throw new Exception("Shard $shardID is down", Z_ERROR_SHARD_UNAVAILABLE);
		}
		else if ($shardInfo['state'] == 'readonly') {
			if ($isWriteQuery && get_called_class() != 'Zotero_Admin_DB') {
				throw new Exception("Cannot write to read-only shard $shardID", Z_ERROR_SHARD_READ_ONLY);
			}
		}
		
		if ($this->readOnly && !$writeInReadMode) {
			$replicas = Zotero_Shards::getReplicaInfo($shardInfo['shardHostID']);
			if ($replicas) {
				// Randomize replica order
				shuffle($replicas);
				foreach ($replicas as $replica) {
					$info = $shardInfo;
					$info['address'] = $replica['address'];
					$info['port'] = $replica['port'];
					
					$conn = new Zotero_DB_Connection;
					$conn->host = $info['address'];
					$conn->shardID = $linkID;
					$conn->link = $this->getLinkFromConnectionInfo($info);
					$this->replicaConnections[$linkID][] = $conn;
				}
				
				//error_log($this->replicaConnections[$linkID][0]->link->getConnection()->host_info);
				return $this->replicaConnections[$linkID][0];
			}
		}
		
		// Host isn't read-only or down, so write queries can use a cached link if available.
		// Otherwise, make a new link.
		if (!isset($this->connections[$linkID])) {
			$conn = new Zotero_DB_Connection;
			$conn->host = $shardInfo['address'];
			$conn->shardID = $linkID;
			$conn->link = $this->getLinkFromConnectionInfo($shardInfo);
			$this->connections[$linkID] = $conn;
		}
		//error_log($this->connections[$linkID]->link->getConnection()->host_info);
		return $this->connections[$linkID];
	}
	
	
	private function getLinkFromConnectionInfo($connInfo) {
		$auth = Zotero_DBConnectAuth('shard');
		$config = array(
			'host'     => $connInfo['address'],
			'port'     => $connInfo['port'],
			'username' => $auth['user'],
			'password' => $auth['pass'],
			'dbname'   => $connInfo['db'],
			'charset'  => !empty($auth['charset']) ? $auth['charset'] : 'utf8',
			'driver_options' => array(
				"MYSQLI_OPT_CONNECT_TIMEOUT" => 5
			)
		);
		
		// TEMP: For now, use separate host
		if (get_called_class() == 'Zotero_FullText_DB') {
			$auth = Zotero_DBConnectAuth('fulltext');
			$config['host'] = $auth['host'];
			$config['port'] = $auth['port'];
		}
		// For admin, use user/pass from master
		else if (get_called_class() == 'Zotero_Admin_DB') {
			$auth = Zotero_DBConnectAuth($this->db);
			$config['username'] = $auth['user'];
			$config['password'] = $auth['pass'];
		}
		
		$link = new Zend_Db_Adapter_Mysqli($config);
		
		// If profile was previously enabled, enable it for this link
		if ($this->profilerEnabled) {
			$link->getProfiler()->setEnabled(true);
		}
		
		return $link;
	}
	
	
	/**
	 * Get an instance of the appropriate class
	 */
	protected static function getInstance() {
		$class = get_called_class();
		
		if (empty(self::$instances[$class])) {
			self::$instances[$class] = new $class;
		}
		
		return self::$instances[$class];
	}
	
	
	public static function readOnly($set) {
		$instance = self::getInstance();
		$instance->readOnly = !!$set;
	}
	
	
	/**
	 * Start a virtual MySQL transaction or increase the transaction nesting level
	 *
	 * If a transaction is already in progress, the nesting level will be incremented by one
	 *
	 * Note that this doesn't actually start a transaction. Transactions are started
	 * lazily on each shard that gets a query while a virtual transaction is open,
	 * with commits on all affected shards at the end.
	 *
	 * This only works with InnoDB tables.
	 */
	public static function beginTransaction() {
		$instance = self::getInstance();
		
		$instance->transactionLevel++;
		if ($instance->transactionLevel > 1) {
			Z_Core::debug("Transaction in progress -- nesting level increased to $instance->transactionLevel");
			return -1;
		}
		
		$instance->transactionTimestamp = null;
		$instance->transactionTimestampUnix = null;
		
		Z_Core::debug("Starting transaction");
		
		foreach ($instance->callbacks['begin'] as $callback) {
			call_user_func($callback);
		}
	}
	
	
	/**
	 * Commit a MySQL transaction or decrease the transaction nesting level
	 *
	 * If a transaction is already in progress, the nesting level will be decremented by one
	 * rather than committing.
	 *
	 * This only works with InnoDB tables
	 */
	public static function commit() {
		$instance = self::getInstance();
		
		if ($instance->transactionLevel == 0) {
			throw new Exception("Transaction not open");
		}
		
		$instance->transactionLevel--;
		if ($instance->transactionLevel) {
			Z_Core::debug("Transaction in progress -- nesting level decreased to $instance->transactionLevel");
			return -1;
		}
		
		if ($instance->transactionRollback) {
			Z_Core::debug("Rolling back previously flagged transaction");
			self::rollback();
			return;
		}
		
		while ($conn = array_pop($instance->transactionConnections)) {
			$instance->commitReal($conn);
		}
		
		foreach ($instance->callbacks['commit'] as $callback) {
			call_user_func($callback);
		}
	}
	
	
	/**
	 * Rollback MySQL transactions on all shards
	 *
	 * This only works with InnoDB tables
	 */
	public static function rollback($all=false) {
		$instance = self::getInstance();
		
		if ($instance->transactionLevel == 0) {
			if (!$all) {
				Z_Core::debug('Transaction not open in Zotero_DB::rollback()');
			}
			return;
		}
		
		if ($all) {
			$instance->transactionLevel = 1;
			self::rollback();
			return;
		}
		
		if ($instance->transactionLevel > 1) {
			Z_Core::debug("Flagging nested transaction for rollback");
			$instance->transactionRollback = true;
			$instance->transactionLevel--;
			return;
		}
		
		while ($conn = array_pop($instance->transactionConnections)) {
			$instance->rollBackReal($conn);
		}
		
		$instance->transactionLevel--;
		$instance->transactionRollback = false;
		
		foreach ($instance->callbacks['rollback'] as $callback) {
			call_user_func($callback);
		}
	}
	
	
	public static function addCallback($action, $cb) {
		$instance = self::getInstance();
		$instance->callbacks[$action][] = $cb;
	}
	
	
	public static function transactionInProgress() {
		$instance = self::getInstance();
		return $instance->transactionLevel > 0;
	}
	
	
	public static function getTransactionTimestamp() {
		$instance = self::getInstance();
		
		if ($instance->transactionLevel == 0) {
			throw new Exception("Transaction not open");
		}
		
		if (empty($instance->transactionTimestamp)) {
			$instance->transactionTimestamp = Zotero_DB::valueQuery("SELECT NOW()");
		}
		
		return $instance->transactionTimestamp;
	}
	
	
	public static function getTransactionTimestampUnix() {
		$instance = self::getInstance();
		
		if ($instance->transactionLevel == 0) {
			throw new Exception("Transaction not open");
		}
		
		if (empty($instance->transactionTimestampUnix)) {
			$ts = self::getTransactionTimestamp();
			$instance->transactionTimestampUnix = strtotime($ts);
		}
		
		return $instance->transactionTimestampUnix;
	}
	
	
	public static function registerTransactionTimestamp($unixTimestamp) {
		$instance = self::getInstance();
		
		if (!empty($instance->transactionTimestamp)) {
			throw new Exception("Transaction timestamp already set");
		}
		
		$instance->transactionTimestamp = date("Y-m-d H:i:s", $unixTimestamp);
		$instance->transactionTimestampUnix = $unixTimestamp;
	}
	
	
	/*
	 * @return	Zotero_DBStatement
	 */
	public static function getStatement($sql, $cache = false, $shardID = 0, array $options = []) {
		$instance = self::getInstance();
		
		// For testing, simulate an error reading from a replica (disabled by default)
		$testFailures = false;
		if ($testFailures && $shardID != 0 && !empty($options['internalStatement']) && $instance->readOnly) {
			if (!isset($instance->testFailureCounts[$shardID])) {
				$instance->testFailureCounts[$shardID] = 0;
			}
			$instance->testFailureCounts[$shardID]++;
			if ($instance->testFailureCounts[$shardID] == 5) {
				error_log("Failing for test!");
				throw new Exception("Fake failure");
			}
		}
		
		$options['isWriteQuery'] = self::isWriteQuery($sql);
		$conn = $instance->getShardConnection($shardID, $options);
		
		if ($cache) {
			if (is_bool($cache)) {
				$key = md5($sql);
			}
			// Supplied key
			else if (is_string($cache)) {
				$key = $cache;
			}
			else {
				throw new Exception("Invalid cache type '$cache'");
			}
		}
		
		// See if statement is already cached for this shard
		if ($cache && isset($conn->statements[$key])) {
			return $conn->statements[$key];
		}
		
		$stmt = new Zotero_DB_Statement($conn->link, $sql, $shardID);
		
		// Cache for future use
		if ($cache) {
			$conn->statements[$key] = $stmt;
		}
		
		return $stmt;
	}
	
	public static function query($sql, $params=false, $shardID=0, array $options=[]) {
		self::logQuery($sql, $params, $shardID);
		
		$instance = self::getInstance();
		$instance->checkShardTransaction($shardID);
		$isWriteQuery = self::isWriteQuery($sql);
		$cacheStatement = !isset($options['cache']) || $options['cache'] === true;
		$options['internalStatement'] = true;
		
		if ($params !== false && (is_scalar($params) || is_null($params))) {
			$params = array($params);
		}
		
		try {
			if (is_array($params)) {
				// Replace null parameter placeholders with 'NULL'
				for ($i=0, $len=sizeOf($params); $i<$len; $i++) {
					if (is_null($params[$i])) {
						preg_match_all('/\s*=?\s*\?/', $sql, $matches, PREG_OFFSET_CAPTURE);
						if (strpos($matches[0][$i][0], '=') === false) {
							preg_match_all('/\?/', $sql, $matches, PREG_OFFSET_CAPTURE);
							$repl = 'NULL';
							$sublen = 1;
						}
						else if (!$isWriteQuery) {
							$repl = ' IS NULL';
							$sublen = strlen($matches[0][$i][0]);
						}
						else {
							$repl = '=NULL';
							$sublen = strlen($matches[0][$i][0]);
						}
						
						$subpos = $matches[0][$i][1];
						$sql = substr_replace($sql, $repl, $subpos, $sublen);
						
						array_splice($params, $i, 1);
						$i--;
						$len--;
						continue;
					}
				}
				
				$stmt = self::getStatement($sql, $cacheStatement, $shardID, $options);
				$stmt->execute($params);
			}
			else {
				$stmt = self::getStatement($sql, $cacheStatement, $shardID, $options);
				$stmt->execute();
			}
			
			return self::queryFromStatement($stmt);
		}
		catch (Exception $e) {
			// Writes in read mode are allowed to fail
			if (!empty($options['writeInReadMode'])) {
				$str = self::getErrorString($e, $sql, $params, $shardID);
				error_log("WARNING: $str");
				return;
			}
			
			// In read mode, retry automatically
			if ($instance->readOnly && empty($options['lastLinkFailed'])) {
				$options['lastLinkFailed'] = true;
				return self::query($sql, $params, $shardID, $options);
			}
			
			self::error($e, $sql, $params, $shardID);
		}
		finally {
			if (isset($stmt) && !$cacheStatement) {
				$stmt->close();
			}
		}
	}
	
	
	public static function queryFromStatement(Zotero_DB_Statement $stmt, $params=false) {
		try {
			// Execute statement if not coming from self::query()
			if ($params) {
				self::logQuery($stmt->sql, $params, $stmt->shardID);
				
				// If this is a write query, make sure shard is writeable
				if ($stmt->isWriteQuery && $stmt->shardID && !Zotero_Shards::shardIsWriteable($stmt->shardID)) {
					throw new Exception("Cannot write to read-only shard $stmt->shardID", Z_ERROR_SHARD_READ_ONLY);
				}
				
				$instance = self::getInstance();
				$instance->checkShardTransaction($stmt->shardID);
				
				if (is_scalar($params)) {
					$params = array($params);
				}
				$stmt->execute($params);
			}
			
			$stmt->setFetchMode(Zend_Db::FETCH_ASSOC);
			
			$mystmt = $stmt->getDriverStatement();
			
			// Not a read statement
			if (!$mystmt->field_count) {
				// Determine the type of query using first word
				preg_match('/^[^\s\(]*/', $stmt->sql, $matches);
				$queryMethod = strtolower($matches[0]);
				
				if ($queryMethod == "update" || $queryMethod == "delete") {
					return $stmt->rowCount();
				}
				else if ($queryMethod == "insert") {
					$insertID = (int) $stmt->link->lastInsertID();
					if ($insertID) {
						return $insertID;
					}
					$affectedRows = $stmt->rowCount();
					if (!$affectedRows) {
						return false;
					}
					return $affectedRows;
				}
				return true;
			}
			
			// Cast integers
			$intFieldNames = self::getIntegerColumns($mystmt);
			$results = array();
			while ($row = $stmt->fetch()) {
				if ($intFieldNames) {
					foreach ($intFieldNames as $name) {
						if (is_null($row[$name])) {
							$row[$name] = null;
						}
						// 32-bit hack: cast only numbers shorter than 10 characters as ints
						else if (strlen($row[$name]) < 10) {
							$row[$name] = (int) $row[$name];
						}
					}
				}
				$results[] = $row;
			}
		}
		catch (Exception $e) {
			self::error($e, $stmt->sql, $params, $stmt->shardID);
		}
			
		return $results;
	}
	
	
	public static function columnQuery($sql, $params = false, $shardID = 0, array $options = []) {
		self::logQuery($sql, $params, $shardID);
		
		$instance = self::getInstance();
		$instance->checkShardTransaction($shardID);
		if (self::isWriteQuery($sql)) {
			throw new Exception("Can't use columnQuery() for write query -- use query()");
		}
		$cacheStatement = !isset($options['cache']) || $options['cache'] === true;
		$options['internalStatement'] = true;
		
		// TODO: Use instance->link->fetchCol once it supports type casting
		
		if ($params && is_scalar($params)) {
			$params = array($params);
		}
		
		try {
			$stmt = self::getStatement($sql, $cacheStatement, $shardID, $options);
			if ($params) {
				$stmt->execute($params);
			}
			else {
				$stmt->execute();
			}
			
			return self::columnQueryFromStatement($stmt);
		}
		catch (Exception $e) {
			// In read mode, retry automatically
			if ($instance->readOnly && empty($options['lastLinkFailed'])) {
				$options['lastLinkFailed'] = true;
				return self::columnQuery($sql, $params, $shardID, $options);
			}
			
			self::error($e, $sql, $params, $shardID);
		}
		finally {
			if (isset($stmt) && !$cacheStatement) {
				$stmt->close();
			}
		}
	}
	
	
	public static function columnQueryFromStatement(Zotero_DB_Statement $stmt, $params=false) {
		try {
			// Execute statement if not coming from self::query()
			if ($params) {
				self::logQuery($stmt->sql, $params, $stmt->shardID);
				
				$instance = self::getInstance();
				$instance->checkShardTransaction($stmt->shardID);
				
				if (is_scalar($params)) {
					$params = array($params);
				}
				$stmt->execute($params);
			}
			
			$stmt->setFetchMode(Zend_Db::FETCH_NUM);
			
			$vals = array();
			while ($val = $stmt->fetchColumn()) {
				$vals[] = $val;
			}
			if (!$vals) {
				return false;
			}
			
			// Cast integers
			$mystmt = $stmt->getDriverStatement();
			if (self::getIntegerColumns($mystmt)) {
				$cast = function ($val) {
					if (is_null($val)) {
						return null;
					}
					// 32-bit hack: cast only numbers shorter than 10 characters as ints
					return (strlen($val) < 10) ? (int) $val : $val;
				};
				return array_map($cast, $vals);
			}
			
			return $vals;
		}
		catch (Exception $e) {
			self::error($e, $stmt->sql, $params, $stmt->shardID);
		}
	}
	
	
	public static function rowQuery($sql, $params = false, $shardID = 0, array $options = []) {
		self::logQuery($sql, $params, $shardID);
		
		$instance = self::getInstance();
		$instance->checkShardTransaction($shardID);
		if (self::isWriteQuery($sql)) {
			throw new Exception("Can't use rowQuery() for write query -- use query()");
		}
		$cacheStatement = !isset($options['cache']) || $options['cache'] === true;
		$options['internalStatement'] = true;
		
		if ($params !== false && (is_scalar($params) || is_null($params))) {
			$params = array($params);
		}
		
		try {
			$stmt = self::getStatement($sql, $cacheStatement, $shardID, $options);
			if ($params) {
				$stmt->execute($params);
			}
			else {
				$stmt->execute();
			}
			
			return self::rowQueryFromStatement($stmt);
		}
		catch (Exception $e) {
			// In read mode, retry automatically
			if ($instance->readOnly && empty($options['lastLinkFailed'])) {
				$options['lastLinkFailed'] = true;
				return self::rowQuery($sql, $params, $shardID, $options);
			}
			
			self::error($e, $sql, $params, $shardID);
		}
		finally {
			if (isset($stmt) && !$cacheStatement) {
				$stmt->close();
			}
		}
	}
	
	
	public static function rowQueryFromStatement(Zotero_DB_Statement $stmt, $params=false) {
		try {
			// Execute statement if not coming from self::query()
			if ($params) {
				self::logQuery($stmt->sql, $params, $stmt->shardID);
				
				$instance = self::getInstance();
				$instance->checkShardTransaction($stmt->shardID);
				
				if (is_scalar($params)) {
					$params = array($params);
				}
				$stmt->execute($params);
			}
			
			$stmt->setFetchMode(Zend_Db::FETCH_ASSOC);
			$row = $stmt->fetch();
			if (!$row) {
				return false;
			}
			
			// Cast integers
			$mystmt = $stmt->getDriverStatement();
			$intFieldNames = self::getIntegerColumns($mystmt);
			if ($intFieldNames) {
				foreach ($intFieldNames as $name) {
					if (is_null($row[$name])) {
						$row[$name] = null;
					}
					// 32-bit hack: cast only numbers shorter than 10 characters as ints
					else if (strlen($row[$name]) < 10) {
						$row[$name] = (int) $row[$name];
					}
				}
			}
			return $row;
		}
		catch (Exception $e) {
			self::error($e, $stmt->sql, $params, $stmt->shardID);
		}
	}
	
	
	public static function valueQuery($sql, $params = false, $shardID = 0, array $options = []) {
		self::logQuery($sql, $params, $shardID);
		
		$instance = self::getInstance();
		$instance->checkShardTransaction($shardID);
		if (self::isWriteQuery($sql)) {
			throw new Exception("Can't use valueQuery() for write query -- use query()");
		}
		$cacheStatement = !isset($options['cache']) || $options['cache'] === true;
		$options['internalStatement'] = true;
		
		if ($params !== false && (is_scalar($params) || is_null($params))) {
			$params = array($params);
		}
		
		try {
			$stmt = self::getStatement($sql, $cacheStatement, $shardID, $options);
			if ($params) {
				$stmt->execute($params);
			}
			else {
				$stmt->execute();
			}
			return self::valueQueryFromStatement($stmt);
		}
		catch (Exception $e) {
			// In read mode, retry automatically
			if ($instance->readOnly && empty($options['lastLinkFailed'])) {
				$options['lastLinkFailed'] = true;
				return self::valueQuery($sql, $params, $shardID, $options);
			}
			
			self::error($e, $sql, $params, $shardID);
		}
		finally {
			if (isset($stmt) && !$cacheStatement) {
				$stmt->close();
			}
		}
	}
	
	
	public static function valueQueryFromStatement(Zotero_DB_Statement $stmt, $params=false) {
		try {
			// Execute statement if not coming from self::valueQuery()
			if ($params) {
				self::logQuery($stmt->sql, $params, $stmt->shardID);
				
				$instance = self::getInstance();
				$instance->checkShardTransaction($stmt->shardID);
				
				if (is_scalar($params)) {
					$params = array($params);
				}
				$stmt->execute($params);
			}
			
			$stmt->setFetchMode(Zend_Db::FETCH_NUM);
			$row = $stmt->fetch();
			if (!$row) {
				return false;
			}
			
			$mystmt = $stmt->getDriverStatement();
			
			return (self::getIntegerColumns($mystmt) && strlen($row[0]) < 10) ? (is_null($row[0]) ? null : (int) $row[0]) : $row[0];
		}
		catch (Exception $e) {
			self::error($e, $stmt->sql, $params, $stmt->shardID);
		}
	}
	
	
	public static function bulkInsert($sql, $sets, $maxInsertGroups, $firstVal = false, $shardID = 0, array $options = []) {
		$origInsertSQL = $sql;
		$insertSQL = $origInsertSQL;
		$insertParams = array();
		$insertCounter = 0;
		$options['internalStatement'] = true;
		
		if (!$sets) {
			return;
		}
		
		$paramsPerGroup = sizeOf($sets[0]);
		if ($firstVal) {
			$paramsPerGroup++;
		}
		$placeholderStr = "(" . implode(",", array_fill(0, $paramsPerGroup, "?")) . "),";
		
		foreach ($sets as $set) {
			if (is_scalar($set)) {
				$set = array($set);
			}
			
			if ($insertCounter < $maxInsertGroups) {
				$insertSQL .= $placeholderStr;
				$insertParams = array_merge(
					$insertParams,
					$firstVal === false ? $set : array_merge(array($firstVal), $set)
				);
			}
			
			if ($insertCounter == $maxInsertGroups - 1) {
				$insertSQL = substr($insertSQL, 0, -1);
				$stmt = self::getStatement($insertSQL, true, $shardID, $options);
				self::queryFromStatement($stmt, $insertParams);
				$insertSQL = $origInsertSQL;
				$insertParams = array();
				$insertCounter = -1;
			}
			
			$insertCounter++;
		}
		
		if ($insertCounter > 0 && $insertCounter < $maxInsertGroups) {
			$insertSQL = substr($insertSQL, 0, -1);
			$stmt = self::getStatement($insertSQL, true, $shardID, $options);
			self::queryFromStatement($stmt, $insertParams);
		}
	}
	
	
/*	// Checks the existence of a table in DB
	public static function table_exists($table) {
		$instance = self::getInstance();
		return $instance->link->tableExists($table);
	}
	
	
	// List fields in table
	public static function list_fields($table, $exclude=array()) {
		$instance = self::getInstance();
		
		if (is_string($exclude)) { // allow for single excludes to be passed as strings
			$exclude = array($exclude);
		}
		
		$result = $instance->direct_query('SHOW COLUMNS FROM ' . $table);
		while ($row = mysqli_fetch_row($result)) {
			$field = $row[0];
			
			// Check for excluded columns
			if ($exclude) { 
				if (!in_array($field, $exclude)) {
					$fields[] = $field;
				}
			}
			else {
				$fields[] = $field;
			}
		}
		return $fields;
	}
	
	
	public static function has_field($table,$field) {
		$instance = self::getInstance();
		
		if (isset($GLOBALS['fieldcheck'][$table][$field])) {
			return $GLOBALS['fieldcheck'][$table][$field];
		}
		$fields = $instance->list_fields($table);
		if (is_array($fields) && in_array($field,$fields)) {
			return true;
		}
		return false;
	}
*/	
	
	/**
	* Get the possible enum values for a column
	*
	* @param		string	$table		DB table
	* @param		string	$field		Enum column
	* @return	array				Array of possible values
	**/
/*	public static function enum_values($table, $field) {
		$instance = self::getInstance();
		
		$result = $instance->direct_query("SHOW COLUMNS FROM `$table` LIKE '$field'");
		if (mysqli_num_rows($result)>0) {
			$row = mysqli_fetch_row($result);
			$options = explode("','", preg_replace("/(enum|set)\('(.+?)'\)/","\\2", $row[1]));
		}
		else {
			$options=array();
		}
		return $options;
	}
*/	
	
	protected function checkShardTransaction($shardID) {
		if (!$this->transactionLevel) {
			return;
		}
		if ($this->readOnly) {
			error_log("WARNING: Transaction started in read-only mode");
			error_log(Z_Core::getBacktrace());
		}
		
		// Start a transaction for this shard if necessary
		$conn = $this->getShardConnection($shardID);
		if (!$conn->transactionStarted) {
			Z_Core::debug("Beginning transaction on shard $shardID");
			$conn->link->beginTransaction();
			$conn->transactionStarted = true;
			$this->transactionConnections[] = $conn;
		}
	}
	
	private function commitReal(Zotero_DB_Connection $conn) {
		Z_Core::debug("Committing transaction on shard $conn->shardID");
		$conn->link->commit();
		$conn->transactionStarted = false;
	}
	
	private function rollbackReal(Zotero_DB_Connection $conn) {
		Z_Core::debug("Rolling back transaction on shard $conn->shardID");
		$conn->link->rollBack();
		$conn->transactionStarted = false;
	}
	
	
	/**
	 * Determine the type of query using first word
	 */
	public static function isWriteQuery($sql) {
		preg_match('/^\(*([^\s\(]+)/', $sql, $matches);
		$command = strtoupper($matches[1]);
		switch ($command) {
			case 'SELECT':
			case 'SHOW':
				return false;
		}
		return true;
	}
	
	
	protected static function getIntegerColumns(mysqli_stmt $stmt) {
		if (!$stmt->field_count) {
			return false;
		}
		$result = $stmt->result_metadata();
		$fieldInfo = mysqli_fetch_fields($result);
		$intFieldNames = array();
		for ($i=0, $len=sizeOf($fieldInfo); $i<$len; $i++) {
			switch ($fieldInfo[$i]->type) {
				// From http://us2.php.net/manual/en/mysqli-result.fetch-field-direct.php
				case 1:
				case 2:
				case 3:
				case 8:
				case 9:
					$intFieldNames[] = $fieldInfo[$i]->name;
					break;
			}
		}
		return $intFieldNames;
	}
	
	
	/**
	 * Return the SQL command used in the query
	 */
	protected static function getQueryCommand($sql) {
		preg_match('/^[^\s\(]*/', $sql, $matches);
		return $matches[0];
	}
	
	
	protected static function logQuery($sql, $params, $shardID) {
		Z_Core::debug($sql
			. ($params ? " (" . (is_scalar($params) ? $params : implode(",", $params)) . ") "
			. "(shard: $shardID)" : ""));
	}
	
	
	public static function profileStart() {
		$instance = self::getInstance();
		$instance->profilerEnabled = true;
		// TODO: Support replica connections
		foreach ($instance->connections as $conn) {
			$profiler = $conn->link->getProfiler();
			$profiler->setEnabled(true);
		}
	}
	
	public static function profileEnd($id="", $appendRandomID=true) {
		$instance = self::getInstance();
		$instance->profilerEnabled = false;
		
		$str = "";
		$first = true;
		// TODO: Support replica connections
		foreach ($instance->connections as $shardID => $link) {
			$profiler = $link->getProfiler();
			
			$totalTime    = $profiler->getTotalElapsedSecs();
			$queryCount   = $profiler->getTotalNumQueries();
			$longestTime  = 0;
			$longestQuery = null;
			
			if (!$queryCount) {
				$profiler->setEnabled(false);
				continue;
			}
			
			ob_start();
			
			if ($first) {
				echo "======================================================================\n\n";
				$first = false;
			}
			else {
				echo "----------------------------------------------------------------------\n\n";
			}
			echo "Shard: $shardID\n\n";
			
			$queries = [];
			
			$profiles = $profiler->getQueryProfiles();
			if ($profiles) {
				foreach ($profiles as $query) {
					$sql = str_replace("\t", "", str_replace("\n", " ", $query->getQuery()));
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
			}
			
			foreach($queries as &$query) {
				//$query['avg'] = $query['time'] / $query['count'];
			}
			
			usort($queries, function ($a, $b) {
				if ($a['time'] == $b['time']) {
					return 0;
				}
				return ($a['time'] < $b['time']) ? -1 : 1;
			});
			
			var_dump($queries);
			
			echo 'Executed ' . $queryCount . ' queries in ' . $totalTime . ' seconds' . "\n";
			echo 'Average query length: ' . ($queryCount ? ($totalTime / $queryCount) : "N/A") . ' seconds' . "\n";
			echo 'Queries per second: ' . ($totalTime ? ($queryCount / $totalTime) : "N/A") . "\n";
			echo 'Longest query length: ' . $longestTime . "\n";
			echo "Longest query: " . $longestQuery . "\n\n";
			
			$str .= ob_get_clean();
			
			$profiler->setEnabled(false);
		}
		
		if ($str) {
			if ($appendRandomID) {
				if ($id) $id .= "_";
				$id .= substr(md5(uniqid(rand(), true)), 0, 10);
			}
			file_put_contents("/tmp/profile" . ($id ? "_" . $id : ""), $str);
		}
	}
	
	
	public static function error(Exception $e, $sql, $params=array(), $shardID=0) {
		$str = self::getErrorString($e, $sql, $params, $shardID);
		
		if (strpos($e->getMessage(), "Can't connect to MySQL server") !== false) {
			throw new Exception($str, Z_ERROR_SHARD_UNAVAILABLE);
		}
		
		throw new Exception($str, $e->getCode());
	}
	
	
	private static function getErrorString(Exception $e, $sql, $params = [], $shardID = 0) {
		$error = $e->getMessage();
		$paramsArray = Z_Array::array2string($params);
		
		$str = "$error\n\n"
				. "Shard: $shardID\n\n"
				. "Query:\n$sql\n\n"
				. "Params:\n$paramsArray\n\n";
		
		if (function_exists('xdebug_get_function_stack')) {
			$str .= Z_Array::array2string(xdebug_get_function_stack());
		}
		
		return $str;
	}
	
	
	public static function close($shardID=0) {
		$instance = self::getInstance();
		$conn = $instance->getShardConnection($shardID);
		// Remove prepared statements for this connection
		$conn->statements = [];
		$conn->link->closeConnection();
	}
}


class Zotero_FullText_DB extends Zotero_DB {
	protected $db = 'fulltext';
	
	protected function __construct() {
		parent::__construct();
	}
}


//
// TODO: Handle failover here instead of in calling code
//
class Zotero_ID_DB_1 extends Zotero_DB {
	protected $db = 'id1';
	
	protected function __construct() {
		parent::__construct();
	}
}


class Zotero_ID_DB_2 extends Zotero_DB {
	protected $db = 'id2';
	
	protected function __construct() {
		parent::__construct();
	}
}


class Zotero_WWW_DB_1 extends Zotero_DB {
	protected $db = 'www1';
	
	protected function __construct() {
		parent::__construct();
	}
}


class Zotero_WWW_DB_2 extends Zotero_DB {
	protected $db = 'www2';
	
	protected function __construct() {
		parent::__construct();
	}
}


class Zotero_Cache_DB extends Zotero_DB {
	protected $db = 'cache';
	
	protected function __construct() {
		parent::__construct();
	}
}


class Zotero_Admin_DB extends Zotero_DB {
	protected $db = 'admin';
	
	protected function __construct() {
		parent::__construct();
	}
}


class Zotero_DB_Connection {
	public $shardID;
	public $host;
	public $link;
	public $statements = [];
	public $transactionStarted = false;
}


class Zotero_DB_Statement extends Zend_Db_Statement_Mysqli {
	private $link;
	private $sql;
	private $shardID;
	private $isWriteQuery;
	
	public function __construct($link, $sql, $shardID=0) {
		try {
			parent::__construct($link, $sql);
		}
		catch (Exception $e) {
			Zotero_DB::error($e, $sql, array(), $shardID);
		}
		$this->link = $link;
		$this->sql = $sql;
		$this->shardID = $shardID;
		
		$this->isWriteQuery = Zotero_DB::isWriteQuery($sql);
	}
	
	public function __get($name) {
		switch ($name) {
			case 'link':
			case 'sql':
			case 'shardID':
			case 'isWriteQuery':
				return $this->$name;
		}
		trigger_error("Undefined property '$name' in __get()", E_USER_NOTICE);
		return null;
	}
}
