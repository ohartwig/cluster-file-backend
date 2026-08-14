<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadReference;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PayloadReference::class)]
final class PayloadReferenceTest extends TestCase
{
    public function testBuildProducesShardedPath(): void
    {
        $hash = new PayloadHash(str_repeat('a', 64));
        $ref = PayloadReference::build('/app/var/cache/cluster/pages', $hash);
        self::assertSame('/app/var/cache/cluster/pages/aa/' . $hash->digest, $ref->path);
        self::assertSame('/app/var/cache/cluster/pages/aa', $ref->directory());
    }

    public function testTrailingSlashIsNormalized(): void
    {
        $hash = new PayloadHash(str_repeat('f', 64));
        $ref = PayloadReference::build('/app/var/cache/cluster/pages/', $hash);
        self::assertSame('/app/var/cache/cluster/pages/ff/' . $hash->digest, $ref->path);
    }
}
