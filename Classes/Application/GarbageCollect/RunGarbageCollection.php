<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Application\GarbageCollect;

use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;

/**
 * Orchestrates garbage collection of the metadata cache. The actual eviction
 * logic is delegated to the configured TYPO3 cache backend (e.g.
 * {@see \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::collectGarbage()},
 * Redis/Valkey expiration, etc.). This class is a thin wrapper that also
 * produces run reports.
 */
final readonly class RunGarbageCollection
{
    public function __construct(
        private MetadataCachePort $metadataCache,
        private ClockPort $clock,
    ) {}

    public function execute(CacheNamespace $namespace, bool $dryRun = false): GarbageCollectionReport
    {
        $start = $this->clock->now();
        if (!$dryRun) {
            $this->metadataCache->collectGarbage();
        }

        return new GarbageCollectionReport(
            namespace: $namespace->toKvKeyPrefix(),
            dryRun: $dryRun,
            durationMs: ($this->clock->now() - $start) * 1000,
        );
    }
}
