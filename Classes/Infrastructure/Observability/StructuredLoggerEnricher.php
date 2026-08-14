<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Observability;

/**
 * Enriches ClusterFileBackend log records with structured observability
 * fields. Currently adds `podName` (resolved from `POD_NAME` env var or
 * `gethostname()`) so log lines from different pods can be correlated.
 *
 * Usage as a PSR-3 processor:
 *   $logger->pushProcessor(new StructuredLoggerEnricher());
 */
final class StructuredLoggerEnricher
{
    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    public function __invoke(array $record): array
    {
        $record['context'] = ($record['context'] ?? []) + [
            'podName' => $this->resolvePodName(),
        ];

        return $record;
    }

    private function resolvePodName(): string
    {
        $env = getenv('POD_NAME');
        if (\is_string($env) && '' !== $env) {
            return $env;
        }
        $host = gethostname();

        return false !== $host ? $host : 'unknown';
    }
}
