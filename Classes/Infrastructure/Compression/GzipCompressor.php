<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Compression;

use Moselwal\Typo3ClusterCache\Domain\Contract\CompressorPort;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionAlgo;

final class GzipCompressor implements CompressorPort
{
    public function name(): string
    {
        return CompressionAlgo::Gzip->value;
    }

    public function isAvailable(): bool
    {
        return \function_exists('gzdeflate');
    }

    public function compress(string $bytes): string
    {
        $result = gzdeflate($bytes, 6);
        if (false === $result) {
            throw new \RuntimeException('gzdeflate() failed');
        }

        return $result;
    }

    public function decompress(string $bytes, int $maxOutputBytes): string
    {
        // gzinflate's $max_length parameter caps the inflate output and
        // returns false when the limit is reached — exactly the
        // compression-bomb protection we need. The `@` suppresses the
        // accompanying E_WARNING ("insufficient memory") that PHP emits
        // when the cap fires; we re-raise it deterministically as a
        // RuntimeException instead.
        $result = @gzinflate($bytes, $maxOutputBytes);
        if (false === $result) {
            throw new \RuntimeException(\sprintf('gzinflate() failed or output exceeded %d bytes', $maxOutputBytes));
        }

        return $result;
    }
}
