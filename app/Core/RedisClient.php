<?php

namespace App\Core;

use App\Config\Env;
use Redis;
use Throwable;

class RedisClient
{
    private static ?Redis $client = null;

    public static function getInstance(): ?Redis
    {
        if (self::$client === null) {
            try {
                if (extension_loaded('redis')) {
                    $redis = new Redis();
                    $host = Env::get('REDIS_HOST', '127.0.0.1');
                    $port = (int) Env::get('REDIS_PORT', 6379);
                    $connected = $redis->connect($host, $port, 2.0);
                    if ($connected) {
                        $password = Env::get('REDIS_PASSWORD');
                        if ($password) {
                            $redis->auth($password);
                        }
                        self::$client = $redis;
                    }
                }
            } catch (Throwable $e) {
                // Redis is optional fallback; application will degrade gracefully if connection fails
                self::$client = null;
            }
        }

        return self::$client;
    }

    public static function get(string $key): mixed
    {
        $redis = self::getInstance();
        if (!$redis) {
            return null;
        }
        $val = $redis->get($key);
        return $val !== false ? json_decode($val, true) ?? $val : null;
    }

    public static function set(string $key, mixed $value, int $ttlSeconds = 3600): bool
    {
        $redis = self::getInstance();
        if (!$redis) {
            return false;
        }
        $val = is_array($value) || is_object($value) ? json_encode($value) : (string)$value;
        return $redis->setex($key, $ttlSeconds, $val);
    }

    public static function delete(string $key): bool
    {
        $redis = self::getInstance();
        if (!$redis) {
            return false;
        }
        return (bool) $redis->del($key);
    }
}
