<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Deployment;

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
use PHPUnit\Framework\TestCase;

/**
 * Cluster consistency tests: verifies that invalidations (flush /
 * flushByTag) triggered on one pod are seen IMMEDIATELY by another pod on
 * the next read — without any pod-to-pod synchronisation.
 *
 * The pods are simulated here by two `ClusterFileBackend` stand-ins that
 * share the same `FakeMetadataCache` (= central source of truth) but EACH
 * has its OWN pod-local file view. This setup mirrors the production
 * topology exactly: shared metadata-cache frontend (Redis/DB/Memcached),
 * separate `emptyDir` volumes.
 *
 * If these tests ever fail, the cluster promise (central cache validity)
 * is broken.
 */
final class CrossPodFlushTest extends TestCase
{
    private CacheNamespace $namespace;
    private FakeMetadataCache $sharedMetadata;   // central source of truth (shared)
    private InMemoryLocalPayloadStore $podALocal; // Pod A: own file view
    private InMemoryLocalPayloadStore $podBLocal; // Pod B: own file view
    private FakeClock $clock;
    private FakeMetrics $metrics;
    private WriteCacheEntry $podAWriter;
    private WriteCacheEntry $podBWriter;
    private ReadCacheEntry $podAReader;
    private ReadCacheEntry $podBReader;
    private FlushNamespace $flusher;
    private FlushByTag $tagFlusher;

    protected function setUp(): void
    {
        $this->namespace = new CacheNamespace(EnvironmentName::Testing, 'cluster', 'pages');
        $this->sharedMetadata = new FakeMetadataCache();
        $this->podALocal = new InMemoryLocalPayloadStore();
        $this->podBLocal = new InMemoryLocalPayloadStore();
        $this->clock = new FakeClock(1_700_000_000);
        $this->metrics = new FakeMetrics();

        $compressor = new NullCompressor();
        $hasher = new ComputePayloadHash();
        $serializer = SerializerName::phpNative();
        $compression = CompressionName::none();
        $backendVersion = new BackendVersion(1);

        $this->podAWriter = new WriteCacheEntry(
            metadataCache: $this->sharedMetadata,
            localStore: $this->podALocal,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $this->clock,
            metrics: $this->metrics,
            hasher: $hasher,
            serializer: $serializer,
            compression: $compression,
            backendVersion: $backendVersion,
            minCompressedBytes: 0,
        );
        $this->podBWriter = new WriteCacheEntry(
            metadataCache: $this->sharedMetadata,
            localStore: $this->podBLocal,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $this->clock,
            metrics: $this->metrics,
            hasher: $hasher,
            serializer: $serializer,
            compression: $compression,
            backendVersion: $backendVersion,
            minCompressedBytes: 0,
        );
        $this->podAReader = new ReadCacheEntry(
            metadataCache: $this->sharedMetadata,
            localStore: $this->podALocal,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $this->clock,
            metrics: $this->metrics,
        );
        $this->podBReader = new ReadCacheEntry(
            metadataCache: $this->sharedMetadata,
            localStore: $this->podBLocal,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $this->clock,
            metrics: $this->metrics,
        );
        $this->flusher = new FlushNamespace($this->sharedMetadata, $this->metrics);
        $this->tagFlusher = new FlushByTag($this->sharedMetadata, $this->metrics);
    }

    /**
     * Scenario: editor clicks "Clear all caches" in the TYPO3 backend → Pod A
     * runs `flush()`. Pod B (fresh request) MUST see a cache miss
     * immediately — without any pod-to-pod sync mechanism.
     */
    public function testFlushOnPodAIsImmediatelyVisibleOnPodB(): void
    {
        $id = new CacheIdentifier('page_42');

        // Pod A writes, then both pods read successfully (Pod B repairs via
        // caller rebuild — we simulate this here by writing the same payload
        // directly on Pod B).
        $this->podAWriter->execute($this->namespace, $id, 'content_v1', new TagSet(), 3600);
        $this->podBWriter->execute($this->namespace, $id, 'content_v1', new TagSet(), 3600);
        self::assertSame('content_v1', $this->podAReader->execute($this->namespace, $id));
        self::assertSame('content_v1', $this->podBReader->execute($this->namespace, $id));

        // Pod A runs clear-cache.
        $this->flusher->execute($this->namespace);

        // Pod B reads right after → MUST see a cache miss.
        // No sync step between the pods required.
        self::assertNull(
            $this->podBReader->execute($this->namespace, $id),
            'Pod B must see the flush from Pod A immediately on next get()',
        );
        self::assertNull(
            $this->podAReader->execute($this->namespace, $id),
            'Pod A must see its own flush, of course',
        );
    }

    /**
     * Scenario: editor saves page 42 → TYPO3 calls `flushByTag('pageId_42')`
     * on Pod A. Other tags stay valid. Pod B immediately sees:
     * page_42 → miss, page_7 → hit.
     */
    public function testFlushByTagOnPodAInvalidatesOnlyMatchingEntriesOnPodB(): void
    {
        $page42 = new CacheIdentifier('page_42');
        $page7 = new CacheIdentifier('page_7');

        // Pod A writes two entries with different tags
        $this->podAWriter->execute($this->namespace, $page42, 'content_42', new TagSet(['pageId_42']), 3600);
        $this->podAWriter->execute($this->namespace, $page7, 'content_7', new TagSet(['pageId_7']), 3600);
        // Pod B repairs (deterministic identical bytes)
        $this->podBWriter->execute($this->namespace, $page42, 'content_42', new TagSet(['pageId_42']), 3600);
        $this->podBWriter->execute($this->namespace, $page7, 'content_7', new TagSet(['pageId_7']), 3600);

        // Pod A invalidates only the tag pageId_42
        $this->tagFlusher->execute($this->namespace, 'pageId_42');

        // Pod B sees: page_42 → miss, page_7 → hit
        self::assertNull(
            $this->podBReader->execute($this->namespace, $page42),
            'Pod B must see the tag flush from Pod A',
        );
        self::assertSame(
            'content_7',
            $this->podBReader->execute($this->namespace, $page7),
            'Untagged entry must remain valid on Pod B',
        );
    }

    /**
     * Hard invariant check: Pod B's local cache file survives a flush — but
     * it is not served because the metadata is gone. If Pod B then re-writes
     * the same content, it materialises the same filename (hash determinism)
     * — the old file can be overwritten harmlessly or stays as a no-op
     * identity.
     */
    public function testLocalFileSurvivesFlushButIsUnreachableWithoutMetadata(): void
    {
        $id = new CacheIdentifier('page_42');
        $this->podAWriter->execute($this->namespace, $id, 'content', new TagSet(), 3600);
        $this->podBWriter->execute($this->namespace, $id, 'content', new TagSet(), 3600);

        // Pod B's local file exists after the write
        $hashesBefore = [];
        foreach ($this->podBLocal->iterateAll() as $payloadHash) {
            $hashesBefore[] = $payloadHash->digest;
        }
        self::assertCount(1, $hashesBefore, 'Pod B materialized exactly one file');

        // Pod A flushes
        $this->flusher->execute($this->namespace);

        // Pod B's file is STILL there — but is not served
        $hashesAfter = [];
        foreach ($this->podBLocal->iterateAll() as $hashAfter) {
            $hashesAfter[] = $hashAfter->digest;
        }
        self::assertSame($hashesBefore, $hashesAfter, 'Local file survives flush (orphan)');
        self::assertNull(
            $this->podBReader->execute($this->namespace, $id),
            'But it is unreachable: metadata is gone, so the file cannot be served',
        );
    }

    /**
     * Ensures that after a flush a **new** write on Pod B works (identical
     * content → identical hash → idempotent materialisation; or new content
     * → new hash → new file).
     */
    public function testWriteAfterFlushReestablishesConsistency(): void
    {
        $id = new CacheIdentifier('page_42');

        $this->podAWriter->execute($this->namespace, $id, 'v1', new TagSet(), 3600);
        $this->flusher->execute($this->namespace);

        // After the flush: Pod B writes v2 (content changed) — hash differs.
        $this->podBWriter->execute($this->namespace, $id, 'v2', new TagSet(), 3600);

        // Pod A on the next read → metadata from the cache (written by Pod B),
        // but a local file with the new hash is missing on Pod A → blob miss.
        self::assertNull(
            $this->podAReader->execute($this->namespace, $id),
            'Pod A blob-misses because Pod B wrote a different content (different hash)',
        );
        self::assertSame(1, $this->metrics->counterTotal('blob_miss_total'));

        // Pod A repairs via caller rebuild (= own write of the same v2)
        $this->podAWriter->execute($this->namespace, $id, 'v2', new TagSet(), 3600);
        self::assertSame('v2', $this->podAReader->execute($this->namespace, $id));
    }

    /**
     * Global `flush()` operation: Pod A clears the central cache → Pod B,
     * Pod C, …, Pod N all see cache misses, regardless of how many pods are
     * in the cluster. We simulate this with 5 independent local stores
     * against one shared metadata cache.
     */
    public function testFlushPropagatesToArbitraryNumberOfPods(): void
    {
        $id = new CacheIdentifier('page_42');

        // 5 pods independently write the same deterministic content
        $localStores = [
            $this->podALocal,
            $this->podBLocal,
            new InMemoryLocalPayloadStore(),
            new InMemoryLocalPayloadStore(),
            new InMemoryLocalPayloadStore(),
        ];
        $writers = \array_map(
            fn(InMemoryLocalPayloadStore $store): WriteCacheEntry => new WriteCacheEntry(
                metadataCache: $this->sharedMetadata,
                localStore: $store,
                compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single(new NullCompressor()),
                clock: $this->clock,
                metrics: $this->metrics,
                hasher: new ComputePayloadHash(),
                serializer: SerializerName::phpNative(),
                compression: CompressionName::none(),
                backendVersion: new BackendVersion(1),
                minCompressedBytes: 0,
            ),
            $localStores,
        );
        $readers = \array_map(
            fn(InMemoryLocalPayloadStore $store): ReadCacheEntry => new ReadCacheEntry(
                metadataCache: $this->sharedMetadata,
                localStore: $store,
                compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single(new NullCompressor()),
                clock: $this->clock,
                metrics: $this->metrics,
            ),
            $localStores,
        );

        foreach ($writers as $writer) {
            $writer->execute($this->namespace, $id, 'shared', new TagSet(), 3600);
        }
        foreach ($readers as $i => $reader) {
            self::assertSame('shared', $reader->execute($this->namespace, $id), "Pod {$i} initial hit");
        }

        // An arbitrary pod (here: Pod 0) flushes
        $this->flusher->execute($this->namespace);

        // All 5 pods immediately see a miss
        foreach ($readers as $i => $reader) {
            self::assertNull(
                $reader->execute($this->namespace, $id),
                "Pod {$i} must see the flush propagated through shared metadata cache",
            );
        }
    }
}
