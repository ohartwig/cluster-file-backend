<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

final readonly class PayloadHash
{
    public const string ALGO = 'sha256';
    private const string DIGEST_PATTERN = '/^[a-f0-9]{64}$/';

    public function __construct(
        public string $digest,
    ) {
        if (1 !== preg_match(self::DIGEST_PATTERN, $digest)) {
            throw new \InvalidArgumentException(\sprintf('PayloadHash digest "%s" must be a 64-character lowercase hex sha256 string', $digest));
        }
    }

    public static function compute(
        SerializerName $serializer,
        CompressionName $compression,
        BackendVersion $backendVersion,
        string $phpMajorMinor,
        string $serializedBytes,
    ): self {
        $hashInput = \sprintf(
            '%s|%s|%d|%s|',
            $serializer->version,
            $compression->name->value,
            $backendVersion->value,
            $phpMajorMinor,
        );

        return new self(hash(self::ALGO, $hashInput . $serializedBytes));
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->digest, $other->digest);
    }

    public function prefix(int $length): string
    {
        return substr($this->digest, 0, $length);
    }
}
