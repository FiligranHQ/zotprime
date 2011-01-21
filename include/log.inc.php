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

// Thrift/Scribe
$THRIFT_ROOT = Z_ENV_BASE_PATH . 'include/Thrift';

class Z_Log {
	/**
	 * Log a single message
	 */
	public static function log($category, $message) {
		self::logm(
			array(
				array($category, $message)
			)
		);
	}
	
	/**
	 * Log an array of category/message pairs
	 */
	public static function logm($categoryMessagePairs) {
		// Parse timestamp into date and milliseconds
		$ts = microtime(true);
		if (strpos($ts, '.') === false) {
			$ts .= '.';
		}
		list($ts, $msec) = explode('.', $ts);
		$date = new DateTime(date(DATE_RFC822, $ts));
		$date->setTimezone(new DateTimeZone(Z_CONFIG::$LOG_TIMEZONE));
		$date = $date->format('Y-m-d H:i:s') . '.' . str_pad($msec, 4, '0');
		
		// Get short hostname
		$host = gethostname();
		if (strpos($host, '.') !== false) {
			$host = substr($host, 0, strpos($host, '.'));
		}
		
		$messages = array();
		foreach ($categoryMessagePairs as $pair) {
			$messages[] = array(
				'category' => $pair[0],
				'message' => "$date [$host] " . $pair[1]
			);
		}
		
		if (Z_CONFIG::$LOG_TO_SCRIBE) {
			self::logToScribe($messages);
		}
		else {
			//self::logToStdOut($messages);
			self::logToErrorLog($messages);
		}
	}
	
	private static function logToErrorLog($messages) {
		foreach ($messages as $message) {
			error_log($message['message']);
		}
	}
	
	private static function logToStdOut($messages) {
		foreach ($messages as $message) {
			echo $message['message'] . "\n";
		}
	}
	
	private static function logToScribe($messages) {
		GLOBAL $THRIFT_ROOT;
		require_once($THRIFT_ROOT . '/Thrift.php');
		require_once($THRIFT_ROOT . '/transport/TSocket.php');
		require_once($THRIFT_ROOT . '/transport/TFramedTransport.php');
		require_once($THRIFT_ROOT . '/protocol/TBinaryProtocol.php');
		require_once('Scribe.php');
		
		$entries = array();
		foreach ($messages as $message) {
			$entries[] = new LogEntry($message);
		}
		
		$socket = new TSocket(Z_CONFIG::$LOG_ADDRESS, Z_CONFIG::$LOG_PORT, true);
		$transport = new TFramedTransport($socket);
		$protocol = new TBinaryProtocol($transport, false, false);
		$scribe = new scribeClient($protocol, $protocol);
		 
		$transport->open();
		$scribe->Log($entries);
		$transport->close();
	}
}

?>