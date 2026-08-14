<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Support;

use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;

final class FakeClock implements ClockPort
{
    public function __construct(
        private int $time = 1_700_000_000,
    ) {}

    public function now(): int
    {
        return $this->time;
    }

    public function setNow(int $time): void
    {
        $this->time = $time;
    }

    public function advance(int $seconds): void
    {
        $this->time += $seconds;
    }
}
