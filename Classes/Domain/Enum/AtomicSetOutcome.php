<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Enum;

/**
 * What {@see \Moselwal\Typo3ClusterCache\Domain\Contract\AtomicMetadataGenerationPort::setWithGeneration()}
 * did.
 */
enum AtomicSetOutcome
{
    /** The entry is stored and the generation is the new value. */
    case Published;

    /**
     * The entry is stored; the generation is another writer's dirty marker
     * and was left as it is. That writer publishes when it is done.
     */
    case Deferred;

    /**
     * Nothing was stored: a writer that does not know this store's
     * generation is active. The caller runs the dirty/publish protocol.
     */
    case Refused;
}
