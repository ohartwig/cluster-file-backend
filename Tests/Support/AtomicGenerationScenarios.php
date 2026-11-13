<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Support;

use Moselwal\KeyValueStore\Cache\Backend\GenerationBackendInterface;
use Moselwal\Typo3ClusterCache\Domain\Enum\CacheState;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\Lifetime;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\AtomicGenerationMetadataCache;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\CrossRequestMetadataMemo;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\GenerationGuardedMetadataCache;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Typo3MetadataCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Backend\BackendInterface;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * The generation in the metadata backend, end to end: the real adapters
 * and the real TYPO3 frontend (which serializes and signs what it stores)
 * over a backend that keeps generations.
 *
 * Pods come in two kinds:
 *
 * - **new**: this version on a backend with the capability — the wiring
 *   {@see AtomicGenerationMetadataCache::forFrontend()} produces;
 * - **legacy**: the three-step protocol through the frontend only, on the
 *   legacy key. That is, to the byte, what a pod of a release before the
 *   raw key does, and what this version does on a backend without the
 *   capability.
 *
 * Each call of newPod()/legacyPod() is one request of that pod: a fresh
 * decorator with a fresh request scope over the pod's process-wide memo.
 *
 * Run against an in-memory backend (unit suite) and against a real
 * Valkey/Redis through moselwal/keyvalue-store (functional suite).
 */
abstract class AtomicGenerationScenarios extends TestCase
{
    protected BackendInterface&GenerationBackendInterface $backend;

    private VariableFrontend $frontend;

    private CacheNamespace $namespace;

    private FakeClock $clock;

    /** @var array<string, CrossRequestMetadataMemo> */
    private array $memos = [];

    /** @var array<string, FakeMetrics> */
    private array $metrics = [];

    private mixed $encryptionKeyBefore = null;

    abstract protected function createBackend(): BackendInterface&GenerationBackendInterface;

    /** Round trips to the store that $work caused. */
    abstract protected function roundTrips(\Closure $work): int;

    protected function setUp(): void
    {
        $this->encryptionKeyBefore = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? null;
        // The frontend signs what it stores with a key derived from this.
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('k', 96);
        $this->backend = $this->createBackend();
        $this->frontend = new VariableFrontend('cluster_meta', $this->backend);
        $this->namespace = new CacheNamespace(EnvironmentName::Testing, 'cluster', 'pages');
        $this->clock = new FakeClock(1_700_000_000);
        $this->memos = [];
        $this->metrics = [];
    }

    protected function tearDown(): void
    {
        if (null === $this->encryptionKeyBefore) {
            unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = $this->encryptionKeyBefore;
        }
    }

    private function newPod(string $name): GenerationGuardedMetadataCache
    {
        $store = AtomicGenerationMetadataCache::forFrontend($this->frontend, $this->namespace);
        self::assertInstanceOf(AtomicGenerationMetadataCache::class, $store);

        return $this->pod($name, $store);
    }

    private function legacyPod(string $name): GenerationGuardedMetadataCache
    {
        return $this->pod($name, new Typo3MetadataCache($this->frontend, $this->namespace));
    }

    private function pod(string $name, AtomicGenerationMetadataCache|Typo3MetadataCache $store): GenerationGuardedMetadataCache
    {
        return new GenerationGuardedMetadataCache(
            inner: $store,
            generation: $store,
            clock: $this->clock,
            metrics: $this->metrics[$name] ??= new FakeMetrics(),
            memo: $this->memos[$name] ??= new CrossRequestMetadataMemo(),
            requestScope: new \stdClass(),
        );
    }

    private function metadata(string $id, int $size = 1): CacheMetadata
    {
        return new CacheMetadata(
            identifier: new CacheIdentifier($id),
            hash: new PayloadHash(str_repeat('a', 64)),
            checksum: new PayloadChecksum(str_repeat('b', 64)),
            lifetime: Lifetime::fromSeconds(3600, $this->clock),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            payloadSize: $size,
            tags: new TagSet([]),
            state: CacheState::Valid,
            backendVersion: new BackendVersion(1),
        );
    }

    private function id(string $id): CacheIdentifier
    {
        return new CacheIdentifier($id);
    }

    private function rawGeneration(): ?string
    {
        return $this->backend->readGeneration(AtomicGenerationMetadataCache::GENERATION_NAME)->generation;
    }

    /** The legacy generation as a legacy pod reads it; null when absent. */
    private function legacyGeneration(): ?string
    {
        $value = $this->frontend->get(Typo3MetadataCache::GENERATION_KEY);

        return \is_string($value) ? $value : null;
    }

    /** How the last generation check of the pod ended: reuse, reset or bypass. */
    private function lastCheck(string $pod): ?string
    {
        $result = null;
        foreach ($this->metrics[$pod]->counters ?? [] as $entry) {
            if ('metadata_memo_check_total' === $entry['name']) {
                $result = $entry['labels']['result'] ?? null;
            }
        }

        return $result;
    }

    private function counted(string $pod, string $name, ?string $result = null): int
    {
        $total = 0;
        foreach ($this->metrics[$pod]->counters ?? [] as $entry) {
            if ($entry['name'] === $name && (null === $result || ($entry['labels']['result'] ?? null) === $result)) {
                $total += $entry['delta'];
            }
        }

        return $total;
    }

    #[Test]
    public function aSetIsOneRoundTripAndMovesTheRawGenerationEveryTime(): void
    {
        $pod = $this->newPod('A');
        self::assertTrue($pod->isAtomic());
        $pod->set($this->id('warm-up'), $this->metadata('warm-up'), [], 60);

        $seen = [$this->rawGeneration()];
        for ($i = 0; $i < 3; ++$i) {
            $trips = $this->roundTrips(fn() => $pod->set($this->id('x'), $this->metadata('x', $i + 1), ['t'], 60));
            self::assertSame(1, $trips, 'entry, tags and generation in one round trip');
            $seen[] = $this->rawGeneration();
        }

        self::assertCount(4, array_unique($seen));
        foreach ($seen as $generation) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $generation, 'raw, clean');
        }
        self::assertNull($this->legacyGeneration(), 'the legacy key is never created');
        self::assertSame(3, $this->newPod('B')->get($this->id('x'))?->payloadSize, 'the frontend reads what it encoded');
        self::assertSame(['x'], $this->newPod('B')->findIdentifiersByTag('t'));
    }

    #[Test]
    public function theOtherMutationsTakeADirtyMarkerTheMutationAndOnePublish(): void
    {
        $pod = $this->newPod('A');
        $pod->set($this->id('x'), $this->metadata('x'), ['t'], 60);
        $before = $this->rawGeneration();

        $trips = $this->roundTrips(static fn() => $pod->flushByTag('t'));

        self::assertSame(3, $trips);
        self::assertNull($this->newPod('B')->get($this->id('x')));
        self::assertNotSame($before, $this->rawGeneration());
        self::assertStringStartsNotWith('dirty-', (string) $this->rawGeneration());
    }

    #[Test]
    public function aReaderServesFromItsMemoUntilAnotherPodsOneStepSet(): void
    {
        $this->newPod('B')->set($this->id('x'), $this->metadata('x', 1), [], 60);
        $this->newPod('A')->get($this->id('x'));

        // Steady state: one generation read, no entry read.
        $request = $this->newPod('A');
        $trips = $this->roundTrips(fn() => $request->get($this->id('x')));
        self::assertSame(1, $trips);
        self::assertSame('reuse', $this->lastCheck('A'));

        $this->newPod('B')->set($this->id('x'), $this->metadata('x', 2), [], 60);

        self::assertSame(2, $this->newPod('A')->get($this->id('x'))?->payloadSize);
        self::assertSame('reset', $this->lastCheck('A'));
        self::assertSame(2, $this->newPod('A')->get($this->id('x'))?->payloadSize);
        self::assertSame('reuse', $this->lastCheck('A'));
    }

    /**
     * Another writer is in the middle of a mutation of several steps (its
     * dirty marker is the generation). A set must not publish a clean
     * generation on top: if that writer's own publish then failed, readers
     * would trust a clean generation over its half-applied mutation.
     */
    #[Test]
    public function aSetLeavesAForeignDirtyMarkerAndReadersThenBypassTheirMemo(): void
    {
        $this->newPod('B')->set($this->id('x'), $this->metadata('x', 1), [], 60);
        $this->newPod('A')->get($this->id('x'));
        $this->backend->writeGeneration(AtomicGenerationMetadataCache::GENERATION_NAME, 'dirty-other');

        $this->newPod('B')->set($this->id('x'), $this->metadata('x', 2), [], 60);

        self::assertSame('dirty-other', $this->rawGeneration(), 'left for its writer');
        self::assertSame(1, $this->counted('B', 'metadata_generation_publish_total', 'deferred'));
        self::assertSame(2, $this->newPod('A')->get($this->id('x'))?->payloadSize, 'read from the store, not from the memo');
        self::assertSame('bypass', $this->lastCheck('A'));

        // That writer publishes: memos work again, under a new generation.
        $this->backend->publishGeneration(AtomicGenerationMetadataCache::GENERATION_NAME, 'published-by-other', 'dirty-other');
        self::assertSame(2, $this->newPod('A')->get($this->id('x'))?->payloadSize);
        self::assertSame('reset', $this->lastCheck('A'));
    }

    #[Test]
    public function aFlushOfTheWholeStoreTakesTheGenerationAlong(): void
    {
        $this->newPod('B')->set($this->id('x'), $this->metadata('x'), [], 60);
        $this->newPod('A')->get($this->id('x'));

        // Not through this package: e.g. `cache:flush` on the metadata cache.
        $this->frontend->flush();

        self::assertNull($this->rawGeneration());
        self::assertNull($this->newPod('A')->get($this->id('x')));
        self::assertSame('bypass', $this->lastCheck('A'));
    }

    // --- version skew ----------------------------------------------------

    #[Test]
    public function aNewReaderSeesALegacyPodsWriteAtItsNextRequest(): void
    {
        $this->newPod('B')->set($this->id('x'), $this->metadata('x', 1), [], 60);
        $this->newPod('A')->get($this->id('x'));
        self::assertSame('reuse', $this->requestCheck('A'));

        $this->legacyPod('old')->set($this->id('x'), $this->metadata('x', 2), [], 60);

        self::assertNotNull($this->legacyGeneration());
        self::assertSame(2, $this->newPod('A')->get($this->id('x'))?->payloadSize);
        self::assertSame('bypass', $this->lastCheck('A'), 'the legacy key exists: the raw generation says nothing about legacy writers');
    }

    #[Test]
    public function aLegacyReaderSeesANewPodsWriteAtItsNextRequest(): void
    {
        $this->legacyPod('writer')->set($this->id('x'), $this->metadata('x', 1), [], 60);
        $this->legacyPod('old')->get($this->id('x'));
        $this->legacyPod('old')->get($this->id('x'));
        self::assertSame('reuse', $this->lastCheck('old'));

        $pod = $this->newPod('B');
        $trips = $this->roundTrips(fn() => $pod->set($this->id('x'), $this->metadata('x', 2), [], 60));

        self::assertSame(1, $this->counted('B', 'metadata_generation_atomic_refused_total'), 'no one-step set under a legacy generation a legacy reader trusts');
        self::assertSame(2, $this->legacyPod('old')->get($this->id('x'))?->payloadSize);
        self::assertSame('bypass', $this->lastCheck('old'));
        self::assertNull($this->legacyGeneration(), 'retired by the new pod');
        // refused set, raw dirty marker, legacy dirty marker, set,
        // publish, legacy read, legacy remove
        self::assertSame(7, $trips);
    }

    #[Test]
    public function onceOnlyNewPodsWriteTheLegacyKeyIsRetiredAndSetsAreOneRoundTripAgain(): void
    {
        $this->legacyPod('old')->set($this->id('x'), $this->metadata('x', 1), [], 60);
        self::assertNotNull($this->legacyGeneration());

        $pod = $this->newPod('B');
        $pod->set($this->id('x'), $this->metadata('x', 2), [], 60);
        self::assertNull($this->legacyGeneration());

        $trips = $this->roundTrips(fn() => $pod->set($this->id('x'), $this->metadata('x', 3), [], 60));
        self::assertSame(1, $trips);
        self::assertSame(1, $this->counted('B', 'metadata_generation_atomic_refused_total'));
        $this->newPod('A')->get($this->id('x'));
        self::assertSame(3, $this->newPod('A')->get($this->id('x'))?->payloadSize);
        self::assertSame('reuse', $this->lastCheck('A'), 'memos serve again');
    }

    /**
     * A legacy writer is mid-mutation: its dirty marker sits in the legacy
     * key. A new pod's protocol takes the marker over for the time of its
     * own mutation — as a second legacy writer would — but a marker written
     * after that is foreign and stays.
     */
    #[Test]
    public function aLegacyWritersDirtyMarkerWrittenDuringANewPodsMutationIsNotRetired(): void
    {
        $this->legacyPod('old')->set($this->id('x'), $this->metadata('x', 1), [], 60);
        $legacy = new Typo3MetadataCache($this->frontend, $this->namespace);
        $store = AtomicGenerationMetadataCache::forFrontend($this->frontend, $this->namespace);
        self::assertInstanceOf(AtomicGenerationMetadataCache::class, $store);

        // The new pod's protocol, step by step.
        $store->writeGeneration('dirty-new');
        self::assertSame('dirty-new', $this->legacyGeneration(), 'legacy readers stop trusting their memos');
        $store->remove($this->id('x'));
        // Meanwhile a legacy writer starts its own mutation.
        $legacy->writeGeneration('dirty-legacy');
        self::assertTrue($store->publishGeneration('dirty-new', 'clean-new'));

        self::assertSame('clean-new', $this->rawGeneration());
        self::assertSame('dirty-legacy', $this->legacyGeneration(), 'left for the legacy writer');
        $this->newPod('A')->get($this->id('x'));
        self::assertSame('bypass', $this->lastCheck('A'), 'no memo while a legacy writer is active');

        // The legacy writer finishes; the next new mutation retires its key.
        $legacy->writeGeneration('clean-legacy');
        $this->newPod('A')->get($this->id('x'));
        self::assertSame('bypass', $this->lastCheck('A'));
        $this->newPod('B')->set($this->id('y'), $this->metadata('y'), [], 60);
        self::assertNull($this->legacyGeneration());
        $this->newPod('A')->get($this->id('x'));
        self::assertSame('reset', $this->lastCheck('A'));
    }

    #[Test]
    public function aLegacyWriteAfterTheKeyWasRetiredIsSeenByNewReaders(): void
    {
        $this->newPod('B')->set($this->id('x'), $this->metadata('x', 1), [], 60);
        $this->newPod('A')->get($this->id('x'));
        self::assertSame('reuse', $this->requestCheck('A'));
        $raw = $this->rawGeneration();

        $this->legacyPod('old')->remove($this->id('x'));

        self::assertSame($raw, $this->rawGeneration(), 'a legacy pod does not know the raw key');
        self::assertNull($this->newPod('A')->get($this->id('x')), 'and still its removal is seen');
        self::assertSame('bypass', $this->lastCheck('A'));
    }

    #[Test]
    public function aNewPodsFlushUnderALegacyGenerationStopsLegacyMemosBeforeItMutates(): void
    {
        $this->legacyPod('writer')->set($this->id('x'), $this->metadata('x'), ['t'], 60);
        $this->legacyPod('old')->get($this->id('x'));
        self::assertSame('reuse', $this->requestCheck('old', legacy: true));

        $this->newPod('B')->flushByTag('t');

        self::assertNull($this->legacyPod('old')->get($this->id('x')));
        self::assertSame('bypass', $this->lastCheck('old'));
        self::assertNull($this->legacyGeneration());
        self::assertStringStartsNotWith('dirty-', (string) $this->rawGeneration());
    }

    /** One more request of the pod that reads nothing new; returns how its check ended. */
    private function requestCheck(string $pod, bool $legacy = false): ?string
    {
        ($legacy ? $this->legacyPod($pod) : $this->newPod($pod))->get($this->id('x'));

        return $this->lastCheck($pod);
    }
}
