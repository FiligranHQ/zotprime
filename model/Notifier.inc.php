<?php
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2013 Center for History and New Media
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

class Zotero_Notifier {
	private static $locked = false;
	private static $queue = array();
	private static $inTransaction = false;
	private static $observers = array();
	
	private static $types = array(
		'collection', 'creator', 'search', 'share', 'share-items', 'item',
		'collection-item', 'item-tag', 'tag', 'group', 'trash', 'relation',
		'library'
	);
	
	/**
	 * @param $obj Class class with method notify($event, $type, $ids, $extraData)
	 * @param $types [array] Types to receive notications for
	 */
	public static function registerObserver($observer, $types=array()) {
		if (is_scalar($types)) {
			$types = array($types);
		}
		foreach ($types as $type) {
			if (!in_array($type, self::$types)) {
				throw new Exception("Invalid type '$type'");
			}
		}
		
		$len = 2;
		$tries = 10;
		do {
			// Increase the hash length if we can't find a unique key
			if (!$tries) {
				$len++;
				$tries = 5;
			}
			
			$hash = Zotero_Utilities::randomString($len, 'mixed');
			$tries--;
		}
		while (isset(self::$observers[$hash]));
		
		Z_Core::debug('Registering observer for '
			. ($types ? '[' . implode(",", $types) . ']' : 'all types')
			. ' in notifier with hash ' . $hash . "'", 4);
		self::$observers[$hash] = array(
			"observer" => $observer,
			"types" => $types
		);
		return $hash;
	}
	
	
	public static function unregisterObserver($hash) {
		Z_Core::debug("Unregistering observer in notifier with hash '$hash'", 4);
		unset(self::$observers[$hash]);
	}
	
	
	/**
	* Trigger a notification to the appropriate observers
	*
	* Possible values:
	*
	* 	event: 'add', 'modify', 'delete', 'move' ('c', for changing parent),
	*		'remove' (ci, it), 'refresh', 'redraw', 'trash'
	* 	type - 'collection', 'search', 'item', 'collection-item', 'item-tag', 'tag', 'group', 'relation'
	* 	ids - single id or array of ids
	*
	* Notes:
	*
	* - If event queuing is on, events will not fire until commit() is called
	* unless $force is true.
	*
	* - New events and types should be added to the order arrays in commit()
	**/
	public static function trigger($event, $type, $ids, $extraData=null, $force=false) {
		if (!in_array($type, self::$types)) {
			throw new Exception("Invalid type '$type'");
		}
		
		if (is_scalar($ids)) {
			$ids = array($ids);
		}
		
		$queue = self::$inTransaction && !$force;
		
		Z_Core::debug("Notifier trigger('$event', '$type', [" . implode(",", $ids) . '])'
			. ($queue ? " queued" : " called " . "[observers: " . sizeOf(self::$observers) . "]"));
		
		// Merge with existing queue
		if ($queue) {
			if (!isset(self::$queue[$type])) {
				self::$queue[$type] = array();
			}
			if (!isset(self::$queue[$type][$event])) {
				self::$queue[$type][$event] = array(
					"ids" => array(),
					"data" => array()
				);
			}
			
			// Merge ids
			self::$queue[$type][$event]['ids'] = array_merge(
				self::$queue[$type][$event]['ids'], $ids
			);
			
			// Merge extraData keys
			if ($extraData) {
				foreach ($extraData as $dataID => $data) {
					self::$queue[$type][$event]['data'][$dataID] = $data;
				}
			}
			
			return true;
		}
		
		foreach (self::$observers as $hash => $observer) {
			Z_Core::debug("Calling notify('$event') on observer with hash '$hash'", 4);
			
			// Find observers that handle notifications for this type (or all types)
			if (!$observer['types'] || in_array($type, $observer['types'])) {
				// Catch exceptions so all observers get notified even if
				// one throws an error
				try {
					call_user_func_array(
						array($observer['observer'], "notify"),
						array($event, $type, $ids, $extraData)
					);
				}
				catch (Exception $e) {
					error_log($e);
				}
			}
		}
		
		return true;
	}
	
	
	/*
	 * Begin queueing event notifications (i.e., don't notify the observers)
	 *
	 * $lock will prevent subsequent commits from running the queue until
	 * commit() is called with $unlock set to true
	 */
	public static function begin($lock=false) {
		if ($lock && !self::$locked) {
			self::$locked = true;
			$unlock = true;
		}
		else {
			$unlock = false;
		}
		
		if (self::$inTransaction) {
			//Zotero.debug("Notifier queue already open", 4);
		}
		else {
			Z_Core::debug("Beginning Notifier event queue");
			self::$inTransaction = true;
		}
		
		return $unlock;
	}
	
	
	/*
	 * Send notifications for ids in the event queue
	 *
	 * If the queue is locked, notifications will only run if $unlock is true
	 */
	public static function commit($unlock=null) {
		if (!self::$queue) {
			return;
		}
		
		// If there's a lock on the event queue and $unlock isn't given, don't commit
		if (($unlock === null && self::$locked) || $unlock === false) {
			//Zotero.debug("Keeping Notifier event queue open", 4);
			return;
		}
		
		$runQueue = array();
		
		$order = array(
			'library',
			'collection',
			'search',
			'item',
			'collection-item',
			'item-tag',
			'tag'
		);
		uasort(self::$queue, function ($a, $b) {
			return array_search($b, $order) - array_search($a, $order);
		});
		
		$order = array('add', 'modify', 'remove', 'move', 'delete', 'trash');
		$totals = '';
		foreach (array_keys(self::$queue) as $type) {
			if (!isset($runQueue[$type])) {
				$runQueue[$type] = array();
			}
			
			asort(self::$queue[$type]);
			
			foreach (self::$queue[$type] as $event => $obj) {
				$runObj = array(
					'ids' => array(),
					'data' => array()
				);
				
				// Remove redundant ids
				for ($i = 0, $len = sizeOf($obj['ids']); $i < $len; $i++) {
					$id = $obj['ids'][$i];
					$data = isset($obj['data'][$id]) ? $obj['data'][$id] : null;
					
					if (!in_array($id, $runObj['ids'])) {
						$runObj['ids'][] = $id;
						$runObj['data'][$id] = $data;
					}
				}
				
				if ($runObj['ids'] || $event == 'refresh') {
					$totals .= " [$event-$type: " . sizeOf($runObj['ids']) . "]";
				}
				
				$runQueue[$type][$event] = $runObj;
			}
		}
		
		self::reset();
		
		if ($totals) {
			Z_Core::debug("Committing Notifier event queue" . $totals);
			
			foreach (array_keys($runQueue) as $type) {
				foreach ($runQueue[$type] as $event => $obj) {
					if (sizeOf($obj['ids']) || $event == 'refresh') {
						self::trigger(
							$event, $type, $obj['ids'], $obj['data'], true
						);
					}
				}
			}
		}
	}
	
	
	/*
	 * Reset the event queue
	 */
	public static function reset() {
		Z_Core::debug("Resetting Notifier event queue");
		self::$locked = false;
		self::$queue = array();
		self::$inTransaction = false;
	}
}
