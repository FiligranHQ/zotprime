<?php
/*
	The code is based on Stripe request limiter:
	https://stripe.com/blog/rate-limiters

	Rate limiter is the primary safeguard that rejects
	requests that are called too often. Supports bursts.

	Concurrent request limiter is the secondary safeguard,
	targeted to slow requests.
	The higher server load is, the slower requests become,
	and therefore more requests are automatically rejected.

	Notice: Don't use PHP serializer for the redis connection,
	because data can't be deserialized inside lua scripts.
*/

class Z_RequestLimiter {
	const REDIS_PREFIX = '';
	// Lua script is from https://gist.github.com/ptarjan/e38f45f2dfe601419ca3af937fff574d
	const RATE_LIMITER_LUA = '
		local tokens_key = KEYS[1]
		local timestamp_key = KEYS[2]
		
		local rate = tonumber(ARGV[1])
		local capacity = tonumber(ARGV[2])
		local now = tonumber(ARGV[3])
		
		local fill_time = capacity/rate
		local ttl = math.floor(fill_time*2)
		
		local last_tokens = tonumber(redis.call("get", tokens_key))
		if last_tokens == nil then
			last_tokens = capacity
		end
		
		local last_refreshed = tonumber(redis.call("get", timestamp_key))
		if last_refreshed == nil then
			last_refreshed = 0
		end
		
		local delta = math.max(0, now-last_refreshed)
		local filled_tokens = math.min(capacity, last_tokens+(delta*rate))
		local allowed = filled_tokens >= 1
		local new_tokens = filled_tokens
		if allowed then
			new_tokens = filled_tokens - 1
		end
		
		redis.call("setex", tokens_key, ttl, new_tokens)
		redis.call("setex", timestamp_key, ttl, now)
		
		return { allowed, new_tokens }';
	
	// Lua script is based on https://gist.github.com/ptarjan/e38f45f2dfe601419ca3af937fff574d
	const CONCURRENCY_LIMITER_LUA = '
		local key = KEYS[1]
		
		local capacity = tonumber(ARGV[1])
		local id = ARGV[2]
		local timestamp = tonumber(ARGV[3])
		local ttl = tonumber(ARGV[4])
		
		local count = redis.call("zcard", key)
		local allowed = count < capacity
		
		if allowed then
			redis.call("zadd", key, timestamp, id)
			redis.call("expire", key, ttl)
		end
		
		return { allowed, count }';
	
	protected static $initialized = false;
	protected static $redis;
	protected static $concurrentRequest = null;
	
	public static function init() {
		if (self::$initialized) return true;
		self::$redis = Z_Redis::get('request-limiter');
		if (!self::$redis) return false;
		return self::$initialized = true;
	}
	
	public static function isInitialized() {
		return self::$initialized;
	}
	
	public static function isConcurrentRequestActive() {
		return !!self::$concurrentRequest;
	}
	
	/**
	 * @param $params {bucket, capacity, replenishRate}
	 * @return bool|null (true - allowed, false - not allowed, null - error)
	 */
	public static function checkBucketRate($params) {
		if (!isset($params['bucket'], $params['capacity'], $params['replenishRate'])) {
			Z_Core::logError('Warning: Misconfigured request rate limit');
			return null;
		}
		$prefix = self::REDIS_PREFIX .'rrl:' . $params['bucket'];
		$keys = [$prefix . '.tk', $prefix . '.ts'];
		$args = [$params['replenishRate'], $params['capacity'], time()];
		try {
			$res = self::evalLua(self::RATE_LIMITER_LUA, $keys, $args);
		}
		catch (Exception $e) {
			Z_Core::logError($e);
			return null;
		}
		return !!$res[0];
	}
	
	/**
	 * Call this function before the actual request logic
	 * @param $params {bucket, capacity, ttl}
	 * @return bool|null (true - allowed, false - not allowed, null - error)
	 */
	public static function beginConcurrentRequest($params) {
		if (!isset($params['bucket'], $params['capacity'], $params['ttl'])) {
			Z_Core::logError('Warning: Misconfigured concurrent request limit');
			return null;
		}
		$id = Zotero_Utilities::randomString(5, 'mixed');
		$timestamp = time();
		$key = self::REDIS_PREFIX . 'crl:' . $params['bucket'];
		$args = [$params['capacity'], $id, $timestamp, $params['ttl']];
		try {
			// Clear out old requests that got lost (or taking longer than TTL)
			$numRemoved = self::$redis->zRemRangeByScore($key, '-inf', $timestamp - $params['ttl']);
			if ($numRemoved > 0) {
				Z_Core::logError("Warning: Timed out concurrent requests found: {$numRemoved}");
			}
			$res = self::evalLua(self::CONCURRENCY_LIMITER_LUA, [$key], $args);
		}
		catch (Exception $e) {
			Z_Core::logError($e);
			return null;
		}
		
		self::$concurrentRequest = [
			'bucket' => $params['bucket'],
			'id' => $id
		];
		return !!$res[0];
	}
	
	/**
	 * Call this function after the actual request logic
	 * @return bool|null (true - success, false - nothing to finish, null - error)
	 */
	public static function finishConcurrentRequest() {
		if (!self::$concurrentRequest) return false;
		$key = self::REDIS_PREFIX . 'crl:' . self::$concurrentRequest['bucket'];
		try {
			if (!self::$redis->zRem($key, self::$concurrentRequest['id'])) {
				throw new Exception('Failed to remove an element from a sorted set');
			}
		}
		catch (Exception $e) {
			Z_Core::logError($e);
			return null;
		}
		return true;
	}
	
	private static function evalLua($lua, $keys, $args) {
		$sha1 = sha1($lua);
		$res = self::$redis->evalSha($sha1, array_merge($keys, $args), count($keys));
		if (!$res) {
			Z_Core::logError('Warning: Failed to eval Lua script by SHA1');
			$res = self::$redis->eval($lua, array_merge($keys, $args), count($keys));
			if (!$res) {
				throw new Exception('Failed to eval Lua script');
			}
		}
		return $res;
	}
}
