<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SerializerName::class)]
final class SerializerNameTest extends TestCase
{
    public function testPhpNativeFactory(): void
    {
        $name = SerializerName::phpNative();
        self::assertSame('php', $name->name);
        self::assertSame('php:native', $name->version);
    }

    public function testIgbinaryFactoryProducesPrefixedVersion(): void
    {
        $name = SerializerName::igbinary();
        self::assertSame('igbinary', $name->name);
        self::assertStringStartsWith('igbinary:', $name->version);
    }

    public function testIgbinaryVersionContainsOnlyMajorNotPatch(): void
    {
        if (!\extension_loaded('igbinary')) {
            self::markTestSkipped('igbinary extension not loaded');
        }
        $name = SerializerName::igbinary();
        // Regression: before the fix this was e.g. "igbinary:3.2.16" — every
        // patch bump of the module would have invalidated the entire cluster
        // cache. Now: "igbinary:3" (major version only).
        self::assertMatchesRegularExpression(
            '/^igbinary:(\d+|unknown)$/',
            $name->version,
            'igbinary version-tag must contain only the major version (or "unknown")',
        );
    }

    public function testUnknownNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SerializerName('msgpack', 'msgpack:1.0');
    }

    public function testEmptyVersionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SerializerName(SerializerName::PHP_NATIVE, '');
    }

    public function testDetectReturnsAvailableSerializer(): void
    {
        $detected = SerializerName::detect();
        self::assertContains($detected->name, [SerializerName::IGBINARY, SerializerName::PHP_NATIVE]);
    }
}
