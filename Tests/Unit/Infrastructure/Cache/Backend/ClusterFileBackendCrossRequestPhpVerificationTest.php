<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\Cache\Backend;

use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend;
use Moselwal\Typo3ClusterCache\Infrastructure\LocalStore\VerifiedPayloadFingerprints;
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
 * Option `crossRequestPhpVerification` ("variant C"): a code-cache payload
 * whose sha256 was verified is trusted in later requests of the same process
 * only while one lstat() shows the same device, inode, size and ctime. These
 * tests pin both halves: the re-hash is skipped for an unchanged file, and
 * every kind of change (in-place write, replacement, symlink) is caught on
 * the next require.
 *
 * A fingerprint is only recorded once the file's ctime lies in an earlier
 * second than the check, so the tests that rely on a recorded fingerprint
 * wait for the next second after writing the payload.
 */
#[CoversClass(ClusterFileBackend::class)]
final class ClusterFileBackendCrossRequestPhpVerificationTest extends TestCase
{
    private string $localPath;
    private FakeClock $clock;
    private FakeMetrics $metrics;
    private FakeMetadataFrontend $metadataFrontend;
    private bool $crossRequest = true;

    protected function setUp(): void
    {
        parent::setUp();
        VerifiedPayloadFingerprints::resetProcessWide();

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
        VerifiedPayloadFingerprints::resetProcessWide();

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
            'crossRequestPhpVerification' => $this->crossRequest,
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

    /** Waits until the wall clock is in a later second than the payload's ctime. */
    private function letTheCtimeAge(): void
    {
        clearstatcache();
        $ctime = (int) \filectime($this->soledPayloadFile());
        while (\time() <= $ctime) {
            \usleep(50_000);
        }
    }

    /** @return array<string, int> result label → count of php_payload_fingerprint_total */
    private function fingerprintResults(): array
    {
        $results = [];
        foreach ($this->metrics->counters as $entry) {
            if ('php_payload_fingerprint_total' === $entry['name']) {
                $result = $entry['labels']['result'] ?? '?';
                $results[$result] = ($results[$result] ?? 0) + $entry['delta'];
            }
        }

        return $results;
    }

    #[Test]
    public function theOptionIsOffByDefault(): void
    {
        $backend = new ClusterFileBackend([
            'localPath' => $this->localPath,
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'prod', 'instance' => 'site'],
        ]);

        self::assertFalse($backend->isCrossRequestPhpVerificationEnabled());
        self::assertTrue($this->phpBackend()->isCrossRequestPhpVerificationEnabled());
    }

    /**
     * The point of the option: the next request (a new backend instance, as
     * TYPO3 builds one per request) does not read and hash the file again.
     */
    #[Test]
    public function anUnchangedFileIsNotHashedAgainInTheNextRequest(): void
    {
        $this->phpBackend()->set('entry-a', "<?php\nreturn ['answer' => 42];\n");
        $this->letTheCtimeAge();

        self::assertSame(['answer' => 42], $this->phpBackend()->require('entry-a'));
        self::assertSame(['answer' => 42], $this->phpBackend()->require('entry-a'));
        self::assertSame(['answer' => 42], $this->phpBackend()->require('entry-a'));

        self::assertSame(['verified' => 1, 'unchanged' => 2], $this->fingerprintResults());
    }

    /**
     * Same size, same inode, different bytes: only ctime tells, and it does.
     */
    #[Test]
    public function anInPlaceWriteAfterTheVerificationIsCaughtOnTheNextRequire(): void
    {
        $this->phpBackend()->set('entry-a', "<?php\nreturn ['answer' => 42];\n");
        $this->letTheCtimeAge();
        self::assertSame(['answer' => 42], $this->phpBackend()->require('entry-a'));

        $payload = $this->soledPayloadFile();
        clearstatcache();
        $inode = \fileinode($payload);
        $handle = \fopen($payload, 'r+');
        self::assertNotFalse($handle);
        \fwrite($handle, "<?php\nreturn ['answer' => 66];\n");
        \fclose($handle);
        clearstatcache();
        self::assertSame($inode, \fileinode($payload), 'precondition: same inode');

        self::assertFalse($this->phpBackend()->require('entry-a'), 'a changed payload must not be executed');
        self::assertFalse($this->phpBackend()->has('entry-a'), 'and the entry is marked broken');
    }

    /**
     * Even a write that restores the original mtime is seen: it changes
     * ctime, which no unprivileged process can set back.
     */
    #[Test]
    public function aWriteThatRestoresTheMtimeIsStillCaught(): void
    {
        $this->phpBackend()->set('entry-a', "<?php\nreturn ['answer' => 42];\n");
        $this->letTheCtimeAge();
        self::assertSame(['answer' => 42], $this->phpBackend()->require('entry-a'));

        $payload = $this->soledPayloadFile();
        clearstatcache();
        $mtime = (int) \filemtime($payload);
        \file_put_contents($payload, "<?php\nreturn ['answer' => 66];\n");
        \touch($payload, $mtime);

        self::assertFalse($this->phpBackend()->require('entry-a'));
    }

    /**
     * Another file under the same name (a new inode) is verified in full,
     * and accepted when its bytes are the right ones.
     */
    #[Test]
    public function aReplacedFileIsVerifiedInFull(): void
    {
        $this->phpBackend()->set('entry-a', "<?php\nreturn ['answer' => 42];\n");
        $this->letTheCtimeAge();
        self::assertSame(['answer' => 42], $this->phpBackend()->require('entry-a'));

        $payload = $this->soledPayloadFile();
        $bytes = (string) \file_get_contents($payload);
        $replacement = $payload . '.new';
        \file_put_contents($replacement, $bytes);
        \rename($replacement, $payload);

        self::assertSame(['answer' => 42], $this->phpBackend()->require('entry-a'));
        self::assertSame(['verified' => 2], $this->fingerprintResults());
    }

    #[Test]
    public function aSymlinkIsRefusedEvenAfterTheTargetWasVerified(): void
    {
        $this->phpBackend()->set('entry-a', "<?php\nreturn ['answer' => 42];\n");
        $this->letTheCtimeAge();
        self::assertSame(['answer' => 42], $this->phpBackend()->require('entry-a'));

        $payload = $this->soledPayloadFile();
        $elsewhere = \sys_get_temp_dir() . '/cfb-xr-symlink-' . \bin2hex(\random_bytes(6)) . '.php';
        \rename($payload, $elsewhere);
        \symlink($elsewhere, $payload);

        try {
            self::assertFalse($this->phpBackend()->require('entry-a'), 'a symlinked payload is never executed');
            self::assertFalse($this->phpBackend()->has('entry-a'));
        } finally {
            @\unlink($elsewhere);
        }
    }

    /**
     * A file whose ctime is in the current second could still be written
     * within that second without its ctime changing, so it is verified again.
     */
    #[Test]
    public function aFileWrittenThisSecondIsVerifiedEveryTime(): void
    {
        $this->phpBackend()->set('entry-a', "<?php\nreturn ['answer' => 42];\n");
        clearstatcache();
        $ctime = (int) \filectime($this->soledPayloadFile());
        if (\time() > $ctime) {
            self::markTestSkipped('the second turned over between write and require; nothing to observe');
        }

        self::assertSame(['answer' => 42], $this->phpBackend()->require('entry-a'));
        self::assertSame(['answer' => 42], $this->phpBackend()->require('entry-a'));

        if (\time() === $ctime) {
            self::assertSame(['verified' => 2], $this->fingerprintResults());
        }
    }

    /**
     * Within a request the fingerprint is compared on every require, not just
     * the first: a change between two requires of one request is caught too.
     */
    #[Test]
    public function aChangeBetweenTwoRequiresOfOneRequestIsCaught(): void
    {
        $backend = $this->phpBackend();
        $backend->set('entry-a', "<?php\nreturn ['answer' => 42];\n");
        $this->letTheCtimeAge();
        self::assertSame(['answer' => 42], $backend->require('entry-a'));

        \file_put_contents($this->soledPayloadFile(), "<?php\nreturn ['answer' => 66];\n");

        self::assertFalse($backend->require('entry-a'));
    }

    #[Test]
    public function withTheOptionOffNothingIsRecorded(): void
    {
        $this->crossRequest = false;
        $this->phpBackend()->set('entry-a', "<?php\nreturn ['answer' => 42];\n");
        $this->letTheCtimeAge();

        self::assertSame(['answer' => 42], $this->phpBackend()->require('entry-a'));
        self::assertSame(0, VerifiedPayloadFingerprints::processWide()->count());
        self::assertSame([], $this->fingerprintResults());
    }
}
