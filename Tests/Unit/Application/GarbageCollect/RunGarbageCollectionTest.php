<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Application\GarbageCollect;

use Moselwal\Typo3ClusterCache\Application\GarbageCollect\RunGarbageCollection;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Infrastructure\LocalStore\EmptyDirPayloadStore;
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

    /**
     * AUD-075-F01 / I-669: five versions of one page leave five files; after
     * the lifetime passed and the GC ran, only the file the current metadata
     * references (read recently) is left.
     */
    public function testSupersededPayloadFilesAreReclaimed(): void
    {
        $dir = sys_get_temp_dir() . '/cfb-gc-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o750, true);

        try {
            $store = new EmptyDirPayloadStore($dir . '/pages');
            $clock = new FakeClock(1_700_000_000);
            $hashes = [];
            for ($version = 1; $version <= 5; ++$version) {
                $bytes = "\x00<html>version {$version}</html>";
                $hash = new PayloadHash(hash('sha256', $bytes));
                $store->write($hash, $bytes);
                $hashes[] = $hash;
                // All written eight days ago.
                touch($store->pathFor($hash), $clock->now() - 8 * 86400);
            }
            self::assertCount(5, iterator_to_array($store->iterateAll()));

            // The current version is read (verified read refreshes its mtime).
            $current = $hashes[4];
            $store->readVerified($current, PayloadChecksum::ofBytes("\x00<html>version 5</html>"));

            $service = new RunGarbageCollection(new FakeMetadataCache(), $clock, $store);
            $report = $service->execute(new CacheNamespace(EnvironmentName::Testing, 'test', 'pages'));

            $left = array_map(static fn (PayloadHash $h): string => $h->digest, iterator_to_array($store->iterateAll(), false));
            self::assertSame([$current->digest], $left);
            self::assertSame(4, $report->localFilesRemoved);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function testDryRunKeepsLocalFiles(): void
    {
        $dir = sys_get_temp_dir() . '/cfb-gc-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o750, true);

        try {
            $store = new EmptyDirPayloadStore($dir);
            $hash = new PayloadHash(hash('sha256', 'x'));
            $store->write($hash, 'x');
            touch($store->pathFor($hash), 1_000_000);

            $report = (new RunGarbageCollection(new FakeMetadataCache(), new FakeClock(1_700_000_000), $store))
                ->execute(new CacheNamespace(EnvironmentName::Testing, 'test', 'pages'), dryRun: true);

            self::assertTrue($store->exists($hash));
            self::assertSame(0, $report->localFilesRemoved);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
