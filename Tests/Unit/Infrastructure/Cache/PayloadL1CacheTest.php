<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\Cache;

use Moselwal\Typo3ClusterCache\Infrastructure\Cache\PayloadL1Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PayloadL1Cache::class)]
final class PayloadL1CacheTest extends TestCase
{
    public function testGetReturnsNullForMiss(): void
    {
        $cache = new PayloadL1Cache(maxEntries: 4, maxBytes: 1024);
        self::assertNull($cache->get('unknown'));
    }

    public function testPutThenGetReturnsValue(): void
    {
        $cache = new PayloadL1Cache(maxEntries: 4, maxBytes: 1024);
        $cache->put('a', 'value-A');
        self::assertSame('value-A', $cache->get('a'));
        self::assertSame(1, $cache->count());
        self::assertSame(7, $cache->totalBytes());
    }

    public function testGetPromotesEntryToMostRecentlyUsed(): void
    {
        $cache = new PayloadL1Cache(maxEntries: 3, maxBytes: 1024);
        $cache->put('a', 'A');
        $cache->put('b', 'B');
        $cache->put('c', 'C');
        // Touch 'a' so it becomes MRU; head should now be 'b'.
        $cache->get('a');
        $cache->put('d', 'D'); // forces eviction of head ('b')

        self::assertNull($cache->get('b'));
        self::assertSame('A', $cache->get('a'));
        self::assertSame('C', $cache->get('c'));
        self::assertSame('D', $cache->get('d'));
    }

    public function testEntryCountCapEvictsOldest(): void
    {
        $cache = new PayloadL1Cache(maxEntries: 3, maxBytes: 1024);
        $cache->put('a', 'A');
        $cache->put('b', 'B');
        $cache->put('c', 'C');
        $cache->put('d', 'D'); // evicts 'a'

        self::assertNull($cache->get('a'));
        self::assertSame(['b', 'c', 'd'], $cache->keys());
        self::assertSame(3, $cache->count());
    }

    public function testBytesCapEvictsOldestUntilUnderBudget(): void
    {
        // Budget exactly fits two 100-byte entries; a third forces one eviction.
        $cache = new PayloadL1Cache(maxEntries: 100, maxBytes: 200);
        $cache->put('a', str_repeat('A', 100));
        $cache->put('b', str_repeat('B', 100));
        self::assertSame(200, $cache->totalBytes());

        $cache->put('c', str_repeat('C', 100));
        self::assertSame(200, $cache->totalBytes());
        self::assertNull($cache->get('a'));
        self::assertSame(['b', 'c'], $cache->keys());
    }

    public function testOversizeEntryBypassesCache(): void
    {
        $cache = new PayloadL1Cache(maxEntries: 100, maxBytes: 100);
        $cache->put('big', str_repeat('X', 200));
        self::assertNull($cache->get('big'));
        self::assertSame(0, $cache->count());
        self::assertSame(0, $cache->totalBytes());
    }

    public function testForgetRemovesEntryAndUpdatesByteCount(): void
    {
        $cache = new PayloadL1Cache(maxEntries: 4, maxBytes: 1024);
        $cache->put('a', 'AAAA');
        $cache->put('b', 'BBBB');
        $cache->forget('a');
        self::assertNull($cache->get('a'));
        self::assertSame('BBBB', $cache->get('b'));
        self::assertSame(1, $cache->count());
        self::assertSame(4, $cache->totalBytes());
    }

    public function testForgetIsNoOpForMissingKey(): void
    {
        $cache = new PayloadL1Cache(maxEntries: 4, maxBytes: 1024);
        $cache->put('a', 'A');
        $cache->forget('does-not-exist');
        self::assertSame('A', $cache->get('a'));
        self::assertSame(1, $cache->totalBytes());
    }

    public function testClearWipesCacheCompletely(): void
    {
        $cache = new PayloadL1Cache(maxEntries: 4, maxBytes: 1024);
        $cache->put('a', 'A');
        $cache->put('b', 'B');
        $cache->clear();
        self::assertSame(0, $cache->count());
        self::assertSame(0, $cache->totalBytes());
        self::assertNull($cache->get('a'));
    }

    public function testPutReplacesExistingKeyAndUpdatesByteCount(): void
    {
        $cache = new PayloadL1Cache(maxEntries: 4, maxBytes: 1024);
        $cache->put('a', 'short');         // 5 bytes
        $cache->put('a', 'a-longer-value'); // 14 bytes
        self::assertSame('a-longer-value', $cache->get('a'));
        self::assertSame(1, $cache->count());
        self::assertSame(14, $cache->totalBytes());
    }

    public function testZeroMaxEntriesDisablesCacheEntirely(): void
    {
        $cache = new PayloadL1Cache(maxEntries: 0, maxBytes: 1024);
        self::assertFalse($cache->isEnabled());

        $cache->put('a', 'A');
        self::assertNull($cache->get('a'));
        self::assertSame(0, $cache->count());
    }

    public function testZeroMaxBytesDisablesByteCheckButCountStillApplies(): void
    {
        // maxBytes=0 means "no byte budget" — only the entry-count cap.
        $cache = new PayloadL1Cache(maxEntries: 2, maxBytes: 0);
        $cache->put('a', str_repeat('A', 1_000_000));
        $cache->put('b', str_repeat('B', 1_000_000));
        $cache->put('c', str_repeat('C', 1_000_000)); // evicts 'a' on count, not bytes

        self::assertNull($cache->get('a'));
        self::assertSame(2, $cache->count());
        self::assertSame(2_000_000, $cache->totalBytes());
    }

    public function testNegativeMaxEntriesRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PayloadL1Cache(maxEntries: -1, maxBytes: 1024);
    }

    public function testNegativeMaxBytesRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PayloadL1Cache(maxEntries: 4, maxBytes: -1);
    }
}
