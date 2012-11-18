<?
class Zotero_Processors {
	public static function notifyProcessors($mode, $signal="NEXT") {
		$sql = "SELECT INET_NTOA(addr) AS addr, port FROM processorDaemons WHERE mode=?";
		$daemons = Zotero_DB::query($sql, array($mode));
		
		if (!$daemons) {
			Z_Core::logError("No $mode processor daemons found");
			return;
		}
		
		foreach ($daemons as $daemon) {
			self::notifyProcessor($mode, $signal, $daemon['addr'], $daemon['port']);
		}
	}
	
	
	public static function notifyProcessor($mode, $signal, $addr, $port) {
		switch ($mode) {
			case 'download':
			case 'upload':
			case 'error':
				break;
			
			default:
				throw new Exception("Invalid processor mode '$mode'");
		}
		
		if (!$addr) {
			throw new Exception("Host address not provided");
		}
		
		Z_Core::debug("Notifying $mode processor $addr with signal $signal");
		
		$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		$success = socket_sendto($socket, $signal, strlen($signal), MSG_EOF, $addr, $port);
		if (!$success) {
			$code = socket_last_error($socket);
			throw new Exception(socket_strerror($code));
		}
	}
	
	
	public static function register($mode, $addr, $port) {
		$sql = "INSERT INTO processorDaemons (mode, addr, port) VALUES (?, INET_ATON(?), ?)
				ON DUPLICATE KEY UPDATE port=?, lastSeen=NOW()";
		Zotero_DB::query($sql, array($mode, $addr, $port, $port));
	}
	
	
	public static function unregister($mode, $addr, $port) {
		$sql = "DELETE FROM processorDaemons WHERE mode=? AND addr=INET_ATON(?) AND port=?";
		Zotero_DB::query($sql, array($mode, $addr, $port));
	}
}
?>
