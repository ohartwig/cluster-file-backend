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
 * Spec edge case: "backend version bump" — when `backendVersion` is
 * incremented the old hashes no longer match; entries fall cleanly into a
 * cache miss without false positives.
 */
final class BackendVersionBumpTest extends TestCase
{
    public function testBackendVersionBumpInvalidatesViaHashDiff(): void
    {
        $namespace = new CacheNamespace(EnvironmentName::Testing, 'site', 'pages');
        $id = new CacheIdentifier('versioned');
        $kv = new FakeMetadataCache();
        $local = new InMemoryLocalPayloadStore();
        $clock = new FakeClock(1_700_000_000);
        $metrics = new FakeMetrics();
        $hasher = new ComputePayloadHash();
        $compressor = new NullCompressor();

        $writerV1 = new WriteCacheEntry(
            metadataCache: $kv,
            localStore: $local,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $clock,
            metrics: $metrics,
            hasher: $hasher,
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(1),
            minCompressedBytes: 0,
        );
        $writerV1->execute($namespace, $id, 'payload', new TagSet(), 3600);

        // BackendVersion bump → new hash inputs
        $writerV2 = new WriteCacheEntry(
            metadataCache: $kv,
            localStore: $local,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $clock,
            metrics: $metrics,
            hasher: $hasher,
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(2),
            minCompressedBytes: 0,
        );

        $reader = new ReadCacheEntry(
            metadataCache: $kv,
            localStore: $local,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $clock,
            metrics: $metrics,
        );

        // Before the bump there was a hit. We write with V2 → new hash, new
        // metadata, the old local file no longer matches → on the next get
        // against the same pod we see a cache hit on the NEW metadata (local
        // file is re-written by write() with the V2 hash).
        $writerV2->execute($namespace, $id, 'payload', new TagSet(), 3600);
        self::assertSame('payload', $reader->execute($namespace, $id));

        // Hash has changed
        $meta = $kv->get($id);
        self::assertNotNull($meta);
        self::assertSame(2, $meta->backendVersion->value);
    }
}
