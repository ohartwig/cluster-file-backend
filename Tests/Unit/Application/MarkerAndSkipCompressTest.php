<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Application;

use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
use Moselwal\Typo3ClusterCache\Application\Read\ReadCacheEntry;
use Moselwal\Typo3ClusterCache\Application\Write\WriteCacheEntry;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionAlgo;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use Moselwal\Typo3ClusterCache\Tests\Support\InMemoryLocalPayloadStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * v2.2.0 marker-prefixed payload format + skip-compress threshold:
 * the writer prepends a one-byte CompressionAlgo marker, the reader uses
 * that marker to pick a decompressor. Below `minCompressedBytes` the
 * writer falls back to NullCompressor regardless of the configured codec.
 */
#[CoversClass(WriteCacheEntry::class)]
#[CoversClass(ReadCacheEntry::class)]
final class MarkerAndSkipCompressTest extends TestCase
{
    private CacheNamespace $namespace;
    private FakeMetadataCache $metadataCache;
    private InMemoryLocalPayloadStore $local;
    private FakeClock $clock;
    private FakeMetrics $metrics;

    protected function setUp(): void
    {
        $this->namespace = new CacheNamespace(EnvironmentName::Testing, 'site', 'fluid_template');
        $this->metadataCache = new FakeMetadataCache();
        $this->local = new InMemoryLocalPayloadStore();
        $this->clock = new FakeClock(1_700_000_000);
        $this->metrics = new FakeMetrics();
    }

    public function testSmallPayloadIsStoredUncompressedAndStartsWithNoneMarker(): void
    {
        $writer = $this->buildWriter(CompressionName::gzip(), minCompressedBytes: 1024);
        $id = new CacheIdentifier('small');
        $payload = str_repeat('x', 512);

        $writer->execute($this->namespace, $id, $payload, new TagSet([]), 3600);

        $stored = $this->capturedDiskBytes();
        self::assertSame(CompressionAlgo::None->marker(), $stored[0], 'small payload below threshold must use None marker');
        self::assertSame($payload, substr($stored, 1), 'payload bytes after marker must equal raw input (no compression applied)');
    }

    public function testLargePayloadGetsConfiguredCompressionMarker(): void
    {
        $writer = $this->buildWriter(CompressionName::gzip(), minCompressedBytes: 1024);
        $id = new CacheIdentifier('large');
        $payload = str_repeat('repeatable-block-', 200);

        $writer->execute($this->namespace, $id, $payload, new TagSet([]), 3600);

        $stored = $this->capturedDiskBytes();
        self::assertSame(CompressionAlgo::Gzip->marker(), $stored[0]);
        self::assertNotSame($payload, substr($stored, 1));
    }

    public function testRoundTripPreservesPayloadForBothPaths(): void
    {
        $writer = $this->buildWriter(CompressionName::gzip(), minCompressedBytes: 1024);
        $reader = $this->buildReader();

        $writer->execute($this->namespace, new CacheIdentifier('tiny'), 'hello', new TagSet([]), 3600);
        $writer->execute($this->namespace, new CacheIdentifier('huge'), str_repeat('compressible-', 300), new TagSet([]), 3600);

        self::assertSame('hello', $reader->execute($this->namespace, new CacheIdentifier('tiny')));
        self::assertSame(str_repeat('compressible-', 300), $reader->execute($this->namespace, new CacheIdentifier('huge')));
    }

    public function testThresholdZeroAlwaysCompresses(): void
    {
        $writer = $this->buildWriter(CompressionName::gzip(), minCompressedBytes: 0);
        $writer->execute($this->namespace, new CacheIdentifier('e'), 'x', new TagSet([]), 3600);

        $stored = $this->capturedDiskBytes();
        self::assertSame(CompressionAlgo::Gzip->marker(), $stored[0]);
    }

    public function testNoneConfiguredAlwaysSkipsCompression(): void
    {
        // PhpFrontend path: setCache() forces compression = none on the
        // backend instance. WriteCacheEntry must honour that without any
        // size-based logic in between.
        $writer = $this->buildWriter(CompressionName::none(), minCompressedBytes: 1024);
        $writer->execute($this->namespace, new CacheIdentifier('php'), str_repeat('x', 5000), new TagSet([]), 3600);

        $stored = $this->capturedDiskBytes();
        self::assertSame(CompressionAlgo::None->marker(), $stored[0]);
    }

    public function testCorruptMarkerByteIsTreatedAsIntegrityFailure(): void
    {
        $writer = $this->buildWriter(CompressionName::none(), minCompressedBytes: 1024);
        $reader = $this->buildReader();
        $id = new CacheIdentifier('corrupt');
        $writer->execute($this->namespace, $id, 'payload', new TagSet([]), 3600);

        // Rewrite the disk bytes with an unknown marker (0xFF) while
        // keeping the same hash key. The reader must surface a miss and
        // promote the metadata entry to broken.
        $metadata = $this->metadataCache->get($id);
        self::assertNotNull($metadata);
        $this->local->corrupt($metadata->hash, "\xFF" . 'payload');

        // Round-trip and confirm miss + broken-state.
        $result = $reader->execute($this->namespace, $id);
        self::assertNull($result);
    }

    private function buildWriter(CompressionName $compression, int $minCompressedBytes): WriteCacheEntry
    {
        return new WriteCacheEntry(
            metadataCache: $this->metadataCache,
            localStore: $this->local,
            compressorsByAlgo: CodecRegistry::all(),
            clock: $this->clock,
            metrics: $this->metrics,
            hasher: new ComputePayloadHash(),
            serializer: SerializerName::phpNative(),
            compression: $compression,
            backendVersion: new BackendVersion(1),
            minCompressedBytes: $minCompressedBytes,
        );
    }

    private function buildReader(): ReadCacheEntry
    {
        return new ReadCacheEntry(
            metadataCache: $this->metadataCache,
            localStore: $this->local,
            compressorsByAlgo: CodecRegistry::all(),
            clock: $this->clock,
            metrics: $this->metrics,
        );
    }

    private function capturedDiskBytes(): string
    {
        $hashes = iterator_to_array($this->local->iterateAll());
        self::assertCount(1, $hashes, 'expected exactly one stored payload');
        $hash = $hashes[0];
        self::assertInstanceOf(PayloadHash::class, $hash);
        $reflection = new \ReflectionClass($this->local);
        $files = $reflection->getProperty('files');
        /** @var array<string, string> $map */
        $map = $files->getValue($this->local);

        return $map[$hash->digest];
    }
}
