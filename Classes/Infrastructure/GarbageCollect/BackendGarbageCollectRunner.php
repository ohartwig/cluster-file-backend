<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\GarbageCollect;

use Moselwal\Typo3ClusterCache\Application\GarbageCollect\GarbageCollectionReport;
use Moselwal\Typo3ClusterCache\Application\GarbageCollect\RunGarbageCollection;
use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\AtomicGenerationMetadataCache;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\GenerationGuardedMetadataCache;
use Moselwal\Typo3ClusterCache\Infrastructure\Observability\PrometheusMetrics;
use Moselwal\Typo3ClusterCache\Infrastructure\LocalStore\EmptyDirPayloadStore;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Glue between the Presentation layer (CLI command) and the pure
 * {@see RunGarbageCollection} application service. Resolves the cluster
 * frontend instance for a given {@see CacheNamespace} via TYPO3's
 * {@see CacheManager} and constructs the application service against its
 * runtime-bound metadata-cache collaborator.
 *
 * Sibling of {@see \Moselwal\Typo3ClusterCache\Infrastructure\WarmUp\BackendWarmUpRunner};
 * both classes exist because the Application-layer services
 * (RunGarbageCollection, WarmUpCacheBackend, …) depend on a namespace-bound
 * MetadataCachePort which cannot be wired by Symfony's DI compiler. The
 * runner is autowire-fähig — CacheManager and ClockPort are global services
 * — and produces the namespace-bound dependency tree on demand.
 */
readonly class BackendGarbageCollectRunner
{
    public function __construct(
        private CacheManager $cacheManager,
        private ClockPort $clock,
    ) {}

    public function run(CacheNamespace $namespace, bool $dryRun = false, ?int $localMaxAgeSeconds = null): GarbageCollectionReport
    {
        $frontend = $this->cacheManager->getCache($namespace->cacheName);
        $clusterBackend = $frontend->getBackend();
        if (!$clusterBackend instanceof ClusterFileBackend) {
            throw new \RuntimeException(\sprintf('Cache "%s" is not configured with ClusterFileBackend; cannot run GC.', $namespace->cacheName));
        }

        $metadataFrontend = $this->resolveMetadataFrontend($clusterBackend);
        $store = AtomicGenerationMetadataCache::forFrontend($metadataFrontend, $namespace);

        $service = new RunGarbageCollection(
            // Garbage collection mutates the store, so it goes through the
            // generation guard like every other mutation: pods with the
            // cross-request memo drop it at their next request.
            metadataCache: new GenerationGuardedMetadataCache($store, $store, $this->clock, new PrometheusMetrics()),
            clock: $this->clock,
            localPurger: new EmptyDirPayloadStore($clusterBackend->getLocalPath()),
            localMaxAgeSeconds: $localMaxAgeSeconds ?? RunGarbageCollection::DEFAULT_LOCAL_MAX_AGE_SECONDS,
        );

        return $service->execute($namespace, $dryRun);
    }

    private function resolveMetadataFrontend(ClusterFileBackend $clusterBackend): FrontendInterface
    {
        return $this->cacheManager->getCache($clusterBackend->getMetadataCacheIdentifier());
    }

    /**
     * Convenience constructor for callers that don't have CacheManager/Clock
     * injected — primarily for hand-wired tests outside the TYPO3 container.
     */
    public static function createDefault(): self
    {
        return new self(
            cacheManager: GeneralUtility::makeInstance(CacheManager::class),
            clock: GeneralUtility::makeInstance(ClockPort::class),
        );
    }
}
