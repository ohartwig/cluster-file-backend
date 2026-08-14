<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\EdgeCases;

use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
use Moselwal\Typo3ClusterCache\Application\Read\ReadCacheEntry;
use Moselwal\Typo3ClusterCache\Application\Write\WriteCacheEntry;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\Lifetime;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\NullCompressor;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use Moselwal\Typo3ClusterCache\Tests\Support\InMemoryLocalPayloadStore;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the TYPO3 cache-API convention that `$lifetime === 0`
 * means "cache forever". Before the fix the backend silently rewrote
 * `0` to `cfbDefaultLifetime`, which meant `system`-group caches
 * (`cache_core`, `cache_runtime`, …) that rely on unlimited lifetime
 * were evicted after `defaultLifetime` seconds — a subtle but
 * production-impacting bug.
 */
final class UnlimitedLifetimeTest extends TestCase
{
    private CacheNamespace $namespace;
    private FakeMetadataCache $metadataCache;
    private InMemoryLocalPayloadStore $local;
    private FakeClock $clock;
    private WriteCacheEntry $writer;
    private ReadCacheEntry $reader;

    protected function setUp(): void
    {
        $this->namespace = new CacheNamespace(EnvironmentName::Testing, 'site', 'pages');
        $this->metadataCache = new FakeMetadataCache();
        $this->local = new InMemoryLocalPayloadStore();
        $this->clock = new FakeClock(1_700_000_000);
        $metrics = new FakeMetrics();
        $compressor = new NullCompressor();
        $this->writer = new WriteCacheEntry(
            metadataCache: $this->metadataCache,
            localStore: $this->local,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $this->clock,
            metrics: $metrics,
            hasher: new ComputePayloadHash(),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(1),
            minCompressedBytes: 0,
        );
        $this->reader = new ReadCacheEntry(
            metadataCache: $this->metadataCache,
            localStore: $this->local,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $this->clock,
            metrics: $metrics,
        );
    }

    public function testLifetimeZeroProducesUnlimitedMetadata(): void
    {
        $id = new CacheIdentifier('forever');
        $this->writer->execute($this->namespace, $id, 'eternal', new TagSet(), 0);

        $metadata = $this->metadataCache->get($id);
        self::assertNotNull($metadata);
        self::assertTrue($metadata->lifetime->isUnlimited());
        self::assertSame(Lifetime::UNLIMITED_EXPIRES_AT, $metadata->lifetime->expiresAt);
    }

    public function testUnlimitedEntryStaysHitFarInTheFuture(): void
    {
        $id = new CacheIdentifier('forever_read');
        $this->writer->execute($this->namespace, $id, 'eternal', new TagSet(), 0);

        // Advance the clock by 10 years — entry must still be a hit.
        $this->clock->advance(10 * 365 * 86_400);
        self::assertSame('eternal', $this->reader->execute($this->namespace, $id));
    }

    public function testFiniteLifetimeStillExpires(): void
    {
        $id = new CacheIdentifier('finite');
        $this->writer->execute($this->namespace, $id, 'short-lived', new TagSet(), 60);

        $this->clock->advance(120);
        self::assertNull($this->reader->execute($this->namespace, $id));
    }
}
