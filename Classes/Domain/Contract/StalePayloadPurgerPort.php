<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Contract;

/**
 * Pod-local reclamation of payload files that no read has touched for a
 * while. The store is content-addressed, so a changed payload leaves its
 * predecessor behind; metadata flushes never reach the local files.
 */
interface StalePayloadPurgerPort
{
    /**
     * Removes payload files whose modification time is older than the cutoff.
     *
     * @param int $cutoff Unix timestamp; files with mtime < cutoff are removed
     *
     * @return int number of files removed
     */
    public function purgeOlderThan(int $cutoff): int;
}
