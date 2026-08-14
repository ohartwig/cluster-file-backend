<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Application;

use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
use Moselwal\Typo3ClusterCache\Application\Invalidate\FlushByTag;
use Moselwal\Typo3ClusterCache\Application\Invalidate\FlushNamespace;
use Moselwal\Typo3ClusterCache\Application\Read\ReadCacheEntry;
use Moselwal\Typo3ClusterCache\Application\Write\WriteCacheEntry;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\NullCompressor;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use Moselwal\Typo3ClusterCache\Tests\Support\InMemoryLocalPayloadStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WriteCacheEntry::class)]
#[CoversClass(ReadCacheEntry::class)]
#[CoversClass(FlushNamespace::class)]
#[CoversClass(FlushByTag::class)]
final class ReadWriteFlowTest extends TestCase
{
    private CacheNamespace $namespace;
    private FakeMetadataCache $metadataCache;
    private InMemoryLocalPayloadStore $local;
    private FakeClock $clock;
    private FakeMetrics $metrics;
    private WriteCacheEntry $writer;
    private ReadCacheEntry $reader;
    private FlushNamespace $flusher;
    private FlushByTag $tagFlusher;

    protected function setUp(): void
    {
        $this->namespace = new CacheNamespace(EnvironmentName::Testing, 'site', 'pages');
        $this->metadataCache = new FakeMetadataCache();
        $this->local = new InMemoryLocalPayloadStore();
        $this->clock = new FakeClock(1_700_000_000);
        $this->metrics = new FakeMetrics();
        $compressor = new NullCompressor();
        $this->writer = new WriteCacheEntry(
            metadataCache: $this->metadataCache,
            localStore: $this->local,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $this->clock,
            metrics: $this->metrics,
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
            metrics: $this->metrics,
        );
        $this->flusher = new FlushNamespace($this->metadataCache, $this->metrics);
        $this->tagFlusher = new FlushByTag($this->metadataCache, $this->metrics);
    }

    public function testSetThenGetHit(): void
    {
        $id = new CacheIdentifier('page_42');
        $this->writer->execute($this->namespace, $id, 'payload bytes', new TagSet(), 3600);
        $bytes = $this->reader->execute($this->namespace, $id);

        self::assertSame('payload bytes', $bytes);
        self::assertSame(1, $this->metrics->counterTotal('cache_hit_total'));
        self::assertSame(1, $this->metrics->counterTotal('cache_write_total'));
    }

    public function testBlobMissReturnsNull(): void
    {
        $id = new CacheIdentifier('page_42');
        $this->writer->execute($this->namespace, $id, 'payload bytes', new TagSet(), 3600);
        foreach ($this->local->iterateAll() as $hash) {
            $this->local->delete($hash);
        }
        self::assertNull($this->reader->execute($this->namespace, $id));
        self::assertSame(1, $this->metrics->counterTotal('blob_miss_total'));
    }

    public function testExpiredEntryIsCacheMiss(): void
    {
        $id = new CacheIdentifier('page_42');
        $this->writer->execute($this->namespace, $id, 'payload bytes', new TagSet(), 60);
        $this->clock->advance(120);
        self::assertNull($this->reader->execute($this->namespace, $id));
        self::assertSame(1, $this->metrics->counterTotal('cache_miss_total'));
    }

    public function testFlushClearsAllEntries(): void
    {
        $id = new CacheIdentifier('page_42');
        $this->writer->execute($this->namespace, $id, 'payload bytes', new TagSet(), 3600);
        self::assertNotNull($this->reader->execute($this->namespace, $id));

        $this->flusher->execute($this->namespace);
        self::assertNull($this->reader->execute($this->namespace, $id));
    }

    public function testTaggedSetIsFlushableByTag(): void
    {
        $id1 = new CacheIdentifier('page_42');
        $id2 = new CacheIdentifier('page_7');
        $this->writer->execute($this->namespace, $id1, 'p1', new TagSet(['pages_42', 'site_1']), 3600);
        $this->writer->execute($this->namespace, $id2, 'p2', new TagSet(['pages_7', 'site_1']), 3600);

        $this->tagFlusher->execute($this->namespace, 'site_1');

        self::assertNull($this->reader->execute($this->namespace, $id1));
        self::assertNull($this->reader->execute($this->namespace, $id2));
    }

    public function testFlushByTagLeavesUntaggedEntries(): void
    {
        $id1 = new CacheIdentifier('page_42');
        $id2 = new CacheIdentifier('page_7');
        $this->writer->execute($this->namespace, $id1, 'p1', new TagSet(['pages_42']), 3600);
        $this->writer->execute($this->namespace, $id2, 'p2', new TagSet(['pages_7']), 3600);

        $this->tagFlusher->execute($this->namespace, 'pages_42');

        self::assertNull($this->reader->execute($this->namespace, $id1));
        self::assertSame('p2', $this->reader->execute($this->namespace, $id2));
    }

    public function testCorruptPayloadResultsInCacheMissAndBrokenState(): void
    {
        $id = new CacheIdentifier('page_42');
        $this->writer->execute($this->namespace, $id, 'payload bytes', new TagSet(), 3600);
        foreach ($this->local->iterateAll() as $hash) {
            $this->local->corrupt($hash, 'tampered');
            break;
        }
        self::assertNull($this->reader->execute($this->namespace, $id));
        $stored = $this->metadataCache->get($id);
        self::assertNotNull($stored);
        self::assertSame('broken', $stored->state->value);
    }
}
