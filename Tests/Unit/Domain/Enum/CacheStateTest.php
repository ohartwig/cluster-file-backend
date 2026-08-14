<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Enum;

use Moselwal\Typo3ClusterCache\Domain\Enum\CacheState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheState::class)]
final class CacheStateTest extends TestCase
{
    public function testValidStateIsValid(): void
    {
        self::assertTrue(CacheState::Valid->isValid());
    }

    public function testBrokenStateIsNotValid(): void
    {
        self::assertFalse(CacheState::Broken->isValid());
    }

    public function testEnumValuesAreStable(): void
    {
        self::assertSame('valid', CacheState::Valid->value);
        self::assertSame('broken', CacheState::Broken->value);
    }
}
