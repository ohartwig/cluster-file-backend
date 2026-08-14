<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Presentation\Command;

use Moselwal\Typo3ClusterCache\Application\WarmUp\WarmUpReport;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Infrastructure\WarmUp\BackendWarmUpRunner;
use Moselwal\Typo3ClusterCache\Presentation\Command\WarmUpCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The pre-flight check a deployment runs before it sends traffic to a new node.
 * Its exit code is the whole point, and the three values are deliberately
 * distinct: 0 warm, 1 degraded, 64 the operator got the invocation wrong.
 *
 * Collapsing the last two is the mistake worth guarding against. A typo in a
 * namespace and a node whose local store is read-only both stop the deploy, but
 * only one of them means the cluster is unhealthy — and a rollout that retries
 * on "degraded" would loop forever on a typo.
 *
 * The other property is that one bad namespace does not hide the rest: the run
 * continues, every report is printed, and the exit code reflects all of them.
 */
#[CoversClass(WarmUpCommand::class)]
final class WarmUpCommandTest extends TestCase
{
    private const OK = 0;
    private const FAILED = 1;
    private const ARG_ERROR = 64;

    /** @var list<array{namespace: string, identifiers: list<string>}> */
    private array $runs = [];

    /**
     * @param array<string, bool> $healthByCacheName cache name → run succeeded
     * @param list<string>        $throwFor          cache names whose run blows up
     */
    private function tester(array $healthByCacheName = [], array $throwFor = []): CommandTester
    {
        $runner = $this->createMock(BackendWarmUpRunner::class);
        $runner->method('run')->willReturnCallback(
            function (CacheNamespace $namespace, array $identifiers = []) use ($healthByCacheName, $throwFor): WarmUpReport {
                $this->runs[] = [
                    'namespace' => $namespace->toKvKeyPrefix(),
                    'identifiers' => array_values($identifiers),
                ];

                if (\in_array($namespace->cacheName, $throwFor, true)) {
                    throw new \RuntimeException('cache is not a cluster cache');
                }

                $healthy = $healthByCacheName[$namespace->cacheName] ?? true;

                return new WarmUpReport(
                    namespace: $namespace->toKvKeyPrefix(),
                    metadataCacheHealthy: $healthy,
                    localStoreWritable: $healthy,
                    prefetchedIdentifiers: \count($identifiers),
                    localHits: 0,
                    blobMisses: 0,
                    durationMs: 3,
                );
            },
        );

        return new CommandTester(new WarmUpCommand($runner));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reports(CommandTester $tester): array
    {
        $reports = [];
        foreach (explode("\n", trim($tester->getDisplay())) as $line) {
            if (str_starts_with(trim($line), '{')) {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
                $reports[] = $decoded;
            }
        }

        return $reports;
    }

    #[Test]
    public function aWarmNamespaceExitsZeroAndPrintsItsReport(): void
    {
        $tester = $this->tester();
        $tester->execute(['--namespace' => ['cfb:prod:site:pages']]);

        self::assertSame(self::OK, $tester->getStatusCode());

        $reports = $this->reports($tester);
        self::assertCount(1, $reports);
        self::assertSame('cfb:prod:site:pages', $reports[0]['namespace']);
        self::assertTrue($reports[0]['succeeded']);
    }

    #[Test]
    public function aDegradedNamespaceExitsOne(): void
    {
        $tester = $this->tester(healthByCacheName: ['pages' => false]);
        $tester->execute(['--namespace' => ['cfb:prod:site:pages']]);

        self::assertSame(self::FAILED, $tester->getStatusCode());
        self::assertFalse($this->reports($tester)[0]['succeeded']);
    }

    /**
     * The regression worth guarding: a malformed namespace is an operator
     * error, not a cluster failure. A rollout that retries on "degraded" would
     * otherwise loop forever on a typo.
     */
    #[Test]
    public function aMalformedNamespaceIsAnArgumentErrorNotAFailure(): void
    {
        foreach (['not-a-namespace', 'cfb:prod:site', 'cfb:production:site:pages', 'cfb:prod:Site:pages'] as $bad) {
            $this->runs = [];
            $tester = $this->tester();
            $tester->execute(['--namespace' => [$bad]]);

            self::assertSame(self::ARG_ERROR, $tester->getStatusCode(), $bad);
            self::assertStringContainsString('Invalid --namespace', $tester->getDisplay());
            self::assertSame([], $this->runs, 'nothing is warmed up for an unparseable namespace');
        }
    }

    #[Test]
    public function aMissingNamespaceIsAnArgumentError(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(self::ARG_ERROR, $tester->getStatusCode());
        self::assertStringContainsString('At least one --namespace is required', $tester->getDisplay());
    }

    #[Test]
    public function everyNamespaceGivenIsWarmedUp(): void
    {
        $tester = $this->tester();
        $tester->execute(['--namespace' => ['cfb:prod:site:pages', 'cfb:prod:site:rootline']]);

        self::assertSame(self::OK, $tester->getStatusCode());
        self::assertCount(2, $this->runs);
        self::assertCount(2, $this->reports($tester));
    }

    /**
     * One degraded namespace must not hide the others. Every report is still
     * printed — an operator needs to see the whole picture, not the first
     * problem.
     */
    #[Test]
    public function oneDegradedNamespaceDoesNotStopTheRest(): void
    {
        $tester = $this->tester(healthByCacheName: ['pages' => false]);
        $tester->execute(['--namespace' => ['cfb:prod:site:pages', 'cfb:prod:site:rootline']]);

        self::assertSame(self::FAILED, $tester->getStatusCode());

        $reports = $this->reports($tester);
        self::assertCount(2, $reports);
        self::assertFalse($reports[0]['succeeded']);
        self::assertTrue($reports[1]['succeeded']);
    }

    /**
     * A cache that is not backed by this extension at all makes the runner
     * throw. That is reported and fails the run rather than escaping as a
     * stack trace into the deployment log.
     */
    #[Test]
    public function aRunnerFailureIsReportedAndFailsTheRun(): void
    {
        $tester = $this->tester(throwFor: ['pages']);
        $tester->execute(['--namespace' => ['cfb:prod:site:pages', 'cfb:prod:site:rootline']]);

        self::assertSame(self::FAILED, $tester->getStatusCode());
        self::assertStringContainsString('Warm-up failed for cfb:prod:site:pages', $tester->getDisplay());
        self::assertStringContainsString('cache is not a cluster cache', $tester->getDisplay());
        self::assertCount(1, $this->reports($tester), 'the second namespace is still warmed up');
    }

    #[Test]
    public function identifiersAreForwardedAsAProbeList(): void
    {
        $tester = $this->tester();
        $tester->execute([
            '--namespace' => ['cfb:prod:site:pages'],
            '--identifiers' => 'entry-a, entry-b ,entry-c',
        ]);

        self::assertSame(['entry-a', 'entry-b', 'entry-c'], $this->runs[0]['identifiers']);
        self::assertSame(3, $this->reports($tester)[0]['prefetchedIdentifiers']);
    }

    /**
     * A trailing comma, or an empty --identifiers, produces no phantom entry.
     * An empty identifier would be probed and always miss.
     */
    #[Test]
    public function emptyIdentifierEntriesAreDropped(): void
    {
        $tester = $this->tester();
        $tester->execute(['--namespace' => ['cfb:prod:site:pages'], '--identifiers' => 'entry-a,,  ,']);

        self::assertSame(['entry-a'], $this->runs[0]['identifiers']);

        $this->runs = [];
        $tester = $this->tester();
        $tester->execute(['--namespace' => ['cfb:prod:site:pages'], '--identifiers' => '']);

        self::assertCount(1, $this->runs);
        self::assertSame([], $this->runs[0]['identifiers']);
    }
}
