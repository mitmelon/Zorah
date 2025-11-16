<?php

namespace Manomite\Engine;
/**
 *  Cache class for fast page laoding and optimizations.
 *
 *  @author Manomite
 */
require_once __DIR__ . "/../../autoload.php";
class CacheAdapter
{
    public $adapter;

    public function __construct()
    {
        $path = SYSTEM_DIR . '/cache/';
        if (!is_dir($path)) {
            mkdir($path, 0600, true);
        }
        $this->adapter = new \Shieldon\SimpleCache\Cache('redis', $config = [
            'host' => '127.0.0.1',
            'port' => 6379,
            'user' => null,
            'pass' => null,
        ]);
        //$this->clear();
    }

    public function getCache(string $key)
    {
        if ($this->adapter->has($key)) {
            return $this->adapter->get($key);
        } else {
            return null;
        }
    }
    public function cache(string $content, string $key, ?int $ttl = null)
    {
        $ttl = $ttl ?: (int) CACHE_EXPIRE;
        $this->adapter->set($key, $content, $ttl);
        return $this->adapter->get($key);
    }
    public function clear()
    {
        return $this->adapter->clear();
    }
    public function delete(string $key)
    {
        return $this->adapter->delete($key);
    }
    public function getKey()
    {
        return $this->adapter->getKey();
    }

    /**
     * Generate a secure cache key from prefix and identifier
     * Uses SHA-256 hashing to prevent collisions and injection attacks
     * 
     * @param string $prefix Cache key prefix (e.g., 'user:token:', 'user:addr:')
     * @param string $identifier Unique identifier (e.g., auth token, address)
     * @return string Secure cache key
     */
    public function generateCacheKey(string $prefix, string $identifier): string
    {
        // Security: Sanitize the identifier to prevent cache key injection
        $sanitized = preg_replace('/[^a-zA-Z0-9_\-:]/', '', $identifier);
        
        // Use SHA-256 hash for consistent key length and collision resistance
        return $prefix . hash('sha256', $sanitized);
    }

    /**
     * Serialize data for cache storage
     * Converts arrays/objects to JSON string for Redis storage
     * 
     * @param mixed $data Data to serialize (typically array or object)
     * @return string JSON-encoded string
     */
    public function serializeForCache($data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Unserialize data from cache storage
     * Converts JSON string back to array/object
     * 
     * @param mixed $data Cached data (JSON string)
     * @return mixed|null Decoded data or null on error
     */
    public function unserializeFromCache($data)
    {
        if ($data === null || $data === '') {
            return null;
        }

        $decoded = json_decode($data, true);
        
        // Check for JSON decode errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Cache JSON decode error: " . json_last_error_msg());
            return null;
        }
        
        return $decoded;
    }

    /**
     * Invalidate multiple cache keys by patterns
     * Useful for clearing related cached data when entity is updated/deleted
     * 
     * @param array $cacheKeys Array of cache keys to invalidate
     * @return void
     */
    public function invalidateCacheKeys(array $cacheKeys): void
    {
        try {
            foreach ($cacheKeys as $key) {
                if (!empty($key)) {
                    $this->delete($key);
                }
            }
        } catch (\Exception $e) {
            // Log but don't throw - cache invalidation failures shouldn't break app
            error_log("Cache invalidation error: " . $e->getMessage());
        }
    }
}
