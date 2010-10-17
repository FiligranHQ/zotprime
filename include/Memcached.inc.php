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

// Client subclass to add prefix to all keys (set to the server port below)
class Z_MemcachedClientLocal {
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
		
		$this->prefix = $prefix . '_';
		
		if (!empty($config['disabled'])){
			$this->disabled = true;
			return false;
		}
		
		$this->client = new Memcache;
		
		// Add server connections
		foreach ($config['servers'] as $server) {
			$hpw = explode(':', $server);
			$added = $this->client->addServer(
				// host
				$hpw[0],
				// port
				empty($hpw[1]) ? 11211 : $hpw[1],
				// persistent
				true,
				// weight
				empty($hpw[2]) ? 1 : $hpw[2]
			);
		}
	}
	
	
	public function get($keys) {
		if ($this->disabled) {
			return false;
		}
		
		if (is_array($keys)){
			for ($i=0; $i<sizeOf($keys); $i++) {
				$keys[$i] = $this->prefix . $keys[$i];
			}
		}
		else if (is_string($keys)) {
			$keys = $this->prefix . $keys;
		}
		else {
			throw new Exception('$keys must be an array or string');
		}
		
		return $this->client->get($keys);
	}
	
	
	public function set($key, $val, $exptime = 0) {
		if ($this->disabled) {
			return false;
		}
		
		if ($this->queuing) {
			// If this is already set, clear the previous value to save memory
			// and mark for skipping when committing
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
		
		$success = $this->client->set($this->prefix . $key, $val, null, $exptime);
		if (!$success && !$this->errorLogged) {
			Z_Core::logError("Setting memcache value failed", "Key $key, value $val");
			$this->errorLogged = true;
		}
		return $success;
	}
	
	
	public function add($key, $val, $exptime = 0) {
		if ($this->disabled) {
			return false;
		}
		
		if ($this->queuing) {
			// If this is already set, skip, because that's what add() does
			if (isset($this->queueKeyPos[$key])) {
				return false;
			}
			
			$this->queue[] = array(
				'op' => 'add',
				'key' => $key,
				'exp' => $exptime
			);
			
			$this->queueValues[$key] = $val;
			
			return true;
		}
		
		return $this->client->add($this->prefix . $key, $val, null, $exptime);
	}
	
	
	public function delete($key, $time = 0){
		if ($this->disabled){
			return false;
		}
		return $this->client->delete($this->prefix . $key, $time);
	}
	
	
	public function replace($key, $val, $exptime = 0){
		if ($this->disabled){
			return false;
		}
		return $this->client->replace($this->prefix . $key, $val, null, $exptime);
	}
	
	
	public function increment($key, $value = 1){
		if ($this->disabled){
			return false;
		}
		return $this->client->increment($this->prefix . $key, $value);
	}
	
	
	public function decrement($key, $value = 1){
		if ($this->disabled){
			return false;
		}
		return $this->client->increment($this->prefix . $key, $value);
	}
	
	public function begin() {
		if ($this->queuing) {
			return false;
		}
		$this->queuing = true;
		return true;
	}
	
	public function commit() {
		if (!$this->queuing) {
			throw new Exception("Memcache wasn't queuing");
		}
		
		if (!$this->queue) {
			return;
		}
		
		$this->queuing = false;
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
	}
	
	public function rollback() {
		if (!$this->queuing) {
			throw new Exception("Memcache wasn't queuing");
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
