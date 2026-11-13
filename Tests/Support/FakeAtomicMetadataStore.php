<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Support;

use Moselwal\Typo3ClusterCache\Domain\Contract\AtomicMetadataGenerationPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataGenerationPort;
use Moselwal\Typo3ClusterCache\Domain\Enum\AtomicSetOutcome;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;

/**
 * A metadata store with the one-step capability: entries and generation in
 * one object. `$calls` records every generation-related call in order;
 * `$refuse` makes the one-step set refuse (as while a legacy writer is
 * active).
 */
final class FakeAtomicMetadataStore implements MetadataCachePort, MetadataGenerationPort, AtomicMetadataGenerationPort
{
    public ?string $generation = null;

    /** @var list<string> */
    public array $calls = [];

    public bool $refuse = false;

    public bool $failAtomicSet = false;

    public bool $failPublish = false;

    /** Called in place of every plain mutation; lets a test interleave another writer. */
    public ?\Closure $duringMutation = null;

    private FakeMetadataCache $entries;

    public function __construct()
    {
        $this->entries = new FakeMetadataCache();
    }

    public function get(CacheIdentifier $identifier): ?CacheMetadata
    {
        return $this->entries->get($identifier);
    }

    public function set(CacheIdentifier $identifier, CacheMetadata $metadata, array $tags, int $ttlSeconds): void
    {
        $this->mutation('set');
        $this->entries->set($identifier, $metadata, $tags, $ttlSeconds);
    }

    public function remove(CacheIdentifier $identifier): bool
    {
        $this->mutation('remove');

        return $this->entries->remove($identifier);
    }

    public function flush(): void
    {
        $this->mutation('flush');
        $this->entries->flush();
    }

    public function flushByTag(string $tag): void
    {
        $this->mutation('flushByTag');
        $this->entries->flushByTag($tag);
    }

    public function flushByTags(array $tags): void
    {
        $this->mutation('flushByTags');
        $this->entries->flushByTags($tags);
    }

    public function findIdentifiersByTag(string $tag): array
    {
        return $this->entries->findIdentifiersByTag($tag);
    }

    public function collectGarbage(): void
    {
        $this->mutation('collectGarbage');
    }

    public function readGeneration(): ?string
    {
        $this->calls[] = 'readGeneration';

        return $this->generation;
    }

    public function writeGeneration(string $generation): void
    {
        $this->calls[] = 'writeGeneration:' . (str_starts_with($generation, 'dirty-') ? 'dirty' : 'clean');
        $this->generation = $generation;
    }

    public function setWithGeneration(
        CacheIdentifier $identifier,
        CacheMetadata $metadata,
        array $tags,
        int $ttlSeconds,
        string $generation,
    ): AtomicSetOutcome {
        $this->calls[] = 'setWithGeneration';
        if ($this->failAtomicSet) {
            throw new \RuntimeException('store unreachable', 1794300003);
        }
        if ($this->refuse) {
            return AtomicSetOutcome::Refused;
        }
        $this->entries->set($identifier, $metadata, $tags, $ttlSeconds);
        if (null !== $this->generation && str_starts_with($this->generation, 'dirty-')) {
            return AtomicSetOutcome::Deferred;
        }
        $this->generation = $generation;

        return AtomicSetOutcome::Published;
    }

    public function publishGeneration(string $ownDirtyMarker, string $generation): bool
    {
        $this->calls[] = 'publishGeneration';
        if ($this->failPublish) {
            throw new \RuntimeException('publish failed', 1794300004);
        }
        if (null !== $this->generation && $this->generation !== $ownDirtyMarker && str_starts_with($this->generation, 'dirty-')) {
            return false;
        }
        $this->generation = $generation;

        return true;
    }

    private function mutation(string $what): void
    {
        $this->calls[] = $what;
        if (null !== $this->duringMutation) {
            ($this->duringMutation)();
        }
    }
}
