<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Application\Invalidate;

use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;

final readonly class RemoveCacheEntry
{
    public function __construct(
        private MetadataCachePort $metadataCache,
    ) {}

    public function execute(CacheIdentifier $identifier): bool
    {
        return $this->metadataCache->remove($identifier);
    }
}
