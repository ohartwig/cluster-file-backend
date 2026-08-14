<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\LocalStore;

use Moselwal\Typo3ClusterCache\Domain\Exception\LocalStoreWriteException;
use Moselwal\Typo3ClusterCache\Domain\Exception\PayloadIntegrityException;
use Moselwal\Typo3ClusterCache\Domain\Exception\PayloadNotFoundException;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Infrastructure\LocalStore\EmptyDirPayloadStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmptyDirPayloadStore::class)]
final class EmptyDirPayloadStoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cfb-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->tmpDir);
    }

    public function testWriteAndReadVerifiedRoundtrip(): void
    {
        $store = new EmptyDirPayloadStore($this->tmpDir);
        $bytes = 'hello world';
        $hash = new PayloadHash(hash('sha256', $bytes));
        $checksum = PayloadChecksum::ofBytes($bytes);

        $store->write($hash, $bytes);

        self::assertTrue($store->exists($hash));
        self::assertSame($bytes, $store->readVerified($hash, $checksum));
    }

    public function testReadVerifiedThrowsOnMissingFile(): void
    {
        $store = new EmptyDirPayloadStore($this->tmpDir);
        $hash = new PayloadHash(str_repeat('e', 64));
        $checksum = PayloadChecksum::ofBytes('x');

        $this->expectException(PayloadNotFoundException::class);
        $store->readVerified($hash, $checksum);
    }

    public function testReadVerifiedThrowsOnChecksumMismatch(): void
    {
        $store = new EmptyDirPayloadStore($this->tmpDir);
        $bytes = 'original';
        $hash = new PayloadHash(hash('sha256', $bytes));
        $store->write($hash, $bytes);

        $wrongChecksum = PayloadChecksum::ofBytes('different');

        $this->expectException(PayloadIntegrityException::class);
        $store->readVerified($hash, $wrongChecksum);
    }

    public function testDeleteIsIdempotent(): void
    {
        $store = new EmptyDirPayloadStore($this->tmpDir);
        $hash = new PayloadHash(str_repeat('a', 64));
        $store->delete($hash);
        self::assertFalse($store->exists($hash));
    }

    public function testRequiresAbsolutePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmptyDirPayloadStore('relative/path');
    }

    /**
     * The regression worth guarding on the read path: a symlink where a payload
     * should be means something bypassed the atomic write. Following it lets
     * whatever placed it decide what the cache serves.
     */
    public function testASymlinkedPayloadIsRefusedRatherThanFollowed(): void
    {
        $store = new EmptyDirPayloadStore($this->tmpDir);
        $bytes = 'the original payload';
        $hash = new PayloadHash(hash('sha256', $bytes));
        $store->write($hash, $bytes);

        $path = $store->pathFor($hash);
        $elsewhere = $this->tmpDir . '/elsewhere';
        file_put_contents($elsewhere, $bytes);
        unlink($path);
        symlink($elsewhere, $path);

        $this->expectException(PayloadIntegrityException::class);
        $store->readVerified($hash, PayloadChecksum::ofBytes($bytes));
    }

    /**
     * And the same on the way in — writing through a symlink would have the
     * cache overwrite a file outside its own store.
     */
    public function testWritingThroughASymlinkIsRefused(): void
    {
        $store = new EmptyDirPayloadStore($this->tmpDir);
        $hash = new PayloadHash(hash('sha256', 'first'));
        $store->write($hash, 'first');

        $path = $store->pathFor($hash);
        $elsewhere = $this->tmpDir . '/elsewhere';
        file_put_contents($elsewhere, 'do not overwrite me');
        unlink($path);
        symlink($elsewhere, $path);

        try {
            $store->write($hash, 'second');
            self::fail('the write should have been refused');
        } catch (LocalStoreWriteException) {
            self::assertSame('do not overwrite me', file_get_contents($elsewhere));
        }
    }

    /**
     * A payload is written to a temp file and renamed into place. Nothing may
     * be left behind: a shard full of `.cfb.tmp.*` is an emptyDir filling up
     * with no cache benefit, and it is invisible until the pod runs out of disk.
     */
    public function testNoTemporaryFileSurvivesAWrite(): void
    {
        $store = new EmptyDirPayloadStore($this->tmpDir);
        $store->write(new PayloadHash(hash('sha256', 'a')), 'payload');

        $leftovers = [];
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (str_contains($file->getFilename(), '.cfb.tmp.')) {
                $leftovers[] = $file->getFilename();
            }
        }

        self::assertSame([], $leftovers);
    }

    public function testAWriteReplacesWhatWasThere(): void
    {
        $store = new EmptyDirPayloadStore($this->tmpDir);
        $hash = new PayloadHash(hash('sha256', 'a'));

        $store->write($hash, 'first');
        $store->write($hash, 'second');

        self::assertSame('second', $store->readVerified($hash, PayloadChecksum::ofBytes('second')));
    }

    public function testBinaryPayloadsSurviveUnchanged(): void
    {
        $store = new EmptyDirPayloadStore($this->tmpDir);
        $bytes = "line one\n\x00\xFFline two";
        $hash = new PayloadHash(hash('sha256', $bytes));

        $store->write($hash, $bytes);

        self::assertSame($bytes, $store->readVerified($hash, PayloadChecksum::ofBytes($bytes)));
    }

    /**
     * Payloads are sharded rather than dumped into one directory: a flat
     * directory with hundreds of thousands of entries is slow to open on most
     * filesystems.
     */
    public function testPayloadsAreShardedByTheirHash(): void
    {
        $store = new EmptyDirPayloadStore($this->tmpDir);
        $a = new PayloadHash(hash('sha256', 'a'));
        $b = new PayloadHash(hash('sha256', 'b'));

        self::assertNotSame(\dirname($store->pathFor($a)), \dirname($store->pathFor($b)));
        self::assertStringContainsString($this->tmpDir, $store->pathFor($a));
    }

    public function testAMalformedFileSuffixIsRefused(): void
    {
        foreach (['php', '.PHP', '.', '.toolongasuffixvalue1'] as $suffix) {
            try {
                new EmptyDirPayloadStore($this->tmpDir, $suffix);
                self::fail(sprintf('"%s" should have been refused', $suffix));
            } catch (\InvalidArgumentException $refusal) {
                self::assertStringContainsString('fileSuffix', $refusal->getMessage(), $suffix);
            }
        }
    }

    public function testTheConfiguredSuffixIsAppliedConsistently(): void
    {
        $store = new EmptyDirPayloadStore($this->tmpDir, '.bin');
        $hash = new PayloadHash(hash('sha256', 'a'));

        self::assertStringEndsWith('.bin', $store->pathFor($hash));

        $store->write($hash, 'payload');

        self::assertFileExists($store->pathFor($hash));
        self::assertSame('payload', $store->readVerified($hash, PayloadChecksum::ofBytes('payload')));
    }

    /**
     * The probe is what the warm-up reports as "local store writable", so it
     * writes and reads back rather than checking a permission bit — a read-only
     * mount and a full disk both pass an is_writable check.
     */
    public function testTheProbeCreatesTheStoreAndVerifiesARoundTrip(): void
    {
        $path = $this->tmpDir . '/not-yet-there';
        $store = new EmptyDirPayloadStore($path);

        self::assertTrue($store->probe());
        self::assertDirectoryExists($path);
        self::assertFileDoesNotExist($path . '/.cfb-probe', 'the sentinel is cleaned up');
    }

    public function testTheProbeFailsWhenTheStoreCannotBeCreated(): void
    {
        file_put_contents($this->tmpDir . '/blocker', 'not a directory');

        self::assertFalse(new EmptyDirPayloadStore($this->tmpDir . '/blocker/store')->probe());
    }

    public function testEveryStoredPayloadIsIterated(): void
    {
        $store = new EmptyDirPayloadStore($this->tmpDir);
        $hashes = [];
        foreach (['a', 'b', 'c'] as $seed) {
            $hash = new PayloadHash(hash('sha256', $seed));
            $store->write($hash, 'payload ' . $seed);
            $hashes[] = $hash->digest;
        }

        $seen = $this->iterated($store);

        sort($hashes);
        self::assertSame($hashes, $seen);
    }

    /**
     * Garbage collection runs on a cold node too, where nothing has been
     * written yet.
     */
    public function testAnUncreatedStoreIteratesEmpty(): void
    {
        self::assertSame([], $this->iterated(new EmptyDirPayloadStore($this->tmpDir . '/never-written')));
    }

    /**
     * The probe sentinel and any stray file must not be reported as a payload:
     * garbage collection would look each one up in the metadata cache, find
     * nothing, and delete a file it does not own.
     */
    public function testStrayFilesAreNotMistakenForPayloads(): void
    {
        $store = new EmptyDirPayloadStore($this->tmpDir);
        $hash = new PayloadHash(hash('sha256', 'a'));
        $store->write($hash, 'payload');

        file_put_contents($this->tmpDir . '/.cfb-probe', 'sentinel');
        file_put_contents($this->tmpDir . '/README.txt', 'not a payload');

        self::assertSame([$hash->digest], $this->iterated($store));
    }

    /**
     * @return list<string>
     */
    private function iterated(EmptyDirPayloadStore $store): array
    {
        $seen = [];
        foreach ($store->iterateAll() as $found) {
            $seen[] = $found instanceof PayloadHash ? $found->digest : (string) $found;
        }
        sort($seen);

        return $seen;
    }

    private function removeRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
