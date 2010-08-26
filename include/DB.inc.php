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
	
	protected $db;
	protected $link;
	
	private $transactionLevel = 0;
	private $transactionTimestamp;
	private $transactionTimestampMS;
	private $preparedStatements = array();
	
	protected function __construct() {
		if ($auth = $this->dbConnect()) {
			
			$this->link = new Zend_Db_Adapter_Mysqli(array(
				'host'     => $auth['host'],
				'username' => $auth['user'],
				'password' => $auth['pass'],
				'dbname'   => $auth['db'],
				'charset'  => 'utf8'
			));
		}
		else {
			$output = ob_get_clean();
			ob_start("ob_gzhandler");
			die("Error connecting to DB");
		}
	}
	
	
	/**
	* Get an instance of the appropriate class
	**/
	protected static function getInstance() {
		$class = get_called_class();
		
		if (empty(self::$instances[$class])) {
			self::$instances[$class] = new $class;
		}
		
		return self::$instances[$class];
	}
	
	
	// Returns the name of the DB
	public static function get_name() {
		$instance = self::getInstance();
		
		return $instance->db;
	}
	
	
	protected function dbConnect() {
		$this->db = 'main';
		return Zotero_DBConnectAuth($this->db);
	}
	
	
	/**
	* Start a MySQL transaction or increase the transaction nesting level
	*
	* If a transaction is already in progress, the nesting level will be incremented by one
	*
	* N.B. Only works with InnoDB tables
	*
	* @return	int				1 on txn start, -1 if txn already in progress, or false on error
	**/
	public static function beginTransaction() {
		$instance = self::getInstance();
		
		$instance->transactionLevel++;
		if ($instance->transactionLevel>1) {
			Z_Core::debug("Transaction in progress—nesting level increased to $instance->transactionLevel");
			return -1;
		}
		Z_Core::debug("Starting transaction");
		
		// Generate a fixed timestamp for the entire transaction
		$time = microtime(true);
		$instance->transactionTimestamp = gmdate('Y-m-d H:i:s', (int) $time);
		$instance->transactionTimestampMS = (int) substr(strrchr($time, "."), 1);
		$instance->transactionTimestampUnix = (int) $time;
		
		return $instance->link->beginTransaction();
	}
	
	
	/**
	* Commit a MySQL transaction or decrease the transaction nesting level
	*
	* If a transaction is already in progress, the nesting level will be decremented by one
	* rather than committing.
	*
	* N.B. Only works with InnoDB tables
	*
	* @return	int				1 on txn commit, -1 if txn already in progress, or false on error
	**/
	public static function commit() {
		$instance = self::getInstance();
		
		$instance->transactionLevel--;
		if ($instance->transactionLevel) {
			Z_Core::debug("Transaction in progress—nesting level decreased to {$instance->transactionLevel}");
			return -1;
		}
		Z_Core::debug("Committing transaction");
		$instance->link->commit();
	}
	
	
	/**
	* Rollback a MySQL transaction
	*
	* N.B. Only works with InnoDB tables
	**/
	public static function rollback() {
		$instance = self::getInstance();
		
		Z_Core::debug("Rolling back transaction");
		$instance->link->rollBack();
	}
	
	
	public static function getTransactionTimestamp() {
		$instance = self::getInstance();
		if ($instance->transactionLevel == 0) {
			throw new Exception("No transaction active");
		}
		return $instance->transactionTimestamp;
	}
	
	
	public static function getTransactionTimestampMS() {
		$instance = self::getInstance();
		if ($instance->transactionLevel == 0) {
			throw new Exception("No transaction active");
		}
		return $instance->transactionTimestampMS;
	}
	
	
	public static function getTransactionTimestampUnix() {
		$instance = self::getInstance();
		if ($instance->transactionLevel == 0) {
			throw new Exception("No transaction active");
		}
		return $instance->transactionTimestampUnix;
	}
	
	
	/*
	 * @return	Zotero_DBStatement
	 */
	public static function getStatement($sql, $cache=false) {
		$instance = self::getInstance();
		
		if ($cache) {
			if (is_bool($cache)) {
				$key = md5($sql);
			}
			// Supplied key
			else {
				$key = $cache;
			}
		}
		
		// See if statement is already cached
		if ($cache && isset($instance->preparedStatements[$key])) {
			return $instance->preparedStatements[$key];
		}
		
		$stmt = new Zotero_DB_Statement($instance->link, $sql);
		
		// Cache for future use
		if ($cache) {
			$instance->preparedStatements[$key] = $stmt;
		}
		
		return $stmt;
	}
		
		
	public static function query($sql, $params=false) {
		$instance = self::getInstance();
		
		if ($params !== false && (is_scalar($params) || is_null($params))) {
			$params = array($params);
		}
		
		try {
			if (is_array($params)) {
				// Determine the type of query using first word
				preg_match('/^[^\s\(]*/', $sql, $matches);
				$queryMethod = strtolower($matches[0]);
				
				// Replace null parameter placeholders with 'NULL'
				for ($i=0, $len=sizeOf($params); $i<$len; $i++) {
					if (is_null($params[$i])) {
						preg_match_all('/\s*=?\s*\?/', $sql, $matches, PREG_OFFSET_CAPTURE);
						if (strpos($matches[0][$i][0], '=') === false) {
							preg_match_all('/\?/', $sql, $matches, PREG_OFFSET_CAPTURE);
							$repl = 'NULL';
							$sublen = 1;
						}
						else if ($queryMethod == 'select') {
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
				
				$stmt = new Zotero_DB_Statement($instance->link, $sql);
				$stmt->execute($params);
			}
			else {
				$stmt = new Zotero_DB_Statement($instance->link, $sql);
				$stmt->execute();
			}
		}
		catch (Exception $e) {
			self::error($e, $sql, $params);
		}
		
		return self::queryFromStatement($stmt);
	}
	
	
	public static function queryFromStatement(Zotero_DB_Statement $stmt, $params=false) {
		$instance = self::getInstance();
		
		try {
			// Execute statement if not coming from self::query()
			if ($params) {
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
					$insertID = $instance->lastInsertID();
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
						$row[$name] = is_null($row[$name]) ? null : (int) $row[$name];
					}
				}
				$results[] = $row;
			}
		}
		catch (Exception $e) {
			self::error($e, $stmt->sql, $params);
		}
			
		return $results;
	}
	
	
	public static function columnQuery($sql, $params=false) {
		$instance = self::getInstance();
		
		// TODO: Use instance->link->fetchCol once it supports type casting
		
		if ($params && is_scalar($params)) {
			$params = array($params);
		}
		
		try {
			$stmt = new Zotero_DB_Statement($instance->link, $sql);
			if ($params) {
				$stmt->execute($params);
			}
			else {
				$stmt->execute();
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
					return is_null($val) ? null : (int) $val;
				};
				return array_map($cast, $vals);
			}
		}
		catch (Exception $e) {
			self::error($e, $sql, $params);
		}
		
		return $vals;
	}
	
	
	public static function rowQuery($sql, $params=false) {
		$instance = self::getInstance();
		
		if ($params !== false && (is_scalar($params) || is_null($params))) {
			$params = array($params);
		}
		
		try {
			$stmt = new Zotero_DB_Statement($instance->link, $sql);
			if ($params) {
				$stmt->execute($params);
			}
			else {
				$stmt->execute();
			}
			
			return self::rowQueryFromStatement($stmt);
		}
		catch (Exception $e) {
			self::error($e, $sql, $params);
		}
	}
	
	
	public static function rowQueryFromStatement(Zotero_DB_Statement $stmt, $params=false) {
		$instance = self::getInstance();
		
		try {
			// Execute statement if not coming from self::query()
			if ($params) {
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
					$row[$name] = is_null($row[$name]) ? null : (int) $row[$name];
				}
			}
			return $row;
		}
		catch (Exception $e) {
			self::error($e, $stmt->sql, $params);
		}
	}
	
	
	public static function valueQuery($sql, $params=false) {
		$instance = self::getInstance();
		
		if ($params !== false && (is_scalar($params) || is_null($params))) {
			$params = array($params);
		}
		
		$stmt = new Zotero_DB_Statement($instance->link, $sql);
		try {
			if ($params) {
				$stmt->execute($params);
			}
			else {
				$stmt->execute();
			}
			$stmt->setFetchMode(Zend_Db::FETCH_NUM);
			$row = $stmt->fetch();
			if (!$row) {
				return false;
			}
			
			$mystmt = $stmt->getDriverStatement();
			
			return self::getIntegerColumns($mystmt) ? (is_null($row[0]) ? null : (int) $row[0]) : $row[0];
		}
		catch (Exception $e) {
			self::error($e, $sql, $params);
		}
	}
	
	
	public static function bulkInsert($sql, $sets, $maxInsertGroups, $firstVal=false) {
		$origInsertSQL = $sql;
		$insertSQL = $origInsertSQL;
		$insertParams = array();
		$insertCounter = 0;
		
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
				$stmt = Zotero_DB::getStatement($insertSQL, true);
				Zotero_DB::queryFromStatement($stmt, $insertParams);
				$insertSQL = $origInsertSQL;
				$insertParams = array();
				$insertCounter = -1;
			}
			
			$insertCounter++;
		}
		
		if ($insertCounter > 0 && $insertCounter < $maxInsertGroups) {
			$insertSQL = substr($insertSQL, 0, -1);
			$stmt = Zotero_DB::getStatement($insertSQL, true);
			Zotero_DB::queryFromStatement($stmt, $insertParams);
		}
	}
	
	
	public static function lastInsertID() {
		$instance = self::getInstance();
		return (int) $instance->link->lastInsertId();
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
	
	
	public static function getProfiler() {
		$instance = self::getInstance();
		return $instance->link->getProfiler();
	}
	
	
	public static function error(Exception $e, $sql, $params=array()) {
		$paramsArray = Z_Array::array2string($params);
		
		$error = $e->getMessage();
		$errno = $e->getCode();
		
		$str = "$error\n\n"
				. "Query:\n$sql\n\n"
				. "Params:\n$paramsArray\n\n";
		
		if (function_exists('xdebug_get_function_stack')) {
			$str .= Z_Array::array2string(xdebug_get_function_stack());
		}
		
		throw new Exception($str, $errno);
	}
	
	
	public static function close() {
		$instance = self::getInstance();
		
		if (isset($instance->link) && is_resource($instance->link)) {
			$instance->link->closeConnection();
			unset($instance->link);
		}
		
		unset($instance);
	}
	
	public function __destruct() {
		$this->close();
	}
}


class Zotero_DB_Statement extends Zend_Db_Statement_Mysqli {
	private $sql;
	
	public function __construct($db, $sql) {
		parent::__construct($db, $sql);
		$this->sql = $sql;
	}
	
	public function __get($name) {
		switch ($name) {
			case 'sql':
				return $this->$name;
		}
		trigger_error("Undefined property '$name' in __get()", E_USER_NOTICE);
		return null;
	}
}


class Z_SessionsDB extends Zotero_DB {
	protected function dbConnect() {
		$this->db = 'sessions';
		return Zotero_DBConnectAuth('sessions');
	}
	
	public function __destruct() {
		// Fix for new teardown order >PHP 5.0.5
		// See http://bugs.php.net/bug.php?id=33772 
		session_write_close();
		parent::__destruct();
	}
}
?>
