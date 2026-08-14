<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Application\Hash;

use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComputePayloadHash::class)]
final class ComputePayloadHashTest extends TestCase
{
    public function testIdenticalInputProducesIdenticalHash(): void
    {
        $hasher = new ComputePayloadHash();
        $hash1 = $hasher->fromRawBytes(
            'payload',
            SerializerName::phpNative(),
            CompressionName::zstd(),
            new BackendVersion(1),
        );
        $hash2 = $hasher->fromRawBytes(
            'payload',
            SerializerName::phpNative(),
            CompressionName::zstd(),
            new BackendVersion(1),
        );
        self::assertTrue($hash1->equals($hash2));
    }

    public function testDifferentPayloadProducesDifferentHash(): void
    {
        $hasher = new ComputePayloadHash();
        $hash1 = $hasher->fromRawBytes('a', SerializerName::phpNative(), CompressionName::zstd(), new BackendVersion(1));
        $hash2 = $hasher->fromRawBytes('b', SerializerName::phpNative(), CompressionName::zstd(), new BackendVersion(1));
        self::assertFalse($hash1->equals($hash2));
    }
}
