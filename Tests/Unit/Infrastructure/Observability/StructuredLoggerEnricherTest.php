<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\Observability;

use Moselwal\Typo3ClusterCache\Infrastructure\Observability\StructuredLoggerEnricher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructuredLoggerEnricher::class)]
final class StructuredLoggerEnricherTest extends TestCase
{
    private ?string $previousPodName = null;

    protected function setUp(): void
    {
        $current = getenv('POD_NAME');
        $this->previousPodName = false === $current ? null : $current;
    }

    protected function tearDown(): void
    {
        if (null === $this->previousPodName) {
            putenv('POD_NAME');
        } else {
            putenv('POD_NAME=' . $this->previousPodName);
        }
    }

    public function testPodNameFromEnvironmentVariable(): void
    {
        putenv('POD_NAME=typo3-pod-123');
        $enricher = new StructuredLoggerEnricher();

        $record = ($enricher)(['message' => 'event', 'context' => ['cacheName' => 'pages']]);

        self::assertSame('typo3-pod-123', $record['context']['podName']);
        self::assertSame('pages', $record['context']['cacheName']);
    }

    public function testPodNameFallsBackToHostname(): void
    {
        putenv('POD_NAME');
        $enricher = new StructuredLoggerEnricher();

        $record = ($enricher)(['message' => 'event']);

        self::assertNotEmpty($record['context']['podName']);
        self::assertNotSame('unknown', $record['context']['podName']);
    }

    public function testRecordWithoutPriorContextGetsContextArray(): void
    {
        putenv('POD_NAME=pod-x');
        $enricher = new StructuredLoggerEnricher();

        $record = ($enricher)(['message' => 'event']);

        self::assertIsArray($record['context']);
        self::assertSame('pod-x', $record['context']['podName']);
    }
}
