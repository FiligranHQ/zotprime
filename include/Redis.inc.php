<?php
class Z_Redis {
	private static $links = [];
	
	public static function get($name='default') {
		if (!isset(self::$links[$name])) {
			if (!isset(Z_CONFIG::$REDIS_HOSTS[$name])) {
				throw new Exception("Redis host '$name' not defined");
			}
			
			// Host format can be "host" or "host:port"
			$parts = explode(':', Z_CONFIG::$REDIS_HOSTS[$name]);
			$host = $parts[0];
			$port = isset($parts[1]) ? $parts[1] : null;
			
			// Set up new phpredis instance for this host
			self::$links[$name] = new Redis();
			if ($port) {
				self::$links[$name]->pconnect($host, $port);
			}
			else {
				self::$links[$name]->pconnect($host);
			}
			self::$links[$name]->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
			if (!Z_CONFIG::$REDIS_PREFIX) {
				self::$links[$name]->setOption(Redis::OPT_PREFIX, Z_CONFIG::$REDIS_PREFIX);
			}
		}
		return self::$links[$name];
	}
}
