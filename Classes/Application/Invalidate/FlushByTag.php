<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Application\Invalidate;

use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;

final readonly class FlushByTag
{
    public function __construct(
        private MetadataCachePort $metadataCache,
        private MetricsPort $metrics,
    ) {}

    public function execute(CacheNamespace $namespace, string $tag): void
    {
        $this->metadataCache->flushByTag($tag);
        $this->metrics->counter('cache_flush_total', [
            'cacheName' => $namespace->cacheName,
            'namespace' => $namespace->toKvKeyPrefix(),
            'kind' => 'tag',
        ]);
    }

    /**
     * @param list<string> $tags
     */
    public function executeMany(CacheNamespace $namespace, array $tags): void
    {
        $this->metadataCache->flushByTags($tags);
        $this->metrics->counter('cache_flush_total', [
            'cacheName' => $namespace->cacheName,
            'namespace' => $namespace->toKvKeyPrefix(),
            'kind' => 'tag',
        ], \count($tags));
    }
}
