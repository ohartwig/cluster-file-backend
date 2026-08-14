<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;

final readonly class Lifetime
{
    /**
     * Sentinel value for unlimited lifetime entries. Mirrors the TYPO3
     * core convention (`Typo3DatabaseBackend::FAKED_UNLIMITED_EXPIRE`):
     * `2147483647` is the last second of the 32-bit Unix epoch and far
     * enough in the future to count as "forever" for cache purposes.
     */
    public const int UNLIMITED_EXPIRES_AT = 2_147_483_647;

    public function __construct(
        public int $createdAt,
        public int $expiresAt,
    ) {
        if ($createdAt <= 0) {
            throw new \InvalidArgumentException(\sprintf('createdAt must be > 0, got %d', $createdAt));
        }
        if ($expiresAt <= $createdAt) {
            throw new \InvalidArgumentException(\sprintf('expiresAt (%d) must be > createdAt (%d)', $expiresAt, $createdAt));
        }
    }

    /**
     * Builds a finite lifetime from a positive duration.
     *
     * @throws \InvalidArgumentException when `$seconds < 1` — use
     *                                   {@see self::unlimited()} for
     *                                   "cache forever" semantics
     */
    public static function fromSeconds(int $seconds, ClockPort $clock): self
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException(\sprintf('Lifetime duration must be >= 1 second, got %d — use Lifetime::unlimited() for forever-cached entries', $seconds));
        }
        $now = $clock->now();

        return new self($now, $now + $seconds);
    }

    /**
     * Builds an unlimited lifetime ("cache forever"). Maps to TYPO3's
     * convention of `lifetime === 0` at the cache API boundary.
     */
    public static function unlimited(ClockPort $clock): self
    {
        return new self($clock->now(), self::UNLIMITED_EXPIRES_AT);
    }

    public function isUnlimited(): bool
    {
        return self::UNLIMITED_EXPIRES_AT === $this->expiresAt;
    }

    public function isExpired(int $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function remainingSeconds(int $now): int
    {
        return max(0, $this->expiresAt - $now);
    }
}
