<?php

namespace Tests\Unit;

use App\Services\Cache\FileState;
use App\Services\Cache\FileStateCache;
use PHPUnit\Framework\TestCase;

class FileStateCacheTest extends TestCase
{
    public function test_set_and_get(): void
    {
        $cache = new FileStateCache();
        $state = new FileState('hello', time());
        $cache->set('/tmp/test.txt', $state);

        $retrieved = $cache->get('/tmp/test.txt');
        $this->assertNotNull($retrieved);
        $this->assertSame('hello', $retrieved->content);
    }

    public function test_returns_null_for_missing_key(): void
    {
        $cache = new FileStateCache();
        $this->assertNull($cache->get('/no/such/file'));
    }

    public function test_has(): void
    {
        $cache = new FileStateCache();
        $this->assertFalse($cache->has('/tmp/x'));
        $cache->set('/tmp/x', new FileState('x', time()));
        $this->assertTrue($cache->has('/tmp/x'));
    }

    public function test_delete(): void
    {
        $cache = new FileStateCache();
        $cache->set('/tmp/x', new FileState('x', time()));
        $this->assertTrue($cache->delete('/tmp/x'));
        $this->assertFalse($cache->has('/tmp/x'));
        $this->assertFalse($cache->delete('/tmp/x'));
    }

    public function test_clear(): void
    {
        $cache = new FileStateCache();
        $cache->set('/tmp/a', new FileState('a', time()));
        $cache->set('/tmp/b', new FileState('b', time()));
        $cache->clear();
        $this->assertSame(0, $cache->size());
    }

    public function test_lru_eviction_by_count(): void
    {
        $cache = new FileStateCache(maxEntries: 2, maxSizeBytes: PHP_INT_MAX);
        $cache->set('/tmp/a', new FileState('a', time()));
        $cache->set('/tmp/b', new FileState('b', time()));
        $cache->set('/tmp/c', new FileState('c', time())); // should evict /tmp/a

        $this->assertNull($cache->get('/tmp/a'));
        $this->assertNotNull($cache->get('/tmp/b'));
        $this->assertNotNull($cache->get('/tmp/c'));
    }

    public function test_lru_eviction_by_size(): void
    {
        // Max 10 bytes, each entry ~5 bytes
        $cache = new FileStateCache(maxEntries: 100, maxSizeBytes: 10);
        $cache->set('/tmp/a', new FileState('12345', time()));
        $cache->set('/tmp/b', new FileState('67890', time()));
        $cache->set('/tmp/c', new FileState('xxxxx', time())); // evict /tmp/a

        $this->assertNull($cache->get('/tmp/a'));
        $this->assertSame(2, $cache->size());
    }

    public function test_touch_updates_lru_order(): void
    {
        $cache = new FileStateCache(maxEntries: 2, maxSizeBytes: PHP_INT_MAX);
        $cache->set('/tmp/a', new FileState('a', time()));
        $cache->set('/tmp/b', new FileState('b', time()));

        // Touch /tmp/a, making /tmp/b the LRU
        $cache->get('/tmp/a');

        $cache->set('/tmp/c', new FileState('c', time())); // should evict /tmp/b
        $this->assertNotNull($cache->get('/tmp/a'));
        $this->assertNull($cache->get('/tmp/b'));
    }

    public function test_clone_creates_deep_copy(): void
    {
        $cache = new FileStateCache();
        $cache->set('/tmp/x', new FileState('original', time()));

        $clone = $cache->clone();
        $clone->set('/tmp/y', new FileState('added', time()));

        $this->assertFalse($cache->has('/tmp/y'));
        $this->assertTrue($clone->has('/tmp/y'));
    }

    public function test_partial_view_flag(): void
    {
        $cache = new FileStateCache();
        $state = new FileState('partial content', time(), offset: 10, limit: 50, isPartialView: true);
        $cache->set('/tmp/partial', $state);

        $retrieved = $cache->get('/tmp/partial');
        $this->assertTrue($retrieved->isPartialView);
        $this->assertSame(10, $retrieved->offset);
        $this->assertSame(50, $retrieved->limit);
    }
}
