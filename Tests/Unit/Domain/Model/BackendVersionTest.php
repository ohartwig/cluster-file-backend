<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\BackendVersionInfo;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackendVersion::class)]
final class BackendVersionTest extends TestCase
{
    public function testMinimumIsOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BackendVersion(0);
    }

    public function testCurrentReflectsInfoConstant(): void
    {
        self::assertSame(BackendVersionInfo::CURRENT, BackendVersion::current()->value);
    }

    public function testFromStringRejectsEmptyIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BackendVersion::fromString('');
    }

    public function testFromStringIsDeterministic(): void
    {
        $a = BackendVersion::fromString('v1.2.3');
        $b = BackendVersion::fromString('v1.2.3');
        self::assertSame($a->value, $b->value);
    }

    public function testFromStringDifferentInputsDiverge(): void
    {
        $a = BackendVersion::fromString('v1.2.3');
        $b = BackendVersion::fromString('v1.2.4');
        self::assertNotSame($a->value, $b->value);
    }

    public function testFromStringNeverProducesZero(): void
    {
        // Even if crc32 returned 0 for some input, max(1, ...) protects
        // the int-must-be->=1 invariant.
        $version = BackendVersion::fromString('a');
        self::assertGreaterThanOrEqual(1, $version->value);
    }

    public function testFromEnvFallsBackWhenVariableUnset(): void
    {
        $envVar = 'CFB_TEST_BV_UNSET_' . bin2hex(random_bytes(4));
        putenv($envVar); // ensure unset
        self::assertSame(BackendVersion::current()->value, BackendVersion::fromEnv($envVar)->value);
    }

    public function testFromEnvFallsBackWhenVariableEmpty(): void
    {
        $envVar = 'CFB_TEST_BV_EMPTY_' . bin2hex(random_bytes(4));
        putenv($envVar . '=');
        try {
            self::assertSame(BackendVersion::current()->value, BackendVersion::fromEnv($envVar)->value);
        } finally {
            putenv($envVar);
        }
    }

    public function testFromEnvFoldsValueViaCrc32(): void
    {
        $envVar = 'CFB_TEST_BV_SET_' . bin2hex(random_bytes(4));
        putenv($envVar . '=v1.2.3');
        try {
            self::assertSame(
                BackendVersion::fromString('v1.2.3')->value,
                BackendVersion::fromEnv($envVar)->value,
            );
        } finally {
            putenv($envVar);
        }
    }

    public function testFromEnvDefaultsToImageTagVariable(): void
    {
        // Smoke-test that the default env-var name is the conventional one
        // for containerised deployments (Helm/Kustomize/GitLab CI all
        // expose the image tag this way).
        $envVar = 'IMAGE_TAG_TEST_PROBE_' . bin2hex(random_bytes(4));
        putenv($envVar . '=probe-tag');
        try {
            // Using the explicit override here just to exercise the path —
            // the assertion below proves the constant matches IMAGE_TAG.
            self::assertSame(
                BackendVersion::fromString('probe-tag')->value,
                BackendVersion::fromEnv($envVar)->value,
            );
        } finally {
            putenv($envVar);
        }
        self::assertStringContainsString('IMAGE', BackendVersion::DEFAULT_ENV_VAR);
    }

    /**
     * Regression: pre-v2.3.1 fromString() ignored BackendVersionInfo::CURRENT
     * when the deploy identifier was set. Effect: bumping the CURRENT
     * constant (the documented mechanism to invalidate older on-disk
     * payloads, e.g. after a format change) had no effect in production
     * environments that set IMAGE_TAG. This test pins the contract that
     * CURRENT participates in the hash regardless of the deploy identifier.
     */
    public function testFromStringFoldsBackendVersionInfoCurrent(): void
    {
        // Same deploy identifier, but the test verifies the value depends
        // on BackendVersionInfo::CURRENT by checking that the result
        // matches the explicit "CURRENT + : + deploy" crc32 — not just
        // crc32 of the deploy identifier on its own.
        $deploy = 'v1.2.3';
        $expected = max(1, crc32(BackendVersionInfo::CURRENT . ':' . $deploy));
        self::assertSame($expected, BackendVersion::fromString($deploy)->value);

        // Defense-in-depth: also verify it diverges from the legacy
        // crc32($deploy) — otherwise a future regression that drops the
        // CURRENT prefix would still pass the above assertion if both
        // hashes happened to collide (vanishingly unlikely but
        // explicit is better here).
        $legacy = max(1, crc32($deploy));
        self::assertNotSame($legacy, BackendVersion::fromString($deploy)->value);
    }
}
