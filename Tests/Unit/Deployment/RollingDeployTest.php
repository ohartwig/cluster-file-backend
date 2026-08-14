<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Deployment;

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
 * Spec SC-005 / T162: during a rolling deploy NO cache inconsistencies
 * appear between old and new pods. Simulated by two backend instances with
 * identical configuration (same BackendVersion, same serializer, same
 * compression) sharing the same metadata cache — stand-in for "Pod A (old)
 * + Pod B (new) with an identical image".
 */
final class RollingDeployTest extends TestCase
{
    public function testTwoBackendInstancesShareConsistentReads(): void
    {
        $namespace = new CacheNamespace(EnvironmentName::Testing, 'rolling', 'pages');
        $sharedCache = new FakeMetadataCache();
        $podALocal = new InMemoryLocalPayloadStore();
        $podBLocal = new InMemoryLocalPayloadStore();
        $clock = new FakeClock(1_700_000_000);
        $metrics = new FakeMetrics();
        $compressor = new NullCompressor();
        $hasher = new ComputePayloadHash();

        $writerA = new WriteCacheEntry(
            metadataCache: $sharedCache,
            localStore: $podALocal,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $clock,
            metrics: $metrics,
            hasher: $hasher,
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(1),
            minCompressedBytes: 0,
        );
        $readerA = new ReadCacheEntry(
            metadataCache: $sharedCache,
            localStore: $podALocal,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $clock,
            metrics: $metrics,
        );
        $readerB = new ReadCacheEntry(
            metadataCache: $sharedCache,
            localStore: $podBLocal,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $clock,
            metrics: $metrics,
        );

        $id = new CacheIdentifier('rolling_page');
        $writerA->execute($namespace, $id, 'shared_payload', new TagSet(), 3600);

        // Pod A serves from its local store
        self::assertSame('shared_payload', $readerA->execute($namespace, $id));
        // Pod B (freshly deployed, empty local store) sees a blob miss
        self::assertNull($readerB->execute($namespace, $id));
        self::assertSame(1, $metrics->counterTotal('blob_miss_total'));
    }

    public function testBlobMissOnNewPodIsRecoveredByDeterministicWrite(): void
    {
        $namespace = new CacheNamespace(EnvironmentName::Testing, 'rolling', 'pages');
        $sharedCache = new FakeMetadataCache();
        $podALocal = new InMemoryLocalPayloadStore();
        $podBLocal = new InMemoryLocalPayloadStore();
        $clock = new FakeClock(1_700_000_000);
        $metrics = new FakeMetrics();
        $compressor = new NullCompressor();
        $hasher = new ComputePayloadHash();

        $build = fn(InMemoryLocalPayloadStore $store): WriteCacheEntry => new WriteCacheEntry(
            metadataCache: $sharedCache,
            localStore: $store,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $clock,
            metrics: $metrics,
            hasher: $hasher,
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(1),
            minCompressedBytes: 0,
        );

        $writerA = $build($podALocal);
        $writerB = $build($podBLocal);
        $readerB = new ReadCacheEntry(
            metadataCache: $sharedCache,
            localStore: $podBLocal,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single($compressor),
            clock: $clock,
            metrics: $metrics,
        );

        $id = new CacheIdentifier('rolling_recover');
        $writerA->execute($namespace, $id, 'payload', new TagSet(), 3600);
        $metaA = $sharedCache->get($id);

        // Pod B sees a blob miss, the caller re-writes the identical payload
        self::assertNull($readerB->execute($namespace, $id));
        $writerB->execute($namespace, $id, 'payload', new TagSet(), 3600);
        $metaB = $sharedCache->get($id);

        // Hash stays identical — Pod B repairs without changing identity
        self::assertNotNull($metaA);
        self::assertNotNull($metaB);
        self::assertTrue($metaA->hash->equals($metaB->hash));
        self::assertSame('payload', $readerB->execute($namespace, $id));
        self::assertSame(1, $metrics->counterTotal('repair_success_total'));
    }
}
