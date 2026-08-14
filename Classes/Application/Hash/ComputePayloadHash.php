<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Application\Hash;

use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
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

    private function phpMajorMinor(): string
    {
        return \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION;
    }
}
