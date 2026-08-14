<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\EdgeCases;

use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
use Moselwal\Typo3ClusterCache\Application\Read\ReadCacheEntry;
use Moselwal\Typo3ClusterCache\Application\Write\WriteCacheEntry;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\NullCompressor;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use Moselwal\Typo3ClusterCache\Tests\Support\InMemoryLocalPayloadStore;
use PHPUnit\Framework\TestCase;

/**
 * Verifies graceful degradation when the central metadata cache backend
 * (Redis/Valkey/DB) is unreachable.
 *
 * Behavioural contract:
 * - **Reads** must return null (= cache miss) so the TYPO3 frontend can
 *   trigger a caller rebuild. The application must NOT crash.
 * - **Writes** must surface the error so the caller can decide how to
 *   handle it. Silently swallowing write errors would mask Redis outages.
 * - **No cluster-wide corruption**: even repeated failures leave the
 *   local payload store untouched (no orphans created).
 */
final class MetadataCacheOfflineTest extends TestCase
{
    public function testReadReturnsNullWhenMetadataBackendThrows(): void
    {
        $reader = $this->buildReader($this->throwingMetadataCache());
        $namespace = new CacheNamespace(EnvironmentName::Testing, 'site', 'pages');
        $id = new CacheIdentifier('any_id');

        // Must not throw — TYPO3 caching frontends expect false/null on
        // read failure so they can rebuild.
        self::assertNull($reader->execute($namespace, $id));
    }

    public function testWriteSurfacesUnderlyingExceptionForCallerHandling(): void
    {
        $namespace = new CacheNamespace(EnvironmentName::Testing, 'site', 'pages');
        $writer = $this->buildWriter($this->throwingMetadataCache(), new InMemoryLocalPayloadStore());

        $this->expectException(\RuntimeException::class);
        $writer->execute(
            $namespace,
            new CacheIdentifier('any_id'),
            'payload',
            new TagSet(),
            3600,
        );
    }

    public function testGetFailureLeavesLocalStoreCompletelyUntouched(): void
    {
        // When the metadata backend is dead, the write path fails at the
        // initial metadata.get() (used for the repair-path check) — before
        // any local I/O runs. No orphans created at all.
        $namespace = new CacheNamespace(EnvironmentName::Testing, 'site', 'pages');
        $localStore = new InMemoryLocalPayloadStore();
        $writer = $this->buildWriter($this->throwingMetadataCache(), $localStore);

        try {
            $writer->execute(
                $namespace,
                new CacheIdentifier('any_id'),
                'payload',
                new TagSet(),
                3600,
            );
        } catch (\RuntimeException) {
            // expected — the throwing metadata cache surfaced.
        }

        $hashes = [];
        foreach ($localStore->iterateAll() as $hash) {
            $hashes[] = $hash;
        }
        self::assertCount(
            0,
            $hashes,
            'Local store stays empty when metadata backend is unreachable',
        );
    }

    public function testOrphanFromFailedMetadataSetIsBoundedToOneFile(): void
    {
        // Subtler case: metadata.get() succeeds (returns null = no
        // existing entry), local.write() succeeds, then metadata.set()
        // fails. The local file is left behind as a harmless orphan —
        // unreachable without metadata, removed by GC or pod restart.
        // This test asserts the orphan count never grows beyond one per
        // failed write (no accidental retries / duplicates).
        $namespace = new CacheNamespace(EnvironmentName::Testing, 'site', 'pages');
        $localStore = new InMemoryLocalPayloadStore();
        $metadata = $this->setThrowingMetadataCache();
        $writer = $this->buildWriter($metadata, $localStore);

        try {
            $writer->execute(
                $namespace,
                new CacheIdentifier('any_id'),
                'payload',
                new TagSet(),
                3600,
            );
        } catch (\RuntimeException) {
            // expected — only metadata.set() throws.
        }

        $hashes = [];
        foreach ($localStore->iterateAll() as $hash) {
            $hashes[] = $hash;
        }
        self::assertCount(
            1,
            $hashes,
            'Local file is the expected harmless orphan; only metadata.set() failed',
        );
    }

    private function setThrowingMetadataCache(): MetadataCachePort
    {
        // get() succeeds (returns null), set() throws.
        return new class implements MetadataCachePort {
            public function get(CacheIdentifier $identifier): ?CacheMetadata
            {
                return null;
            }

            public function set(CacheIdentifier $identifier, CacheMetadata $metadata, array $tags, int $ttlSeconds): void
            {
                throw new \RuntimeException('Redis connection refused on set');
            }

            public function remove(CacheIdentifier $identifier): bool
            {
                return false;
            }

            public function flush(): void {}

            public function flushByTag(string $tag): void {}

            public function flushByTags(array $tags): void {}

            public function findIdentifiersByTag(string $tag): array
            {
                return [];
            }

            public function collectGarbage(): void {}
        };
    }

    private function throwingMetadataCache(): MetadataCachePort
    {
        return new class implements MetadataCachePort {
            public function get(CacheIdentifier $identifier): ?CacheMetadata
            {
                throw new \RuntimeException('Redis connection refused');
            }

            public function set(CacheIdentifier $identifier, CacheMetadata $metadata, array $tags, int $ttlSeconds): void
            {
                throw new \RuntimeException('Redis connection refused');
            }

            public function remove(CacheIdentifier $identifier): bool
            {
                throw new \RuntimeException('Redis connection refused');
            }

            public function flush(): void
            {
                throw new \RuntimeException('Redis connection refused');
            }

            public function flushByTag(string $tag): void
            {
                throw new \RuntimeException('Redis connection refused');
            }

            public function flushByTags(array $tags): void
            {
                throw new \RuntimeException('Redis connection refused');
            }

            public function findIdentifiersByTag(string $tag): array
            {
                throw new \RuntimeException('Redis connection refused');
            }

            public function collectGarbage(): void
            {
                throw new \RuntimeException('Redis connection refused');
            }
        };
    }

    private function buildReader(MetadataCachePort $metadata): ReadCacheEntry
    {
        return new ReadCacheEntry(
            metadataCache: $metadata,
            localStore: new InMemoryLocalPayloadStore(),
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single(new NullCompressor()),
            clock: new FakeClock(1_700_000_000),
            metrics: new FakeMetrics(),
        );
    }

    private function buildWriter(MetadataCachePort $metadata, InMemoryLocalPayloadStore $local): WriteCacheEntry
    {
        return new WriteCacheEntry(
            metadataCache: $metadata,
            localStore: $local,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single(new NullCompressor()),
            clock: new FakeClock(1_700_000_000),
            metrics: new FakeMetrics(),
            hasher: new ComputePayloadHash(),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(1),
            minCompressedBytes: 0,
        );
    }
}
