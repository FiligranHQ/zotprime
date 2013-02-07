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
	public static function init() {
		// TEMP: disabled
		//Zotero_Notifier::registerObserver(__CLASS__, array("item", "library"));
	}
	
	
	public static function notify($event, $type, $ids, $extraData) {
		if ($type == "item") {
			$url = Z_CONFIG::$SQS_QUEUE_URL_PREFIX
				. Z_CONFIG::$SQS_QUEUE_ITEM_UPDATES;
			
			$messages = array();
			foreach ($ids as $libraryKey) {
				list($libraryID, $key) = explode("/", $libraryKey);
				$message = array(
					"libraryID" => (int) $libraryID,
					"key" => $key,
					"event" => $event
				);
				if (!empty($extraData[$libraryKey])) {
					$message["data"] = $extraData[$libraryKey];
				}
				$messages[] = json_encode($message);
			}
			Z_SQS::sendBatch($url, $messages);
		}
		else if ($type == "library") {
			$url = Z_CONFIG::$SQS_QUEUE_URL_PREFIX
				. Z_CONFIG::$SQS_QUEUE_LIBRARY_UPDATES;
			
			$messages = array();
			foreach ($ids as $libraryID) {
				$message = array(
					"libraryID" => (int) $libraryID,
					"event" => $event
				);
				if (!empty($extraData[$libraryID])) {
					$message["data"] = $extraData[$libraryID];
				}
				$messages[] = json_encode($message);
			}
			Z_SQS::sendBatch($url, $messages);
		}
	}
}
