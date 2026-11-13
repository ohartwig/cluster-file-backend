<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\LocalStore;

use Moselwal\Typo3ClusterCache\Infrastructure\LocalStore\VerifiedPayloadFingerprints;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerifiedPayloadFingerprints::class)]
final class VerifiedPayloadFingerprintsTest extends TestCase
{
    #[Test]
    public function onlyTheExactFingerprintCounts(): void
    {
        $record = new VerifiedPayloadFingerprints();
        self::assertTrue($record->remember('k', [1, 2, 3, 100], 102));

        self::assertTrue($record->isVerified('k', [1, 2, 3, 100]));
        self::assertFalse($record->isVerified('k', [1, 2, 3, 101]), 'ctime');
        self::assertFalse($record->isVerified('k', [1, 2, 4, 100]), 'size');
        self::assertFalse($record->isVerified('k', [1, 9, 3, 100]), 'inode');
        self::assertFalse($record->isVerified('k', [9, 2, 3, 100]), 'device');
        self::assertFalse($record->isVerified('other', [1, 2, 3, 100]), 'key');
    }

    #[Test]
    public function aCtimeLessThanTwoSecondsBeforeObservationIsNotRecorded(): void
    {
        $record = new VerifiedPayloadFingerprints();

        self::assertFalse($record->remember('same', [1, 2, 3, 100], 100));
        self::assertFalse($record->remember('future', [1, 2, 3, 105], 100));
        // The kernel's file clock runs behind time(): a write at time() 101
        // can be stamped 100, so a ctime one second old is not settled yet.
        self::assertFalse($record->remember('one second', [1, 2, 3, 100], 101));
        self::assertTrue($record->remember('two seconds', [1, 2, 3, 100], 102));
        self::assertFalse($record->isVerified('same', [1, 2, 3, 100]));
        self::assertSame(1, $record->count(), 'only the settled one');
    }

    #[Test]
    public function aRefusedRecordDropsAnOlderOne(): void
    {
        $record = new VerifiedPayloadFingerprints();
        $record->remember('k', [1, 2, 3, 100], 102);
        $record->remember('k', [1, 2, 3, 200], 200);

        self::assertFalse($record->isVerified('k', [1, 2, 3, 100]));
    }

    #[Test]
    public function theOldestEntryGoesWhenFull(): void
    {
        $record = new VerifiedPayloadFingerprints(2);
        $record->remember('a', [1, 1, 1, 1], 3);
        $record->remember('b', [1, 1, 1, 1], 3);
        $record->remember('c', [1, 1, 1, 1], 3);

        self::assertSame(2, $record->count());
        self::assertFalse($record->isVerified('a', [1, 1, 1, 1]));
        self::assertTrue($record->isVerified('c', [1, 1, 1, 1]));
    }

    #[Test]
    public function theFingerprintComesFromLstat(): void
    {
        $file = sys_get_temp_dir() . '/cfb-fp-' . bin2hex(random_bytes(6));
        file_put_contents($file, 'abc');
        $link = $file . '.link';
        symlink($file, $link);
        try {
            clearstatcache();
            $stat = lstat($file);
            self::assertNotFalse($stat);
            $mtime = null;
            self::assertSame([$stat['dev'], $stat['ino'], 3, $stat['ctime']], VerifiedPayloadFingerprints::fingerprintOf($file, $mtime));
            self::assertSame($stat['mtime'], $mtime);
            $linkFingerprint = VerifiedPayloadFingerprints::fingerprintOf($link);
            self::assertNotNull($linkFingerprint);
            self::assertNotSame($stat['ino'], $linkFingerprint[1], 'lstat: the link itself, not its target');
            self::assertNull(VerifiedPayloadFingerprints::fingerprintOf($file . '.missing'));
        } finally {
            @unlink($link);
            @unlink($file);
        }
    }

    #[Test]
    public function aBoundOfOneKeepsTheLatestEntry(): void
    {
        $record = new VerifiedPayloadFingerprints(1);
        $record->remember('a', [1, 1, 1, 1], 3);
        $record->remember('b', [1, 1, 1, 1], 3);

        self::assertSame(1, $record->count());
        self::assertTrue($record->isVerified('b', [1, 1, 1, 1]));
    }

    #[Test]
    public function aBoundBelowOneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VerifiedPayloadFingerprints(0);
    }
}
