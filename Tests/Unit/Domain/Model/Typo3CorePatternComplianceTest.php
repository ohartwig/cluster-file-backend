<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression: before the fix (TYPO3 core pattern adoption) our domain VOs
 * rejected legitimate identifiers and tags that TYPO3 core uses (`%`, `&`).
 * The pattern constants are now strictly aligned with
 * {@see \TYPO3\CMS\Core\Cache\Frontend\FrontendInterface::PATTERN_ENTRYIDENTIFIER}
 * and `::PATTERN_TAG`.
 *
 * If this test fails the backend would immediately throw on a TYPO3 cache
 * frontend that, for example, sets a tag containing `%`.
 */
#[CoversClass(CacheIdentifier::class)]
#[CoversClass(TagSet::class)]
final class Typo3CorePatternComplianceTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function validTypo3CoreIdentifiers(): iterable
    {
        // As seen / documented in TYPO3 core:
        yield 'simple alphanumeric' => ['pages_42_lang_0'];
        yield 'identifier with percent' => ['cf-%abc%-key'];
        yield 'identifier with ampersand' => ['key&detail'];
        yield 'identifier with dash' => ['a-b-c'];
        yield 'identifier with underscore' => ['my_cache_key'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function validTypo3CoreTags(): iterable
    {
        yield 'page-id tag style' => ['pageId_42'];
        yield 'tag with percent' => ['site_%a%'];
        yield 'tag with ampersand' => ['scope_a&b'];
        yield 'tag with dash' => ['en-US'];
        yield 'simple tag' => ['frontend'];
    }

    #[DataProvider('validTypo3CoreIdentifiers')]
    public function testCacheIdentifierAcceptsTypo3CorePatterns(string $value): void
    {
        $id = new CacheIdentifier($value);
        self::assertSame($value, $id->value);
    }

    #[DataProvider('validTypo3CoreTags')]
    public function testTagSetAcceptsTypo3CorePatterns(string $tag): void
    {
        $tags = new TagSet([$tag]);
        self::assertTrue($tags->contains($tag));
    }

    public function testTagSetWithMixedCorePatterns(): void
    {
        $tags = new TagSet(['pageId_42', 'site_%instance%', 'scope_a&b', 'en-US']);
        self::assertCount(4, $tags->toArray());
    }
}
