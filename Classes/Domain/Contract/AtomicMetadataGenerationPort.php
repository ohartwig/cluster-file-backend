<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Contract;

use Moselwal\Typo3ClusterCache\Domain\Enum\AtomicSetOutcome;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;

/**
 * Optional capability of a metadata store that keeps entries and generation
 * in one place and can change both in a single step on the server.
 *
 * A store without it (any TYPO3 cache backend that only knows entries)
 * is driven through {@see MetadataGenerationPort} alone: dirty marker,
 * mutation, read, publish. A store with it saves the three extra round
 * trips on a set and the read on every other mutation, and it closes the
 * window between "read the generation" and "write the clean one".
 *
 * Both methods keep the rule the protocol rests on: a dirty marker is
 * replaced by a clean generation only by the writer that wrote it.
 */
interface AtomicMetadataGenerationPort
{
    /**
     * {@see MetadataCachePort::set()} and the generation write as one step:
     * no reader can see the new entry under the old generation.
     *
     * @param list<string> $tags
     *
     * @throws \Throwable when the store could not carry out the step; the entry is then not stored under an unchanged generation
     */
    public function setWithGeneration(
        CacheIdentifier $identifier,
        CacheMetadata $metadata,
        array $tags,
        int $ttlSeconds,
        string $generation,
    ): AtomicSetOutcome;

    /**
     * The publish step of a mutation that ran under the dirty marker
     * $ownDirtyMarker: stores the clean $generation unless another writer's
     * dirty marker has replaced ours. Check and write are one step.
     *
     * @return bool false when it deferred to another writer's marker
     */
    public function publishGeneration(string $ownDirtyMarker, string $generation): bool;
}
