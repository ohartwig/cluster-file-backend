<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Support;

use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;

final class FakeMetrics implements MetricsPort
{
    /** @var list<array{name: string, labels: array<string, string>, delta: int}> */
    public array $counters = [];
    /** @var list<array{name: string, labels: array<string, string>, value: float}> */
    public array $histograms = [];

    public function counter(string $name, array $labels = [], int $delta = 1): void
    {
        $this->counters[] = ['name' => $name, 'labels' => $labels, 'delta' => $delta];
    }

    public function histogram(string $name, array $labels, float $value): void
    {
        $this->histograms[] = ['name' => $name, 'labels' => $labels, 'value' => $value];
    }

    public function counterTotal(string $name): int
    {
        $total = 0;
        foreach ($this->counters as $entry) {
            if ($entry['name'] === $name) {
                $total += $entry['delta'];
            }
        }

        return $total;
    }
}
