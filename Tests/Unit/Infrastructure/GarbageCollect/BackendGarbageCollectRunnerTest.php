<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\GarbageCollect;

use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend;
use Moselwal\Typo3ClusterCache\Infrastructure\GarbageCollect\BackendGarbageCollectRunner;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataFrontend;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Assembles a garbage-collection run out of a live cache. Like the warm-up
 * runner, it takes the metadata cache from the backend that is actually
 * configured rather than from anything the caller passes in — collecting
 * against the wrong metadata cache would delete entries that are still
 * referenced, and report a clean run while doing it.
 *
 * The refusal in front of it is the same and matters for the same reason: a
 * cache backed by something other than this extension has no cluster metadata
 * to collect, and saying so beats returning a report that reads as success.
 *
 * Dry run exists so an operator can see what a collection would do on a
 * production cache before letting it. That flag has to survive the whole way
 * down, and it has to be visible in the report — a dry run reported as a real
 * one is a change nobody made.
 */
#[CoversClass(BackendGarbageCollectRunner::class)]
final class BackendGarbageCollectRunnerTest extends TestCase
{
    private string $localPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->localPath = sys_get_temp_dir() . '/cfb-gc-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();

        if (is_dir($this->localPath)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->localPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->localPath);
        }

        parent::tearDown();
    }

    private function cacheManagerReturning(FrontendInterface $frontend): CacheManager
    {
        return new class ($frontend) extends CacheManager {
            public function __construct(private FrontendInterface $frontend)
            {
                parent::__construct();
            }

            public function getCache(string $identifier): FrontendInterface
            {
                return $this->frontend;
            }
        };
    }

    private function clusterBackend(): ClusterFileBackend
    {
        /* @phpstan-ignore staticMethod.internal (the documented way to place a double) */
        GeneralUtility::setSingletonInstance(
            CacheManager::class,
            $this->cacheManagerReturning(new FakeMetadataFrontend()),
        );
        // Order matters: the constructor resolves metrics before the clock.
        GeneralUtility::addInstance(MetricsPort::class, new FakeMetrics());
        GeneralUtility::addInstance(ClockPort::class, new FakeClock());

        return new ClusterFileBackend([
            'localPath' => $this->localPath,
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'prod', 'instance' => 'site'],
        ]);
    }

    private function runner(mixed $backend): BackendGarbageCollectRunner
    {
        $frontend = $this->createMock(FrontendInterface::class);
        $frontend->method('getBackend')->willReturn($backend);

        return new BackendGarbageCollectRunner(
            cacheManager: $this->cacheManagerReturning($frontend),
            clock: new FakeClock(),
        );
    }

    private function namespace(): CacheNamespace
    {
        return new CacheNamespace(EnvironmentName::Production, 'site', 'pages');
    }

    #[Test]
    public function aClusterCacheProducesAReportForTheRequestedNamespace(): void
    {
        $report = $this->runner($this->clusterBackend())->run($this->namespace());

        self::assertSame('cfb:prod:site:pages', $report->namespace);
        self::assertFalse($report->dryRun);
    }

    /**
     * The regression worth guarding: a cache backed by something else has no
     * cluster metadata to collect. Returning a report would read as success.
     */
    #[Test]
    public function aCacheWithADifferentBackendIsRefusedLoudly(): void
    {
        $runner = $this->runner(new NullBackend());

        // Nicht expectExceptionMessage(): PHPUnit hat es mit 13 als veraltet
        // markiert (sebastianbergmann/phpunit#6560), und der geteilte
        // PHPStan-Standard meldet den Aufruf. Das try/catch-Muster ist
        // ausserdem das, was dieses Repository ohnehin verwendet
        // (WriteOrderTest, EmptyDirPayloadStoreTest).
        //
        // Die Nachricht wird mitgeprueft und nicht nur der Typ: RuntimeException
        // wirft an dieser Stelle auch alles Unerwartete, und ein Test, der jede
        // RuntimeException akzeptiert, bestuende auch dann noch, wenn die
        // Pruefung, um die es geht, ersatzlos entfiele.
        try {
            $runner->run($this->namespace());
            self::fail('a backend other than ClusterFileBackend should have been refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('is not configured with ClusterFileBackend', $e->getMessage());
        }
    }

    /**
     * A dry run has to say so. Reported as a real collection, it becomes a
     * change nobody made — and the operator who asked for a preview believes
     * the entries are gone.
     */
    #[Test]
    public function aDryRunSaysSoInItsReport(): void
    {
        $report = $this->runner($this->clusterBackend())->run($this->namespace(), dryRun: true);

        self::assertTrue($report->dryRun);
        self::assertTrue($report->toArray()['dryRun']);
    }

    #[Test]
    public function theReportSerialisesForTheCommandLine(): void
    {
        $report = $this->runner($this->clusterBackend())->run($this->namespace());

        self::assertSame(
            ['namespace', 'dryRun', 'durationMs'],
            array_keys($report->toArray()),
        );
        self::assertSame('cfb:prod:site:pages', $report->toArray()['namespace']);
        self::assertGreaterThanOrEqual(0, $report->toArray()['durationMs']);
    }
}
