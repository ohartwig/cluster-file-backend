<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\Observability;

use Moselwal\Typo3ClusterCache\Infrastructure\Observability\PrometheusMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(PrometheusMetrics::class)]
final class PrometheusMetricsTest extends TestCase
{
    public function testCounterEmitsStructuredLogRecord(): void
    {
        $logger = $this->recordingLogger();
        $metrics = new PrometheusMetrics($logger);

        $metrics->counter('cache_hit_total', ['cacheName' => 'pages']);

        self::assertCount(1, $logger->records);
        self::assertSame('cfb.metric.counter', $logger->records[0]['message']);
        self::assertSame('cache_hit_total', $logger->records[0]['context']['metric']);
        self::assertSame('counter', $logger->records[0]['context']['kind']);
        self::assertSame(1, $logger->records[0]['context']['delta']);
        self::assertSame(['cacheName' => 'pages'], $logger->records[0]['context']['labels']);
    }

    public function testCounterWithExplicitDelta(): void
    {
        $logger = $this->recordingLogger();
        $metrics = new PrometheusMetrics($logger);

        $metrics->counter('blob_miss_total', [], 5);

        self::assertSame(5, $logger->records[0]['context']['delta']);
    }

    public function testHistogramEmitsStructuredLogRecord(): void
    {
        $logger = $this->recordingLogger();
        $metrics = new PrometheusMetrics($logger);

        $metrics->histogram('metadata_latency_ms', ['op' => 'get'], 12.5);

        self::assertSame('cfb.metric.histogram', $logger->records[0]['message']);
        self::assertSame('histogram', $logger->records[0]['context']['kind']);
        self::assertSame(12.5, $logger->records[0]['context']['value']);
        self::assertSame(['op' => 'get'], $logger->records[0]['context']['labels']);
    }

    /**
     * @return AbstractLogger&object{records: array<int, array{message: string, context: array<string, mixed>}>}
     */
    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var array<int, array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }
}
