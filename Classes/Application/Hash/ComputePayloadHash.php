<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Application\Hash;

use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;

final class ComputePayloadHash
{
    public function fromRawBytes(
        string $rawBytes,
        SerializerName $serializer,
        CompressionName $compression,
        BackendVersion $backendVersion,
    ): PayloadHash {
        return PayloadHash::compute(
            $serializer,
            $compression,
            $backendVersion,
            $this->phpMajorMinor(),
            $rawBytes,
        );
    }

    /**
     * The hash, and the checksum of the same bytes from the same pass. For a
     * payload stored exactly as it was serialized: no compression, no marker
     * byte (PHP caches, see WriteCacheEntry::$bareBytes) -- there the checksum
     * of the stored file is the sha256 of the serialized bytes.
     *
     * @return array{PayloadHash, PayloadChecksum}
     */
    public function fromRawBytesWithChecksum(
        string $rawBytes,
        SerializerName $serializer,
        CompressionName $compression,
        BackendVersion $backendVersion,
    ): array {
        return PayloadHash::computeWithChecksumOfBytes(
            $serializer,
            $compression,
            $backendVersion,
            $this->phpMajorMinor(),
            $rawBytes,
        );
    }

    private function phpMajorMinor(): string
    {
        return \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION;
    }
}
