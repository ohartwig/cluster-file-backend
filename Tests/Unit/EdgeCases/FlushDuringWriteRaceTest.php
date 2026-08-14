<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\EdgeCases;

use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
use Moselwal\Typo3ClusterCache\Application\Invalidate\FlushByTag;
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
use PHPUnit\Framework\TestCase;

/**
 * Spec edge case T164: tag invalidation while writes are in flight. In both
 * possible orders the final state must be deterministic — never "valid with
 * an already invalidated tag".
 */
final class FlushDuringWriteRaceTest extends TestCase
{
    private CacheNamespace $namespace;
    private FakeMetadataCache $cache;
    private InMemoryLocalPayloadStore $local;
    private WriteCacheEntry $writer;
    private ReadCacheEntry $reader;
    private FlushByTag $flusher;

    protected function setUp(): void
    {
        $this->namespace = new CacheNamespace(EnvironmentName::Testing, 'race', 'pages');
        $this->cache = new FakeMetadataCache();
        $this->local = new InMemoryLocalPayloadStore();
        $clock = new FakeClock(1_700_000_000);
        $metrics = new FakeMetrics();
        $compressor = new NullCompressor();
        $this->writer = new WriteCacheEntry(
            metadataCache: $this->cache,
            localStore: $this->local,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $clock,
            metrics: $metrics,
            hasher: new ComputePayloadHash(),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(1),
            minCompressedBytes: 0,
        );
        $this->reader = new ReadCacheEntry(
            metadataCache: $this->cache,
            localStore: $this->local,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $clock,
            metrics: $metrics,
        );
        $this->flusher = new FlushByTag($this->cache, $metrics);
    }

    public function testSetThenFlushByTagResultsInInvalidEntry(): void
    {
        $id = new CacheIdentifier('race1');
        $this->writer->execute($this->namespace, $id, 'p', new TagSet(['tag_x']), 3600);
        $this->flusher->execute($this->namespace, 'tag_x');

        self::assertNull($this->reader->execute($this->namespace, $id));
    }

    public function testFlushByTagThenSetResultsInValidEntry(): void
    {
        // Tag is invalidated BEFORE any entry exists
        $this->flusher->execute($this->namespace, 'tag_x');

        $id = new CacheIdentifier('race2');
        $this->writer->execute($this->namespace, $id, 'p', new TagSet(['tag_x']), 3600);

        // Set after flush → entry is new and valid
        self::assertSame('p', $this->reader->execute($this->namespace, $id));
    }

    public function testInterleavedSetFlushSetEndsInValidEntry(): void
    {
        $id = new CacheIdentifier('race3');
        $this->writer->execute($this->namespace, $id, 'v1', new TagSet(['tag_x']), 3600);
        $this->flusher->execute($this->namespace, 'tag_x');
        $this->writer->execute($this->namespace, $id, 'v2', new TagSet(['tag_x']), 3600);

        // Last set wins — read returns v2
        self::assertSame('v2', $this->reader->execute($this->namespace, $id));
    }

    public function testEntryWithDifferentTagSurvivesFlush(): void
    {
        $id1 = new CacheIdentifier('race4a');
        $id2 = new CacheIdentifier('race4b');
        $this->writer->execute($this->namespace, $id1, 'a', new TagSet(['tag_x']), 3600);
        $this->writer->execute($this->namespace, $id2, 'b', new TagSet(['tag_y']), 3600);

        $this->flusher->execute($this->namespace, 'tag_x');

        self::assertNull($this->reader->execute($this->namespace, $id1));
        self::assertSame('b', $this->reader->execute($this->namespace, $id2));
    }
}
