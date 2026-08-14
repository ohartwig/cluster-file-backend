<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\Compression;

use Moselwal\Typo3ClusterCache\Infrastructure\Compression\GzipCompressor;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\NullCompressor;
use Moselwal\Typo3ClusterCache\Infrastructure\Compression\ZstdCompressor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GzipCompressor::class)]
#[CoversClass(NullCompressor::class)]
#[CoversClass(ZstdCompressor::class)]
final class CompressorRoundtripTest extends TestCase
{
    public function testNullRoundtrip(): void
    {
        $compressor = new NullCompressor();
        $bytes = 'arbitrary payload bytes';
        self::assertSame($bytes, $compressor->decompress($compressor->compress($bytes), 1024));
    }

    public function testNullEnforcesMaxOutputBytes(): void
    {
        $this->expectException(\RuntimeException::class);
        new NullCompressor()->decompress(str_repeat('x', 1000), 999);
    }

    public function testGzipRoundtrip(): void
    {
        if (!\function_exists('gzdeflate')) {
            self::markTestSkipped('zlib extension not loaded');
        }
        $compressor = new GzipCompressor();
        $bytes = str_repeat('payload', 100);
        self::assertSame($bytes, $compressor->decompress($compressor->compress($bytes), 10_000));
    }

    public function testGzipRejectsDecompressionAboveLimit(): void
    {
        if (!\function_exists('gzdeflate')) {
            self::markTestSkipped('zlib extension not loaded');
        }
        // Compress a 1MB highly-compressible payload to ~1KB; then refuse
        // to decompress beyond 512 bytes — should throw.
        $bytes = str_repeat('a', 1024 * 1024);
        $compressed = new GzipCompressor()->compress($bytes);
        self::assertLessThan(\strlen($bytes), \strlen($compressed));

        $this->expectException(\RuntimeException::class);
        new GzipCompressor()->decompress($compressed, 512);
    }

    public function testZstdRoundtrip(): void
    {
        $compressor = new ZstdCompressor();
        if (!$compressor->isAvailable()) {
            self::markTestSkipped('zstd extension not loaded');
        }
        $bytes = str_repeat('zstd payload ', 50);
        self::assertSame($bytes, $compressor->decompress($compressor->compress($bytes), 10_000));
    }

    public function testZstdRejectsDecompressionAboveLimit(): void
    {
        $compressor = new ZstdCompressor();
        if (!$compressor->isAvailable()) {
            self::markTestSkipped('zstd extension not loaded');
        }
        $bytes = str_repeat('a', 100_000);
        $compressed = $compressor->compress($bytes);

        $this->expectException(\RuntimeException::class);
        $compressor->decompress($compressed, 1000);
    }

    public function testNamesMatchCompressionAlgo(): void
    {
        self::assertSame('none', new NullCompressor()->name());
        self::assertSame('gzip', new GzipCompressor()->name());
        self::assertSame('zstd', new ZstdCompressor()->name());
    }

    public function testIsAvailable(): void
    {
        self::assertTrue(new NullCompressor()->isAvailable());
        self::assertSame(\function_exists('gzdeflate'), new GzipCompressor()->isAvailable());
        self::assertSame(\extension_loaded('zstd'), new ZstdCompressor()->isAvailable());
    }
}
