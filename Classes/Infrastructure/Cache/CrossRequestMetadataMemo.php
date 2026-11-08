<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Cache;

use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;

/**
 * Process-wide memo of metadata entries of one metadata store, valid for
 * exactly one generation of that store.
 *
 * State only; the rules live in {@see GenerationGuardedMetadataCache}.
 * One instance per metadata cache identifier and PHP process (in FrankenPHP
 * worker mode: per worker, surviving the per-request TYPO3 bootstrap),
 * shared by every cache that keeps its metadata in that store, because the
 * store keys its entries by the bare cache identifier.
 *
 * Besides the entries the memo records for which requests it has checked
 * the generation ("scopes": metadata cache frontend objects, which TYPO3
 * creates per request — sometimes more than one, e.g. an early bootstrap
 * CacheManager next to the container's) and whether it may serve them, so
 * that all caches of one scope share a single generation read. Scopes are
 * held weakly and drop out when their request ends.
 *
 * Bounded by `$maxEntries`; once full, further entries are not added (they
 * are still read from the store, once per request). The memo is emptied on
 * every generation change, so a full memo does not stay full for long.
 */
final class CrossRequestMetadataMemo
{
    /**
     * About 1.5 KiB of PHP memory per entry: 2048 entries cost some 3 MiB
     * per worker process.
     */
    public const int DEFAULT_MAX_ENTRIES = 2048;

    /** @var array<string, self> */
    private static array $registry = [];

    /** @var array<string, CacheMetadata|null> */
    private array $entries = [];

    private ?string $generation = null;

    private int $filledAt = 0;

    /** @var \WeakMap<object, bool> scope → may the memo serve it */
    private \WeakMap $checks;

    public function __construct(
        private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES,
    ) {
        if ($maxEntries < 1) {
            throw new \InvalidArgumentException('maxEntries must be >= 1');
        }
        $this->checks = new \WeakMap();
    }

    /**
     * The process-wide memo for the metadata store with the given TYPO3
     * cache identifier.
     */
    public static function forStore(string $metadataCacheIdentifier): self
    {
        return self::$registry[$metadataCacheIdentifier] ??= new self();
    }

    /**
     * Forgets every process-wide memo. For tests; production code never
     * needs it, because a stale memo is detected by its generation.
     *
     * @internal
     */
    public static function resetRegistry(): void
    {
        self::$registry = [];
    }

    public function isCheckedFor(object $scope): bool
    {
        return isset($this->checks[$scope]);
    }

    /**
     * Records the outcome of the generation check for the given request.
     */
    public function markChecked(object $scope, bool $usable): void
    {
        $this->checks[$scope] = $usable;
    }

    /**
     * Whether the memo may serve the given (checked) request.
     */
    public function isUsableFor(object $scope): bool
    {
        return $this->checks[$scope] ?? false;
    }

    public function generation(): ?string
    {
        return $this->generation;
    }

    public function filledAt(): int
    {
        return $this->filledAt;
    }

    /**
     * Drops every entry and starts over under the given generation (null:
     * under no generation, i.e. nothing may be reused later).
     */
    public function reset(?string $generation, int $now): void
    {
        $this->entries = [];
        $this->generation = $generation;
        $this->filledAt = $now;
    }

    /**
     * Drops every entry and the generation, and stops serving the requests
     * checked so far. Called before this process mutates the store.
     */
    public function invalidate(): void
    {
        $this->entries = [];
        $this->generation = null;
        $scopes = [];
        foreach ($this->checks as $scope => $usable) {
            $scopes[] = $scope;
        }
        foreach ($scopes as $scope) {
            $this->checks[$scope] = false;
        }
    }

    public function has(string $identifier): bool
    {
        return \array_key_exists($identifier, $this->entries);
    }

    public function get(string $identifier): ?CacheMetadata
    {
        return $this->entries[$identifier] ?? null;
    }

    /**
     * Stores an entry (null: a confirmed miss). Returns false if the memo
     * is full and the entry was not stored.
     */
    public function put(string $identifier, ?CacheMetadata $metadata): bool
    {
        if (!\array_key_exists($identifier, $this->entries) && \count($this->entries) >= $this->maxEntries) {
            return false;
        }
        $this->entries[$identifier] = $metadata;

        return true;
    }

    public function forget(string $identifier): void
    {
        unset($this->entries[$identifier]);
    }

    public function count(): int
    {
        return \count($this->entries);
    }
}
