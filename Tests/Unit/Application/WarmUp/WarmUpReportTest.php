<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Application\WarmUp;

use Moselwal\Typo3ClusterCache\Application\WarmUp\WarmUpReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a warm-up run tells the deployment. The distinction that matters is
 * between the two health flags and the three counters: only the flags decide
 * whether the run succeeded.
 *
 * Zero prefetched identifiers is the normal case — nothing was asked for. If
 * that counted as failure, every warm-up without an explicit identifier list
 * would fail the deploy. And blob misses are expected too: a cold node has an
 * empty local store by definition, and reporting that as a failure would block
 * exactly the deployment the warm-up exists to support.
 */
#[CoversClass(WarmUpReport::class)]
final class WarmUpReportTest extends TestCase
{
    private function report(bool $metadataHealthy = true, bool $localWritable = true): WarmUpReport
    {
        return new WarmUpReport(
            namespace: 'cfb:prod:site:pages',
            metadataCacheHealthy: $metadataHealthy,
            localStoreWritable: $localWritable,
            prefetchedIdentifiers: 12,
            localHits: 9,
            blobMisses: 3,
            durationMs: 47,
        );
    }

    #[Test]
    public function aHealthyRunSucceeds(): void
    {
        self::assertTrue($this->report()->succeeded());
    }

    /**
     * Both halves have to hold. A writable local store on a node whose metadata
     * cache is unreachable serves nothing; an intact metadata cache the node
     * cannot write payloads for serves nothing either.
     */
    #[Test]
    public function eitherHealthFlagFailingFailsTheRun(): void
    {
        self::assertFalse($this->report(metadataHealthy: false)->succeeded());
        self::assertFalse($this->report(localWritable: false)->succeeded());
        self::assertFalse($this->report(metadataHealthy: false, localWritable: false)->succeeded());
    }

    /**
     * The regression worth guarding: a cold node has nothing locally, which is
     * the entire reason the warm-up runs. Counting misses as failure would
     * block every first deploy to a new node.
     */
    #[Test]
    public function missesOnAColdNodeDoNotFailTheRun(): void
    {
        $report = new WarmUpReport(
            namespace: 'cfb:prod:site:pages',
            metadataCacheHealthy: true,
            localStoreWritable: true,
            prefetchedIdentifiers: 0,
            localHits: 0,
            blobMisses: 40,
            durationMs: 12,
        );

        self::assertTrue($report->succeeded());
    }

    /**
     * The array is what the CLI prints and what a deployment log keeps. The
     * derived verdict travels with it, so a reader does not have to re-apply
     * the rule.
     */
    #[Test]
    public function theSerialisedFormCarriesEverythingIncludingTheVerdict(): void
    {
        self::assertSame([
            'namespace' => 'cfb:prod:site:pages',
            'metadataCacheHealthy' => true,
            'localStoreWritable' => true,
            'prefetchedIdentifiers' => 12,
            'localHits' => 9,
            'blobMisses' => 3,
            'durationMs' => 47,
            'succeeded' => true,
        ], $this->report()->toArray());
    }

    #[Test]
    public function aDegradedRunSaysSoInItsSerialisedForm(): void
    {
        $array = $this->report(localWritable: false)->toArray();

        self::assertFalse($array['succeeded']);
        self::assertFalse($array['localStoreWritable']);
        self::assertTrue($array['metadataCacheHealthy'], 'the two flags are reported separately');
    }
}
