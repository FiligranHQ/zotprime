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

// Client subclass to add prefix to all keys and add some other behaviors
class Z_MemcachedClientLocal {
	public $requestTime = 0.0;
	
	private $client;
	private $disabled;
	private $queuing = false;
	private $queue = array();
	private $queueKeyPos = array();
	private $queueValues = array();
	private $errorLogged = false;
	
	
	public function __construct($prefix, $config) {
		if (!$prefix) {
			throw new Exception("Prefix not provided");
		}
		
		if (!empty($config['disabled'])){
			$this->disabled = true;
			return false;
		}
		
		$this->client = new Memcached($prefix);
		
		// If persistent connection isn't initialized, set it up
		if (empty($this->client->getServerList())) {
			$this->client->setOptions([
				Memcached::OPT_PREFIX_KEY => $prefix,
				Memcached::OPT_LIBKETAMA_COMPATIBLE => true,
				Memcached::OPT_BINARY_PROTOCOL => true,
				Memcached::OPT_SERIALIZER => Memcached::SERIALIZER_IGBINARY,
				Memcached::OPT_NO_BLOCK => true,
				Memcached::OPT_TCP_NODELAY => true,
				Memcached::OPT_RETRY_TIMEOUT => 10
			]);
			
			// Add server connections
			foreach ($config['servers'] as $server) {
				$hpw = explode(':', $server);
				$added = $this->client->addServer(
					// host
					$hpw[0],
					// port
					empty($hpw[1]) ? 11211 : $hpw[1],
					// weight
					empty($hpw[2]) ? 1 : $hpw[2]
				);
			}
		}
	}
	
	
	public function get($keys) {
		if ($this->disabled) {
			return false;
		}
		
		if (is_array($keys)){
			$multi = true;
		}
		else if (is_string($keys)) {
			if (!$keys) {
				throw new Exception("Memcached key not provided");
			}
			
			// Return values already set within this transaction
			if ($this->queuing && isset($this->queueValues[$keys])) {
				return $this->queueValues[$keys];
			}
			
			$multi = false;
		}
		else {
			throw new Exception('$keys must be an array or string');
		}
		
		$t = microtime(true);
		if ($multi) {
			$results = $this->client->getMulti($keys);
		}
		else {
			$results = $this->client->get($keys);
		}
		if ($this->client->getResultCode() != Memcached::RES_SUCCESS
				&& $this->client->getResultCode() != Memcached::RES_NOTFOUND) {
			error_log("Memcached error: " . $this->client->getResultMessage());
		}
		
		$this->requestTime += microtime(true) - $t;
		if (!$multi) {
			return $results;
		}
		
		if ($results === false) {
			return [];
		}
		
		// Return values already set within this transaction
		foreach (array_keys($results) as $key) {
			if ($this->queuing && isset($this->queueValues[$key])) {
				$results[$key] = $this->queueValues[$key];
			}
		}
		
		return $results;
	}
	
	
	public function set($key, $val, $exptime = 0) {
		if ($this->disabled) {
			return false;
		}
		
		if (!$key) {
			throw new Exception("Memcached key not provided");
		}
		
		if ($this->queuing) {
			// If this is already set, mark the previous position for skipping when committing
			if (isset($this->queueKeyPos[$key])) {
				$pos = $this->queueKeyPos[$key];
				$this->queue[$pos]['skip'] = true;
			}
			
			$this->queue[] = array(
				'op' => 'set',
				'key' => $key,
				'exp' => $exptime
			);
			
			$this->queueValues[$key] = $val;
			
			// Store the position in case we need to clear later
			$this->queueKeyPos[$key] = sizeOf($this->queue) - 1;
			
			return true;
		}
		
		$t = microtime(true);
		$success = $this->client->set($key, $val, $exptime);
		$this->requestTime += microtime(true) - $t;
		if (!$success && !$this->errorLogged) {
			Z_Core::logError("Setting memcache value failed for key $key: "
				. $this->client->getResultMessage()
				. " (" . $this->client->getServerByKey($key)['host'] . ")");
			$this->errorLogged = true;
		}
		return $success;
	}
	
	
	public function add($key, $val, $exptime = 0) {
		if ($this->disabled) {
			return false;
		}
		
		$t = microtime(true);
		$retVal = $this->client->add($key, $val, $exptime);
		$this->requestTime += microtime(true) - $t;
		return $retVal;
	}
	
	
	public function delete($key) {
		if ($this->disabled){
			return false;
		}
		$t = microtime(true);
		$retVal = $this->client->delete($key);
		$this->requestTime += microtime(true) - $t;
		return $retVal;
	}
	
	
	public function replace($key, $val, $exptime = 0){
		if ($this->disabled){
			return false;
		}
		$t = microtime(true);
		$retVal = $this->client->replace($key, $val, $exptime);
		$this->requestTime += microtime(true) - $t;
		return $retVal;
	}
	
	
	public function increment($key, $value = 1){
		if ($this->disabled){
			return false;
		}
		$t = microtime(true);
		$retVal = $this->client->increment($key, $value);
		$this->requestTime += microtime(true) - $t;
		return $retVal;
	}
	
	
	public function decrement($key, $value = 1){
		if ($this->disabled){
			return false;
		}
		$t = microtime(true);
		$retVal = $this->client->increment($key, $value);
		$this->requestTime += microtime(true) - $t;
		return $retVal;
	}
	
	public function begin() {
		if ($this->queuing) {
			//Z_Core::debug("Already queueing memcached transaction");
			return false;
		}
		//Z_Core::debug("Beginning memcached transaction");
		$this->queuing = true;
		return true;
	}
	
	public function commit() {
		if (!$this->queuing) {
			throw new Exception("Memcache wasn't queuing");
		}
		
		//Z_Core::debug("Committing memcached transaction");
		
		$this->queuing = false;
		
		if (!$this->queue) {
			return;
		}
		
		foreach ($this->queue as $arr) {
			if (!empty($arr['skip'])) {
				continue;
			}
			
			$op = $arr['op'];
			$key = $arr['key'];
			$val = $this->queueValues[$key];
			$exp = $arr['exp'];
			
			switch ($op) {
				case 'set':
				case 'add':
					break;
				
				default:
					throw new Exception("Unknown operation '$op'");
			}
			
			$this->$op($key, $val, $exp);
		}
		$this->queue = array();
		$this->queueKeyPos = array();
		$this->queueValues = array();
	}
	
	public function rollback() {
		if (!$this->queuing) {
			Z_Core::debug('Transaction not open in Z_MemcachedClientLocal::rollback()');
			return;
		}
		
		if (!$this->queue) {
			return;
		}
		
		$this->queuing = false;
		$this->queue = array();
		$this->queueKeyPos = array();
		$this->queueValues = array();
	}
}
?>
