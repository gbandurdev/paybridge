<?php

declare(strict_types=1);

namespace PayBridge\Tests\Integration\Store\Redis;

use PayBridge\Store\Redis\RedisConnectionFactory;
use PayBridge\Store\Redis\RedisEventStore;
use PayBridge\Store\Redis\RedisIdempotencyStore;
use PayBridge\Store\Redis\RedisLockStore;
use PHPUnit\Framework\TestCase;

/**
 * Runs the Redis store contract against a real Redis server. Skipped (not failed) when
 * ext-redis isn't loaded, or the server at PAYBRIDGE_TEST_REDIS_DSN (default
 * redis://127.0.0.1:6379) isn't reachable — same convention as docs/PLAN.md §11.7's sandbox
 * tests skipping without credentials.
 */
final class RedisStoreTest extends TestCase
{
    private const string TEST_PREFIX = 'paybridge:test:';

    private ?\Redis $redis = null;

    protected function setUp(): void
    {
        if (!\extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is not installed.');
        }

        $dsn = getenv('PAYBRIDGE_TEST_REDIS_DSN');
        $dsn = \is_string($dsn) && $dsn !== '' ? $dsn : 'redis://127.0.0.1:6379';

        try {
            $redis = RedisConnectionFactory::fromDsn($dsn);
            $redis->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped(sprintf('Redis is not reachable at "%s": %s', $dsn, $e->getMessage()));
        }

        $this->redis = $redis;
        $this->flushTestKeys();
    }

    protected function tearDown(): void
    {
        if ($this->redis !== null) {
            $this->flushTestKeys();
        }
    }

    public function testIdempotencyStoreRoundTripsAndExpires(): void
    {
        $store = new RedisIdempotencyStore($this->redis(), self::TEST_PREFIX);

        $store->put('idem:stripe:order-1', ['fingerprint' => str_repeat('a', 64), 'status' => 'completed'], 1);
        self::assertSame(['fingerprint' => str_repeat('a', 64), 'status' => 'completed'], $store->get('idem:stripe:order-1'));

        sleep(2);
        self::assertNull($store->get('idem:stripe:order-1'));
    }

    public function testLockStoreAcquireIsAtomicAndReleaseCompareAndDeletes(): void
    {
        $store = new RedisLockStore($this->redis(), self::TEST_PREFIX);

        $token = $store->acquire('idem:stripe:order-1', 30);
        self::assertNotNull($token);
        self::assertNull($store->acquire('idem:stripe:order-1', 30));

        $store->release('idem:stripe:order-1', 'not-the-real-token');
        self::assertNull($store->acquire('idem:stripe:order-1', 30));

        $store->release('idem:stripe:order-1', $token);
        self::assertNotNull($store->acquire('idem:stripe:order-1', 30));
    }

    public function testEventStoreClaimAndRelease(): void
    {
        $store = new RedisEventStore($this->redis(), self::TEST_PREFIX);

        self::assertTrue($store->claim('stripe:evt_1', 300));
        self::assertFalse($store->claim('stripe:evt_1', 300));

        $store->release('stripe:evt_1');
        self::assertTrue($store->claim('stripe:evt_1', 300));
    }

    private function redis(): \Redis
    {
        \assert($this->redis instanceof \Redis);

        return $this->redis;
    }

    private function flushTestKeys(): void
    {
        $keys = $this->redis()->keys(self::TEST_PREFIX . '*');

        if ($keys !== []) {
            $this->redis()->del($keys);
        }
    }
}
