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

    /**
     * The identity of a payload: sha256 over the serialized bytes, then the
     * context they were produced in (serializer, compression, backend
     * version, PHP major.minor). Same bytes in the same context, same hash,
     * on every pod (R-005, FR-016).
     *
     * The context is a suffix, not a prefix, since BackendVersionInfo 3: a
     * caller that also needs the plain sha256 of the bytes -- the checksum of
     * a payload stored as it is (WriteCacheEntry, PHP caches) -- takes it from
     * the same pass over the bytes instead of hashing them twice
     * (computeWithChecksumOfBytes()).
     */
    public static function compute(
        SerializerName $serializer,
        CompressionName $compression,
        BackendVersion $backendVersion,
        string $phpMajorMinor,
        string $serializedBytes,
    ): self {
        return new self(Sha256::hex($serializedBytes . self::context($serializer, $compression, $backendVersion, $phpMajorMinor)));
    }

    /**
     * compute() and the sha256 of the bytes alone.
     *
     * With OpenSSL (Sha256) two passes are cheaper than one through ext-hash:
     * 2.1 ms against 10.6 ms for 2.2 MB. Without it, one ext-hash pass gives
     * both: the state after the bytes is copied before the context goes in.
     *
     * @return array{self, PayloadChecksum}
     */
    public static function computeWithChecksumOfBytes(
        SerializerName $serializer,
        CompressionName $compression,
        BackendVersion $backendVersion,
        string $phpMajorMinor,
        string $serializedBytes,
    ): array {
        if (Sha256::usesOpenSsl()) {
            return [
                new self(Sha256::hex($serializedBytes . self::context($serializer, $compression, $backendVersion, $phpMajorMinor))),
                new PayloadChecksum(Sha256::hex($serializedBytes)),
            ];
        }
        $state = hash_init(self::ALGO);
        hash_update($state, $serializedBytes);
        $checksum = new PayloadChecksum(hash_final(hash_copy($state)));
        hash_update($state, self::context($serializer, $compression, $backendVersion, $phpMajorMinor));

        return [new self(hash_final($state)), $checksum];
    }

    private static function context(
        SerializerName $serializer,
        CompressionName $compression,
        BackendVersion $backendVersion,
        string $phpMajorMinor,
    ): string {
        return \sprintf(
            '|%s|%s|%d|%s',
            $serializer->version,
            $compression->name->value,
            $backendVersion->value,
            $phpMajorMinor,
        );
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
