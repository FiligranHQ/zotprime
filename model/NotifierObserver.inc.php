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

class Zotero_NotifierObserver {
	private static $messageReceivers = [];
	
	public static function init($messageReceiver=null) {
		Zotero_Notifier::registerObserver(
			__CLASS__,
			["library", "publications", "apikey-library"],
			"NotifierObserver"
		);
		
		// Send notifications to SNS by default
		self::$messageReceivers[] = function ($topic, $message) {
			$sns = Z_Core::$AWS->createSns();
			$sns->publish([
				'TopicArn' => $topic,
				'Message' => $message
			]);
		};
	}
	
	
	public static function addMessageReceiver($messageReceiver) {
		self::$messageReceivers[] = $messageReceiver;
	}
	
	
	public static function notify($event, $type, $ids, $extraData) {
		if (empty(Z_CONFIG::$SNS_TOPIC_STREAM_EVENTS)) {
			error_log('WARNING: Z_CONFIG::$SNS_TOPIC_STREAM_EVENTS not set '
				. '-- skipping stream notifications');
			return;
		}
		
		if ($type == "library" || $type == "publications") {
			switch ($event) {
			case "modify":
				$event = "topicUpdated";
				break;
			
			case "delete":
				$event = "topicDeleted";
				break;
			
			default:
				return;
			}
			
			$entries = [];
			foreach ($ids as $id) {
				$libraryID = $id;
				// For most libraries, get topic from URI
				if ($event != 'topicDeleted') {
					// Convert 'http://zotero.org/users/...' to '/users/...'
					$topic = str_replace(
						Zotero_URI::getBaseURI(), "/", Zotero_URI::getLibraryURI($libraryID)
					);
					if ($type == 'publications') {
						$topic .= '/publications';
					}
				}
				// For deleted libraries (groups), the URI-based method fails,
				// so just build from parts
				else {
					$topic = '/' . Zotero_Libraries::getType($libraryID) . "s/"
					. Zotero_Libraries::getLibraryTypeID($libraryID);
				}
				$message = [
					"event" => $event,
					"topic" => $topic
				];
				if (!empty($extraData[$id])) {
					foreach ($extraData[$id] as $key => $val) {
						$message[$key] = $val;
					}
				}
				foreach (self::$messageReceivers as $receiver) {
					$receiver(
						Z_CONFIG::$SNS_TOPIC_STREAM_EVENTS,
						json_encode($message, JSON_UNESCAPED_SLASHES)
					);
				}
			}
		}
		else if ($type == "apikey-library") {
			switch ($event) {
			case "add":
				$event = "topicAdded";
				break;
			
			case "remove":
				$event = "topicRemoved";
				break;
			
			default:
				return;
			}
			
			$entries = [];
			foreach ($ids as $id) {
				list($apiKey, $libraryID) = explode("-", $id);
				// Get topic from URI
				$topic = str_replace(
					Zotero_URI::getBaseURI(), "/", Zotero_URI::getLibraryURI($libraryID)
				);
				$message = [
					"event" => $event,
					"apiKey" => $apiKey,
					"topic" => $topic
				];
				if (!empty($extraData[$id])) {
					foreach ($extraData[$id] as $key => $val) {
						$message[$key] = $val;
					}
				}
				self::send($message);
			}
		}
	}
	
	
	private static function send($message) {
		$message = json_encode($message, JSON_UNESCAPED_SLASHES);
		Z_Core::debug("Sending notification: " . $message);
		foreach (self::$messageReceivers as $receiver) {
			$receiver(Z_CONFIG::$SNS_TOPIC_STREAM_EVENTS, $message);
		}
	}
}
