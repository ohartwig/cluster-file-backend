<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Contract;

use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;

/**
 * Narrow contract for reading/writing/invalidating central cache metadata.
 * The only allowed view onto the metadata storage in this package; the
 * concrete implementation encapsulates an arbitrary TYPO3 cache frontend
 * (e.g. {@see \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend}).
 *
 * This interface knows NOTHING about Redis, Lua, or connection handling —
 * only cache semantics in the sense of the TYPO3 cache API.
 */
interface MetadataCachePort
{
    public function get(CacheIdentifier $identifier): ?CacheMetadata;

    /**
     * @param list<string> $tags
     */
    public function set(CacheIdentifier $identifier, CacheMetadata $metadata, array $tags, int $ttlSeconds): void;

    public function remove(CacheIdentifier $identifier): bool;

    public function flush(): void;

    public function flushByTag(string $tag): void;

    /**
     * @param list<string> $tags
     */
    public function flushByTags(array $tags): void;

    /**
     * @return list<string>
     */
    public function findIdentifiersByTag(string $tag): array;

    public function collectGarbage(): void;
}
