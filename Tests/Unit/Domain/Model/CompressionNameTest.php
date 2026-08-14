<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Model\CompressionAlgo;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompressionName::class)]
#[CoversClass(CompressionAlgo::class)]
final class CompressionNameTest extends TestCase
{
    public function testZstdFactory(): void
    {
        self::assertSame(CompressionAlgo::Zstd, CompressionName::zstd()->name);
    }

    public function testGzipFactory(): void
    {
        self::assertSame(CompressionAlgo::Gzip, CompressionName::gzip()->name);
    }

    public function testNoneFactory(): void
    {
        self::assertSame(CompressionAlgo::None, CompressionName::none()->name);
    }

    public function testFromStringValid(): void
    {
        self::assertSame(CompressionAlgo::Zstd, CompressionName::fromString('zstd')->name);
        self::assertSame(CompressionAlgo::Gzip, CompressionName::fromString('gzip')->name);
        self::assertSame(CompressionAlgo::None, CompressionName::fromString('none')->name);
    }

    public function testFromStringInvalidThrows(): void
    {
        $this->expectException(\ValueError::class);
        CompressionName::fromString('lz4');
    }
}
