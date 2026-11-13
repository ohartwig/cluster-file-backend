<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\Cache;

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
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\CrossRequestMetadataMemo;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\GenerationGuardedMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\CountingMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeAtomicMetadataStore;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The generation guard on a store that can write an entry and the
 * generation in one step ({@see \Moselwal\Typo3ClusterCache\Domain\Contract\AtomicMetadataGenerationPort}).
 * The three-step protocol on a store without it is pinned by
 * {@see GenerationGuardedMetadataCacheTest}.
 */
#[CoversClass(GenerationGuardedMetadataCache::class)]
final class GenerationGuardedMetadataCacheAtomicTest extends TestCase
{
    private FakeAtomicMetadataStore $store;
    private FakeClock $clock;
    private FakeMetrics $metrics;
    private CrossRequestMetadataMemo $memo;

    protected function setUp(): void
    {
        $this->store = new FakeAtomicMetadataStore();
        $this->store->generation = 'g0';
        $this->clock = new FakeClock(1_700_000_000);
        $this->metrics = new FakeMetrics();
        $this->memo = new CrossRequestMetadataMemo();
    }

    private function guarded(): GenerationGuardedMetadataCache
    {
        return new GenerationGuardedMetadataCache(
            inner: $this->store,
            generation: $this->store,
            clock: $this->clock,
            metrics: $this->metrics,
            labels: ['cacheName' => 'pages'],
            memo: $this->memo,
            requestScope: new \stdClass(),
        );
    }

    private function metadata(string $id): CacheMetadata
    {
        return new CacheMetadata(
            identifier: new CacheIdentifier($id),
            hash: new PayloadHash(str_repeat('a', 64)),
            checksum: new PayloadChecksum(str_repeat('b', 64)),
            lifetime: Lifetime::fromSeconds(3600, $this->clock),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            payloadSize: 1,
            tags: new TagSet([]),
            state: CacheState::Valid,
            backendVersion: new BackendVersion(1),
        );
    }

    private function counter(string $name, ?string $result = null): int
    {
        $total = 0;
        foreach ($this->metrics->counters as $entry) {
            if ($entry['name'] === $name && (null === $result || ($entry['labels']['result'] ?? null) === $result)) {
                $total += $entry['delta'];
            }
        }

        return $total;
    }

    #[Test]
    public function aSetIsOneStepWithoutDirtyMarkerReadOrSeparatePublish(): void
    {
        $guarded = $this->guarded();
        self::assertTrue($guarded->isAtomic());

        $guarded->set(new CacheIdentifier('x'), $this->metadata('x'), ['t'], 60);

        self::assertSame(['setWithGeneration'], $this->store->calls);
        self::assertNotNull($this->store->get(new CacheIdentifier('x')));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $this->store->generation);
        self::assertSame(1, $this->counter('metadata_generation_publish_total', 'published'));
        self::assertSame(0, $this->counter('metadata_generation_atomic_refused_total'));
    }

    #[Test]
    public function everySetMovesTheGenerationToAValueItNeverHad(): void
    {
        $seen = ['g0'];
        for ($i = 0; $i < 5; ++$i) {
            $this->guarded()->set(new CacheIdentifier('x'), $this->metadata('x'), [], 60);
            $seen[] = (string) $this->store->generation;
        }

        self::assertCount(6, array_unique($seen));
    }

    #[Test]
    public function aForeignDirtyMarkerIsLeftAndCountedAsDeferred(): void
    {
        $this->store->generation = 'dirty-other';

        $this->guarded()->set(new CacheIdentifier('x'), $this->metadata('x'), [], 60);

        self::assertSame('dirty-other', $this->store->generation);
        self::assertSame(['setWithGeneration'], $this->store->calls, 'no publish of our own on top');
        self::assertNotNull($this->store->get(new CacheIdentifier('x')), 'the entry is written all the same');
        self::assertSame(1, $this->counter('metadata_generation_publish_total', 'deferred'));
        self::assertSame(0, $this->counter('metadata_generation_publish_total', 'published'));
    }

    #[Test]
    public function aRefusedSetRunsTheThreeStepProtocolWithAnAtomicPublish(): void
    {
        $this->store->refuse = true;

        $this->guarded()->set(new CacheIdentifier('x'), $this->metadata('x'), [], 60);

        self::assertSame(['setWithGeneration', 'writeGeneration:dirty', 'set', 'publishGeneration'], $this->store->calls);
        self::assertNotNull($this->store->get(new CacheIdentifier('x')));
        self::assertStringStartsNotWith('dirty-', (string) $this->store->generation);
        self::assertSame(1, $this->counter('metadata_generation_atomic_refused_total'));
        self::assertSame(1, $this->counter('metadata_generation_publish_total', 'published'));
    }

    #[Test]
    public function everyOtherMutationKeepsTheProtocolAndPublishesInOneStep(): void
    {
        $mutations = [
            'remove' => static fn(GenerationGuardedMetadataCache $g) => $g->remove(new CacheIdentifier('x')),
            'flush' => static fn(GenerationGuardedMetadataCache $g) => $g->flush(),
            'flushByTag' => static fn(GenerationGuardedMetadataCache $g) => $g->flushByTag('t'),
            'flushByTags' => static fn(GenerationGuardedMetadataCache $g) => $g->flushByTags(['t', 'u']),
            'collectGarbage' => static fn(GenerationGuardedMetadataCache $g) => $g->collectGarbage(),
        ];
        foreach ($mutations as $name => $mutation) {
            $this->store->calls = [];
            $before = $this->store->generation;

            $mutation($this->guarded());

            self::assertSame(['writeGeneration:dirty', $name, 'publishGeneration'], $this->store->calls, $name);
            self::assertNotSame($before, $this->store->generation, $name);
            self::assertStringStartsNotWith('dirty-', (string) $this->store->generation, $name);
        }
    }

    #[Test]
    public function theProtocolDefersToAMarkerThatReplacedItsOwn(): void
    {
        $this->store->duringMutation = function (): void {
            $this->store->generation = 'dirty-other';
        };

        $this->guarded()->flush();

        self::assertSame('dirty-other', $this->store->generation);
        self::assertSame(1, $this->counter('metadata_generation_publish_total', 'deferred'));
    }

    #[Test]
    public function aFailedOneStepSetPropagatesAndPublishesNothing(): void
    {
        $this->store->failAtomicSet = true;

        try {
            $this->guarded()->set(new CacheIdentifier('x'), $this->metadata('x'), [], 60);
            self::fail('expected the store error');
        } catch (\RuntimeException $e) {
            self::assertSame(1794300003, $e->getCode());
        }

        self::assertSame(['setWithGeneration'], $this->store->calls);
        self::assertSame('g0', $this->store->generation, 'nothing happened, so nothing to announce');
        self::assertSame(0, $this->counter('metadata_generation_publish_total'));
    }

    #[Test]
    public function aFailedAtomicPublishLeavesTheGenerationDirtyAndIsCounted(): void
    {
        $this->store->failPublish = true;

        $this->guarded()->flush();

        self::assertStringStartsWith('dirty-', (string) $this->store->generation);
        self::assertSame(1, $this->counter('metadata_generation_publish_total', 'error'));
    }

    #[Test]
    public function aOneStepSetEmptiesTheProcessMemoForTheRestOfTheRequest(): void
    {
        $x = new CacheIdentifier('x');
        $this->guarded()->set($x, $this->metadata('x'), [], 60);

        $request = $this->guarded();
        $request->get($x);
        self::assertTrue($this->memo->has('x'));

        $request->set($x, $this->metadata('x'), [], 60);

        self::assertFalse($this->memo->has('x'));
        $request->get($x);
        self::assertFalse($this->memo->has('x'), 'switched off until the next request');
    }

    #[Test]
    public function anotherPodsOneStepSetIsSeenAtTheNextRequest(): void
    {
        $x = new CacheIdentifier('x');
        $this->guarded()->set($x, $this->metadata('x'), [], 60);
        $this->guarded()->get($x);
        $generationOfTheMemo = $this->memo->generation();

        // Another pod: its own decorator, no memo of ours.
        $other = new GenerationGuardedMetadataCache($this->store, $this->store, $this->clock, new FakeMetrics());
        $other->remove($x);
        $other->set(new CacheIdentifier('y'), $this->metadata('y'), [], 60);

        $next = $this->guarded();
        self::assertNull($next->get($x));
        self::assertNotSame($generationOfTheMemo, $this->memo->generation());
    }

    #[Test]
    public function theCapabilityIsOnlyUsedWhenEntriesAndGenerationAreTheSameStore(): void
    {
        $guarded = new GenerationGuardedMetadataCache(
            inner: new CountingMetadataCache($this->store),
            generation: $this->store,
            clock: $this->clock,
            metrics: $this->metrics,
        );
        self::assertFalse($guarded->isAtomic());

        $guarded->set(new CacheIdentifier('x'), $this->metadata('x'), [], 60);

        self::assertSame(['writeGeneration:dirty', 'set', 'readGeneration', 'writeGeneration:clean'], $this->store->calls);
    }
}
