<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain;

final class BackendVersionInfo
{
    /**
     * Bumped to 2 in v2.2.0: the on-disk payload format gained a one-byte
     * compression marker so that the cache can store uncompressed payloads
     * (skip-compress for tiny values) and PHP-cache payloads (PhpFrontend
     * support) in the same store. Bumping CURRENT folds into every hash and
     * makes pre-v2.2 entries unreachable, so the reader never tries to
     * interpret an old payload without a marker as a marker-prefixed one.
     */
    public const int CURRENT = 2;

    private function __construct() {}
}
