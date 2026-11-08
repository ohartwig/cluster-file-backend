<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Support;

use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataGenerationPort;

/**
 * The generation key of a shared metadata store, with call counters and
 * failure switches. One instance stands for the store all simulated pods
 * share.
 */
final class FakeGenerationStore implements MetadataGenerationPort
{
    public ?string $value = null;
    public int $reads = 0;
    /** @var list<string> every value written, in order */
    public array $written = [];
    public bool $failReads = false;
    /** Number of upcoming writes that fail (-1: all of them). */
    public int $failWrites = 0;
    /** Called before every write; lets a test interleave another pod. */
    public ?\Closure $beforeWrite = null;

    public function readGeneration(): ?string
    {
        ++$this->reads;
        if ($this->failReads) {
            throw new \RuntimeException('generation read failed', 1794200001);
        }

        return $this->value;
    }

    public function writeGeneration(string $generation): void
    {
        if (null !== $this->beforeWrite) {
            ($this->beforeWrite)($generation);
        }
        if (0 !== $this->failWrites) {
            if ($this->failWrites > 0) {
                --$this->failWrites;
            }
            throw new \RuntimeException('generation write failed', 1794200002);
        }
        $this->written[] = $generation;
        $this->value = $generation;
    }
}
