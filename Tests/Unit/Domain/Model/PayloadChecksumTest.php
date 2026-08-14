<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PayloadChecksum::class)]
final class PayloadChecksumTest extends TestCase
{
    public function testOfBytesIsDeterministic(): void
    {
        self::assertTrue(
            PayloadChecksum::ofBytes('a')->equals(PayloadChecksum::ofBytes('a')),
        );
    }

    public function testDifferentBytesYieldDifferentChecksum(): void
    {
        self::assertFalse(
            PayloadChecksum::ofBytes('a')->equals(PayloadChecksum::ofBytes('b')),
        );
    }

    public function testInvalidDigestRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PayloadChecksum('not-hex');
    }
}
