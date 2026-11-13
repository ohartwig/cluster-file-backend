<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Cache;

use Moselwal\KeyValueStore\Cache\Backend\GenerationBackendInterface;
use Moselwal\Typo3ClusterCache\Domain\Contract\AtomicMetadataGenerationPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataGenerationPort;
use Moselwal\Typo3ClusterCache\Domain\Enum\AtomicSetOutcome;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * The metadata store when its TYPO3 cache backend can keep a generation
 * itself ({@see GenerationBackendInterface}, moselwal/keyvalue-store from
 * 4.9.0): entries go through the frontend as before, the generation is a
 * raw key of the backend, and a set changes both in one round trip.
 *
 * Two generation keys exist while package versions are mixed:
 *
 * - **raw** (`generation:{@see GENERATION_NAME}` in the backend's key
 *   space): the generation of this class. A plain string, `dirty-<random>`
 *   or `<random>`, never serialized. It is the only one pods of this
 *   version trust.
 * - **legacy** ({@see Typo3MetadataCache::GENERATION_KEY}, an entry written
 *   through the frontend, i.e. serialized and signed): the generation of
 *   pods that run a release before the raw key, or whose backend cannot
 *   keep one. Those pods read and write only this key.
 *
 * The rules that keep both kinds of pod correct next to each other:
 *
 * 1. A reader here trusts the raw generation only while the legacy key is
 *    absent. A legacy writer writes that key before it mutates and again
 *    after, so its mutations are never missed; they cost this pod its memo
 *    until the key is retired.
 * 2. The one-round-trip set refuses while the legacy key exists (the
 *    caller then runs the protocol below), so it never changes an entry
 *    under a legacy generation a legacy reader still trusts.
 * 3. The dirty/publish protocol here writes its dirty marker to the raw
 *    key and, if the legacy key exists, to that key too — exactly the first
 *    step a legacy writer takes, so legacy readers stop using their memos.
 *    Its publish step writes the clean raw generation and then retires the
 *    legacy key (removes it) unless that key holds a dirty marker of
 *    another writer, who is still mutating and publishes itself.
 *
 * A removed legacy key reads as "absent" on legacy pods, which is their
 * signal to read per entry. So during a rolling deploy both sides lose
 * their memos while the other side writes, and nobody serves stale
 * metadata; once only pods of this version write, the first mutation
 * retires the legacy key and every later set is one round trip.
 *
 * What remains is the case the protocol never excluded: a legacy writer
 * that is still mutating when its dirty marker is taken over, and whose
 * own final write then fails. It is bounded by the memo's maximum age, as
 * between two legacy writers.
 */
final readonly class AtomicGenerationMetadataCache implements MetadataCachePort, MetadataGenerationPort, AtomicMetadataGenerationPort
{
    /**
     * Name of the raw generation in the backend. One per backend key space,
     * like the legacy key and for the same reason: all caches sharing the
     * metadata frontend write into one key space. The `v2` names the
     * format; an incompatible successor takes a new name.
     */
    public const string GENERATION_NAME = 'cfb_generation_v2';

    public function __construct(
        private Typo3MetadataCache $store,
        private GenerationBackendInterface $backend,
    ) {}

    /**
     * The store for a metadata frontend: this class when the frontend's
     * backend can keep a generation, the plain adapter otherwise.
     */
    public static function forFrontend(FrontendInterface $frontend, CacheNamespace $namespace): MetadataCachePort&MetadataGenerationPort
    {
        $store = new Typo3MetadataCache($frontend, $namespace);
        $backend = $frontend->getBackend();

        return $backend instanceof GenerationBackendInterface ? new self($store, $backend) : $store;
    }

    public function get(CacheIdentifier $identifier): ?CacheMetadata
    {
        return $this->store->get($identifier);
    }

    public function set(CacheIdentifier $identifier, CacheMetadata $metadata, array $tags, int $ttlSeconds): void
    {
        $this->store->set($identifier, $metadata, $tags, $ttlSeconds);
    }

    public function remove(CacheIdentifier $identifier): bool
    {
        return $this->store->remove($identifier);
    }

    public function flush(): void
    {
        $this->store->flush();
    }

    public function flushByTag(string $tag): void
    {
        $this->store->flushByTag($tag);
    }

    public function flushByTags(array $tags): void
    {
        $this->store->flushByTags($tags);
    }

    public function findIdentifiersByTag(string $tag): array
    {
        return $this->store->findIdentifiersByTag($tag);
    }

    public function collectGarbage(): void
    {
        $this->store->collectGarbage();
    }

    /**
     * The generation a reader may trust: null while the legacy key exists
     * (rule 1), whatever the raw key holds.
     */
    public function readGeneration(): ?string
    {
        $state = $this->backend->readGeneration(self::GENERATION_NAME, Typo3MetadataCache::GENERATION_KEY);

        return $state->guardEntryExists ? null : $state->generation;
    }

    /**
     * Writes the raw generation unconditionally — the dirty marker of the
     * protocol — and repeats it in the legacy key when that exists (rule 3).
     */
    public function writeGeneration(string $generation): void
    {
        $state = $this->backend->writeGeneration(self::GENERATION_NAME, $generation, Typo3MetadataCache::GENERATION_KEY);
        if ($state->guardEntryExists) {
            $this->store->writeGeneration($generation);
        }
    }

    public function setWithGeneration(
        CacheIdentifier $identifier,
        CacheMetadata $metadata,
        array $tags,
        int $ttlSeconds,
        string $generation,
    ): AtomicSetOutcome {
        // The frontend encodes the entry as it always does; the backend
        // sends its set() together with the generation write.
        $state = $this->backend->setWithGeneration(
            self::GENERATION_NAME,
            $generation,
            fn() => $this->store->set($identifier, $metadata, $tags, $ttlSeconds),
            Typo3MetadataCache::GENERATION_KEY,
        );
        if ($state->guardEntryExists) {
            return AtomicSetOutcome::Refused;
        }

        return $state->written ? AtomicSetOutcome::Published : AtomicSetOutcome::Deferred;
    }

    public function publishGeneration(string $ownDirtyMarker, string $generation): bool
    {
        $state = $this->backend->publishGeneration(self::GENERATION_NAME, $generation, $ownDirtyMarker, Typo3MetadataCache::GENERATION_KEY);
        if ($state->guardEntryExists) {
            $this->retireLegacyGeneration($ownDirtyMarker);
        }

        return $state->written;
    }

    /**
     * Removes the legacy key unless a legacy writer is mid-mutation (its
     * dirty marker is in the key). Until it is removed, readers here do not
     * trust the raw generation just published, so the order of the two
     * steps does not matter to them; legacy readers see our dirty marker,
     * then nothing.
     */
    private function retireLegacyGeneration(string $ownDirtyMarker): void
    {
        $legacy = $this->store->readGeneration();
        if (null !== $legacy && $legacy !== $ownDirtyMarker && str_starts_with($legacy, GenerationBackendInterface::DIRTY_PREFIX)) {
            return;
        }
        $this->store->removeGeneration();
    }
}
