<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\WarmUp;

use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend;
use Moselwal\Typo3ClusterCache\Infrastructure\WarmUp\BackendWarmUpRunner;
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
 * Assembles a warm-up out of a live cache: it takes the metadata frontend and
 * the local path from the backend that is actually configured, rather than from
 * anything the caller passes in. That is the point — a warm-up run against a
 * path the node does not really use would report a healthy cache while the
 * cache stays cold.
 *
 * The refusal in front of it matters just as much. A cache backed by something
 * other than this extension cannot be warmed up here, and saying so loudly beats
 * returning a report that reads as success.
 *
 * Identifiers arrive from a CLI flag, so they are untrusted: one that TYPO3
 * would reject as an entry identifier is skipped rather than allowed to abort
 * the whole run.
 */
#[CoversClass(BackendWarmUpRunner::class)]
final class BackendWarmUpRunnerTest extends TestCase
{
    private string $localPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->localPath = sys_get_temp_dir() . '/cfb-runner-' . bin2hex(random_bytes(6));
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

    private function runner(mixed $backend): BackendWarmUpRunner
    {
        $frontend = $this->createMock(FrontendInterface::class);
        $frontend->method('getBackend')->willReturn($backend);

        return new BackendWarmUpRunner(
            cacheManager: $this->cacheManagerReturning($frontend),
            clock: new FakeClock(),
            metrics: new FakeMetrics(),
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
        self::assertTrue($report->metadataCacheHealthy);
        self::assertTrue($report->localStoreWritable);
    }

    /**
     * The local store is taken from the configured backend, and the run creates
     * it. A report claiming a writable store for a path the node never uses is
     * exactly the false green this exists to prevent.
     */
    #[Test]
    public function theLocalStoreCheckedIsTheOneTheBackendActuallyUses(): void
    {
        $backend = $this->clusterBackend();

        $report = $this->runner($backend)->run($this->namespace());

        self::assertSame($this->localPath, $backend->getLocalPath());
        self::assertTrue($report->localStoreWritable);
        self::assertDirectoryExists($this->localPath);
    }

    /**
     * The regression worth guarding: a cache backed by something else cannot be
     * warmed up here. Returning a report would read as success.
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

    #[Test]
    public function identifiersAreProbedAndCounted(): void
    {
        $report = $this->runner($this->clusterBackend())->run($this->namespace(), ['entry-a', 'entry-b']);

        self::assertSame(2, $report->prefetchedIdentifiers);
    }

    /**
     * Identifiers come from a CLI flag. One that TYPO3 would reject as an entry
     * identifier is dropped rather than allowed to abort a run that was going
     * to warm up everything else fine.
     */
    #[Test]
    public function anUnusableIdentifierIsSkippedRatherThanFatal(): void
    {
        $report = $this->runner($this->clusterBackend())->run(
            $this->namespace(),
            ['entry-a', 'no spaces allowed', 'entry-b'],
        );

        self::assertSame(2, $report->prefetchedIdentifiers);
        self::assertTrue($report->localStoreWritable);
    }

    #[Test]
    public function aRunWithoutIdentifiersStillReportsHealth(): void
    {
        $report = $this->runner($this->clusterBackend())->run($this->namespace());

        self::assertSame(0, $report->prefetchedIdentifiers);
        self::assertTrue($report->succeeded());
    }
}
