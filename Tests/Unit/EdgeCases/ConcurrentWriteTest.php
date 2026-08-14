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
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\NullCompressor;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use Moselwal\Typo3ClusterCache\Tests\Support\InMemoryLocalPayloadStore;
use PHPUnit\Framework\TestCase;

/**
 * Two pods write to the same cache identifier nearly simultaneously.
 *
 * Contract verified by these tests:
 * - **Last-Writer-Wins** at the metadata level: the surviving metadata
 *   record reflects exactly one of the writes (deterministically the
 *   latest), never a merged state.
 * - **Loser-hash becomes an orphan** in its own pod's local store —
 *   harmless, cleaned up by `emptyDir` reset on pod restart or by GC.
 * - **No data corruption**: a reader on either pod returns either the
 *   winning content (when the winner's local file is present) or null
 *   (blob miss, triggers caller rebuild). Never the loser's content
 *   served as if it were the winner.
 *
 * Note: we cannot test true concurrency in PHP unit tests; instead we
 * simulate by writing in close sequence against a shared metadata cache
 * and asserting the final convergent state.
 */
final class ConcurrentWriteTest extends TestCase
{
    private CacheNamespace $namespace;
    private FakeMetadataCache $sharedMetadata;
    private InMemoryLocalPayloadStore $podALocal;
    private InMemoryLocalPayloadStore $podBLocal;
    private WriteCacheEntry $podAWriter;
    private WriteCacheEntry $podBWriter;
    private ReadCacheEntry $podAReader;
    private ReadCacheEntry $podBReader;
    private FakeMetrics $metrics;

    protected function setUp(): void
    {
        $this->namespace = new CacheNamespace(EnvironmentName::Testing, 'cluster', 'pages');
        $this->sharedMetadata = new FakeMetadataCache();
        $this->podALocal = new InMemoryLocalPayloadStore();
        $this->podBLocal = new InMemoryLocalPayloadStore();
        $clock = new FakeClock(1_700_000_000);
        $this->metrics = new FakeMetrics();
        $compressor = new NullCompressor();
        $hasher = new ComputePayloadHash();

        $build = fn(InMemoryLocalPayloadStore $store): WriteCacheEntry => new WriteCacheEntry(
            metadataCache: $this->sharedMetadata,
            localStore: $store,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $clock,
            metrics: $this->metrics,
            hasher: $hasher,
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(1),
            minCompressedBytes: 0,
        );
        $this->podAWriter = $build($this->podALocal);
        $this->podBWriter = $build($this->podBLocal);

        $buildReader = fn(InMemoryLocalPayloadStore $store): ReadCacheEntry => new ReadCacheEntry(
            metadataCache: $this->sharedMetadata,
            localStore: $store,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $clock,
            metrics: $this->metrics,
        );
        $this->podAReader = $buildReader($this->podALocal);
        $this->podBReader = $buildReader($this->podBLocal);
    }

    public function testLastWriterWinsAtMetadataLevel(): void
    {
        $id = new CacheIdentifier('hot_key');

        $this->podAWriter->execute($this->namespace, $id, 'payload_A', new TagSet(), 3600);
        $this->podBWriter->execute($this->namespace, $id, 'payload_B', new TagSet(), 3600);

        $finalMetadata = $this->sharedMetadata->get($id);
        self::assertNotNull($finalMetadata);
        // Pod B wrote last → its hash must be the surviving one.
        $expectedHashB = $this->hashFor('payload_B');
        self::assertSame($expectedHashB, $finalMetadata->hash->digest);
    }

    public function testLoserHashBecomesHarmlessOrphan(): void
    {
        $id = new CacheIdentifier('hot_key_orphan');

        $this->podAWriter->execute($this->namespace, $id, 'payload_A', new TagSet(), 3600);
        $this->podBWriter->execute($this->namespace, $id, 'payload_B', new TagSet(), 3600);

        // Pod A's local store still has the file for payload_A (its hash),
        // but the metadata no longer references it — orphan.
        $podAHashes = [];
        foreach ($this->podALocal->iterateAll() as $hash) {
            $podAHashes[] = $hash->digest;
        }
        self::assertContains(
            $this->hashFor('payload_A'),
            $podAHashes,
            'Pod A keeps the orphan blob until emptyDir reset or GC',
        );

        // Reading on Pod A: metadata says hash_B, Pod A does not have
        // hash_B locally → blob miss → null (TYPO3 frontend will rebuild).
        self::assertNull($this->podAReader->execute($this->namespace, $id));
    }

    public function testWinnerPodReadsConsistently(): void
    {
        $id = new CacheIdentifier('hot_key_winner');

        $this->podAWriter->execute($this->namespace, $id, 'payload_A', new TagSet(), 3600);
        $this->podBWriter->execute($this->namespace, $id, 'payload_B', new TagSet(), 3600);

        // Pod B (winner) has both metadata and the local blob for hash_B.
        self::assertSame('payload_B', $this->podBReader->execute($this->namespace, $id));
    }

    public function testIdenticalPayloadDoesNotCauseOrphans(): void
    {
        // If both pods write the *same* bytes, the hash is identical and
        // no orphan is created — neither side has anything to clean up.
        $id = new CacheIdentifier('hot_key_idempotent');

        $this->podAWriter->execute($this->namespace, $id, 'same_payload', new TagSet(), 3600);
        $this->podBWriter->execute($this->namespace, $id, 'same_payload', new TagSet(), 3600);

        $podAHashes = $this->collectHashes($this->podALocal);
        $podBHashes = $this->collectHashes($this->podBLocal);
        self::assertCount(1, $podAHashes);
        self::assertCount(1, $podBHashes);
        self::assertSame($podAHashes, $podBHashes);
    }

    private function hashFor(string $payload): string
    {
        return new ComputePayloadHash()->fromRawBytes(
            $payload,
            SerializerName::phpNative(),
            CompressionName::none(),
            new BackendVersion(1),
        )->digest;
    }

    /**
     * @return list<string>
     */
    private function collectHashes(InMemoryLocalPayloadStore $store): array
    {
        $hashes = [];
        foreach ($store->iterateAll() as $hash) {
            $hashes[] = $hash->digest;
        }

        return $hashes;
    }
}
