<?php
namespace Manomite\Engine\Security;

use Symfony\Component\RateLimiter\{
    RateLimiterFactory,
    Storage\InMemoryStorage,
    RateLimit as RT
};
use Manomite\Model\Reflect;

class Ratelimit
{
    private $storage;
    private $db;
    private $limiters = [];

    public function __construct()
    {
        $this->storage = new InMemoryStorage();
        $this->db = new Reflect('Security');
        $this->initializeLimiters();
    }

    private function initializeLimiters()
    {
        // Initialize limiters using constants
        $this->createLimiter('GET', RATE_LIMIT_GET);
        $this->createLimiter('POST', RATE_LIMIT_POST);
        $this->createLimiter('OPTIONS', RATE_LIMIT_OPTIONS);
        $this->createLimiter('DEFAULT', RATE_LIMIT_DEFAULT);
    }

    private function createLimiter(string $method, int $limit = 10, int $interval = 60): void
    {
        $factory = new RateLimiterFactory([
            'id' => 'method_' . strtolower($method),
            'policy' => 'token_bucket',
            'limit' => $limit,
            'rate' => ['interval' => "$interval seconds"]
        ], $this->storage);
        $this->limiters[$method] = $factory;
    }

    public function limit(string $identifier, string $action, ?int $limit = null, int $interval = 60): bool
    {
        // Determine the method from the action (e.g., GET, POST, etc.)
        $method = strtoupper(explode('_', $action)[1] ?? 'DEFAULT');

        // Use the provided limit and interval if available, otherwise use the default
        if ($limit !== null || $interval !== 60) {
            $this->createLimiter($method, $limit ?? $this->getRateLimit($method), $interval);
        }

        // Get the appropriate limiter
        $limiter = $this->limiters[$method] ?? $this->limiters['DEFAULT'];

        // Create consumer for this specific identifier and action
        $key = $identifier . '_' . $action;
        $rateLimiter = $limiter->create($key);

        // Load existing state from FileDB if available
        $currentLimit = $limit ?? $this->getRateLimit($method);
        $currentInterval = $interval;
        $remaining = $currentLimit;
        $reset = time() + $currentInterval;

        if ($this->db->rateLimitExists($key)) {
            $data = $this->db->getRateLimit($key);
            if ($data['reset'] > time()) {
                // State is still valid, use stored values
                $remaining = $data['remaining'];
                $reset = $data['reset'];
            } else {
                // Reset the state if the reset time has passed
                $remaining = $currentLimit;
                $reset = time() + $currentInterval;
            }
        }

        // Check if the request is allowed
        $isAccepted = $remaining > 0;

        // Update remaining tokens
        if ($isAccepted) {
            $remaining--;
        }

        // Store the updated state
        $this->storeRateLimitInfo($key, $currentLimit, $remaining, $reset);

        return $isAccepted;
    }

    public function getRateLimitInfo(string $identifier, string $action): array
    {
        $key = $identifier . '_' . $action;
        return $this->db->rateLimitExists($key) ? $this->db->getRateLimit($key) : [
            'limit' => $this->getRateLimit('DEFAULT'),
            'remaining' => $this->getRateLimit('DEFAULT'),
            'reset' => time() + 60
        ];
    }

    private function storeRateLimitInfo(string $key, int $limit, int $remaining, int $reset): void
    {
        $info = [
            'limit' => $limit,
            'remaining' => $remaining,
            'reset' => $reset
        ];

        if ($this->db->rateLimitExists($key)) {
            $this->db->updateRateLimit($key, $info);
        } else {
            $this->db->createRateLimit($key, $info);
        }
    }

    public function reset(string $identifier, string $action)
    {
        $key = $identifier . '_' . $action;
        if ($this->db->rateLimitExists($key)) {
            return $this->db->deleteRateLimit($key);
        }
        return false;
    }

    private function getRateLimit(string $method): int
    {
        return $this->limiters[$method]?->getOptions()['limit'] ?? RATE_LIMIT_DEFAULT;
    }
}