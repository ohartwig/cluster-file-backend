<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Compression;

use Moselwal\Typo3ClusterCache\Domain\Contract\CompressorPort;
use Moselwal\Typo3ClusterCache\Domain\Exception\CompressorUnavailableException;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionAlgo;

final class ZstdCompressor implements CompressorPort
{
    public function name(): string
    {
        return CompressionAlgo::Zstd->value;
    }

    public function isAvailable(): bool
    {
        return \extension_loaded('zstd');
    }

    public function compress(string $bytes): string
    {
        if (!$this->isAvailable()) {
            throw new CompressorUnavailableException('PHP extension "zstd" is not loaded');
        }
        $result = @zstd_compress($bytes);
        if (!\is_string($result)) {
            throw new \RuntimeException('zstd_compress() returned non-string result');
        }

        return $result;
    }

    public function decompress(string $bytes, int $maxOutputBytes): string
    {
        if (!$this->isAvailable()) {
            throw new CompressorUnavailableException('PHP extension "zstd" is not loaded');
        }
        // ext-zstd has no built-in output-size limit, so we post-check.
        // The decompression itself still allocates the full size in
        // memory — for a hard-cap before allocation we would need a
        // streaming API which the extension does not expose.
        $result = @zstd_uncompress($bytes);
        if (!\is_string($result)) {
            throw new \RuntimeException('zstd_uncompress() failed');
        }
        if (\strlen($result) > $maxOutputBytes) {
            throw new \RuntimeException(\sprintf('zstd_uncompress() produced %d bytes, exceeding limit of %d', \strlen($result), $maxOutputBytes));
        }

        return $result;
    }
}
