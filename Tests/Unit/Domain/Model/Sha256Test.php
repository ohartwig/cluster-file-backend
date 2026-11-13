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
use Moselwal\Typo3ClusterCache\Domain\Model\Sha256;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sha256::class)]
#[CoversClass(PayloadHash::class)]
final class Sha256Test extends TestCase
{
    protected function tearDown(): void
    {
        Sha256::forceOpenSsl(null);
    }

    /** @return iterable<string, array{string}> */
    public static function inputs(): iterable
    {
        yield 'empty' => [''];
        yield 'one byte' => ["\0"];
        yield 'block boundary' => [str_repeat('a', 64)];
        yield 'binary' => [random_bytes(4099)];
        yield 'large' => [str_repeat("<?php return ['k' => 'v'];\n", 50_000)];
    }

    #[DataProvider('inputs')]
    public function testBothPathsGiveTheDigestOfHash(string $bytes): void
    {
        $expected = hash('sha256', $bytes);

        Sha256::forceOpenSsl(false);
        self::assertFalse(Sha256::usesOpenSsl());
        self::assertSame($expected, Sha256::hex($bytes), 'ext-hash fallback');

        Sha256::forceOpenSsl(true);
        self::assertSame($expected, Sha256::hex($bytes), 'OpenSSL where available, else the fallback');
    }

    #[DataProvider('inputs')]
    public function testHashAndChecksumAreTheSameOnBothPaths(string $bytes): void
    {
        $args = [SerializerName::phpNative(), CompressionName::none(), new BackendVersion(3), '8.5', $bytes];

        Sha256::forceOpenSsl(false);
        [$hashFallback, $checksumFallback] = PayloadHash::computeWithChecksumOfBytes(...$args);
        Sha256::forceOpenSsl(true);
        [$hashOpenSsl, $checksumOpenSsl] = PayloadHash::computeWithChecksumOfBytes(...$args);

        self::assertSame($hashFallback->digest, $hashOpenSsl->digest, 'the identity does not depend on the path');
        self::assertSame($checksumFallback->digest, $checksumOpenSsl->digest);
        self::assertSame(hash('sha256', $bytes), $checksumOpenSsl->digest);
        self::assertSame(PayloadHash::compute(...$args)->digest, $hashOpenSsl->digest);
    }
}
