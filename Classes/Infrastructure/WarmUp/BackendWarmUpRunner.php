<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\WarmUp;

use Moselwal\Typo3ClusterCache\Application\WarmUp\WarmUpCacheBackend;
use Moselwal\Typo3ClusterCache\Application\WarmUp\WarmUpReport;
use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Typo3MetadataCache;
use Moselwal\Typo3ClusterCache\Infrastructure\LocalStore\EmptyDirPayloadStore;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Glue between the Presentation layer (CLI command, event listener) and the
 * pure {@see WarmUpCacheBackend} application service. Resolves the cluster
 * frontend instance for a given {@see CacheNamespace} via TYPO3's
 * {@see CacheManager} and constructs the application service against its
 * internal collaborators.
 */
readonly class BackendWarmUpRunner
{
    public function __construct(
        private CacheManager $cacheManager,
        private ClockPort $clock,
        private MetricsPort $metrics,
    ) {}

    /**
     * @param list<string> $identifiers
     */
    public function run(CacheNamespace $namespace, array $identifiers = []): WarmUpReport
    {
        $frontend = $this->cacheManager->getCache($namespace->cacheName);
        $clusterBackend = $frontend->getBackend();
        if (!$clusterBackend instanceof ClusterFileBackend) {
            throw new \RuntimeException(\sprintf('Cache "%s" is not configured with ClusterFileBackend; cannot warm up.', $namespace->cacheName));
        }

        $metadataFrontend = $this->resolveMetadataFrontend($clusterBackend);
        $localStore = $this->resolveLocalStore($clusterBackend);

        $service = new WarmUpCacheBackend(
            metadataCache: new Typo3MetadataCache($metadataFrontend, $namespace),
            localStore: $localStore,
            clock: $this->clock,
            metrics: $this->metrics,
        );

        $cacheIdentifiers = [];
        foreach ($identifiers as $raw) {
            try {
                $cacheIdentifiers[] = new CacheIdentifier($raw);
            } catch (\InvalidArgumentException) {
                // Skip identifiers that don't pass TYPO3 entry-identifier pattern
            }
        }

        return $service->execute($namespace, $cacheIdentifiers);
    }

    /**
     * The cluster backend keeps its metadata frontend reference private; we
     * resolve it by reading its options through the registered backend. In
     * practice we re-look it up via the CacheManager using the configured
     * identifier (which the backend has stored via setCache()).
     */
    private function resolveMetadataFrontend(ClusterFileBackend $clusterBackend): FrontendInterface
    {
        $metadataCacheIdentifier = $clusterBackend->getMetadataCacheIdentifier();

        return $this->cacheManager->getCache($metadataCacheIdentifier);
    }

    private function resolveLocalStore(ClusterFileBackend $clusterBackend): EmptyDirPayloadStore
    {
        return new EmptyDirPayloadStore($clusterBackend->getLocalPath());
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
            metrics: GeneralUtility::makeInstance(MetricsPort::class),
        );
    }
}
