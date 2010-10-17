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

class Z_Mongo {
	private $mongo;
	private $dbName;
	private $db;
	private $connected = false;
	private $collections;
	
	public function __construct($server, $options, $dbName) {
		$this->mongo = new Mongo($server, $options);
		$this->dbName = $dbName;
	}
	
	
	// TODO: document extensions
	public function __call($origMethod, $arguments) {
		if (empty($arguments[0])) {
			throw new Exception("Collection name not provided");
		}
		
		if (empty($arguments[1])) {
			switch ($origMethod) {
				// removeAll() takes only the collection name
				case 'removeAll':
					break;
				
				default:
					throw new Exception("Data not provided");
			}
		}
		
		switch ($origMethod) {
			case 'batchInsert':
			case 'find':
			case 'findOne':
			case 'insert':
			case 'update':
				$method = $origMethod;
				break;
			
			case 'remove':
				$method = $origMethod;
				if (empty($arguments[1])) {
					throw new Exception("Remove all not allowed via remove()");
				}
				break;
			
			case 'removeAll':
				$method = 'remove';
				// removeAll() takes options as second parameter instead of third
				if (isset($arguments[2])) {
					throw new Exception("removeAll() takes only 1 parameter");
				}
				if (!isset($arguments[1])) {
					$arguments[1] = array();
				}
				$arguments[2] = $arguments[1];
				$arguments[1] = array();
				break;
			
			// Custom
			case 'batchInsertSafe':
			case 'batchInsertIgnoreSafe':
				if ($origMethod == 'batchInsertSafe') {
					$method = 'batchInsert';
				}
				if (!isset($arguments[2])) {
					$arguments[2] = array();
				}
				$arguments[2]['safe'] = true;
				break;
			
			case 'insertSafe':
				$method = 'insert';
				if (!isset($arguments[2])) {
					$arguments[2] = array();
				}
				$arguments[2]['safe'] = true;
				break;
			
			case 'valueQuery':
				$method = 'findOne';
				if (empty($arguments[2])) {
					throw new Exception("Field not provided");
				}
				// Wrap single field in an array
				$arguments[2] = array($arguments[2]);
				break;
			
			default:
				throw new Exception("Unsupported Z_Mongo method '$origMethod'");
		}
		
		// For some methods we allow _id to be passed as second parameter
		// instead of an array
		switch ($origMethod) {
			case 'findOne':
			case 'remove':
			case 'update':
			case 'valueQuery':
				if (is_scalar($arguments[1])) {
					$arguments[1] = array("_id" => $arguments[1]);
				}
				break;
		}
		
		$this->connect();
		
		// Make sure collection exists, since we don't allow collection creation
		$collectionName = $arguments[0];
		if (empty($this->collections[$collectionName])) {
			throw new Exception("Collection '$collectionName' does not exist");
		}
		$col = $this->db->$collectionName;
		array_shift($arguments);
		
		// Insert-or-ignore methods
		switch ($origMethod) {
			case 'batchInsertIgnoreSafe':
				$results = array();
				$docs = $arguments[0];
				
				foreach ($docs as $doc) {
					try {
						$col->insert($doc, $arguments[1]);
					}
					catch (MongoCursorException $e) {
						// Code doesn't currently work
						//if ($e->getCode() == 11000) {
						if (strpos($e->getMessage(), 'E11000 duplicate key error index') !== false) {
							continue;
						}
						throw ($e);
					}
				}
				
				/*
				$moreDocs = true;
				while ($moreDocs) {
					try {
						$col->batchInsert($docs, $arguments[1]);
					}
					catch (MongoCursorException $e) {
						// Code doesn't currently work
						//if ($e->getCode() == 11000) {
						if (strpos($e->getMessage(), 'E11000 duplicate key error index') !== false) {
							// Let's hope that the error message format doesn't change
							preg_match('/dup key: { : "([^"]+)" }/', $e->getMessage(), $matches);
							
							// Documents inserted already stay put,
							// so just continue with remaining documents
							if ($matches) {
								$dupeKey = $matches[1];
								for ($i=0,$len=sizeOf($docs); $i<$len; $i++) {
									if ($i == $len-1) {
										// No more documents
										$moreDocs = false;
										continue 2;
									}
									if ($docs[$i]["_id"] == $dupeKey) {
										$docs = array_slice($docs, $i+1);
										continue 2;
									}
								}
								
								throw new Exception("Dupe key $dupeKey not found in insert documents");
							}
							
							throw new Exception("Dupe key not found in error message");
						}
						throw ($e);
					}
					break;
				}
				*/
				
				return true;
		}
		
		$result = call_user_func_array(array($col, $method), $arguments);
		
		if (!$result) {
			return false;
		}
		
		switch ($origMethod) {
			case 'valueQuery':
				// Return just the field that was requested
				return $result[$arguments[1][0]];
			
			default:
				return $result;
		}
	}
	
	
	/**
	 * Used by unit tests
	 */
	public function resetTestTable() {
		$this->connect();
		$this->db->test->drop();
		$this->db->createCollection("test");
	}
	
	
	/**
	 * Used by unit tests
	 */
	public function dropTestTable() {
		$this->connect();
		$this->db->test->drop();
	}
	
	
	/**
	 * Connect to the database, if necessary
	 */
	private function connect() {
		if ($this->connected) {
			return;
		}
		$this->mongo->connect();
		$this->db = $this->mongo->selectDB($this->dbName);
		
		// Store the list of collections, since we don't allow collection creation
		// and need to validate passed collections
		$collections = $this->db->listCollections();
		foreach ($collections as $collection) {
			$this->collections[$collection->getName()] = true;
		}
		$this->connected = true;
	}
}
?>
