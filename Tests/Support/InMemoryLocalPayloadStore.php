<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Support;

use Moselwal\Typo3ClusterCache\Domain\Contract\LocalPayloadStorePort;
use Moselwal\Typo3ClusterCache\Domain\Exception\PayloadIntegrityException;
use Moselwal\Typo3ClusterCache\Domain\Exception\PayloadNotFoundException;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;

final class InMemoryLocalPayloadStore implements LocalPayloadStorePort
{
    /** @var array<string, string> */
    private array $files = [];

    public function pathFor(PayloadHash $hash): string
    {
        return '/in-memory/' . $hash->digest;
    }

    public function exists(PayloadHash $hash): bool
    {
        return isset($this->files[$hash->digest]);
    }

    public function readVerified(PayloadHash $hash, PayloadChecksum $checksum): string
    {
        if (!isset($this->files[$hash->digest])) {
            throw new PayloadNotFoundException('not found');
        }
        $bytes = $this->files[$hash->digest];
        if (!PayloadChecksum::ofBytes($bytes)->equals($checksum)) {
            throw new PayloadIntegrityException('checksum mismatch');
        }

        return $bytes;
    }

    public function write(PayloadHash $hash, string $bytes): void
    {
        $this->files[$hash->digest] = $bytes;
    }

    public function delete(PayloadHash $hash): void
    {
        unset($this->files[$hash->digest]);
    }

    public function probe(): bool
    {
        // In-memory store is always operable.
        return true;
    }

    public function iterateAll(): iterable
    {
        foreach (array_keys($this->files) as $digest) {
            yield new PayloadHash($digest);
        }
    }

    public function corrupt(PayloadHash $hash, string $newBytes): void
    {
        $this->files[$hash->digest] = $newBytes;
    }
}
