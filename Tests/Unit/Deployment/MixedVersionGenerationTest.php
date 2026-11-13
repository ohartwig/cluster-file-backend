<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Deployment;

use Moselwal\KeyValueStore\Cache\Backend\GenerationBackendInterface;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\AtomicGenerationMetadataCache;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\GenerationGuardedMetadataCache;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Typo3MetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\AtomicGenerationScenarios;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataFrontend;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use Moselwal\Typo3ClusterCache\Tests\Support\InMemoryGenerationBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\BackendInterface;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * {@see AtomicGenerationScenarios} over an in-memory backend that counts
 * round trips, plus what only a double can show: the store that is chosen
 * for a backend without the capability, and failures of single steps.
 */
#[CoversClass(AtomicGenerationMetadataCache::class)]
#[CoversClass(GenerationGuardedMetadataCache::class)]
#[CoversClass(Typo3MetadataCache::class)]
final class MixedVersionGenerationTest extends AtomicGenerationScenarios
{
    protected function createBackend(): BackendInterface&GenerationBackendInterface
    {
        return new InMemoryGenerationBackend();
    }

    protected function roundTrips(\Closure $work): int
    {
        self::assertInstanceOf(InMemoryGenerationBackend::class, $this->backend);
        $before = $this->backend->roundTrips;
        $work();

        return $this->backend->roundTrips - $before;
    }

    #[Test]
    public function aBackendWithoutTheCapabilityGetsThePlainStoreAndTheThreeStepProtocol(): void
    {
        $frontend = new FakeMetadataFrontend();
        $store = AtomicGenerationMetadataCache::forFrontend($frontend, new CacheNamespace(EnvironmentName::Testing, 'cluster', 'pages'));

        self::assertInstanceOf(Typo3MetadataCache::class, $store);
        $guarded = new GenerationGuardedMetadataCache($store, $store, new FakeClock(1_700_000_000), new FakeMetrics());
        self::assertFalse($guarded->isAtomic());

        $guarded->flush();

        self::assertSame(2, $frontend->generationWrites, 'dirty marker and clean generation');
        self::assertSame(1, $frontend->generationReads, 'the read before publishing');
        self::assertIsString($frontend->entries[Typo3MetadataCache::GENERATION_KEY]);
    }

    #[Test]
    public function aStoreThatCannotPlaceTheDirtyMarkerDoesNotMutate(): void
    {
        self::assertInstanceOf(InMemoryGenerationBackend::class, $this->backend);
        $store = AtomicGenerationMetadataCache::forFrontend(
            new VariableFrontend('cluster_meta', $this->backend),
            new CacheNamespace(EnvironmentName::Testing, 'cluster', 'pages'),
        );
        $guarded = new GenerationGuardedMetadataCache($store, $store, new FakeClock(1_700_000_000), new FakeMetrics());
        $this->backend->entries['kept'] = 'x';
        $this->backend->failGenerationCalls = 1;

        try {
            $guarded->flush();
            self::fail('expected the store error');
        } catch (\RuntimeException $e) {
            self::assertSame(1794300002, $e->getCode());
        }

        self::assertSame(['writeGeneration'], $this->backend->log);
        self::assertArrayHasKey('kept', $this->backend->entries);
    }
}
