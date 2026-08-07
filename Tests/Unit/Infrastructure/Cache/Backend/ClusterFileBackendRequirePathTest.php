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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The PhpFrontend path — requireOnce()/require() — had no coverage at all, and
 * it is the one place in this extension where bytes from the local filesystem
 * are executed as code rather than returned as data. Everything the backend
 * does to earn that right lives in doRequire(): the symlink refusal, the
 * first-touch sha256 verification, and the broken-marking that stops a bad
 * entry from being retried forever.
 *
 * None of it produces a visible symptom when it stops working. A missing
 * symlink check does not fail a request — it succeeds, and executes whatever
 * the symlink pointed at. A verification that no longer verifies looks exactly
 * like one that does. So these tests are the only mechanism that can notice.
 *
 * The distinction in the last two tests is deliberate and easy to lose: a
 * sha256 mismatch means the metadata is lying about the bytes and the entry is
 * marked Broken, while a vanished file means the metadata is still right and
 * the payload merely has to be re-materialised. Collapsing the two would either
 * poison entries during a normal concurrent rewrite or keep serving a tampered
 * one.
 */
#[CoversClass(ClusterFileBackend::class)]
final class ClusterFileBackendRequirePathTest extends TestCase
{
    private string $localPath;
    private FakeClock $clock;
    private FakeMetrics $metrics;
    private FakeMetadataFrontend $metadataFrontend;

    protected function setUp(): void
    {
        parent::setUp();

        $this->localPath = \sys_get_temp_dir() . '/cfb-require-' . \bin2hex(\random_bytes(6));
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
        // Order matters: the constructor resolves metrics before the clock.
        GeneralUtility::addInstance(MetricsPort::class, $this->metrics);
        GeneralUtility::addInstance(ClockPort::class, $this->clock);

        return new ClusterFileBackend([
            'localPath' => $this->localPath,
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'prod', 'instance' => 'site'],
        ]);
    }

    /**
     * PhpFrontend's constructor calls setCache() on the backend, which is what
     * flips it into code-cache mode: compression off and a .php suffix, both
     * required before OPcache will ingest the file.
     */
    private function phpBackend(): ClusterFileBackend
    {
        $backend = $this->backend();
        new PhpFrontend('typoscript', $backend);

        return $backend;
    }

    /**
     * The payload file the backend materialised. The store names files by
     * payload hash, so for a single stored entry there is exactly one.
     *
     * @return string absolute path
     */
    private function soledPayloadFile(): string
    {
        $found = \glob($this->localPath . '/*.php');
        $found = false === $found ? [] : $found;
        // The store may shard into subdirectories.
        if ([] === $found) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->localPath, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($items as $item) {
                if ($item->isFile() && 'php' === $item->getExtension()) {
                    $found[] = $item->getPathname();
                }
            }
        }

        self::assertCount(1, $found, 'expected exactly one materialised payload file');

        return $found[0];
    }

    #[Test]
    public function requireOnceEvaluatesTheStoredPhpAndReturnsItsValue(): void
    {
        $backend = $this->phpBackend();
        $backend->set('entry-a', "<?php\nreturn ['answer' => 42];\n");

        self::assertSame(['answer' => 42], $backend->requireOnce('entry-a'));
    }

    /**
     * require() differs from requireOnce() only in the once-guard, and the
     * distinction matters: requireOnce() on an already-included file returns
     * true rather than the value, so a caller that needs the value on every
     * call has to use require().
     */
    #[Test]
    public function requireEvaluatesTheStoredPhpOnEveryCall(): void
    {
        $backend = $this->phpBackend();
        $backend->set('entry-b', "<?php\nreturn ['answer' => 7];\n");

        self::assertSame(['answer' => 7], $backend->require('entry-b'));
        self::assertSame(['answer' => 7], $backend->require('entry-b'));
    }

    #[Test]
    public function requireOnceReportsAMissForAnEntryThatWasNeverWritten(): void
    {
        self::assertFalse($this->phpBackend()->requireOnce('never-written'));
    }

    #[Test]
    public function requireOnceReportsAMissForAnExpiredEntry(): void
    {
        $backend = $this->phpBackend();
        $backend->set('entry-a', "<?php\nreturn 1;\n", [], 60);

        $this->clock->setNow($this->clock->now() + 61);

        self::assertFalse($this->phpBackend()->requireOnce('entry-a'));
    }

    /**
     * Blob-miss: the metadata survived but this pod has not materialised the
     * payload. The contract is to report a miss so TYPO3 rebuilds via set();
     * executing nothing is the point.
     */
    #[Test]
    public function requireOnceReportsAMissWhenThePayloadIsNotOnThisPod(): void
    {
        $backend = $this->phpBackend();
        $backend->set('entry-a', "<?php\nreturn 1;\n");

        \unlink($this->soledPayloadFile());

        self::assertFalse($this->phpBackend()->requireOnce('entry-a'));
    }

    /**
     * The security case. A sidecar, a compromised init container or a
     * world-writable mount can replace the payload file with a symlink between
     * two requests; following it would execute code from a path the backend
     * never wrote.
     *
     * The symlink deliberately points at a file with byte-identical content, so
     * the sha256 verification that runs afterwards would accept it. Anything
     * else lets this test pass on the strength of the checksum instead of the
     * is_link() guard — which is exactly what happened when it was first
     * written with differing content, and made the test unable to notice the
     * guard being removed at all.
     */
    #[Test]
    public function requireOnceRefusesASymlinkedPayloadEvenWhenItsContentWouldVerify(): void
    {
        $backend = $this->phpBackend();
        $backend->set('entry-a', "<?php\nreturn ['answer' => 42];\n");

        $payload = $this->soledPayloadFile();
        $identicalBytes = (string) \file_get_contents($payload);
        $elsewhere = \sys_get_temp_dir() . '/cfb-symlink-target-' . \bin2hex(\random_bytes(6)) . '.php';
        \file_put_contents($elsewhere, $identicalBytes);
        \unlink($payload);
        \symlink($elsewhere, $payload);

        try {
            self::assertFalse(
                $backend->requireOnce('entry-a'),
                'a symlinked payload must never be executed, whatever it contains',
            );
            self::assertFalse(
                $this->phpBackend()->has('entry-a'),
                'the entry must be marked broken, not left readable for the next request',
            );
        } finally {
            @\unlink($elsewhere);
        }
    }

    /**
     * sha256 mismatch: the bytes on disk are not the bytes the metadata
     * describes. Unlike a vanished file this is not a race, so the entry is
     * marked Broken rather than merely reported absent.
     */
    #[Test]
    public function requireOnceRefusesATamperedPayloadAndMarksTheEntryBroken(): void
    {
        $backend = $this->phpBackend();
        $backend->set('entry-a', "<?php\nreturn ['answer' => 42];\n");

        \file_put_contents($this->soledPayloadFile(), "<?php\nreturn ['answer' => 'owned'];\n");

        self::assertFalse(
            $backend->requireOnce('entry-a'),
            'a payload whose checksum does not match must not be executed',
        );
        self::assertFalse(
            $this->phpBackend()->has('entry-a'),
            'a checksum mismatch must mark the entry broken',
        );
    }

    /**
     * A broken entry stays unreadable until the caller writes it again — that
     * is what makes the marking a recovery path rather than a tombstone.
     */
    #[Test]
    public function aBrokenEntryBecomesReadableAgainAfterItIsRewritten(): void
    {
        $backend = $this->phpBackend();
        $backend->set('entry-a', "<?php\nreturn ['answer' => 42];\n");
        \file_put_contents($this->soledPayloadFile(), "<?php\nreturn 'tampered';\n");
        self::assertFalse($backend->requireOnce('entry-a'));

        $rebuilt = $this->phpBackend();
        $rebuilt->set('entry-a', "<?php\nreturn ['answer' => 42];\n");

        self::assertSame(['answer' => 42], $rebuilt->requireOnce('entry-a'));
    }
}
