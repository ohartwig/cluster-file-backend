<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Functional;

use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend;
use Moselwal\Typo3ClusterCache\Infrastructure\LocalStore\VerifiedPayloadFingerprints;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A code cache configured the way an installation configures it, with
 * `crossRequestPhpVerification` on: the option reaches the backend through
 * the option schema, a verified payload is served again without a re-hash
 * once its ctime has aged, and a tampered payload is refused on the next
 * require.
 */
final class CrossRequestPhpVerificationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['moselwal/cluster-file-backend'];

    protected function setUp(): void
    {
        $this->configurationToUseInTestInstance = [
            'SYS' => [
                'caching' => [
                    'cacheConfigurations' => [
                        'cluster_meta' => [
                            'backend' => Typo3DatabaseBackend::class,
                            'options' => ['defaultLifetime' => 0],
                        ],
                        'cfb_code' => [
                            'frontend' => PhpFrontend::class,
                            'backend' => ClusterFileBackend::class,
                            'options' => [
                                'localPath' => '/tmp/cfb-functional-' . getmypid() . '/cfb_code',
                                'metadataCacheIdentifier' => 'cluster_meta',
                                'namespace' => ['environment' => 'testing', 'instance' => 'functional'],
                                'crossRequestPhpVerification' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];
        VerifiedPayloadFingerprints::resetProcessWide();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        VerifiedPayloadFingerprints::resetProcessWide();
        parent::tearDown();
    }

    private function codeCache(): PhpFrontend
    {
        $cache = $this->get(CacheManager::class)->getCache('cfb_code');
        self::assertInstanceOf(PhpFrontend::class, $cache);

        return $cache;
    }

    private function payloadFile(): string
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('/tmp/cfb-functional-' . getmypid() . '/cfb_code', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        self::assertCount(1, $files);

        return $files[0];
    }

    #[Test]
    public function aVerifiedPayloadIsTrustedUntilItChanges(): void
    {
        $cache = $this->codeCache();
        $backend = $cache->getBackend();
        self::assertInstanceOf(ClusterFileBackend::class, $backend);
        self::assertTrue($backend->isCrossRequestPhpVerificationEnabled());

        $cache->set('setup', "return ['page' => 'Start'];");
        $payload = $this->payloadFile();
        clearstatcache();
        $ctime = (int) filectime($payload);
        // Settled: SETTLE_SECONDS after the write, see VerifiedPayloadFingerprints.
        while (time() < $ctime + VerifiedPayloadFingerprints::SETTLE_SECONDS) {
            usleep(50_000);
        }

        self::assertSame(['page' => 'Start'], $cache->require('setup'));
        self::assertSame(1, VerifiedPayloadFingerprints::processWide()->count(), 'the verified file is recorded');
        self::assertSame(['page' => 'Start'], $cache->require('setup'));

        $handle = fopen($payload, 'r+');
        self::assertNotFalse($handle);
        fseek($handle, -10, \SEEK_END);
        fwrite($handle, "'Owned'];\n");
        fclose($handle);

        self::assertFalse($cache->require('setup'), 'a payload changed after its verification is not executed');
    }
}
