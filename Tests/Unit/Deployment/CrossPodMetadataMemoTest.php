<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Deployment;

use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
use Moselwal\Typo3ClusterCache\Application\Read\ReadCacheEntry;
use Moselwal\Typo3ClusterCache\Application\Write\WriteCacheEntry;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\CrossRequestMetadataMemo;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\GenerationGuardedMetadataCache;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\RequestScopedMetadataCache;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\NullCompressor;
use Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry;
use Moselwal\Typo3ClusterCache\Tests\Support\CountingMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeGenerationStore;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use Moselwal\Typo3ClusterCache\Tests\Support\InMemoryLocalPayloadStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cluster consistency with the cross-request metadata memo switched on.
 *
 * Same topology as {@see CrossPodFlushTest} — one shared metadata store,
 * one local file view per pod — plus what the memo adds: each pod keeps a
 * process-wide memo across requests, and the store holds the generation
 * that tells a pod at the start of each request whether its memo still
 * holds. A "request" here is a fresh decorator stack with a fresh request
 * scope, exactly what TYPO3 builds per request in FrankenPHP worker mode.
 *
 * The promise under test is the one {@see CrossPodFlushTest} pins without
 * a memo: a change made on pod B is visible on pod A at A's next request.
 * The gain under test: in a steady state a request costs one generation
 * read, not one read per identifier.
 */
#[CoversClass(GenerationGuardedMetadataCache::class)]
#[CoversClass(CrossRequestMetadataMemo::class)]
final class CrossPodMetadataMemoTest extends TestCase
{
    private CacheNamespace $namespace;
    private FakeMetadataCache $sharedMetadata;
    private FakeGenerationStore $sharedGeneration;
    private FakeClock $clock;
    private FakeMetrics $metrics;

    /** @var array<string, array{memo: CrossRequestMetadataMemo|null, local: InMemoryLocalPayloadStore}> */
    private array $pods = [];

    /** Entry reads that reached the shared store, per pod, in the current request. */
    private CountingMetadataCache $lastCounter;

    protected function setUp(): void
    {
        $this->namespace = new CacheNamespace(EnvironmentName::Testing, 'cluster', 'fluid_template');
        $this->sharedMetadata = new FakeMetadataCache();
        $this->sharedGeneration = new FakeGenerationStore();
        $this->clock = new FakeClock(1_700_000_000);
        $this->metrics = new FakeMetrics();
        $this->pods = [
            'A' => ['memo' => new CrossRequestMetadataMemo(), 'local' => new InMemoryLocalPayloadStore()],
            'B' => ['memo' => new CrossRequestMetadataMemo(), 'local' => new InMemoryLocalPayloadStore()],
        ];
    }

    /**
     * Starts a request on the given pod and returns its metadata port as
     * the backend wires it (request memo over generation guard), with the
     * store reads of this request counted in `$this->lastCounter`.
     */
    private function request(string $pod): MetadataCachePort
    {
        $this->lastCounter = new CountingMetadataCache($this->sharedMetadata);

        return new RequestScopedMetadataCache(new GenerationGuardedMetadataCache(
            inner: $this->lastCounter,
            generation: $this->sharedGeneration,
            clock: $this->clock,
            metrics: $this->metrics,
            memo: $this->pods[$pod]['memo'],
            requestScope: new \stdClass(),
        ));
    }

    private function writer(string $pod, MetadataCachePort $metadata): WriteCacheEntry
    {
        return new WriteCacheEntry(
            metadataCache: $metadata,
            localStore: $this->pods[$pod]['local'],
            compressorsByAlgo: CodecRegistry::single(new NullCompressor()),
            clock: $this->clock,
            metrics: $this->metrics,
            hasher: new ComputePayloadHash(),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(1),
            minCompressedBytes: 0,
        );
    }

    private function reader(string $pod, MetadataCachePort $metadata): ReadCacheEntry
    {
        return new ReadCacheEntry(
            metadataCache: $metadata,
            localStore: $this->pods[$pod]['local'],
            compressorsByAlgo: CodecRegistry::single(new NullCompressor()),
            clock: $this->clock,
            metrics: $this->metrics,
        );
    }

    /**
     * @param list<string> $tags
     */
    private function write(string $pod, string $id, string $payload, array $tags = [], int $lifetime = 3600): void
    {
        $this->writer($pod, $this->request($pod))
            ->execute($this->namespace, new CacheIdentifier($id), $payload, new TagSet($tags), $lifetime);
    }

    /**
     * Reads the given identifiers in one request on the pod and returns the
     * payloads (null = miss).
     *
     * @param list<string> $ids
     *
     * @return array<string, string|null>
     */
    private function readAll(string $pod, array $ids): array
    {
        $reader = $this->reader($pod, $this->request($pod));
        $result = [];
        foreach ($ids as $id) {
            $result[$id] = $reader->execute($this->namespace, new CacheIdentifier($id));
        }

        return $result;
    }

    /**
     * Both pods hold the payloads of `a` and `b` locally, `c` was never
     * written; pod A has read all three once (memo filled).
     */
    private function warmUp(): void
    {
        $this->write('B', 'a', 'payload-a', ['tag_a']);
        $this->write('B', 'b', 'payload-b', ['tag_b']);
        $this->write('A', 'a', 'payload-a', ['tag_a']);
        $this->write('A', 'b', 'payload-b', ['tag_b']);
        $this->readAll('A', ['a', 'b', 'c']);
    }

    #[Test]
    public function aSteadyStateRequestCostsOneGenerationReadAndNoEntryRead(): void
    {
        $this->warmUp();

        for ($request = 0; $request < 3; ++$request) {
            $generationReads = $this->sharedGeneration->reads;
            $result = $this->readAll('A', ['a', 'b', 'c', 'a', 'b', 'c']);

            self::assertSame(['a' => 'payload-a', 'b' => 'payload-b', 'c' => null], $result);
            self::assertSame(1, $this->sharedGeneration->reads - $generationReads, 'one generation read per request');
            self::assertSame(0, $this->lastCounter->gets, 'no entry read in the steady state');
        }
        self::assertSame(0, $this->metrics->counterTotal('metadata_memo_full_total'));
    }

    #[Test]
    public function withoutTheMemoEveryRequestReadsEveryIdentifierAndNoGeneration(): void
    {
        $this->pods['A']['memo'] = null;
        $this->warmUp();
        $generationReads = $this->sharedGeneration->reads;

        $this->readAll('A', ['a', 'b', 'c', 'a']);

        self::assertSame(3, $this->lastCounter->gets);
        self::assertSame($generationReads, $this->sharedGeneration->reads);
    }

    #[Test]
    public function aPodWithoutTheMemoStillAnnouncesItsChanges(): void
    {
        $this->pods['B']['memo'] = null;
        $this->warmUp();

        $this->write('B', 'a', 'payload-a2', ['tag_a']);

        self::assertNull($this->readAll('A', ['a'])['a'], 'A sees the new hash at its next request (blob miss)');
        self::assertSame(1, $this->lastCounter->gets);
    }

    #[Test]
    public function aSetOnPodBIsVisibleOnPodAAtItsNextRequest(): void
    {
        $this->warmUp();
        $stale = $this->sharedMetadata->get(new CacheIdentifier('a'));

        $this->write('B', 'a', 'payload-a2', ['tag_a']);
        $this->write('B', 'c', 'payload-c');

        $onA = $this->request('A');
        $a = $onA->get(new CacheIdentifier('a'));
        self::assertNotNull($a);
        self::assertNotNull($stale);
        self::assertFalse($a->hash->equals($stale->hash), 'A sees the hash B wrote, not the memoised one');
        self::assertNotNull($onA->get(new CacheIdentifier('c')), 'a memoised miss does not outlive B\'s write');
        self::assertSame(['a' => null, 'b' => 'payload-b'], $this->readAll('A', ['a', 'b']), 'a: blob miss on A until it rebuilds');
    }

    #[Test]
    public function aRemoveOnPodBIsVisibleOnPodAAtItsNextRequest(): void
    {
        $this->warmUp();

        $this->request('B')->remove(new CacheIdentifier('a'));

        self::assertSame(['a' => null, 'b' => 'payload-b'], $this->readAll('A', ['a', 'b']));
    }

    #[Test]
    public function aFlushOnPodBIsVisibleOnPodAAtItsNextRequest(): void
    {
        $this->warmUp();

        $this->request('B')->flush();

        self::assertSame(['a' => null, 'b' => null], $this->readAll('A', ['a', 'b']));
    }

    #[Test]
    public function aFlushByTagOnPodBIsVisibleOnPodAAtItsNextRequest(): void
    {
        $this->warmUp();

        $this->request('B')->flushByTag('tag_a');

        self::assertSame(['a' => null, 'b' => 'payload-b'], $this->readAll('A', ['a', 'b']));
    }

    #[Test]
    public function aFlushByTagsOnPodBIsVisibleOnPodAAtItsNextRequest(): void
    {
        $this->warmUp();

        $this->request('B')->flushByTags(['tag_b']);

        self::assertSame(['a' => 'payload-a', 'b' => null], $this->readAll('A', ['a', 'b']));
    }

    #[Test]
    public function garbageCollectionOnPodBMakesPodAReadAgain(): void
    {
        $this->warmUp();

        $this->request('B')->collectGarbage();
        $this->readAll('A', ['a', 'b']);

        self::assertSame(2, $this->lastCounter->gets);
    }

    #[Test]
    public function aChangeWhilePodAIsMidRequestIsVisibleAtItsNextRequest(): void
    {
        $this->warmUp();
        $requestOnA = $this->reader('A', $this->request('A'));
        self::assertSame('payload-a', $requestOnA->execute($this->namespace, new CacheIdentifier('a')));

        $this->request('B')->flushByTag('tag_b');

        self::assertSame(['b' => null], $this->readAll('A', ['b']));
    }

    #[Test]
    public function anEntryIsNotServedFromTheMemoPastItsOwnExpiry(): void
    {
        $this->write('A', 'short', 'payload', [], 60);
        $this->readAll('A', ['short']);
        // The store expires the entry by TTL — no mutation, no new generation.
        $this->sharedMetadata->remove(new CacheIdentifier('short'));
        $this->clock->advance(61);

        self::assertSame(['short' => null], $this->readAll('A', ['short']));
        self::assertSame(1, $this->lastCounter->gets, 'the expired entry was read from the store again');
    }

    #[Test]
    public function theMemoIsRefilledAfterItsMaximumAgeEvenWithoutAGenerationChange(): void
    {
        $this->warmUp();
        // Gone from the store without a generation change (eviction, or a
        // writer whose final generation write failed during a concurrent
        // mutation): the memo must not serve it for ever.
        $this->sharedMetadata->remove(new CacheIdentifier('a'));

        $this->clock->advance(GenerationGuardedMetadataCache::MAX_AGE_SECONDS - 1);
        self::assertSame(['a' => 'payload-a'], $this->readAll('A', ['a']), 'within the bound: memo');

        $this->clock->advance(1);
        self::assertSame(['a' => null], $this->readAll('A', ['a']), 'past the bound: store');
    }

    #[Test]
    public function aFailedGenerationWriteBeforeTheMutationLeavesTheStoreAndEveryMemoConsistent(): void
    {
        $this->warmUp();
        $this->sharedGeneration->failWrites = 1;

        try {
            $this->request('B')->flushByTag('tag_a');
            self::fail('the mutation must fail when its generation cannot be announced');
        } catch (\RuntimeException) {
        }

        self::assertSame(['a' => 'payload-a', 'b' => 'payload-b'], $this->readAll('A', ['a', 'b']));
        self::assertNotNull($this->sharedMetadata->get(new CacheIdentifier('a')), 'the flush was not attempted');
        self::assertSame(0, $this->lastCounter->gets, 'nothing changed, the memo still holds');
    }

    #[Test]
    public function aFailedGenerationWriteAfterTheMutationLeavesEveryPodReadingTheStore(): void
    {
        $this->warmUp();
        $this->sharedGeneration->beforeWrite = function (string $generation): void {
            // Fail only the clean write that publishes the finished flush.
            if (!str_starts_with($generation, GenerationGuardedMetadataCache::DIRTY_PREFIX)) {
                $this->sharedGeneration->failWrites = 1;
                $this->sharedGeneration->beforeWrite = null;
            }
        };

        $this->request('B')->flushByTag('tag_a');

        self::assertStringStartsWith(GenerationGuardedMetadataCache::DIRTY_PREFIX, (string) $this->sharedGeneration->value);
        for ($request = 0; $request < 2; ++$request) {
            self::assertSame(['a' => null, 'b' => 'payload-b'], $this->readAll('A', ['a', 'b']));
            self::assertSame(2, $this->lastCounter->gets, 'dirty generation: per-entry reads, as without memo');
        }

        // The next successful mutation anywhere restores the memo.
        $this->write('B', 'c', 'payload-c');
        $this->readAll('A', ['a', 'b']);
        $this->readAll('A', ['a', 'b']);
        self::assertSame(0, $this->lastCounter->gets);
    }

    #[Test]
    public function anUnreadableGenerationMeansReadingTheStore(): void
    {
        $this->warmUp();
        $this->sharedGeneration->failReads = true;

        self::assertSame(['a' => 'payload-a', 'b' => 'payload-b'], $this->readAll('A', ['a', 'b']));
        self::assertSame(2, $this->lastCounter->gets);
    }

    #[Test]
    public function aStoreWithoutGenerationIsReadPerEntry(): void
    {
        $this->warmUp();
        // E.g. the metadata frontend itself was flushed by "flush all caches".
        $this->sharedGeneration->value = null;

        $this->readAll('A', ['a', 'b']);
        $this->readAll('A', ['a', 'b']);

        self::assertSame(2, $this->lastCounter->gets);
    }

    #[Test]
    public function aFullMemoKeepsServingWhatItHoldsAndReadsTheRest(): void
    {
        $memo = new CrossRequestMetadataMemo(2);
        $this->pods['A']['memo'] = $memo;
        $this->warmUp();

        $result = $this->readAll('A', ['a', 'b', 'c']);

        self::assertSame(['a' => 'payload-a', 'b' => 'payload-b', 'c' => null], $result);
        self::assertSame(1, $this->lastCounter->gets, 'only the identifier beyond the cap goes to the store');
        self::assertSame(2, $memo->count());
        self::assertSame(2, $this->metrics->counterTotal('metadata_memo_full_total'), 'once per request (warm-up and this one), for c');
    }

    /**
     * A single-request process (CLI) starts with an empty memo: it costs the
     * same entry reads as before plus one generation read.
     */
    #[Test]
    public function aSingleRequestProcessReadsEachIdentifierOnce(): void
    {
        $this->warmUp();
        $this->pods['A']['memo'] = new CrossRequestMetadataMemo();
        $generationReads = $this->sharedGeneration->reads;

        self::assertSame(['a' => 'payload-a', 'b' => 'payload-b'], $this->readAll('A', ['a', 'b', 'a']));
        self::assertSame(2, $this->lastCounter->gets);
        self::assertSame(1, $this->sharedGeneration->reads - $generationReads);
    }

    /**
     * Why the memo is off by default: a pod running a package version that
     * predates the generation mutates the store silently. A pod with the
     * memo cannot notice — so the memo may only be switched on once no such
     * pod is left.
     */
    #[Test]
    public function aPodThatDoesNotMaintainTheGenerationIsInvisibleToTheMemo(): void
    {
        $this->warmUp();

        // Old pod: talks to the store directly, no generation guard.
        $this->sharedMetadata->flushByTag('tag_a');

        self::assertSame('payload-a', $this->readAll('A', ['a'])['a'], 'stale: the reason for the opt-in');
    }
}
