<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\Cache\Backend;

use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataFrontend;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use Moselwal\Typo3ClusterCache\Tests\Support\FlushFailingMetadataFrontend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * What the backend does when the metadata cache is unreachable.
 *
 * Every one of these paths is a catch block, and catch blocks are the part of
 * a cache that nobody exercises by hand: the happy path is what you notice
 * working. They also disagree with each other on purpose, and that disagreement
 * is the thing worth pinning.
 *
 * set() rethrows, because a write that did not happen must not be mistaken for
 * one that did. get() and remove() report a miss and a failure respectively,
 * because a cache that throws on read is worse than one that misses. The three
 * flushes swallow their exception entirely — a metadata outage must not break
 * an editor's save — and that is precisely why they need a test: a flush that
 * silently did nothing is indistinguishable from one that worked, right up
 * until the next read serves a stale entry.
 *
 * The message of the rethrown exception is asserted too. set() deliberately
 * does not pass the underlying message through, because TYPO3 renders it on
 * debug error pages; the detail belongs in the structured log instead.
 */
#[CoversClass(ClusterFileBackend::class)]
final class ClusterFileBackendFailurePathsTest extends TestCase
{
    private string $localPath;
    private FakeClock $clock;
    private FakeMetrics $metrics;
    private FakeMetadataFrontend $metadataFrontend;

    /** Holds the flush-failing double so a test can empty it mid-flight. */
    private FlushFailingMetadataFrontend $failingFrontendEntries;

    protected function setUp(): void
    {
        parent::setUp();

        $this->localPath = \sys_get_temp_dir() . '/cfb-failure-' . \bin2hex(\random_bytes(6));
        $this->clock = new FakeClock();
        $this->metrics = new FakeMetrics();
        $this->metadataFrontend = new FakeMetadataFrontend();

        /* @phpstan-ignore staticMethod.internal (the documented way to place a double) */
        GeneralUtility::setSingletonInstance(CacheManager::class, $this->cacheManagerReturning($this->metadataFrontend));
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();

        if (\is_dir($this->localPath)) {
            $this->removeDirectory($this->localPath);
        }

        parent::tearDown();
    }

    private function removeDirectory(string $path): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
        }
        @\rmdir($path);
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

    private function backend(): ClusterFileBackend
    {
        GeneralUtility::addInstance(MetricsPort::class, $this->metrics);
        GeneralUtility::addInstance(ClockPort::class, $this->clock);

        return new ClusterFileBackend([
            'localPath' => $this->localPath,
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'prod', 'instance' => 'site'],
        ]);
    }

    #[Test]
    public function setRethrowsAsABackendExceptionWhenTheMetadataCacheIsOffline(): void
    {
        $backend = $this->backend();
        $this->metadataFrontend->offline = true;

        try {
            $backend->set('entry-a', 'payload');
            self::fail('a write that could not be recorded must not return quietly');
        } catch (Exception $e) {
            self::assertSame(1747500022, $e->getCode());
            self::assertStringNotContainsString(
                'metadata cache offline',
                $e->getMessage(),
                'the underlying message must stay in the log, not reach a debug error page',
            );
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function getReportsAMissWhenTheMetadataCacheIsOffline(): void
    {
        $backend = $this->backend();
        $backend->set('entry-a', 'payload');

        // A second instance: the writing instance would answer from its own
        // payload L1 and never reach the metadata cache at all.
        $reader = $this->backend();
        $this->metadataFrontend->offline = true;

        self::assertFalse($reader->get('entry-a'));
    }

    #[Test]
    public function hasReportsAbsenceWhenTheMetadataCacheIsOffline(): void
    {
        $backend = $this->backend();
        $backend->set('entry-a', 'payload');

        $reader = $this->backend();
        $this->metadataFrontend->offline = true;

        self::assertFalse($reader->has('entry-a'));
    }

    #[Test]
    public function removeReportsFailureWhenTheMetadataCacheIsOffline(): void
    {
        $backend = $this->backend();
        $backend->set('entry-a', 'payload');
        $this->metadataFrontend->offline = true;

        self::assertFalse(
            $backend->remove('entry-a'),
            'a removal that did not reach the metadata cache must report false',
        );
    }

    /**
     * The flushes are the deliberate asymmetry, and they owe the caller three
     * things at once: do not propagate, record that it happened, and still drop
     * the in-process caches.
     *
     * The third is the one that needs care to observe. A failed metadata flush
     * leaves the entry in place, so get() answering with the payload proves
     * nothing — it would answer either from a stale L1 or from the metadata
     * that never got flushed. Emptying the metadata frontend afterwards
     * separates them: from that point only a surviving L1 could still produce
     * the old payload.
     */
    #[Test]
    public function flushKeepsQuietButRecordsTheFailureAndStillClearsL1(): void
    {
        $backend = $this->backendWithFailingFlushes();
        $backend->set('entry-a', 'payload');
        self::assertSame('payload', $backend->get('entry-a'));

        $backend->flush();

        self::assertSame(1, $this->metrics->counterTotal('cache_flush_error_total'));
        self::assertSame('flush', $this->lastFlushErrorOp());

        $this->failingFrontendEntries->entries = [];
        self::assertFalse(
            $backend->get('entry-a'),
            'a swallowed flush error must still clear the in-process payload cache',
        );
    }

    #[Test]
    public function flushByTagKeepsQuietButRecordsTheFailureAndStillClearsL1(): void
    {
        $backend = $this->backendWithFailingFlushes();
        $backend->set('entry-a', 'payload', ['tag-x']);
        self::assertSame('payload', $backend->get('entry-a'));

        $backend->flushByTag('tag-x');

        self::assertSame(1, $this->metrics->counterTotal('cache_flush_error_total'));
        self::assertSame('flushByTag', $this->lastFlushErrorOp());

        $this->failingFrontendEntries->entries = [];
        self::assertFalse($backend->get('entry-a'));
    }

    #[Test]
    public function flushByTagsKeepsQuietButRecordsTheFailureAndStillClearsL1(): void
    {
        $backend = $this->backendWithFailingFlushes();
        $backend->set('entry-a', 'payload', ['tag-x']);
        self::assertSame('payload', $backend->get('entry-a'));

        $backend->flushByTags(['tag-x', 'tag-y']);

        self::assertSame(1, $this->metrics->counterTotal('cache_flush_error_total'));
        self::assertSame('flushByTags', $this->lastFlushErrorOp());

        $this->failingFrontendEntries->entries = [];
        self::assertFalse($backend->get('entry-a'));
    }

    /**
     * The `op` label is what tells an operator which of the three flushes
     * broke; without it the metric says only that flushing is unhappy.
     */
    private function lastFlushErrorOp(): ?string
    {
        foreach (\array_reverse($this->metrics->counters) as $entry) {
            if ('cache_flush_error_total' === $entry['name']) {
                return $entry['labels']['op'] ?? null;
            }
        }

        return null;
    }

    /**
     * FakeMetadataFrontend's `offline` switch does not cover the flush methods,
     * because the flushes are the one group that must keep working from the
     * caller's point of view — and it is final, so this is a sibling double
     * rather than a subclass. Stores like the fake, throws on all three
     * flushes.
     */
    private function backendWithFailingFlushes(): ClusterFileBackend
    {
        $frontend = new FlushFailingMetadataFrontend();

        $this->failingFrontendEntries = $frontend;

        /* @phpstan-ignore staticMethod.internal (the documented way to place a double) */
        GeneralUtility::setSingletonInstance(CacheManager::class, $this->cacheManagerReturning($frontend));

        return $this->backend();
    }
}
