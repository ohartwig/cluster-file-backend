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
     *
     * Bumped to 3: PayloadHash takes its context as a suffix after the
     * serialized bytes instead of a prefix before them, so a PHP cache's
     * checksum comes from the same sha256 pass (PayloadHash::
     * computeWithChecksumOfBytes()). Every hash changes once; nothing old is read
     * as new.
     */
    public const int CURRENT = 3;

    private function __construct() {}
}
