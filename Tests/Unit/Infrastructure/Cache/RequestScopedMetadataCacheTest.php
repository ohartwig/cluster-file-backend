<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\Cache;

use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Enum\CacheState;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\Lifetime;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\RequestScopedMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\CountingMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The memo saves metadata round trips (a network hop to Valkey in production)
 * within one request. Its contract has two halves, and both are pinned here:
 * repeat reads are answered from RAM — misses included — and anything this
 * instance writes, removes or flushes is reflected by its next read, even when
 * the write itself failed half-way.
 *
 * "Another pod" is simulated by writing to the shared inner store directly,
 * bypassing the decorator.
 */
#[CoversClass(RequestScopedMetadataCache::class)]
final class RequestScopedMetadataCacheTest extends TestCase
{
    private CountingMetadataCache $inner;
    private RequestScopedMetadataCache $memo;

    protected function setUp(): void
    {
        $this->inner = new CountingMetadataCache(new FakeMetadataCache());
        $this->memo = new RequestScopedMetadataCache($this->inner);
    }

    #[Test]
    public function aRepeatedReadOfAnEntryReachesTheInnerCacheOnce(): void
    {
        $id = new CacheIdentifier('entry-a');
        $metadata = $this->metadata($id, 'a');
        $this->inner->set($id, $metadata, [], 3600);

        $first = $this->memo->get($id);
        $second = $this->memo->get($id);

        self::assertSame($metadata, $first);
        self::assertSame($metadata, $second);
        self::assertSame(1, $this->inner->gets);
    }

    #[Test]
    public function aRepeatedReadOfAMissingEntryReachesTheInnerCacheOnce(): void
    {
        $id = new CacheIdentifier('missing');

        self::assertNull($this->memo->get($id));
        self::assertNull($this->memo->get($id));
        self::assertSame(1, $this->inner->gets);
    }

    #[Test]
    public function differentIdentifiersAreReadSeparately(): void
    {
        $a = new CacheIdentifier('entry-a');
        $b = new CacheIdentifier('entry-b');
        $this->inner->set($a, $this->metadata($a, 'a'), [], 3600);

        self::assertNotNull($this->memo->get($a));
        self::assertNull($this->memo->get($b));
        self::assertSame(2, $this->inner->gets);
    }

    /**
     * Within one instance a read is served from RAM even if another pod has
     * written meanwhile — that is the trade the memo makes, and why it must
     * never outlive a request.
     */
    #[Test]
    public function aWriteByAnotherPodIsNotSeenByTheSameInstance(): void
    {
        $id = new CacheIdentifier('entry-a');
        self::assertNull($this->memo->get($id));

        $this->inner->set($id, $this->metadata($id, 'a'), [], 3600);

        self::assertNull($this->memo->get($id));
    }

    #[Test]
    public function aFreshInstanceSeesWhatAnotherPodWrote(): void
    {
        $id = new CacheIdentifier('entry-a');
        self::assertNull($this->memo->get($id));

        $metadata = $this->metadata($id, 'a');
        $this->inner->set($id, $metadata, [], 3600);

        self::assertSame($metadata, new RequestScopedMetadataCache($this->inner)->get($id));
    }

    #[Test]
    public function aReadAfterOwnWriteReturnsTheWrittenMetadataWithoutARoundTrip(): void
    {
        $id = new CacheIdentifier('entry-a');
        self::assertNull($this->memo->get($id));

        $written = $this->metadata($id, 'b');
        $this->memo->set($id, $written, ['tag_a'], 600);

        self::assertSame($written, $this->memo->get($id));
        self::assertSame(1, $this->inner->gets);
        self::assertSame([['entry-a', ['tag_a'], 600]], $this->inner->setCalls);
    }

    #[Test]
    public function aFailedWriteDropsTheMemoisedValue(): void
    {
        $id = new CacheIdentifier('entry-a');
        $old = $this->metadata($id, 'a');
        $this->inner->set($id, $old, [], 3600);
        self::assertSame($old, $this->memo->get($id));

        // Another pod replaces the entry, then our own write fails.
        $theirs = $this->metadata($id, 'c');
        $this->inner->set($id, $theirs, [], 3600);
        $this->inner->failing = true;
        try {
            $this->memo->set($id, $this->metadata($id, 'b'), [], 3600);
            self::fail('the inner exception must propagate');
        } catch (\RuntimeException) {
        }
        $this->inner->failing = false;

        self::assertSame($theirs, $this->memo->get($id), 'state is unknown after a failed write: read the source');
        self::assertSame(2, $this->inner->gets);
    }

    #[Test]
    public function aReadAfterOwnRemoveIsAMissWithoutARoundTrip(): void
    {
        $id = new CacheIdentifier('entry-a');
        $this->inner->set($id, $this->metadata($id, 'a'), [], 3600);
        self::assertNotNull($this->memo->get($id));

        self::assertTrue($this->memo->remove($id));

        self::assertNull($this->memo->get($id));
        self::assertSame(1, $this->inner->gets);
        self::assertFalse($this->memo->remove($id), 'the inner result is passed through');
    }

    #[Test]
    public function aFailedRemoveDropsTheMemoisedValue(): void
    {
        $id = new CacheIdentifier('entry-a');
        $metadata = $this->metadata($id, 'a');
        $this->inner->set($id, $metadata, [], 3600);
        self::assertSame($metadata, $this->memo->get($id));

        $this->inner->failing = true;
        try {
            $this->memo->remove($id);
            self::fail('the inner exception must propagate');
        } catch (\RuntimeException) {
        }
        $this->inner->failing = false;

        self::assertSame($metadata, $this->memo->get($id));
        self::assertSame(2, $this->inner->gets);
    }

    /**
     * @return iterable<string, array{\Closure(MetadataCachePort): void}>
     */
    public static function invalidations(): iterable
    {
        yield 'flush' => [static function (MetadataCachePort $cache): void {
            $cache->flush();
        }];
        yield 'flushByTag' => [static function (MetadataCachePort $cache): void {
            $cache->flushByTag('tag_x');
        }];
        yield 'flushByTags' => [static function (MetadataCachePort $cache): void {
            $cache->flushByTags(['tag_x', 'tag_y']);
        }];
        yield 'collectGarbage' => [static function (MetadataCachePort $cache): void {
            $cache->collectGarbage();
        }];
    }

    /**
     * @param \Closure(MetadataCachePort): void $invalidate
     */
    #[Test]
    #[DataProvider('invalidations')]
    public function aFlushDropsTheWholeMemo(\Closure $invalidate): void
    {
        [$a, $b] = $this->twoMemoisedEntries();

        $invalidate($this->memo);
        $this->memo->get($a);
        $this->memo->get($b);

        self::assertSame(4, $this->inner->gets);
        self::assertSame(1, $this->inner->invalidations);
    }

    /**
     * @param \Closure(MetadataCachePort): void $invalidate
     */
    #[Test]
    #[DataProvider('invalidations')]
    public function aFailedFlushStillDropsTheWholeMemo(\Closure $invalidate): void
    {
        [$a, $b] = $this->twoMemoisedEntries();

        $this->inner->failing = true;
        try {
            $invalidate($this->memo);
            self::fail('the inner exception must propagate');
        } catch (\RuntimeException) {
        }
        $this->inner->failing = false;
        $this->memo->get($a);
        $this->memo->get($b);

        self::assertSame(4, $this->inner->gets);
    }

    #[Test]
    public function aFailedReadIsNotMemoised(): void
    {
        $id = new CacheIdentifier('entry-a');
        $this->inner->failing = true;
        try {
            $this->memo->get($id);
            self::fail('the inner exception must propagate');
        } catch (\RuntimeException) {
        }
        $this->inner->failing = false;

        $metadata = $this->metadata($id, 'a');
        $this->inner->set($id, $metadata, [], 3600);

        self::assertSame($metadata, $this->memo->get($id));
    }

    #[Test]
    public function findIdentifiersByTagIsPassedThrough(): void
    {
        $id = new CacheIdentifier('entry-a');
        $this->memo->set($id, $this->metadata($id, 'a'), ['tag_x'], 3600);

        self::assertSame(['entry-a'], $this->memo->findIdentifiersByTag('tag_x'));
        self::assertSame([], $this->memo->findIdentifiersByTag('tag_y'));
    }

    #[Test]
    public function twoInstancesDoNotShareTheirMemo(): void
    {
        $id = new CacheIdentifier('entry-a');
        $other = new RequestScopedMetadataCache($this->inner);
        $metadata = $this->metadata($id, 'a');

        $this->memo->set($id, $metadata, [], 3600);
        $this->inner->remove($id);

        self::assertSame($metadata, $this->memo->get($id));
        self::assertNull($other->get($id));
        self::assertSame(1, $this->inner->gets);
    }

    /**
     * @return array{CacheIdentifier, CacheIdentifier}
     */
    private function twoMemoisedEntries(): array
    {
        $a = new CacheIdentifier('entry-a');
        $b = new CacheIdentifier('entry-b');
        $this->inner->set($a, $this->metadata($a, 'a'), ['tag_x'], 3600);
        $this->memo->get($a);
        $this->memo->get($b);
        self::assertSame(2, $this->inner->gets);

        return [$a, $b];
    }

    private function metadata(CacheIdentifier $id, string $hashChar): CacheMetadata
    {
        return new CacheMetadata(
            identifier: $id,
            hash: new PayloadHash(str_repeat($hashChar, 64)),
            checksum: new PayloadChecksum(str_repeat('b', 64)),
            lifetime: new Lifetime(1_700_000_000, 1_700_003_600),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            payloadSize: 0,
            tags: new TagSet([]),
            state: CacheState::Valid,
            backendVersion: new BackendVersion(1),
        );
    }
}
