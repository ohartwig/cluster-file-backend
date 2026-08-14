<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

final readonly class PayloadChecksum
{
    public const string ALGO = 'sha256';
    private const string DIGEST_PATTERN = '/^[a-f0-9]{64}$/';

    public function __construct(
        public string $digest,
    ) {
        if (1 !== preg_match(self::DIGEST_PATTERN, $digest)) {
            throw new \InvalidArgumentException(\sprintf('PayloadChecksum digest "%s" must be a 64-character lowercase hex sha256 string', $digest));
        }
    }

    public static function ofBytes(string $bytes): self
    {
        return new self(hash(self::ALGO, $bytes));
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->digest, $other->digest);
    }
}
