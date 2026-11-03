<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Application\GarbageCollect;

use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Contract\StalePayloadPurgerPort;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;

/**
 * Orchestrates garbage collection of the metadata cache. The actual eviction
 * logic is delegated to the configured TYPO3 cache backend (e.g.
 * {@see \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::collectGarbage()},
 * Redis/Valkey expiration, etc.). This class is a thin wrapper that also
 * produces run reports.
 *
 * It also reclaims the pod-local payload files: the store is content-addressed,
 * so a changed payload leaves its predecessor behind and nothing else removes
 * it. Files not read (verified reads refresh the mtime) for `$localMaxAgeSeconds`
 * are deleted. A file deleted too early only costs a re-materialisation.
 */
final readonly class RunGarbageCollection
{
    public const int DEFAULT_LOCAL_MAX_AGE_SECONDS = 604800; // 7 days

    public function __construct(
        private MetadataCachePort $metadataCache,
        private ClockPort $clock,
        private ?StalePayloadPurgerPort $localPurger = null,
        private int $localMaxAgeSeconds = self::DEFAULT_LOCAL_MAX_AGE_SECONDS,
    ) {
        if ($localMaxAgeSeconds < 1) {
            throw new \InvalidArgumentException('localMaxAgeSeconds must be >= 1');
        }
    }

    public function execute(CacheNamespace $namespace, bool $dryRun = false): GarbageCollectionReport
    {
        $start = $this->clock->now();
        $localRemoved = 0;
        if (!$dryRun) {
            $this->metadataCache->collectGarbage();
            $localRemoved = $this->localPurger?->purgeOlderThan($start - $this->localMaxAgeSeconds) ?? 0;
        }

        return new GarbageCollectionReport(
            namespace: $namespace->toKvKeyPrefix(),
            dryRun: $dryRun,
            durationMs: ($this->clock->now() - $start) * 1000,
            localFilesRemoved: $localRemoved,
        );
    }
}
