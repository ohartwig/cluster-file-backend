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
 * Counts calls on the decorated port and fails on demand, so the tests can
 * tell RAM answers from round trips.
 */
final class CountingMetadataCache implements MetadataCachePort
{
    public int $gets = 0;
    public int $invalidations = 0;
    /** @var list<array{string, list<string>, int}> */
    public array $setCalls = [];
    public bool $failing = false;

    public function __construct(private readonly MetadataCachePort $store) {}

    public function get(CacheIdentifier $identifier): ?CacheMetadata
    {
        ++$this->gets;
        $this->guard();

        return $this->store->get($identifier);
    }

    public function set(CacheIdentifier $identifier, CacheMetadata $metadata, array $tags, int $ttlSeconds): void
    {
        $this->guard();
        $this->setCalls[] = [$identifier->value, $tags, $ttlSeconds];
        $this->store->set($identifier, $metadata, $tags, $ttlSeconds);
    }

    public function remove(CacheIdentifier $identifier): bool
    {
        $this->guard();

        return $this->store->remove($identifier);
    }

    public function flush(): void
    {
        $this->invalidate();
        $this->store->flush();
    }

    public function flushByTag(string $tag): void
    {
        $this->invalidate();
        $this->store->flushByTag($tag);
    }

    public function flushByTags(array $tags): void
    {
        $this->invalidate();
        $this->store->flushByTags($tags);
    }

    public function findIdentifiersByTag(string $tag): array
    {
        return $this->store->findIdentifiersByTag($tag);
    }

    public function collectGarbage(): void
    {
        $this->invalidate();
        $this->store->collectGarbage();
    }

    private function invalidate(): void
    {
        $this->guard();
        ++$this->invalidations;
    }

    private function guard(): void
    {
        if ($this->failing) {
            throw new \RuntimeException('metadata cache offline', 1794127300);
        }
    }
}
