<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Support;

use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;

/**
 * In-memory implementation of MetadataCachePort for unit tests.
 * Tracks the tags per identifier so that `flushByTag`/`findIdentifiersByTag`
 * behave deterministically.
 */
final class FakeMetadataCache implements MetadataCachePort
{
    /** @var array<string, CacheMetadata> */
    private array $entries = [];
    /** @var array<string, list<string>> identifier => list of tags */
    private array $entryTags = [];
    public int $gcCalls = 0;

    public function get(CacheIdentifier $identifier): ?CacheMetadata
    {
        return $this->entries[$identifier->value] ?? null;
    }

    public function set(CacheIdentifier $identifier, CacheMetadata $metadata, array $tags, int $ttlSeconds): void
    {
        $this->entries[$identifier->value] = $metadata;
        $this->entryTags[$identifier->value] = array_values($tags);
    }

    public function remove(CacheIdentifier $identifier): bool
    {
        if (!isset($this->entries[$identifier->value])) {
            return false;
        }
        unset($this->entries[$identifier->value], $this->entryTags[$identifier->value]);

        return true;
    }

    public function flush(): void
    {
        $this->entries = [];
        $this->entryTags = [];
    }

    public function flushByTag(string $tag): void
    {
        foreach ($this->findIdentifiersByTag($tag) as $identifier) {
            unset($this->entries[$identifier], $this->entryTags[$identifier]);
        }
    }

    public function flushByTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->flushByTag($tag);
        }
    }

    public function findIdentifiersByTag(string $tag): array
    {
        $matches = [];
        foreach ($this->entryTags as $identifier => $tags) {
            if (\in_array($tag, $tags, true)) {
                $matches[] = $identifier;
            }
        }

        return $matches;
    }

    public function collectGarbage(): void
    {
        ++$this->gcCalls;
    }

    public function count(): int
    {
        return \count($this->entries);
    }
}
