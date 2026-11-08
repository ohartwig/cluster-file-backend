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
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\CrossRequestMetadataMemo;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\GenerationGuardedMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\CountingMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeGenerationStore;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The write protocol (announce, mutate, publish) and the per-request
 * generation check of {@see GenerationGuardedMetadataCache}. The
 * cluster-level consequences are pinned in
 * {@see \Moselwal\Typo3ClusterCache\Tests\Unit\Deployment\CrossPodMetadataMemoTest}.
 */
#[CoversClass(GenerationGuardedMetadataCache::class)]
final class GenerationGuardedMetadataCacheTest extends TestCase
{
    private FakeMetadataCache $store;
    private CountingMetadataCache $counting;
    private FakeGenerationStore $generation;
    private FakeClock $clock;
    private FakeMetrics $metrics;
    private CrossRequestMetadataMemo $memo;
    private AbstractLogger $logger;
    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->store = new FakeMetadataCache();
        $this->counting = new CountingMetadataCache($this->store);
        $this->generation = new FakeGenerationStore();
        $this->generation->value = 'g0';
        $this->clock = new FakeClock(1_700_000_000);
        $this->metrics = new FakeMetrics();
        $this->memo = new CrossRequestMetadataMemo();
        $this->logger = new class (fn(string $line) => $this->log[] = $line) extends AbstractLogger {
            public function __construct(private readonly \Closure $record) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ($this->record)($level . ': ' . $message . ' ' . json_encode($context));
            }
        };
    }

    private function guarded(?object $scope = null, bool $withMemo = true): GenerationGuardedMetadataCache
    {
        return new GenerationGuardedMetadataCache(
            inner: $this->counting,
            generation: $this->generation,
            clock: $this->clock,
            metrics: $this->metrics,
            labels: ['cacheName' => 'pages'],
            memo: $withMemo ? $this->memo : null,
            requestScope: $scope ?? new \stdClass(),
            logger: $this->logger,
        );
    }

    private function metadata(string $id, CacheState $state = CacheState::Valid, int $lifetime = 3600): CacheMetadata
    {
        return new CacheMetadata(
            identifier: new CacheIdentifier($id),
            hash: new PayloadHash(str_repeat('a', 64)),
            checksum: new PayloadChecksum(str_repeat('b', 64)),
            lifetime: Lifetime::fromSeconds($lifetime, $this->clock),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            payloadSize: 1,
            tags: new TagSet([]),
            state: $state,
            backendVersion: new BackendVersion(1),
        );
    }

    private function counter(string $name, string $result): int
    {
        $total = 0;
        foreach ($this->metrics->counters as $entry) {
            if ($entry['name'] === $name && ($entry['labels']['result'] ?? null) === $result) {
                $total += $entry['delta'];
            }
        }

        return $total;
    }

    #[Test]
    public function aMutationIsAnnouncedBeforeAndPublishedAfterIt(): void
    {
        $order = [];
        $this->generation->beforeWrite = function (string $value) use (&$order): void {
            $order[] = str_starts_with($value, GenerationGuardedMetadataCache::DIRTY_PREFIX) ? 'dirty' : 'clean';
            $order[] = 'entries:' . $this->store->count();
        };

        $this->guarded()->set(new CacheIdentifier('x'), $this->metadata('x'), [], 60);

        self::assertSame(['dirty', 'entries:0', 'clean', 'entries:1'], $order);
        self::assertCount(2, $this->generation->written);
        self::assertNotSame('g0', $this->generation->value);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $this->generation->value);
        self::assertMatchesRegularExpression('/^dirty-[0-9a-f]{32}$/', $this->generation->written[0]);
        self::assertSame(1, $this->counter('metadata_generation_publish_total', 'published'));
    }

    #[Test]
    public function everyKindOfMutationMovesTheGeneration(): void
    {
        $guarded = $this->guarded();
        $id = new CacheIdentifier('x');
        $mutations = [
            fn() => $guarded->set($id, $this->metadata('x'), ['t'], 60),
            fn() => $guarded->remove($id),
            fn() => $guarded->flush(),
            fn() => $guarded->flushByTag('t'),
            fn() => $guarded->flushByTags(['t']),
            fn() => $guarded->collectGarbage(),
        ];
        foreach ($mutations as $i => $mutation) {
            $before = $this->generation->value;
            $mutation();
            self::assertNotSame($before, $this->generation->value, "mutation #{$i}");
            self::assertStringStartsNotWith(GenerationGuardedMetadataCache::DIRTY_PREFIX, (string) $this->generation->value);
        }
        self::assertCount(12, $this->generation->written);
    }

    #[Test]
    public function readsAndTagLookupsLeaveTheGenerationAlone(): void
    {
        $guarded = $this->guarded();

        $guarded->get(new CacheIdentifier('x'));
        $guarded->findIdentifiersByTag('t');

        self::assertSame([], $this->generation->written);
    }

    #[Test]
    public function removeReportsWhatTheStoreReports(): void
    {
        $guarded = $this->guarded();
        $id = new CacheIdentifier('x');

        self::assertFalse($guarded->remove($id));
        $guarded->set($id, $this->metadata('x'), [], 60);
        self::assertTrue($guarded->remove($id));
    }

    #[Test]
    public function tagLookupsAreAnsweredByTheStore(): void
    {
        $this->store->set(new CacheIdentifier('x'), $this->metadata('x'), ['t'], 60);

        self::assertSame(['x'], $this->guarded()->findIdentifiersByTag('t'));
    }

    #[Test]
    public function aMutationIsNotAttemptedIfItCannotBeAnnounced(): void
    {
        $this->generation->failWrites = 1;

        try {
            $this->guarded()->set(new CacheIdentifier('x'), $this->metadata('x'), [], 60);
            self::fail('expected the announcement failure to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame(1794200002, $e->getCode());
        }

        self::assertSame(0, $this->store->count());
        self::assertSame('g0', $this->generation->value);
    }

    #[Test]
    public function aFailedMutationIsPublishedAllTheSame(): void
    {
        $this->counting->failing = true;

        try {
            $this->guarded()->flush();
            self::fail('expected the store failure to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame(1794127300, $e->getCode());
        }

        self::assertCount(2, $this->generation->written, 'a half-done mutation must still be announced as done');
        self::assertStringStartsNotWith(GenerationGuardedMetadataCache::DIRTY_PREFIX, (string) $this->generation->value);
    }

    /**
     * Routes the store through a hook that runs in the middle of every
     * mutation — where another pod's writes can land.
     */
    private function midMutation(\Closure $hook): void
    {
        $this->counting = new CountingMetadataCache(new class ($this->store, $hook) implements MetadataCachePort {
            public function __construct(private FakeMetadataCache $store, private \Closure $hook) {}

            public function get(CacheIdentifier $identifier): ?CacheMetadata
            {
                return $this->store->get($identifier);
            }

            public function set(CacheIdentifier $identifier, CacheMetadata $metadata, array $tags, int $ttlSeconds): void
            {
                $this->store->set($identifier, $metadata, $tags, $ttlSeconds);
                ($this->hook)();
            }

            public function remove(CacheIdentifier $identifier): bool
            {
                ($this->hook)();

                return $this->store->remove($identifier);
            }

            public function flush(): void
            {
                ($this->hook)();
            }

            public function flushByTag(string $tag): void
            {
                ($this->hook)();
            }

            public function flushByTags(array $tags): void
            {
                ($this->hook)();
            }

            public function findIdentifiersByTag(string $tag): array
            {
                return [];
            }

            public function collectGarbage(): void
            {
                ($this->hook)();
            }
        });
    }

    #[Test]
    public function anotherWritersMarkerIsLeftForThatWriterToPublish(): void
    {
        $other = GenerationGuardedMetadataCache::DIRTY_PREFIX . 'other';
        $this->midMutation(function () use ($other): void {
            $this->generation->value = $other;
        });

        $this->guarded()->set(new CacheIdentifier('x'), $this->metadata('x'), [], 60);

        self::assertSame($other, $this->generation->value);
        self::assertSame(1, $this->counter('metadata_generation_publish_total', 'deferred'));
    }

    /**
     * A clean value from another writer does not stand in for ours: it may
     * have been published before our mutation landed.
     */
    #[Test]
    public function anotherWritersCleanGenerationIsReplaced(): void
    {
        $this->midMutation(function (): void {
            $this->generation->value = 'published-by-other';
        });

        $this->guarded()->collectGarbage();

        self::assertNotSame('published-by-other', $this->generation->value);
        self::assertStringStartsNotWith(GenerationGuardedMetadataCache::DIRTY_PREFIX, (string) $this->generation->value);
        self::assertSame(1, $this->counter('metadata_generation_publish_total', 'published'));
    }

    #[Test]
    public function aGenerationThatVanishedDuringTheMutationIsPublishedAfresh(): void
    {
        // E.g. the metadata frontend was flushed as a whole meanwhile.
        $this->midMutation(function (): void {
            $this->generation->value = null;
        });

        $this->guarded()->flushByTag('t');

        self::assertNotNull($this->generation->value);
        self::assertStringStartsNotWith(GenerationGuardedMetadataCache::DIRTY_PREFIX, $this->generation->value);
    }

    #[Test]
    public function aFailedPublicationIsLoggedAndCountedButNotThrown(): void
    {
        $this->generation->beforeWrite = function (string $value): void {
            if (!str_starts_with($value, GenerationGuardedMetadataCache::DIRTY_PREFIX)) {
                $this->generation->failWrites = 1;
            }
        };

        $this->guarded()->flush();

        self::assertStringStartsWith(GenerationGuardedMetadataCache::DIRTY_PREFIX, (string) $this->generation->value);
        self::assertSame(1, $this->counter('metadata_generation_publish_total', 'error'));
        self::assertSame(0, $this->counter('metadata_generation_publish_total', 'published'));
        self::assertCount(1, $this->log);
        self::assertStringStartsWith('warning: ', $this->log[0]);
        self::assertStringEndsWith('{"exceptionClass":"RuntimeException","exceptionMessage":"generation write failed"}', $this->log[0]);
    }

    #[Test]
    public function withoutAMemoReadsGoStraightToTheStoreAndNeverReadTheGeneration(): void
    {
        $guarded = $this->guarded(withMemo: false);
        $id = new CacheIdentifier('x');

        $guarded->get($id);
        $guarded->get($id);

        self::assertFalse($guarded->isMemoEnabled());
        self::assertSame(2, $this->counting->gets);
        self::assertSame(0, $this->generation->reads);
    }

    #[Test]
    public function oneGenerationReadServesEveryCacheOfARequest(): void
    {
        $scope = new \stdClass();
        $first = $this->guarded($scope);
        $second = $this->guarded($scope);

        $first->get(new CacheIdentifier('x'));
        $second->get(new CacheIdentifier('y'));
        $this->guarded()->get(new CacheIdentifier('x'));

        self::assertTrue($first->isMemoEnabled());
        self::assertSame(2, $this->generation->reads, 'one per request scope');
        self::assertSame(2, $this->counting->gets);
        self::assertSame(1, $this->counter('metadata_memo_check_total', 'reset'));
        self::assertSame(1, $this->counter('metadata_memo_check_total', 'reuse'));
    }

    #[Test]
    public function withoutARequestScopeTheDecoratorIsTheScope(): void
    {
        $make = fn(): GenerationGuardedMetadataCache => new GenerationGuardedMetadataCache(
            $this->counting,
            $this->generation,
            $this->clock,
            $this->metrics,
            memo: $this->memo,
        );
        $first = $make();

        $first->get(new CacheIdentifier('x'));
        $first->get(new CacheIdentifier('y'));
        $make()->get(new CacheIdentifier('x'));

        self::assertSame(2, $this->generation->reads);
        self::assertSame(2, $this->counting->gets, 'x came from the memo in the second instance');
    }

    #[Test]
    public function hitsAndMissesAreMemoisedAndCounted(): void
    {
        $this->store->set(new CacheIdentifier('x'), $this->metadata('x'), [], 60);
        $this->guarded()->get(new CacheIdentifier('x'));
        $this->guarded()->get(new CacheIdentifier('absent'));

        $next = $this->guarded();
        self::assertNotNull($next->get(new CacheIdentifier('x')));
        self::assertNull($next->get(new CacheIdentifier('absent')));

        self::assertSame(2, $this->counting->gets);
        self::assertSame(2, $this->metrics->counterTotal('metadata_memo_hit_total'));
        self::assertSame(2, $this->metrics->counterTotal('metadata_memo_miss_total'));
        $last = end($this->metrics->counters);
        self::assertNotFalse($last);
        self::assertSame(['cacheName' => 'pages'], $last['labels']);
    }

    #[Test]
    public function brokenAndExpiredEntriesAreNotMemoised(): void
    {
        $this->store->set(new CacheIdentifier('broken'), $this->metadata('broken', CacheState::Broken), [], 60);
        $this->store->set(new CacheIdentifier('expired'), $this->metadata('expired', lifetime: 10), [], 60);
        $this->clock->advance(11);

        $this->guarded()->get(new CacheIdentifier('broken'));
        $this->guarded()->get(new CacheIdentifier('expired'));

        self::assertSame(0, $this->memo->count());
    }

    #[Test]
    public function anEntryExpiringInTheMemoIsReadFromTheStoreAgain(): void
    {
        $this->store->set(new CacheIdentifier('x'), $this->metadata('x', lifetime: 10), [], 10);
        $this->guarded()->get(new CacheIdentifier('x'));

        $this->clock->advance(9);
        self::assertNotNull($this->guarded()->get(new CacheIdentifier('x')), 'one second before expiresAt: memo');
        self::assertSame(1, $this->counting->gets);

        $this->clock->advance(1);
        $this->guarded()->get(new CacheIdentifier('x'));
        self::assertSame(2, $this->counting->gets);
    }

    #[Test]
    public function aChangedGenerationEmptiesTheMemo(): void
    {
        $this->guarded()->get(new CacheIdentifier('x'));
        $this->generation->value = 'g1';

        $this->guarded()->get(new CacheIdentifier('x'));

        self::assertSame(2, $this->counting->gets);
        self::assertSame('g1', $this->memo->generation());
        self::assertSame(2, $this->counter('metadata_memo_check_total', 'reset'));
    }

    #[Test]
    public function aDirtyAbsentOrUnreadableGenerationBypassesTheMemo(): void
    {
        foreach (['dirty' => GenerationGuardedMetadataCache::DIRTY_PREFIX . 'x', 'absent' => null] as $value) {
            $this->generation->value = $value;
            $this->guarded()->get(new CacheIdentifier('x'));
            $this->guarded()->get(new CacheIdentifier('x'));
        }
        $this->generation->failReads = true;
        $this->guarded()->get(new CacheIdentifier('x'));

        self::assertSame(5, $this->counting->gets);
        self::assertSame(0, $this->memo->count());
        self::assertNull($this->memo->generation());
        self::assertSame(5, $this->counter('metadata_memo_check_total', 'bypass'));
        self::assertCount(1, $this->log, 'the unreadable generation is logged');
        self::assertStringEndsWith('{"exceptionClass":"RuntimeException","exceptionMessage":"generation read failed"}', $this->log[0]);
    }

    #[Test]
    public function aDirtyGenerationDropsWhatTheMemoHeld(): void
    {
        $this->guarded()->get(new CacheIdentifier('x'));
        self::assertSame(1, $this->memo->count());

        $this->generation->value = GenerationGuardedMetadataCache::DIRTY_PREFIX . 'other';
        $this->guarded()->get(new CacheIdentifier('y'));

        self::assertSame(0, $this->memo->count());
        self::assertNull($this->memo->generation());
    }

    #[Test]
    public function theMemoIsRefilledOnceItReachesItsMaximumAge(): void
    {
        $this->guarded()->get(new CacheIdentifier('x'));
        $this->clock->advance(GenerationGuardedMetadataCache::MAX_AGE_SECONDS - 1);
        $this->guarded()->get(new CacheIdentifier('x'));
        self::assertSame(1, $this->counting->gets);

        $this->clock->advance(1);
        $this->guarded()->get(new CacheIdentifier('x'));
        self::assertSame(2, $this->counting->gets);
        self::assertSame($this->clock->now(), $this->memo->filledAt());
    }

    #[Test]
    public function anOwnMutationEndsTheMemoForTheRestOfTheRequest(): void
    {
        $scope = new \stdClass();
        $guarded = $this->guarded($scope);
        $sibling = $this->guarded($scope);
        $guarded->get(new CacheIdentifier('x'));

        $guarded->set(new CacheIdentifier('y'), $this->metadata('y'), [], 60);
        $guarded->get(new CacheIdentifier('x'));
        $sibling->get(new CacheIdentifier('x'));

        self::assertSame(3, $this->counting->gets);
        self::assertSame(0, $this->memo->count());

        // The next request starts over under the published generation.
        $this->guarded()->get(new CacheIdentifier('x'));
        $this->guarded()->get(new CacheIdentifier('x'));
        self::assertSame(4, $this->counting->gets);
    }

    #[Test]
    public function aStoreErrorOnReadIsNotMemoised(): void
    {
        $this->counting->failing = true;
        try {
            $this->guarded()->get(new CacheIdentifier('x'));
            self::fail('expected the store failure to propagate');
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $this->memo->count());
    }
}
