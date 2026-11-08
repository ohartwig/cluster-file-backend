<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Contract;

/**
 * Access to the cluster-wide generation value of one metadata store.
 *
 * The generation is a single opaque string kept next to the metadata
 * entries. Every mutation of the metadata replaces it with a fresh random
 * value, so a pod that remembers the value it last saw can tell with one
 * read whether anything in the store changed since.
 */
interface MetadataGenerationPort
{
    /**
     * Returns the current generation, or null if none is stored (never
     * written, flushed away, evicted or expired).
     */
    public function readGeneration(): ?string;

    public function writeGeneration(string $generation): void;
}
