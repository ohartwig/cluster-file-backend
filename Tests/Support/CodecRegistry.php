<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Support;

use Moselwal\Typo3ClusterCache\Domain\Contract\CompressorPort;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionAlgo;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\GzipCompressor;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\NullCompressor;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\ZstdCompressor;

/**
 * Test helper: builds the `compressorsByAlgo` map that
 * {@see \Moselwal\Typo3ClusterCache\Application\Write\WriteCacheEntry}
 * and {@see \Moselwal\Typo3ClusterCache\Application\Read\ReadCacheEntry}
 * require. The map always contains all three codecs so the marker-aware
 * reader can decompress whatever marker the writer chose (None for the
 * skip-compress path, or the configured primary).
 */
final class CodecRegistry
{
    /**
     * @return array<string, CompressorPort>
     */
    public static function all(): array
    {
        return [
            CompressionAlgo::None->value => new NullCompressor(),
            CompressionAlgo::Gzip->value => new GzipCompressor(),
            CompressionAlgo::Zstd->value => new ZstdCompressor(),
        ];
    }

    /**
     * Returns a map where every codec slot points at the supplied $primary.
     * Use when a test wants the writer to behave as if a single codec was
     * the configured one — e.g. NullCompressor for "always uncompressed".
     *
     * @return array<string, CompressorPort>
     */
    public static function single(CompressorPort $primary): array
    {
        return [
            CompressionAlgo::None->value => $primary,
            CompressionAlgo::Gzip->value => $primary,
            CompressionAlgo::Zstd->value => $primary,
        ];
    }
}
