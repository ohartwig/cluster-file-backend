<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PayloadHash::class)]
final class PayloadHashTest extends TestCase
{
    public function testComputeIsDeterministic(): void
    {
        $hashes = [];
        for ($i = 0; $i < 100; ++$i) {
            $hashes[] = $this->buildSampleHash('same-bytes')->digest;
        }
        self::assertCount(1, array_unique($hashes), 'Hash must be deterministic across 100 iterations');
    }

    public function testDifferentBytesProduceDifferentHash(): void
    {
        self::assertNotSame(
            $this->buildSampleHash('a')->digest,
            $this->buildSampleHash('b')->digest,
        );
    }

    public function testDifferentSerializerVersionChangesHash(): void
    {
        $h1 = PayloadHash::compute(
            new SerializerName(SerializerName::IGBINARY, 'igbinary:3.2.16'),
            CompressionName::zstd(),
            new BackendVersion(1),
            '8.3',
            'bytes',
        );
        $h2 = PayloadHash::compute(
            new SerializerName(SerializerName::IGBINARY, 'igbinary:3.2.17'),
            CompressionName::zstd(),
            new BackendVersion(1),
            '8.3',
            'bytes',
        );
        self::assertNotSame($h1->digest, $h2->digest);
    }

    public function testDifferentCompressionChangesHash(): void
    {
        $h1 = PayloadHash::compute(
            SerializerName::phpNative(),
            CompressionName::zstd(),
            new BackendVersion(1),
            '8.3',
            'bytes',
        );
        $h2 = PayloadHash::compute(
            SerializerName::phpNative(),
            CompressionName::gzip(),
            new BackendVersion(1),
            '8.3',
            'bytes',
        );
        self::assertNotSame($h1->digest, $h2->digest);
    }

    public function testDifferentBackendVersionChangesHash(): void
    {
        $h1 = PayloadHash::compute(
            SerializerName::phpNative(),
            CompressionName::none(),
            new BackendVersion(1),
            '8.3',
            'bytes',
        );
        $h2 = PayloadHash::compute(
            SerializerName::phpNative(),
            CompressionName::none(),
            new BackendVersion(2),
            '8.3',
            'bytes',
        );
        self::assertNotSame($h1->digest, $h2->digest);
    }

    public function testDifferentPhpVersionChangesHash(): void
    {
        $h1 = PayloadHash::compute(
            SerializerName::phpNative(),
            CompressionName::none(),
            new BackendVersion(1),
            '8.3',
            'bytes',
        );
        $h2 = PayloadHash::compute(
            SerializerName::phpNative(),
            CompressionName::none(),
            new BackendVersion(1),
            '8.4',
            'bytes',
        );
        self::assertNotSame($h1->digest, $h2->digest);
    }

    public function testInvalidDigestIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PayloadHash('not-a-valid-sha256');
    }

    public function testEqualsByDigest(): void
    {
        $a = $this->buildSampleHash('x');
        $b = $this->buildSampleHash('x');
        self::assertTrue($a->equals($b));
    }

    public function testPrefixReturnsCorrectShard(): void
    {
        $hash = $this->buildSampleHash('shard-test');
        self::assertSame(substr($hash->digest, 0, 2), $hash->prefix(2));
    }

    private function buildSampleHash(string $bytes): PayloadHash
    {
        return PayloadHash::compute(
            SerializerName::phpNative(),
            CompressionName::zstd(),
            new BackendVersion(1),
            '8.3',
            $bytes,
        );
    }
}
