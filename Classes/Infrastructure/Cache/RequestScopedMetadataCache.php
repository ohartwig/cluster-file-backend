<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Cache;

use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;

/**
 * Memoising decorator for a {@see MetadataCachePort}, scoped to the lifetime
 * of the instance.
 *
 * Every consumer of the metadata cache inside one backend instance — the
 * backend's own `has()` / `requireOnce()` probe, {@see \Moselwal\Typo3ClusterCache\Application\Read\ReadCacheEntry}
 * and {@see \Moselwal\Typo3ClusterCache\Application\Write\WriteCacheEntry} —
 * shares this one memo, so the typical PhpFrontend sequence
 * `requireOnce() → miss → set() → requireOnce()` costs one metadata round
 * trip instead of three (probe, the writer's existing-entry check, re-probe
 * after the write).
 *
 * Scope: one instance per {@see Backend\ClusterFileBackend},
 * which TYPO3's CacheManager creates once per request (FrankenPHP worker
 * mode bootstraps TYPO3 — container and CacheManager included — per
 * request). The memo therefore never outlives a request, and other pods'
 * writes and flushes become visible at the next request. Within a request a
 * value is served from RAM after the first read, the same window the
 * backend's request-scoped payload L1 already accepts.
 *
 * Writes through this instance keep the memo coherent:
 * - `set()` memoises the metadata it has just written (read-your-writes);
 * - `remove()` memoises a miss;
 * - `flush()`, `flushByTag()`, `flushByTags()` and `collectGarbage()` drop
 *   the whole memo (the tag-to-identifier mapping lives in the inner cache).
 * If the inner call throws, the affected key (or the whole memo) is dropped
 * all the same, so the next read goes to the source again. Read errors are
 * never memoised.
 */
final class RequestScopedMetadataCache implements MetadataCachePort
{
    /**
     * Identifier value → metadata, or null for a memoised miss.
     *
     * @var array<string, CacheMetadata|null>
     */
    private array $memo = [];

    public function __construct(
        private readonly MetadataCachePort $inner,
    ) {}

    public function get(CacheIdentifier $identifier): ?CacheMetadata
    {
        $key = $identifier->value;
        if (\array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        $metadata = $this->inner->get($identifier);
        $this->memo[$key] = $metadata;

        return $metadata;
    }

    public function set(CacheIdentifier $identifier, CacheMetadata $metadata, array $tags, int $ttlSeconds): void
    {
        unset($this->memo[$identifier->value]);
        $this->inner->set($identifier, $metadata, $tags, $ttlSeconds);
        $this->memo[$identifier->value] = $metadata;
    }

    public function remove(CacheIdentifier $identifier): bool
    {
        unset($this->memo[$identifier->value]);
        $removed = $this->inner->remove($identifier);
        $this->memo[$identifier->value] = null;

        return $removed;
    }

    public function flush(): void
    {
        try {
            $this->inner->flush();
        } finally {
            $this->memo = [];
        }
    }

    public function flushByTag(string $tag): void
    {
        try {
            $this->inner->flushByTag($tag);
        } finally {
            $this->memo = [];
        }
    }

    public function flushByTags(array $tags): void
    {
        try {
            $this->inner->flushByTags($tags);
        } finally {
            $this->memo = [];
        }
    }

    public function findIdentifiersByTag(string $tag): array
    {
        return $this->inner->findIdentifiersByTag($tag);
    }

    public function collectGarbage(): void
    {
        try {
            $this->inner->collectGarbage();
        } finally {
            $this->memo = [];
        }
    }
}
