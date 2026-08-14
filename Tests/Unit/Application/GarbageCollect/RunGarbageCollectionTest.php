<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Application\GarbageCollect;

use Moselwal\Typo3ClusterCache\Application\GarbageCollect\RunGarbageCollection;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunGarbageCollection::class)]
final class RunGarbageCollectionTest extends TestCase
{
    public function testExecuteDelegatesToMetadataCache(): void
    {
        $cache = new FakeMetadataCache();
        $runner = new RunGarbageCollection($cache, new FakeClock(1_700_000_000));
        $namespace = new CacheNamespace(EnvironmentName::Testing, 'test', 'pages');

        $report = $runner->execute($namespace);

        self::assertSame(1, $cache->gcCalls);
        self::assertSame('cfb:testing:test:pages', $report->namespace);
        self::assertFalse($report->dryRun);
    }

    public function testDryRunSkipsCollectGarbage(): void
    {
        $cache = new FakeMetadataCache();
        $runner = new RunGarbageCollection($cache, new FakeClock(1_700_000_000));
        $namespace = new CacheNamespace(EnvironmentName::Testing, 'test', 'pages');

        $report = $runner->execute($namespace, dryRun: true);

        self::assertSame(0, $cache->gcCalls);
        self::assertTrue($report->dryRun);
    }
}
