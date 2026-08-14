<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Presentation\Command;

use Moselwal\Typo3ClusterCache\Application\GarbageCollect\GarbageCollectionReport;
use Moselwal\Typo3ClusterCache\Application\GarbageCollect\RunGarbageCollection;
use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Infrastructure\GarbageCollect\BackendGarbageCollectRunner;
use Moselwal\Typo3ClusterCache\Presentation\Command\GarbageCollectCommand;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(GarbageCollectCommand::class)]
final class GarbageCollectCommandTest extends TestCase
{
    private FakeMetadataCache $cache;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->cache = new FakeMetadataCache();
        $runner = $this->makeRunner($this->cache, new FakeClock(1_700_000_000));
        $command = new GarbageCollectCommand($runner);
        $application = new Application();
        $application->addCommand($command);
        $this->tester = new CommandTester($application->find('clusterfilebackend:gc'));
    }

    /**
     * Builds a BackendGarbageCollectRunner test double that bypasses the
     * CacheManager lookup and routes straight to {@see RunGarbageCollection}
     * with the supplied metadata cache. Uses PHPUnit's `MockBuilder` so the
     * parent constructor can be skipped without breaking PHPStan's
     * `constructor.missingParentCall` rule via inheritance.
     */
    private function makeRunner(MetadataCachePort $cache, ClockPort $clock): BackendGarbageCollectRunner
    {
        $runner = $this->getMockBuilder(BackendGarbageCollectRunner::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['run'])
            ->getMock();
        $runner->method('run')->willReturnCallback(
            static fn(CacheNamespace $namespace, bool $dryRun = false): GarbageCollectionReport => new RunGarbageCollection($cache, $clock)->execute($namespace, $dryRun),
        );

        return $runner;
    }

    public function testRequiresNamespaceOption(): void
    {
        $exitCode = $this->tester->execute([]);
        self::assertSame(64, $exitCode);
        self::assertStringContainsString('--namespace is required', $this->tester->getDisplay());
    }

    public function testInvalidNamespacePatternRejected(): void
    {
        $exitCode = $this->tester->execute(['--namespace' => 'invalid:format']);
        self::assertSame(64, $exitCode);
        self::assertStringContainsString('Invalid --namespace', $this->tester->getDisplay());
    }

    public function testValidNamespaceTriggersGcAndEmitsJson(): void
    {
        $exitCode = $this->tester->execute([
            '--namespace' => 'cfb:prod:website-a:pages',
        ]);

        self::assertSame(0, $exitCode);
        self::assertSame(1, $this->cache->gcCalls);

        $output = $this->tester->getDisplay();
        $decoded = \json_decode(\trim($output), true);
        self::assertIsArray($decoded);
        self::assertSame('cfb:prod:website-a:pages', $decoded['namespace']);
        self::assertFalse($decoded['dryRun']);
    }

    public function testDryRunSkipsGcCall(): void
    {
        $exitCode = $this->tester->execute([
            '--namespace' => 'cfb:testing:test:pages',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertSame(0, $this->cache->gcCalls);
        self::assertStringContainsString('"dryRun":true', $this->tester->getDisplay());
    }

    public function testUnknownEnvironmentFailsWithExit64(): void
    {
        $exitCode = $this->tester->execute([
            '--namespace' => 'cfb:unknown-env:site:pages',
        ]);
        self::assertSame(64, $exitCode);
    }
}
