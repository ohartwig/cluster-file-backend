<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Application\WarmUp;

use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\LocalPayloadStorePort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;

/**
 * Performs a pre-flight warm-up for a single cache namespace, typically
 * triggered at deployment time. Three responsibilities, in order:
 *
 * 1. **Health-check the metadata cache.** A trivial read against a sentinel
 *    key verifies that the configured TYPO3 cache frontend (and its
 *    underlying backend — Redis, database, etc.) is reachable. Fail-fast
 *    here means broken backend wiring is surfaced before the first
 *    production request hits.
 *
 * 2. **Ensure the local payload directory is writable.** Triggers an
 *    inexpensive write probe so directory creation, permission, mount
 *    issues fail at deploy time, not at first cache write.
 *
 * 3. **Optionally pre-touch identifiers.** Given a list of known identifiers
 *    (typically read from a seed list, often the most-trafficked pages),
 *    `WarmUpCacheBackend` reads each one. For entries whose metadata is
 *    present and whose local file exists, this is a fast path that confirms
 *    cluster consistency. For entries whose metadata is present but the
 *    local file is absent, this counts as a blob-miss — the caller does
 *    not re-materialise here (re-materialisation is request-driven through
 *    the TYPO3 caching frontend), but the count is reported.
 */
final readonly class WarmUpCacheBackend
{
    public function __construct(
        private MetadataCachePort $metadataCache,
        private LocalPayloadStorePort $localStore,
        private ClockPort $clock,
        private MetricsPort $metrics,
    ) {}

    /**
     * @param list<CacheIdentifier> $identifiersToProbe
     */
    public function execute(
        CacheNamespace $namespace,
        array $identifiersToProbe = [],
    ): WarmUpReport {
        $start = $this->clock->now();
        $metadataHealthy = $this->checkMetadataHealth($namespace);
        $localWritable = $this->checkLocalStoreWritable($namespace);

        $localHits = 0;
        $blobMisses = 0;
        foreach ($identifiersToProbe as $identifier) {
            $metadata = $this->metadataCache->get($identifier);
            if (null === $metadata) {
                continue;
            }
            if ($this->localStore->exists($metadata->hash)) {
                ++$localHits;
            } else {
                ++$blobMisses;
            }
        }

        $this->metrics->counter('cache_warmup_total', [
            'cacheName' => $namespace->cacheName,
            'namespace' => $namespace->toKvKeyPrefix(),
        ]);

        return new WarmUpReport(
            namespace: $namespace->toKvKeyPrefix(),
            metadataCacheHealthy: $metadataHealthy,
            localStoreWritable: $localWritable,
            prefetchedIdentifiers: \count($identifiersToProbe),
            localHits: $localHits,
            blobMisses: $blobMisses,
            durationMs: ($this->clock->now() - $start) * 1000,
        );
    }

    private function checkMetadataHealth(CacheNamespace $namespace): bool
    {
        try {
            // A `get` on a non-existent key MUST not throw — that confirms
            // the backend is reachable and configured correctly.
            $sentinel = new CacheIdentifier('cfb_warmup_probe_' . $namespace->cacheName);
            $this->metadataCache->get($sentinel);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkLocalStoreWritable(CacheNamespace $namespace): bool
    {
        unset($namespace);
        try {
            return $this->localStore->probe();
        } catch (\Throwable) {
            return false;
        }
    }
}
