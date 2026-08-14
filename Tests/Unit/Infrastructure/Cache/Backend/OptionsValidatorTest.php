<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\Cache\Backend;

use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\OptionsValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Exception\InvalidCacheException;

#[CoversClass(OptionsValidator::class)]
final class OptionsValidatorTest extends TestCase
{
    private OptionsValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new OptionsValidator();
    }

    public function testMinimalValidOptionsApplyDefaults(): void
    {
        $normalized = $this->validator->validateAndApplyDefaults([
            'localPath' => '/app/var/cache/cluster/pages',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => [
                'environment' => 'prod',
                'instance' => 'website-a',
            ],
        ]);

        self::assertSame('zstd', $normalized['compression']);
        self::assertSame('igbinary', $normalized['serializer']);
        self::assertSame(3600, $normalized['defaultLifetimeSeconds']);
        self::assertSame(10_485_760, $normalized['maxPayloadBytes']);
    }

    public function testMissingLocalPathThrows(): void
    {
        $this->expectException(InvalidCacheException::class);
        $this->validator->validateAndApplyDefaults([
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'prod', 'instance' => 'site'],
        ]);
    }

    public function testMissingMetadataCacheIdentifierThrows(): void
    {
        $this->expectException(InvalidCacheException::class);
        $this->validator->validateAndApplyDefaults([
            'localPath' => '/app/var/cache/cluster/pages',
            'namespace' => ['environment' => 'prod', 'instance' => 'site'],
        ]);
    }

    public function testInvalidEnvironmentThrows(): void
    {
        $this->expectException(InvalidCacheException::class);
        $this->validator->validateAndApplyDefaults([
            'localPath' => '/app/var/cache/cluster/pages',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'unknown', 'instance' => 'site'],
        ]);
    }

    public function testRelativeLocalPathIsRejected(): void
    {
        $this->expectException(InvalidCacheException::class);
        $this->validator->validateAndApplyDefaults([
            'localPath' => 'relative/path',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'prod', 'instance' => 'site'],
        ]);
    }

    public function testUnknownOptionIsRejected(): void
    {
        $this->expectException(InvalidCacheException::class);
        $this->validator->validateAndApplyDefaults([
            'localPath' => '/app/var/cache/cluster/pages',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'prod', 'instance' => 'site'],
            'unknownOption' => 'foo',
        ]);
    }

    public function testInvalidCompressionAlgorithmIsRejected(): void
    {
        $this->expectException(InvalidCacheException::class);
        $this->validator->validateAndApplyDefaults([
            'localPath' => '/app/var/cache/cluster/pages',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'prod', 'instance' => 'site'],
            'compression' => 'lz4',
        ]);
    }

    public function testTooSmallMaxPayloadBytesIsRejected(): void
    {
        $this->expectException(InvalidCacheException::class);
        $this->validator->validateAndApplyDefaults([
            'localPath' => '/app/var/cache/cluster/pages',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'prod', 'instance' => 'site'],
            'maxPayloadBytes' => 100,
        ]);
    }
}
