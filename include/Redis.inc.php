<?php
class Z_Redis {
	private static $links = [];
	
	/**
	 * @param string $name
	 * @return Redis|null (Redis - success, null - failure)
	 */
	public static function get($name = 'default') {
		if (isset(self::$links[$name])) {
			return self::$links[$name];
		}
		
		if (!isset(Z_CONFIG::$REDIS_HOSTS)) {
			Z_Core::logError('Warning: $REDIS_HOSTS is not set');
			return null;
		}
		
		if (!isset(Z_CONFIG::$REDIS_HOSTS[$name])) {
			return null;
		}
		
		// Host format can be "host" or "host:port"
		$parts = explode(':', Z_CONFIG::$REDIS_HOSTS[$name]);
		$host = $parts[0];
		$port = isset($parts[1]) ? $parts[1] : 6379;
		// Set up new phpredis instance for this host
		$redis = new Redis();
		try {
			// 1s connection timeout, 100ms retry interval
			if (!$redis->pconnect($host, $port, 1, NULL, 100)) {
				throw new Exception("Redis connection to {$host}:{$port} failed");
			}
			
			// 1s read timeout
			$redis->setOption(Redis::OPT_READ_TIMEOUT, 1);
			// No serializer for now, because it's difficult to
			// deserialize in other environments
			$redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
			if (!empty(Z_CONFIG::$REDIS_PREFIX)) {
				$redis->setOption(Redis::OPT_PREFIX, Z_CONFIG::$REDIS_PREFIX);
			}
		}
		catch (Exception $e) {
			Z_Core::logError($e);
			// Cache connection failure
			return self::$links[$name] = null;
		}
		return self::$links[$name] = $redis;
	}
}
