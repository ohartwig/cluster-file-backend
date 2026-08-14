<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Enum;

use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnvironmentName::class)]
final class EnvironmentNameTest extends TestCase
{
    public function testAllFourEnvironmentsAreDefined(): void
    {
        self::assertSame('prod', EnvironmentName::Production->value);
        self::assertSame('staging', EnvironmentName::Staging->value);
        self::assertSame('testing', EnvironmentName::Testing->value);
        self::assertSame('development', EnvironmentName::Development->value);
    }

    public function testEnvironmentCount(): void
    {
        self::assertCount(4, EnvironmentName::cases());
    }
}
