<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Application\Invalidate;

use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;

final readonly class FlushNamespace
{
    public function __construct(
        private MetadataCachePort $metadataCache,
        private MetricsPort $metrics,
    ) {}

    public function execute(CacheNamespace $namespace): void
    {
        $this->metadataCache->flush();
        $this->metrics->counter('cache_flush_total', [
            'cacheName' => $namespace->cacheName,
            'namespace' => $namespace->toKvKeyPrefix(),
            'kind' => 'full',
        ]);
    }
}
