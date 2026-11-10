<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\LocalStore;

/**
 * Process-wide record of PHP payload files whose sha256 was verified, each
 * with the file's identity at that moment: device, inode, size and ctime from
 * one lstat() (option `crossRequestPhpVerification`).
 *
 * {@see \Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend::doRequire()}
 * consults it before every require of a code-cache payload. A file whose
 * lstat() still returns the recorded fingerprint is the file that was
 * verified, so its content is not read and hashed again; any difference
 * (another inode after a replace, another size, another ctime after any write,
 * chmod, chown, rename or link) means a full verification, exactly as without
 * the record.
 *
 * Threat model. The check defends the payload directory against a process
 * that can write there but is not root (a compromised sidecar or init
 * container, a world-writable misconfiguration). Such a process cannot change
 * a file's content, nor swap the file, without the kernel updating the
 * file's ctime or giving the path another inode; and only root can set ctime
 * (by changing the system clock). Not covered, as without the record: root in
 * the container, and an attacker who can write the metadata cache (it states
 * the checksum).
 *
 * PHP reports ctime in whole seconds. A write in the same second as the
 * recorded ctime would not change it, so a fingerprint is only recorded when
 * its ctime is strictly older than the second in which the lstat() was taken
 * (`$observedAt`): every later write then carries a ctime of that second or
 * later, which differs. A file written less than a second before it was
 * verified is therefore verified again on its next require.
 *
 * Holds no request state: paths of payload files and their stat values. In
 * FrankenPHP worker mode it lives as long as the worker. Bounded by entry
 * count, oldest first out.
 *
 * @internal
 */
final class VerifiedPayloadFingerprints
{
    public const int DEFAULT_MAX_ENTRIES = 4096;

    private static ?self $processWide = null;

    /** @var array<string, array{0: int, 1: int, 2: int, 3: int}> key → [dev, ino, size, ctime] */
    private array $verified = [];

    public function __construct(private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES)
    {
        if ($maxEntries < 1) {
            throw new \InvalidArgumentException('maxEntries must be >= 1');
        }
    }

    public static function processWide(): self
    {
        return self::$processWide ??= new self();
    }

    /**
     * Forgets the process-wide record. For tests.
     *
     * @internal
     */
    public static function resetProcessWide(): void
    {
        self::$processWide = null;
    }

    /**
     * The fingerprint of $path from one lstat() call, or null if it cannot be
     * taken. The caller has cleared PHP's stat cache before. $mtime receives
     * the modification time from the same call.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    public static function fingerprintOf(string $path, ?int &$mtime = null): ?array
    {
        $stat = @lstat($path);
        if (false === $stat) {
            return null;
        }
        $mtime = (int) $stat['mtime'];

        return [(int) $stat['dev'], (int) $stat['ino'], (int) $stat['size'], (int) $stat['ctime']];
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: int} $fingerprint
     */
    public function isVerified(string $key, array $fingerprint): bool
    {
        return ($this->verified[$key] ?? null) === $fingerprint;
    }

    /**
     * Records a fingerprint taken before a successful verification, unless
     * its ctime is not strictly older than the second it was taken in.
     *
     * @param array{0: int, 1: int, 2: int, 3: int} $fingerprint
     * @param int                                   $observedAt  unix time read right before the lstat()
     */
    public function remember(string $key, array $fingerprint, int $observedAt): bool
    {
        unset($this->verified[$key]);
        if ($fingerprint[3] >= $observedAt) {
            return false;
        }
        while (\count($this->verified) >= $this->maxEntries) {
            $oldest = array_key_first($this->verified);
            if (null === $oldest) {
                break;
            }
            unset($this->verified[$oldest]);
        }
        $this->verified[$key] = $fingerprint;

        return true;
    }

    public function forget(string $key): void
    {
        unset($this->verified[$key]);
    }

    public function count(): int
    {
        return \count($this->verified);
    }
}
