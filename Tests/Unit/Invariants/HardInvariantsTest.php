<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Invariants;

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
 * Verifies the 10 "hard invariants" from spec.md against the actual
 * behaviour of the application services. Every invariant has its own test;
 * the identifiers (`I1` … `I10`) match the numbering in the spec.
 *
 * Not every invariant is meaningfully testable inside the package — I6 (no
 * shared cache volumes), I7 (no RWX filesystems), I8 (core cache deployed)
 * and I9 (FAL outside) are architecture/configuration statements enforced
 * outside this package. They are bypassed here with `markTestSkipped()`
 * and a documenting justification.
 */
final class HardInvariantsTest extends TestCase
{
    private CacheNamespace $namespace;
    private FakeMetadataCache $metadataCache;
    private InMemoryLocalPayloadStore $local;
    private FakeClock $clock;
    private FakeMetrics $metrics;
    private WriteCacheEntry $writer;
    private ReadCacheEntry $reader;

    protected function setUp(): void
    {
        $this->namespace = new CacheNamespace(EnvironmentName::Testing, 'invariants', 'pages');
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
    }

    /** I1: metadata is the source of truth. */
    public function testI1MetadataIsSourceOfTruth(): void
    {
        $id = new CacheIdentifier('i1');
        $this->writer->execute($this->namespace, $id, 'bytes', new TagSet(), 3600);

        // Delete the local file manually — metadata stays unchanged
        foreach ($this->local->iterateAll() as $hash) {
            $this->local->delete($hash);
        }
        // Metadata is still valid (source of truth)
        self::assertNotNull($this->metadataCache->get($id));
    }

    /** I2: local files are merely materialisation. */
    public function testI2LocalFilesAreMerelyMaterialization(): void
    {
        $id = new CacheIdentifier('i2');
        // Create file "by hand" — without metadata
        $hash = new \Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash(
            hash('sha256', 'rogue'),
        );
        $this->local->write($hash, 'rogue');

        // Read MUST yield a cache miss — metadata is missing
        self::assertNull($this->reader->execute($this->namespace, $id));
    }

    /** I3: a missing file is NOT a cache miss but a blob miss. */
    public function testI3MissingFileIsBlobMissNotCacheMiss(): void
    {
        $id = new CacheIdentifier('i3');
        $this->writer->execute($this->namespace, $id, 'bytes', new TagSet(), 3600);

        foreach ($this->local->iterateAll() as $hash) {
            $this->local->delete($hash);
        }
        self::assertNull($this->reader->execute($this->namespace, $id));
        self::assertSame(
            1,
            $this->metrics->counterTotal('blob_miss_total'),
            'Blob-miss metric must increment, NOT cache-miss'
        );
        self::assertSame(
            0,
            $this->metrics->counterTotal('cache_miss_total'),
            'Cache-miss metric must NOT increment for a missing file'
        );
    }

    /** I4: repair must not produce a new identity. */
    public function testI4RepairKeepsIdentity(): void
    {
        $id = new CacheIdentifier('i4');
        $this->writer->execute($this->namespace, $id, 'bytes', new TagSet(), 3600);
        $originalHash = $this->metadataCache->get($id)?->hash->digest;
        self::assertNotNull($originalHash);

        // Delete file, caller writes the same bytes again → repair path
        foreach ($this->local->iterateAll() as $hash) {
            $this->local->delete($hash);
        }
        $this->writer->execute($this->namespace, $id, 'bytes', new TagSet(), 3600);

        $newHash = $this->metadataCache->get($id)?->hash->digest;
        self::assertSame($originalHash, $newHash, 'Repair must preserve the identical hash');
        self::assertSame(1, $this->metrics->counterTotal('repair_success_total'));
    }

    /** I5: rebuild (in the TYPO3 sense) only on a real cache miss — tested implicitly via I3. */
    public function testI5RebuildOnlyOnRealCacheMiss(): void
    {
        $id = new CacheIdentifier('i5');
        $this->writer->execute($this->namespace, $id, 'bytes', new TagSet(), 3600);

        // First read → hit, no rebuild
        self::assertSame('bytes', $this->reader->execute($this->namespace, $id));
        self::assertSame(
            0,
            $this->metrics->counterTotal('payload_rebuild_total'),
            'On a cache hit no rebuild must occur'
        );
    }

    /** I6: no shared cache volumes. */
    public function testI6NoSharedCacheVolumes(): void
    {
        self::markTestSkipped(
            'I6 is an architecture rule (Kubernetes deployment topology); '
            . 'not testable inside the package. Enforced by the absence of '
            . 'any RWX / shared-FS operations in the code path.',
        );
    }

    /** I7: no RWX filesystems. */
    public function testI7NoRwxFilesystems(): void
    {
        self::markTestSkipped(
            'I7 is a deployment rule; not testable inside the package.',
        );
    }

    /** I8: core cache remains deployed. */
    public function testI8CoreCacheRemainsDeployed(): void
    {
        self::markTestSkipped(
            'I8 is a container image build statement; not testable inside the package.',
        );
    }

    /** I9: FAL stays outside the cache system. */
    public function testI9FalRemainsOutside(): void
    {
        self::markTestSkipped(
            'I9 is an architectural boundary; not testable inside the package.',
        );
    }

    /** I10: payload files may be lost locally at any time. */
    public function testI10PayloadFilesMayBeLostAnytime(): void
    {
        $id = new CacheIdentifier('i10');
        $this->writer->execute($this->namespace, $id, 'bytes', new TagSet(), 3600);

        // Repeated "file vanished" + re-computation by caller
        for ($i = 0; $i < 5; ++$i) {
            foreach ($this->local->iterateAll() as $hash) {
                $this->local->delete($hash);
            }
            // Caller observes blob miss (false) and re-writes
            self::assertNull($this->reader->execute($this->namespace, $id));
            $this->writer->execute($this->namespace, $id, 'bytes', new TagSet(), 3600);
            // Subsequent read → hit
            self::assertSame('bytes', $this->reader->execute($this->namespace, $id));
        }
        // Identifier is still consistent after 5 loss cycles
        $finalMeta = $this->metadataCache->get($id);
        self::assertNotNull($finalMeta);
        self::assertSame('valid', $finalMeta->state->value);
    }
}
