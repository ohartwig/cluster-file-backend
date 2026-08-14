<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

final readonly class CompressionName
{
    public function __construct(
        public CompressionAlgo $name,
    ) {}

    public static function zstd(): self
    {
        return new self(CompressionAlgo::Zstd);
    }

    public static function gzip(): self
    {
        return new self(CompressionAlgo::Gzip);
    }

    public static function none(): self
    {
        return new self(CompressionAlgo::None);
    }

    public static function fromString(string $value): self
    {
        return new self(CompressionAlgo::from($value));
    }
}
