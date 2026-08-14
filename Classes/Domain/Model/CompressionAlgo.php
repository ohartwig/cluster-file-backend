<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

enum CompressionAlgo: string
{
    case Zstd = 'zstd';
    case Gzip = 'gzip';
    case None = 'none';

    /**
     * One-byte on-disk marker that identifies which codec produced the
     * compressed payload bytes that follow it. Used since v2.2.0 to allow
     * the same backend to store both compressed and uncompressed payloads
     * — e.g. `none` for small values (skip-compress) or for `PhpFrontend`
     * code caches that must remain plain PHP text on disk.
     *
     * Stable: do not renumber once assigned. Pre-v2.2 payloads do not carry
     * a marker; BackendVersionInfo::CURRENT was bumped to 2 in v2.2.0 to
     * invalidate them at the hash layer so the reader never has to guess.
     */
    public function marker(): string
    {
        return match ($this) {
            self::None => "\x00",
            self::Zstd => "\x01",
            self::Gzip => "\x02",
        };
    }

    /**
     * Inverse of {@see marker()}. Throws when the leading byte is none of
     * the assigned markers — a clear signal that the payload is corrupt
     * or comes from a foreign serializer/version.
     */
    public static function fromMarker(string $marker): self
    {
        return match ($marker) {
            "\x00" => self::None,
            "\x01" => self::Zstd,
            "\x02" => self::Gzip,
            default => throw new \InvalidArgumentException(\sprintf('unknown compression marker 0x%02x', \ord($marker))),
        };
    }
}
