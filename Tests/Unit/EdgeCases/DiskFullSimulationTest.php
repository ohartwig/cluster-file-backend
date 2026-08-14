<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\EdgeCases;

use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
use Moselwal\Typo3ClusterCache\Application\Write\WriteCacheEntry;
use Moselwal\Typo3ClusterCache\Domain\Contract\LocalPayloadStorePort;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Exception\LocalStoreWriteException;
use Moselwal\Typo3ClusterCache\Domain\Exception\PayloadNotFoundException;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\NullCompressor;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use PHPUnit\Framework\TestCase;

/**
 * Spec edge case: "pod-local volume full" — write attempts must fail in a
 * controlled way; reads must not deliver inconsistencies.
 */
final class DiskFullSimulationTest extends TestCase
{
    public function testDiskFullDuringWritePropagatesAsLocalStoreWriteException(): void
    {
        $diskFullStore = new class implements LocalPayloadStorePort {
            public function pathFor(PayloadHash $hash): string
            {
                return '/full/' . $hash->digest;
            }

            public function exists(PayloadHash $hash): bool
            {
                return false;
            }

            public function readVerified(PayloadHash $hash, PayloadChecksum $checksum): string
            {
                throw new PayloadNotFoundException('not found');
            }

            public function write(PayloadHash $hash, string $bytes): void
            {
                throw new LocalStoreWriteException('ENOSPC: no space left on device');
            }

            public function delete(PayloadHash $hash): void {}

            public function probe(): bool
            {
                return false;
            }

            public function iterateAll(): iterable
            {
                yield from [];
            }
        };

        $writer = new WriteCacheEntry(
            metadataCache: new FakeMetadataCache(),
            localStore: $diskFullStore,
            compressorsByAlgo: \Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry::single(new NullCompressor()),
            clock: new FakeClock(1_700_000_000),
            metrics: new FakeMetrics(),
            hasher: new ComputePayloadHash(),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(1),
            minCompressedBytes: 0,
        );

        $this->expectException(LocalStoreWriteException::class);
        $writer->execute(
            new CacheNamespace(EnvironmentName::Testing, 'site', 'pages'),
            new CacheIdentifier('id_1'),
            'payload',
            new TagSet(),
            3600,
        );
    }
}
