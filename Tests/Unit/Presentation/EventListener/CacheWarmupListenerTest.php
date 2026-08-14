<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Presentation\EventListener;

use Moselwal\Typo3ClusterCache\Application\WarmUp\WarmUpReport;
use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend;
use Moselwal\Typo3ClusterCache\Infrastructure\WarmUp\BackendWarmUpRunner;
use Moselwal\Typo3ClusterCache\Presentation\EventListener\CacheWarmupListener;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataFrontend;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Event\CacheWarmupEvent;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The hook that runs on `cache:warmup` during a deployment.
 *
 * TYPO3 fires this for the whole installation, and most caches in a typical
 * setup are not backed by this extension at all. So the listener's first job is
 * to walk past everything that is not its business — a cache configured with a
 * different backend, or one that is declared but not instantiated. Getting that
 * wrong turns a foreign cache configuration into a deploy-blocking error.
 *
 * Its second job is the opposite: a cluster cache that comes up degraded has to
 * be reported through the event, because that is the only channel the warm-up
 * command reads. Logging it and returning quietly would let a node go live with
 * an unreachable metadata cache.
 */
#[CoversClass(CacheWarmupListener::class)]
final class CacheWarmupListenerTest extends TestCase
{
    private string $localPath = '';

    private mixed $confVarsBackup = null;

    /** @var list<string> */
    private array $warmedUp = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->confVarsBackup = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $this->localPath = sys_get_temp_dir() . '/cfb-warmup-' . bin2hex(random_bytes(6));
        $this->warmedUp = [];
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();

        if (null === $this->confVarsBackup) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->confVarsBackup;
        }

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

    /**
     * @param list<string> $identifiers
     */
    private function configureCaches(array $identifiers): void
    {
        $configurations = [];
        foreach ($identifiers as $identifier) {
            $configurations[$identifier] = ['backend' => ClusterFileBackend::class];
        }
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] = $configurations;
    }

    /**
     * A real backend rather than a double: ClusterFileBackend is final, and the
     * listener reads its namespace getters to rebuild the namespace. Those
     * values come out of the constructed options, which is exactly the path
     * worth exercising.
     */
    private function clusterBackend(): ClusterFileBackend
    {
        // The constructor resolves its metadata frontend through the global
        // CacheManager, which is a different one from the instance the listener
        // is given: that one answers for the site's caches, this one only has to
        // hand back a metadata frontend.
        /* @phpstan-ignore staticMethod.internal (the documented way to place a double) */
        GeneralUtility::setSingletonInstance(
            CacheManager::class,
            $this->cacheManagerReturningAnything(new FakeMetadataFrontend()),
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

    private function cacheManagerReturningAnything(FrontendInterface $frontend): CacheManager
    {
        return new class ($frontend) extends CacheManager {
            public function __construct(private FrontendInterface $frontend)
            {
                parent::__construct();
            }

            public function hasCache(string $identifier): bool
            {
                return true;
            }

            public function getCache(string $identifier): FrontendInterface
            {
                return $this->frontend;
            }
        };
    }

    private function frontendWith(mixed $backend): FrontendInterface
    {
        $frontend = $this->createMock(FrontendInterface::class);
        $frontend->method('getBackend')->willReturn($backend);

        return $frontend;
    }

    /**
     * @param array<string, FrontendInterface|null> $caches identifier → frontend, null = not instantiated
     */
    private function cacheManager(array $caches): CacheManager
    {
        return new class ($caches) extends CacheManager {
            /**
             * Not named $caches: CacheManager already declares a protected
             * property under that name, and redeclaring it private is a fatal.
             *
             * @param array<string, FrontendInterface|null> $frontends
             */
            public function __construct(private array $frontends)
            {
                parent::__construct();
            }

            public function hasCache(string $identifier): bool
            {
                return null !== ($this->frontends[$identifier] ?? null);
            }

            public function getCache(string $identifier): FrontendInterface
            {
                $cache = $this->frontends[$identifier] ?? null;
                if (null === $cache) {
                    throw new \RuntimeException(sprintf('No cache "%s"', $identifier));
                }

                return $cache;
            }
        };
    }

    /**
     * @param array<string, FrontendInterface|null> $caches
     */
    private function listener(array $caches, bool $healthy = true, bool $throws = false): CacheWarmupListener
    {
        $runner = $this->createMock(BackendWarmUpRunner::class);
        $runner->method('run')->willReturnCallback(
            function (CacheNamespace $namespace, array $identifiers = []) use ($healthy, $throws): WarmUpReport {
                $this->warmedUp[] = $namespace->toKvKeyPrefix();

                if ($throws) {
                    throw new \RuntimeException('metadata cache unreachable');
                }

                return new WarmUpReport(
                    namespace: $namespace->toKvKeyPrefix(),
                    metadataCacheHealthy: $healthy,
                    localStoreWritable: true,
                    prefetchedIdentifiers: 0,
                    localHits: 0,
                    blobMisses: 0,
                    durationMs: 1,
                );
            },
        );

        return new CacheWarmupListener($this->cacheManager($caches), $runner, new NullLogger());
    }

    #[Test]
    public function aClusterCacheIsWarmedUpUnderItsFullNamespace(): void
    {
        $this->configureCaches(['pages']);
        $listener = $this->listener(['pages' => $this->frontendWith($this->clusterBackend())]);

        $event = new CacheWarmupEvent(['system']);
        $listener($event);

        self::assertSame(['cfb:prod:site:pages'], $this->warmedUp);
        self::assertSame([], $event->getErrors());
    }

    /**
     * The regression worth guarding: most caches in a TYPO3 installation belong
     * to somebody else. Touching them would turn a foreign configuration into a
     * failed deploy.
     */
    #[Test]
    public function aCacheWithADifferentBackendIsLeftAlone(): void
    {
        $this->configureCaches(['pages', 'l10n']);
        $listener = $this->listener([
            'pages' => $this->frontendWith($this->clusterBackend()),
            'l10n' => $this->frontendWith(new NullBackend()),
        ]);

        $event = new CacheWarmupEvent(['system']);
        $listener($event);

        self::assertSame(['cfb:prod:site:pages'], $this->warmedUp);
        self::assertSame([], $event->getErrors());
    }

    /**
     * A cache that is configured but never instantiated is skipped rather than
     * resolved — asking the CacheManager for it would throw.
     */
    #[Test]
    public function aConfiguredButAbsentCacheIsSkipped(): void
    {
        $this->configureCaches(['pages', 'never_built']);
        $listener = $this->listener([
            'pages' => $this->frontendWith($this->clusterBackend()),
            'never_built' => null,
        ]);

        $event = new CacheWarmupEvent(['system']);
        $listener($event);

        self::assertSame(['cfb:prod:site:pages'], $this->warmedUp);
        self::assertSame([], $event->getErrors());
    }

    /**
     * The other half: a degraded cluster cache has to reach the event. It is
     * the only channel the warm-up command reads, so logging alone would let a
     * node go live with an unreachable metadata cache.
     */
    #[Test]
    public function aDegradedWarmUpIsReportedThroughTheEvent(): void
    {
        $this->configureCaches(['pages']);
        $listener = $this->listener(['pages' => $this->frontendWith($this->clusterBackend())], healthy: false);

        $event = new CacheWarmupEvent(['system']);
        $listener($event);

        self::assertCount(1, $event->getErrors());
        self::assertStringContainsString('cfb:prod:site:pages', $event->getErrors()[0]);
        self::assertStringContainsString('metadataHealthy=no', $event->getErrors()[0]);
        self::assertStringContainsString('localWritable=yes', $event->getErrors()[0]);
    }

    /**
     * A throwing runner is reported, not propagated: an exception here aborts
     * the whole `cache:warmup`, including the caches that are perfectly fine.
     */
    #[Test]
    public function aThrowingRunUpIsReportedRatherThanPropagated(): void
    {
        $this->configureCaches(['pages']);
        $listener = $this->listener(['pages' => $this->frontendWith($this->clusterBackend())], throws: true);

        $event = new CacheWarmupEvent(['system']);
        $listener($event);

        self::assertCount(1, $event->getErrors());
        self::assertStringContainsString('Cluster warm-up failed for pages', $event->getErrors()[0]);
        self::assertStringContainsString('metadata cache unreachable', $event->getErrors()[0]);
    }

    /**
     * An installation with no cache configuration at all — the state of a
     * half-booted test or a broken LocalConfiguration — is a no-op, not a crash.
     */
    #[Test]
    public function anInstallationWithoutCacheConfigurationIsANoOp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] = 'not an array';
        $listener = $this->listener(['pages' => $this->frontendWith($this->clusterBackend())]);

        $event = new CacheWarmupEvent(['system']);
        $listener($event);

        self::assertSame([], $this->warmedUp);
        self::assertSame([], $event->getErrors());
    }

    /**
     * A cache identifier that cannot be part of a namespace — TYPO3 permits
     * characters the namespace pattern does not — is reported rather than
     * silently dropped.
     */
    #[Test]
    public function anUnusableCacheIdentifierIsReported(): void
    {
        $this->configureCaches(['pages.with.dots']);
        $listener = $this->listener(['pages.with.dots' => $this->frontendWith($this->clusterBackend())]);

        $event = new CacheWarmupEvent(['system']);
        $listener($event);

        self::assertSame([], $this->warmedUp);
        self::assertCount(1, $event->getErrors());
        self::assertStringContainsString('Could not build namespace', $event->getErrors()[0]);
    }

    /**
     * Every cluster cache is warmed up, not just the first one found.
     */
    #[Test]
    public function allClusterCachesAreWarmedUp(): void
    {
        $this->configureCaches(['pages', 'rootline']);
        $listener = $this->listener([
            'pages' => $this->frontendWith($this->clusterBackend()),
            'rootline' => $this->frontendWith($this->clusterBackend()),
        ]);

        $event = new CacheWarmupEvent(['system']);
        $listener($event);

        self::assertSame(['cfb:prod:site:pages', 'cfb:prod:site:rootline'], $this->warmedUp);
    }
}
