<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Application\WarmUp;

/**
 * Result of a single cache warm-up run for one cache namespace.
 *
 * Designed for JSON-Lines output from CLI commands and as a structured
 * return value for the {@see WarmUpCacheBackend} application service.
 */
final readonly class WarmUpReport
{
    public function __construct(
        public string $namespace,
        public bool $metadataCacheHealthy,
        public bool $localStoreWritable,
        public int $prefetchedIdentifiers,
        public int $localHits,
        public int $blobMisses,
        public int $durationMs,
    ) {}

    public function succeeded(): bool
    {
        return $this->metadataCacheHealthy && $this->localStoreWritable;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'namespace' => $this->namespace,
            'metadataCacheHealthy' => $this->metadataCacheHealthy,
            'localStoreWritable' => $this->localStoreWritable,
            'prefetchedIdentifiers' => $this->prefetchedIdentifiers,
            'localHits' => $this->localHits,
            'blobMisses' => $this->blobMisses,
            'durationMs' => $this->durationMs,
            'succeeded' => $this->succeeded(),
        ];
    }
}
