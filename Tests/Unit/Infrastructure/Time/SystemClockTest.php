<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\Time;

use Moselwal\Typo3ClusterCache\Infrastructure\Time\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemClock::class)]
final class SystemClockTest extends TestCase
{
    public function testNowReturnsCurrentTime(): void
    {
        $clock = new SystemClock();
        $now = $clock->now();
        self::assertGreaterThan(1_700_000_000, $now);
        self::assertLessThan(time() + 2, $now);
    }
}
