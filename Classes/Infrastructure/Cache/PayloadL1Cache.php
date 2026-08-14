<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Cache;

/**
 * Request-scoped in-memory L1 cache for decompressed cache payloads.
 *
 * Lives on the {@see Backend\ClusterFileBackend}
 * instance and is naturally request-scoped under FrankenPHP worker mode
 * (TYPO3 bootstraps per request, so a fresh backend + a fresh L1 is
 * created on each request — no shutdown hook needed).
 *
 * Eviction is a least-recently-used policy implemented via PHP array
 * insertion order: a `get()` hit unsets the key and re-adds it, moving
 * the entry to the tail. New entries are appended; eviction drops from
 * the head until both caps (entry count + byte budget) are satisfied.
 *
 * Both caps apply simultaneously. A single entry larger than the byte
 * budget bypasses the cache entirely rather than evicting every other
 * entry on insert.
 */
final class PayloadL1Cache
{
    /** @var array<string, string> */
    private array $entries = [];
    private int $totalBytes = 0;

    public function __construct(
        private readonly int $maxEntries,
        private readonly int $maxBytes,
    ) {
        if ($maxEntries < 0) {
            throw new \InvalidArgumentException('PayloadL1Cache.maxEntries must be >= 0');
        }
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('PayloadL1Cache.maxBytes must be >= 0');
        }
    }

    public function isEnabled(): bool
    {
        return $this->maxEntries > 0;
    }

    /**
     * Returns the cached payload or null if absent. On hit, the entry is
     * promoted to most-recently-used.
     */
    public function get(string $key): ?string
    {
        if (!isset($this->entries[$key])) {
            return null;
        }
        $value = $this->entries[$key];
        unset($this->entries[$key]);
        $this->entries[$key] = $value;

        return $value;
    }

    public function put(string $key, string $bytes): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $size = \strlen($bytes);
        // Oversized single entry: bypass the cache entirely. Otherwise
        // a 5 MB payload would evict every other entry on insert.
        if ($this->maxBytes > 0 && $size > $this->maxBytes) {
            return;
        }
        if (isset($this->entries[$key])) {
            $this->totalBytes -= \strlen($this->entries[$key]);
            unset($this->entries[$key]);
        }
        $this->entries[$key] = $bytes;
        $this->totalBytes += $size;

        while (
            \count($this->entries) > $this->maxEntries
            || ($this->maxBytes > 0 && $this->totalBytes > $this->maxBytes)
        ) {
            $evictKey = array_key_first($this->entries);
            if (null === $evictKey) {
                break;
            }
            $this->totalBytes -= \strlen($this->entries[$evictKey]);
            unset($this->entries[$evictKey]);
        }
    }

    public function forget(string $key): void
    {
        if (!isset($this->entries[$key])) {
            return;
        }
        $this->totalBytes -= \strlen($this->entries[$key]);
        unset($this->entries[$key]);
    }

    public function clear(): void
    {
        $this->entries = [];
        $this->totalBytes = 0;
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    public function totalBytes(): int
    {
        return $this->totalBytes;
    }

    /**
     * @return list<string> identifier keys in insertion (LRU) order — head is least-recently-used
     */
    public function keys(): array
    {
        return array_keys($this->entries);
    }
}
