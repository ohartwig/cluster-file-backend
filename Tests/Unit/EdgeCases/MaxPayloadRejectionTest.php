<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\EdgeCases;

use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\OptionsValidator;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Exception\InvalidCacheException;

/**
 * Spec edge case T165: very large payloads — the schema-validated
 * `maxPayloadBytes` defines the upper bound. Verified here at the config
 * level; the actual `set()` path check lives inside ClusterFileBackend and
 * is only testable with a TYPO3 bootstrap (functional test).
 */
final class MaxPayloadRejectionTest extends TestCase
{
    public function testTooSmallMaxPayloadBytesRejectedBySchema(): void
    {
        $this->expectException(InvalidCacheException::class);
        new OptionsValidator()->validateAndApplyDefaults([
            'localPath' => '/app/var/cache/cluster/pages',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'testing', 'instance' => 'site'],
            'maxPayloadBytes' => 512,
        ]);
    }

    public function testTooLargeMaxPayloadBytesRejectedBySchema(): void
    {
        $this->expectException(InvalidCacheException::class);
        new OptionsValidator()->validateAndApplyDefaults([
            'localPath' => '/app/var/cache/cluster/pages',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'testing', 'instance' => 'site'],
            'maxPayloadBytes' => 2_000_000_000,
        ]);
    }

    public function testValidMaxPayloadBytesPasses(): void
    {
        $normalized = new OptionsValidator()->validateAndApplyDefaults([
            'localPath' => '/app/var/cache/cluster/pages',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'testing', 'instance' => 'site'],
            'maxPayloadBytes' => 5_242_880,
        ]);
        self::assertSame(5_242_880, $normalized['maxPayloadBytes']);
    }
}
