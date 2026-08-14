<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Compression;

use Moselwal\Typo3ClusterCache\Domain\Contract\CompressorPort;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionAlgo;

final class NullCompressor implements CompressorPort
{
    public function name(): string
    {
        return CompressionAlgo::None->value;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function compress(string $bytes): string
    {
        return $bytes;
    }

    public function decompress(string $bytes, int $maxOutputBytes): string
    {
        if (\strlen($bytes) > $maxOutputBytes) {
            throw new \RuntimeException(\sprintf('payload size %d exceeds limit of %d', \strlen($bytes), $maxOutputBytes));
        }

        return $bytes;
    }
}
