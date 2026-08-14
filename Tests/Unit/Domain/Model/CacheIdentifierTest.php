<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheIdentifier::class)]
final class CacheIdentifierTest extends TestCase
{
    public function testEqualsByValue(): void
    {
        self::assertTrue(new CacheIdentifier('a')->equals(new CacheIdentifier('a')));
        self::assertFalse(new CacheIdentifier('a')->equals(new CacheIdentifier('b')));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['has space'];
        yield 'slash' => ['has/slash'];
        yield 'too long' => [str_repeat('a', 251)];
    }

    #[DataProvider('invalidIdentifiers')]
    public function testInvalidIdentifiersAreRejected(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CacheIdentifier($value);
    }

    public function testIdentifierAtExactMaxLengthIsAccepted(): void
    {
        // TYPO3 PATTERN_ENTRYIDENTIFIER allows up to 250 characters.
        $value = str_repeat('a', 250);
        $id = new CacheIdentifier($value);
        self::assertSame($value, $id->value);
    }
}
