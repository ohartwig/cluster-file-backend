<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\Cache\Backend;

use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataFrontend;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The backend itself had almost no direct coverage, which is how the
 * flushByTags regression stayed invisible: a flush that silently did nothing
 * looks exactly like a flush that worked, and the next read served the stale
 * entry.
 *
 * Two design decisions are pinned here because both hide failure by intent:
 * flushes swallow their errors (a metadata-cache outage must not break the
 * editor's save), and a read miss is indistinguishable from an absent entry.
 * Both are correct — and both mean the tests, not production symptoms, have to
 * catch a broken flush.
 *
 * The constructor resolves ClockPort and MetricsPort through
 * GeneralUtility::makeInstance and the metadata frontend through CacheManager,
 * so those are registered as singletons before the backend is built.
 */
#[CoversClass(ClusterFileBackend::class)]
final class ClusterFileBackendTest extends TestCase
{
    private string $localPath;
    private FakeClock $clock;
    private FakeMetrics $metrics;
    private FakeMetadataFrontend $metadataFrontend;

    protected function setUp(): void
    {
        parent::setUp();

        $this->localPath = \sys_get_temp_dir() . '/cfb-test-' . \bin2hex(\random_bytes(6));
        $this->clock = new FakeClock();
        $this->metrics = new FakeMetrics();
        $this->metadataFrontend = new FakeMetadataFrontend();

        // CacheManager is a TYPO3 singleton, the ports are not — those go in
        // through addInstance(), which is a one-shot queue and therefore has to
        // be refilled for every backend that gets built.
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function backend(array $overrides = []): ClusterFileBackend
    {
        // Order matters: the constructor resolves metrics before the clock.
        GeneralUtility::addInstance(MetricsPort::class, $this->metrics);
        GeneralUtility::addInstance(ClockPort::class, $this->clock);

        return new ClusterFileBackend(\array_merge([
            'localPath' => $this->localPath,
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'prod', 'instance' => 'site'],
        ], $overrides));
    }

    #[Test]
    public function anEntryComesBackExactlyAsItWasStored(): void
    {
        $backend = $this->backend();
        // Binary-ish payload: the store must not treat this as text.
        $payload = "line one\n\x00\xFFline two";

        $backend->set('entry-a', $payload);

        self::assertSame($payload, $backend->get('entry-a'));
    }

    #[Test]
    public function anUnknownEntryIsReportedAsAbsent(): void
    {
        $backend = $this->backend();

        self::assertFalse($backend->has('never-written'));
        self::assertFalse($backend->get('never-written'));
    }

    #[Test]
    public function aStoredEntryIsReportedAsPresent(): void
    {
        $backend = $this->backend();
        $backend->set('entry-a', 'payload');

        self::assertTrue($backend->has('entry-a'));
    }

    #[Test]
    public function removingAnEntryMakesItAbsent(): void
    {
        $backend = $this->backend();
        $backend->set('entry-a', 'payload');

        $backend->remove('entry-a');

        self::assertFalse($backend->has('entry-a'));
        self::assertFalse($backend->get('entry-a'));
    }

    #[Test]
    public function overwritingAnEntryYieldsTheNewValue(): void
    {
        $backend = $this->backend();
        $backend->set('entry-a', 'first');
        $backend->set('entry-a', 'second');

        self::assertSame('second', $backend->get('entry-a'));
    }

    /**
     * An entry past its lifetime must not be served. This is the mechanism that
     * limits the damage of a flush that did not happen, so it has to hold.
     */
    /**
     * An entry past its lifetime must not be served. This is the mechanism that
     * limits the damage of a flush that did not happen, so it has to hold.
     *
     * The read goes through a second backend on the same paths: the in-process
     * L1 of the writing instance would answer from memory, which is correct
     * within one request but says nothing about what the next request sees.
     */
    #[Test]
    public function anExpiredEntryIsNoLongerServedToALaterRequest(): void
    {
        $writer = $this->backend();
        $writer->set('entry-a', 'payload', [], 60);

        self::assertSame('payload', $writer->get('entry-a'));

        $this->clock->setNow($this->clock->now() + 61);

        self::assertFalse(
            $this->backend()->get('entry-a'),
            'an entry past its lifetime must be treated as absent',
        );
    }

    #[Test]
    public function flushingRemovesEverything(): void
    {
        $backend = $this->backend();
        $backend->set('entry-a', 'a');
        $backend->set('entry-b', 'b');

        $backend->flush();

        self::assertFalse($backend->has('entry-a'));
        self::assertFalse($backend->has('entry-b'));
    }

    /**
     * The regression that produced site-wide stale delivery lived on this path.
     * A flush that quietly does nothing is indistinguishable from one that
     * worked, so the assertion is that the entries really are gone afterwards.
     */
    #[Test]
    public function flushingByASingleTagDropsTheTaggedEntry(): void
    {
        $backend = $this->backend();
        $backend->set('entry-a', 'a', ['pageId_5']);

        $backend->flushByTag('pageId_5');

        self::assertFalse($backend->get('entry-a'), 'a flushed entry must not be served again');
    }

    #[Test]
    public function flushingByManyTagsDropsTheTaggedEntries(): void
    {
        $backend = $this->backend();
        $backend->set('entry-a', 'a', ['pageId_5']);
        $backend->set('entry-b', 'b', ['pageId_6']);

        $backend->flushByTags(['pageId_5', 'pageId_6']);

        self::assertFalse($backend->get('entry-a'));
        self::assertFalse($backend->get('entry-b'));
    }

    /**
     * The original failure was a tag count above the TagSet limit aborting the
     * whole flush. A batch far beyond that limit therefore has to come back
     * clean rather than leaving entries behind.
     */
    #[Test]
    public function aTagBatchWellBeyondTheOldLimitStillFlushes(): void
    {
        $backend = $this->backend();
        $tags = [];
        for ($i = 0; $i < 200; ++$i) {
            $tags[] = 'pageId_' . $i;
        }

        $backend->set('entry-a', 'a', ['pageId_7']);

        $backend->flushByTags($tags);

        self::assertFalse($backend->get('entry-a'), '200 tags must not abort the flush the way 64+ once did');
    }

    #[Test]
    public function flushingByTagsAcceptsAnEmptyList(): void
    {
        $backend = $this->backend();
        $backend->set('entry-a', 'a', ['pageId_5']);

        $backend->flushByTags([]);

        self::assertSame('a', $backend->get('entry-a'), 'flushing nothing must not drop unrelated entries');
    }

    /**
     * Flushes are called from synchronous backend workflows. A metadata-cache
     * outage must be logged and swallowed rather than breaking the editor's
     * save — that is deliberate, and it is also precisely why a broken flush
     * needs a test instead of waiting for a production symptom.
     */
    /**
     * Flushes are called from synchronous backend workflows, so a metadata-cache
     * outage must not surface as an exception — that would break the editor's
     * save at the moment they expect the new state to land.
     *
     * This is exactly why the flushByTags regression could hide: the failure is
     * swallowed by design, so nothing downstream complains when a flush does
     * not happen. The assertion is therefore the absence of a throw.
     */
    #[Test]
    public function aFailingMetadataCacheDoesNotBreakAFlush(): void
    {
        $backend = $this->backend();
        $backend->set('entry-a', 'a', ['pageId_5']);

        $this->metadataFrontend->offline = true;

        $this->expectNotToPerformAssertions();

        $backend->flushByTag('pageId_5');
        $backend->flushByTags(['pageId_5']);
    }

    #[Test]
    public function namespaceAccessorsReflectTheConfiguration(): void
    {
        $backend = $this->backend();

        self::assertSame('cluster_meta', $backend->getMetadataCacheIdentifier());
        self::assertSame($this->localPath, $backend->getLocalPath());
        self::assertSame('site', $backend->getNamespaceInstance());
        self::assertSame('prod', $backend->getNamespaceEnvironment()->value);
    }

    /**
     * Garbage collection clears out what has expired. Read back through a
     * fresh backend for the same reason as the expiry test — the writer's L1
     * would otherwise answer from memory.
     */
    #[Test]
    public function collectingGarbageDropsExpiredEntries(): void
    {
        $backend = $this->backend();
        $backend->set('entry-a', 'a', [], 1);

        $this->clock->setNow($this->clock->now() + 3600);
        $backend->collectGarbage();

        self::assertFalse($this->backend()->get('entry-a'));
    }
}
