<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Application\GarbageCollect;

final readonly class GarbageCollectionReport
{
    public function __construct(
        public string $namespace,
        public bool $dryRun,
        public int $durationMs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'namespace' => $this->namespace,
            'dryRun' => $this->dryRun,
            'durationMs' => $this->durationMs,
        ];
    }
}
