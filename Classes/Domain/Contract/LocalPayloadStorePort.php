<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Contract;

use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;

interface LocalPayloadStorePort
{
    public function pathFor(PayloadHash $hash): string;

    public function exists(PayloadHash $hash): bool;

    /**
     * Reads the bytes and validates them against the checksum.
     *
     * @throws \Moselwal\Typo3ClusterCache\Domain\Exception\PayloadIntegrityException
     * @throws \Moselwal\Typo3ClusterCache\Domain\Exception\PayloadNotFoundException
     */
    public function readVerified(PayloadHash $hash, PayloadChecksum $checksum): string;

    /**
     * Atomic write.
     *
     * @throws \Moselwal\Typo3ClusterCache\Domain\Exception\LocalStoreWriteException
     */
    public function write(PayloadHash $hash, string $bytes): void;

    public function delete(PayloadHash $hash): void;

    /**
     * Performs a real write probe to verify the local store is operable:
     * creates the local path's directory tree, writes and removes a
     * sentinel file. Used by deployment-time warm-up to surface
     * permission / disk-full / mount-broken issues before traffic hits.
     *
     * @return bool true when a sentinel write succeeded
     */
    public function probe(): bool;

    /**
     * @return iterable<int, PayloadHash>
     */
    public function iterateAll(): iterable;
}
