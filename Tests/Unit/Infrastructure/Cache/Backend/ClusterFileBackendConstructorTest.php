<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\Cache\Backend;

use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Exception\InvalidCacheException;

/**
 * Regression: the previous `parent::__construct($options)` threw
 * `\InvalidArgumentException` for every TYPO3 cache configuration because
 * AbstractBackend expects a setter for each option key — and
 * ClusterFileBackend has none.
 *
 * This test instantiates the backend with the typical options layout from
 * `$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']`.
 * If it fails, TYPO3 would tear the backend apart in production — even
 * before the first `set()` runs.
 *
 * Note: the constructor instantiates default services for `ClockPort` and
 * `MetricsPort` via `GeneralUtility::makeInstance` and fetches a TYPO3
 * cache frontend via `CacheManager::getCache(...)`. In this unit-test
 * environment without a TYPO3 bootstrap that first service lookup would
 * fail on an unloaded class — so we catch \Throwable and verify only the
 * pre-throw point: the `OptionsValidator` MUST raise schema violations
 * before anything else is attempted.
 */
#[CoversClass(ClusterFileBackend::class)]
final class ClusterFileBackendConstructorTest extends TestCase
{
    public function testMissingLocalPathThrowsInvalidCacheException(): void
    {
        $this->expectException(InvalidCacheException::class);
        new ClusterFileBackend([
            // 'localPath' is missing
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'prod', 'instance' => 'site'],
        ]);
    }

    public function testMissingMetadataCacheIdentifierThrowsInvalidCacheException(): void
    {
        $this->expectException(InvalidCacheException::class);
        new ClusterFileBackend([
            'localPath' => '/tmp/cfb-test',
            'namespace' => ['environment' => 'prod', 'instance' => 'site'],
        ]);
    }

    public function testUnknownOptionKeyRejected(): void
    {
        // Specific regression test: TYPO3 AbstractBackend would try to call
        // setters here that do not exist. Our own validator MUST catch this
        // up-front.
        $this->expectException(InvalidCacheException::class);
        new ClusterFileBackend([
            'localPath' => '/tmp/cfb-test',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'prod', 'instance' => 'site'],
            'thisOptionDoesNotExist' => 'hello',
        ]);
    }

    public function testInvalidEnvironmentRejected(): void
    {
        $this->expectException(InvalidCacheException::class);
        new ClusterFileBackend([
            'localPath' => '/tmp/cfb-test',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'unknown', 'instance' => 'site'],
        ]);
    }
}
