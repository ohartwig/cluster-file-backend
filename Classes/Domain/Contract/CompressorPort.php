<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Contract;

interface CompressorPort
{
    public function name(): string;

    public function isAvailable(): bool;

    public function compress(string $bytes): string;

    /**
     * Decompress bytes with an explicit upper bound on the output size, as
     * protection against compression bombs (e.g. a 100-byte gzip stream
     * that expands to gigabytes). Implementations MUST raise
     * {@see \RuntimeException} when the decompressed output would exceed
     * `$maxOutputBytes`.
     */
    public function decompress(string $bytes, int $maxOutputBytes): string;
}
