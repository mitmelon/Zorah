<?php
declare(strict_types=1);
namespace Manomite\Engine\Queue\Redis;
use \Redis;

class RSMQ
{
    const MAX_DELAY = 9999999;
    /**
     * @var Redis
     */
    private $redis;

    /**
     * @var string
     */
    private $ns;

    /**
     * @var bool
     */
    private $realtime;

    /**
     * @var Util
     */
    private $util;

    /**
     * @var string
     */
    private $receiveMessageSha1;

    /**
     * @var string
     */
    private $popMessageSha1;

    /**
     * @var string
     */
    private $changeMessageVisibilitySha1;

    private $host = '127.0.0.1';
    private $port = 6379;
    private $timeout = 2.0;

    public function __construct(Redis $redis, string $ns = 'rsmq', bool $realtime = false)
    {
        $this->redis = $redis;
        $this->ns = "$ns:";
        $this->realtime = $realtime;

        $this->util = new Util();

        try {
            $this->redis->connect($this->host, $this->port, $this->timeout);
            $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, $this->timeout);
            $this->initScripts();
        } catch (\RedisException $e) {
            $this->reconnect();
        }
    }

    private function reconnect(): void {
        try {
            $this->redis = new \Redis();
            $this->redis->connect($this->host, $this->port, $this->timeout);
            $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, $this->timeout);
            $this->initScripts();
        } catch (\RedisException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function createQueue(string $name, int $vt = 30, int $delay = 0, int $maxSize = -1): bool
    {
        $this->validate([
            'queue' => $name,
            'vt' => $vt,
            'delay' => $delay,
            'maxsize' => $maxSize,
        ]);

        $key = "{$this->ns}$name:Q";

        try {
            $resp = $this->redis->time();
            $transaction = $this->redis->multi();
            $transaction->hSetNx($key, 'vt', (string)$vt);
            $transaction->hSetNx($key, 'delay', (string)$delay);
            $transaction->hSetNx($key, 'maxsize', (string)$maxSize);
            $transaction->hSetNx($key, 'created', $resp[0]);
            $transaction->hSetNx($key, 'modified', $resp[0]);
            $resp = $transaction->exec();

            if (!$resp[0]) {
                throw new \Exception('Queue already exists.');
            }

            return (bool)$this->redis->sAdd("{$this->ns}QUEUES", $name);
        } catch (\RedisException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function listQueues()
    {
        try {
            return $this->redis->sMembers("{$this->ns}QUEUES");
        } catch (\RedisException $e) {
            return [];
        }
    }

    public function deleteQueue(string $name): void
    {
        $this->validate([
            'queue' => $name,
        ]);

        $key = "{$this->ns}$name";
        try {
            $transaction = $this->redis->multi();
            $transaction->del("$key:Q", $key);
            $transaction->srem("{$this->ns}QUEUES", $name);
            $resp = $transaction->exec();

            if (!$resp[0]) {
                throw new \Exception('Queue not found.');
            }
        } catch (\RedisException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function getQueueAttributes(string $queue): array
    {
        $this->validate([
            'queue' => $queue,
        ]);

        $key = "{$this->ns}$queue";
        try {
            $resp = $this->redis->time();

            $transaction = $this->redis->multi();
            $transaction->hMGet("$key:Q", ['vt', 'delay', 'maxsize', 'totalrecv', 'totalsent', 'created', 'modified']);
            $transaction->zCard($key);
            $transaction->zCount($key, $resp[0] . '0000', "+inf");
            $resp = $transaction->exec();

            if ($resp[0]['vt'] === false) {
                throw new \Exception('Queue not found.');
            }

            $attributes = [
                'vt' => (int)$resp[0]['vt'],
                'delay' => (int)$resp[0]['delay'],
                'maxsize' => (int)$resp[0]['maxsize'],
                'totalrecv' => (int)$resp[0]['totalrecv'],
                'totalsent' => (int)$resp[0]['totalsent'],
                'created' => (int)$resp[0]['created'],
                'modified' => (int)$resp[0]['modified'],
                'msgs' => $resp[1],
                'hiddenmsgs' => $resp[2],
            ];

            return $attributes;
        } catch (\RedisException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function setQueueAttributes(string $queue, int $vt = 0, int $delay = 0, int $maxSize = 0): array
    {
        $this->validate([
            'vt' => $vt,
            'delay' => $delay,
            'maxsize' => $maxSize,
        ]);
        $this->getQueue($queue);

        try {
            $time = $this->redis->time();
            $transaction = $this->redis->multi();

            $transaction->hSet("{$this->ns}$queue:Q", 'modified', $time[0]);
            if ($vt !== '') {
                $transaction->hSet("{$this->ns}$queue:Q", 'vt', (string)$vt);
            }

            if ($delay !== '') {
                $transaction->hSet("{$this->ns}$queue:Q", 'delay', (string)$delay);
            }

            if ($maxSize !== '') {
                $transaction->hSet("{$this->ns}$queue:Q", 'maxsize', (string)$maxSize);
            }

            $transaction->exec();

            return $this->getQueueAttributes($queue);
        } catch (\RedisException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function sendMessage(string $queue, string $message, array $options = []): string
    {
        $this->validate([
            'queue' => $queue,
        ]);

        $q = $this->getQueue($queue, true);
        $delay = $options['delay'] ?? $q['delay'];

        if ($q['maxsize'] !== -1 && mb_strlen($message) > $q['maxsize']) {
            throw new \Exception('Message too long');
        }

        $key = "{$this->ns}$queue";

        try {
            $transaction = $this->redis->multi();
            $transaction->zadd($key, $q['ts'] + $delay * 1000, $q['uid']);
            $transaction->hset("$key:Q", $q['uid'], $message);
            $transaction->hincrby("$key:Q", 'totalsent', 1);

            if ($this->realtime) {
                $transaction->zCard($key);
            }

            $resp = $transaction->exec();

            if ($this->realtime) {
                $this->redis->publish("{$this->ns}rt:$$queue", $resp[3]);
            }

            return $q['uid'];
        } catch (\RedisException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function receiveMessage(string $queue, array $options = []): array
    {
        $this->validate([
            'queue' => $queue,
        ]);

        try {
            $q = $this->getQueue($queue);
            $vt = $options['vt'] ?? $q['vt'];

            $args = [
                "{$this->ns}$queue",
                $q['ts'],
                $q['ts'] + $vt * 1000
            ];
            $resp = $this->redis->evalSha($this->receiveMessageSha1, $args, 3);
            if (empty($resp)) {
                return [];
            }
            return [
                'id' => $resp[0],
                'message' => $resp[1],
                'rc' => $resp[2],
                'fr' => $resp[3],
                'sent' => base_convert(substr($resp[0], 0, 10), 36, 10) / 1000,
            ];
        } catch (\RedisException $e) {
            return [];
        }
    }

    public function popMessage(string $queue): array
    {
        $this->validate([
            'queue' => $queue,
        ]);

        try {
            $q = $this->getQueue($queue);

            $args = [
                "{$this->ns}$queue",
                $q['ts'],
            ];
            $resp = $this->redis->evalSha($this->popMessageSha1, $args, 2);
            if (empty($resp)) {
                return [];
            }
            return [
                'id' => $resp[0],
                'message' => $resp[1],
                'rc' => $resp[2],
                'fr' => $resp[3],
                'sent' => base_convert(substr($resp[0], 0, 10), 36, 10) / 1000,
            ];
        } catch (\RedisException $e) {
            return [];
        }
    }

    public function deleteMessage(string $queue, string $id): bool
    {
        $this->validate([
            'queue' => $queue,
            'id' => $id,
        ]);

        $key = "{$this->ns}$queue";
        try {
            $transaction = $this->redis->multi();
            $transaction->zRem($key, $id);
            $transaction->hDel("$key:Q", $id, "$id:rc", "$id:fr");
            $resp = $transaction->exec();

            return $resp[0] === 1 && $resp[1] > 0;
        } catch (\RedisException $e) {
            return false;
        }
    }

    public function changeMessageVisibility(string $queue, string $id, int $vt): bool
    {
        $this->validate([
            'queue' => $queue,
            'id' => $id,
            'vt' => $vt,
        ]);

        try {
            $q = $this->getQueue($queue, true);

            $params = [
                "{$this->ns}$queue",
                $id,
                $q['ts'] + $vt * 1000
            ];
            $resp = $this->redis->evalSha($this->changeMessageVisibilitySha1, $params, 3);

            return (bool)$resp;
        } catch (\RedisException $e) {
            return false;
        }
    }

    private function getQueue(string $name, bool $uid = false): array
    {
        $this->validate([
            'queue' => $name,
        ]);

        try {
            $transaction = $this->redis->multi();
            $transaction->hmget("{$this->ns}$name:Q", ['vt', 'delay', 'maxsize']);
            $transaction->time();
            $resp = $transaction->exec();

            if ($resp[0]['vt'] === false) {
                throw new \Exception('Queue not found.');
            }

            $ms = $this->util->formatZeroPad((int)$resp[1][1], 6);

            $queue = [
                'vt' => (int)$resp[0]['vt'],
                'delay' => (int)$resp[0]['delay'],
                'maxsize' => (int)$resp[0]['maxsize'],
                'ts' => (int)($resp[1][0] . substr($ms, 0, 3)),
            ];

            if ($uid) {
                $uid = $this->util->makeID(22);
                $queue['uid'] = base_convert(($resp[1][0] . $ms), 10, 36) . $uid;
            }

            return $queue;
        } catch (\RedisException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    private function initScripts(): void
    {
        try {
            $receiveMessageScript = 'local msg = redis.call("ZRANGEBYSCORE", KEYS[1], "-inf", KEYS[2], "LIMIT", "0", "1")
                if #msg == 0 then
                    return {}
                end
                redis.call("ZADD", KEYS[1], KEYS[3], msg[1])
                redis.call("HINCRBY", KEYS[1] .. ":Q", "totalrecv", 1)
                local mbody = redis.call("HGET", KEYS[1] .. ":Q", msg[1])
                local rc = redis.call("HINCRBY", KEYS[1] .. ":Q", msg[1] .. ":rc", 1)
                local o = {msg[1], mbody, rc}
                if rc==1 then
                    redis.call("HSET", KEYS[1] .. ":Q", msg[1] .. ":fr", KEYS[2])
                    table.insert(o, KEYS[2])
                else
                    local fr = redis.call("HGET", KEYS[1] .. ":Q", msg[1] .. ":fr")
                    table.insert(o, fr)
                end
                return o';

            $popMessageScript = 'local msg = redis.call("ZRANGEBYSCORE", KEYS[1], "-inf", KEYS[2], "LIMIT", "0", "1")
                if #msg == 0 then
                    return {}
                end
                redis.call("HINCRBY", KEYS[1] .. ":Q", "totalrecv", 1)
                local mbody = redis.call("HGET", KEYS[1] .. ":Q", msg[1])
                local rc = redis.call("HINCRBY", KEYS[1] .. ":Q", msg[1] .. ":rc", 1)
                local o = {msg[1], mbody, rc}
                if rc==1 then
                    table.insert(o, KEYS[2])
                else
                    local fr = redis.call("HGET", KEYS[1] .. ":Q", msg[1] .. ":fr")
                    table.insert(o, fr)
                end
                redis.call("ZREM", KEYS[1], msg[1])
                redis.call("HDEL", KEYS[1] .. ":Q", msg[1], msg[1] .. ":rc", msg[1] .. ":fr")
                return o';

            $changeMessageVisibilityScript = 'local msg = redis.call("ZSCORE", KEYS[1], KEYS[2])
                if not msg then
                    return 0
                end
                redis.call("ZADD", KEYS[1], KEYS[3], KEYS[2])
                return 1';

            $this->receiveMessageSha1 = $this->redis->script('load', $receiveMessageScript);
            $this->popMessageSha1 = $this->redis->script('load', $popMessageScript);
            $this->changeMessageVisibilitySha1 = $this->redis->script('load', $changeMessageVisibilityScript);
        } catch (\RedisException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    public function validate(array $params): void
    {
        if (isset($params['queue']) && !preg_match('/^([a-zA-Z0-9_-]){1,160}$/', $params['queue'])) {
            throw new \Exception('Invalid queue name');
        }

        if (isset($params['id']) && !preg_match('/^([a-zA-Z0-9:]){32}$/', $params['id'])) {
            throw new \Exception('Invalid message id');
        }

        if (isset($params['vt']) && ($params['vt'] < 0 || $params['vt'] > self::MAX_DELAY)) {
            throw new \Exception('Visibility time must be between 0 and ' . self::MAX_DELAY);
        }

        if (isset($params['delay']) && ($params['delay'] < 0 || $params['delay'] > self::MAX_DELAY)) {
            throw new \Exception('Delay must be between 0 and ' . self::MAX_DELAY);
        }
    }

    /**
     * @var $queue name
     * This was created to check if queue exist before creating another queue.
     * This functions helps developers to first check if a queue already exist or not before creating a new one.
     */
    public function queueExist(string $name): bool
    {
        $this->validate([
            'queue' => $name,
        ]);

        try {
            $transaction = $this->redis->multi();
            $transaction->hmget("{$this->ns}$name:Q", ['vt', 'delay', 'maxsize']);
            $transaction->time();
            $resp = $transaction->exec();
            if ($resp[0]['vt'] === false) {
                return false;
            }
            //Create Queue
            return true;
        } catch (\RedisException $e) {
            return false;
        }
    }
}