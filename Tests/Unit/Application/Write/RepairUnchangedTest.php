<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Application\Write;

use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
use Moselwal\Typo3ClusterCache\Application\Write\WriteCacheEntry;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
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
 * A set() that would write what is already there writes nothing: the same
 * PHP cache entry rebuilt by a second worker after a flush.
 */
#[CoversClass(WriteCacheEntry::class)]
final class RepairUnchangedTest extends TestCase
{
    private CacheNamespace $namespace;
    private FakeMetadataCache $metadataCache;
    private InMemoryLocalPayloadStore $local;
    private FakeClock $clock;
    private FakeMetrics $metrics;
    private WriteCacheEntry $writer;

    protected function setUp(): void
    {
        $this->namespace = new CacheNamespace(EnvironmentName::Testing, 'site', 'cfb_code');
        $this->metadataCache = new FakeMetadataCache();
        $this->local = new InMemoryLocalPayloadStore();
        $this->clock = new FakeClock(1_700_000_000);
        $this->metrics = new FakeMetrics();
        $this->writer = new WriteCacheEntry(
            metadataCache: $this->metadataCache,
            localStore: $this->local,
            compressorsByAlgo: CodecRegistry::all(),
            clock: $this->clock,
            metrics: $this->metrics,
            hasher: new ComputePayloadHash(),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(3),
            minCompressedBytes: 1024,
            bareBytes: true,
        );
    }

    public function testAnIdenticalSetWritesNeitherMetadataNorFile(): void
    {
        $id = new CacheIdentifier('setup');
        $code = "<?php\nreturn ['page' => 'Start'];\n#";
        $this->writer->execute($this->namespace, $id, $code, new TagSet(['a', 'b']), 0);
        $stored = $this->metadataCache->get($id);
        self::assertNotNull($stored);

        $this->clock->advance(60);
        $this->writer->execute($this->namespace, $id, $code, new TagSet(['b', 'a']), 0);

        self::assertSame($stored, $this->metadataCache->get($id), 'the metadata was not written again');
        self::assertSame(1, $this->metrics->counterTotal('repair_unchanged_total'));
        self::assertSame(0, $this->metrics->counterTotal('repair_success_total'));
    }

    public function testAMissingFileIsWrittenAndTheMetadataLeftAlone(): void
    {
        $id = new CacheIdentifier('setup');
        $code = "<?php\nreturn ['page' => 'Start'];\n#";
        $this->writer->execute($this->namespace, $id, $code, new TagSet([]), 0);
        $stored = $this->metadataCache->get($id);
        self::assertNotNull($stored);
        $this->local->delete($stored->hash);

        $this->writer->execute($this->namespace, $id, $code, new TagSet([]), 0);

        self::assertTrue($this->local->exists($stored->hash), 'the pod that lacked the file has it now');
        self::assertSame($stored, $this->metadataCache->get($id));
    }

    public function testOtherTagsOrALimitedLifetimeStillRepair(): void
    {
        $id = new CacheIdentifier('setup');
        $code = "<?php\nreturn ['page' => 'Start'];\n#";
        $this->writer->execute($this->namespace, $id, $code, new TagSet(['a']), 0);

        $this->writer->execute($this->namespace, $id, $code, new TagSet(['a', 'c']), 0);
        self::assertSame(['a', 'c'], $this->metadataCache->get($id)?->tags->toArray());

        $this->writer->execute($this->namespace, $id, $code, new TagSet(['a', 'c']), 3600);
        self::assertFalse($this->metadataCache->get($id)?->lifetime->isUnlimited(), 'a limited lifetime is written');
        self::assertSame(2, $this->metrics->counterTotal('repair_success_total'));
        self::assertSame(0, $this->metrics->counterTotal('repair_unchanged_total'));
    }
}
