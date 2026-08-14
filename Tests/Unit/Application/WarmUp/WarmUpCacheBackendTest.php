<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Application\WarmUp;

use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
use Moselwal\Typo3ClusterCache\Application\WarmUp\WarmUpCacheBackend;
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

#[CoversClass(WarmUpCacheBackend::class)]
final class WarmUpCacheBackendTest extends TestCase
{
    public function testReportsHealthyOnHappyPath(): void
    {
        $namespace = new CacheNamespace(EnvironmentName::Testing, 'site', 'pages');
        $metadataCache = new FakeMetadataCache();
        $localStore = new InMemoryLocalPayloadStore();

        $service = new WarmUpCacheBackend(
            metadataCache: $metadataCache,
            localStore: $localStore,
            clock: new FakeClock(1_700_000_000),
            metrics: new FakeMetrics(),
        );
        $report = $service->execute($namespace);

        self::assertTrue($report->metadataCacheHealthy);
        self::assertTrue($report->localStoreWritable);
        self::assertSame(0, $report->prefetchedIdentifiers);
        self::assertTrue($report->succeeded());
    }

    public function testReportsLocalHitsAndBlobMissesForProbes(): void
    {
        $namespace = new CacheNamespace(EnvironmentName::Testing, 'site', 'pages');
        $metadataCache = new FakeMetadataCache();
        $localStore = new InMemoryLocalPayloadStore();
        $metrics = new FakeMetrics();
        $clock = new FakeClock(1_700_000_000);

        // Seed two entries, one local, one not
        $writer = new WriteCacheEntry(
            metadataCache: $metadataCache,
            localStore: $localStore,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single(new NullCompressor()),
            clock: $clock,
            metrics: $metrics,
            hasher: new ComputePayloadHash(),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(1),
            minCompressedBytes: 0,
        );
        $present = new CacheIdentifier('page_42');
        $missing = new CacheIdentifier('page_7');
        $writer->execute($namespace, $present, 'present_payload', new TagSet(), 3600);
        $writer->execute($namespace, $missing, 'missing_payload', new TagSet(), 3600);
        // Drop the local copy of the second one to simulate a fresh pod
        foreach ($localStore->iterateAll() as $hash) {
            $metadata = $metadataCache->get($missing);
            if (null !== $metadata && $metadata->hash->equals($hash)) {
                $localStore->delete($hash);
            }
        }

        $service = new WarmUpCacheBackend(
            metadataCache: $metadataCache,
            localStore: $localStore,
            clock: $clock,
            metrics: $metrics,
        );
        $report = $service->execute($namespace, [$present, $missing]);

        self::assertSame(2, $report->prefetchedIdentifiers);
        self::assertSame(1, $report->localHits);
        self::assertSame(1, $report->blobMisses);
        self::assertTrue($report->succeeded());
    }

    public function testCountsWarmupMetric(): void
    {
        $metrics = new FakeMetrics();
        $service = new WarmUpCacheBackend(
            metadataCache: new FakeMetadataCache(),
            localStore: new InMemoryLocalPayloadStore(),
            clock: new FakeClock(1_700_000_000),
            metrics: $metrics,
        );
        $service->execute(new CacheNamespace(EnvironmentName::Testing, 'site', 'pages'));

        self::assertSame(1, $metrics->counterTotal('cache_warmup_total'));
    }
}
