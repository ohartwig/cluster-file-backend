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
        OptionsValidator::forgetValidated();
        $this->validator = new OptionsValidator();
    }

    protected function tearDown(): void
    {
        OptionsValidator::forgetValidated();
    }

    public function testTheSameOptionsAreValidatedOnceAndGiveTheSameResult(): void
    {
        $options = [
            'localPath' => '/app/var/cache/cluster/pages',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'prod', 'instance' => 'website-a'],
        ];
        $first = $this->validator->validateAndApplyDefaults($options);

        $again = new OptionsValidator()->validateAndApplyDefaults($options);

        self::assertSame($first, $again);
        $other = $this->validator->validateAndApplyDefaults(['compression' => 'gzip'] + $options);
        self::assertSame('gzip', $other['compression'], 'another option set is validated on its own');
    }

    public function testAnInvalidSetIsRejectedEveryTime(): void
    {
        $invalid = ['localPath' => '/x', 'metadataCacheIdentifier' => 'm', 'namespace' => ['environment' => 'prod', 'instance' => 'a'], 'crossRequestMetadataMemo' => 'yes'];
        $rejected = 0;
        foreach ([1, 2] as $attempt) {
            try {
                $this->validator->validateAndApplyDefaults($invalid);
            } catch (InvalidCacheException) {
                ++$rejected;
            }
        }

        self::assertSame(2, $rejected, 'an invalid set is not remembered');
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
        self::assertFalse($normalized['crossRequestMetadataMemo'], 'opt-in: safe only once every pod maintains the generation');
    }

    public function testCrossRequestMetadataMemoMustBeABoolean(): void
    {
        $this->expectException(InvalidCacheException::class);

        $this->validator->validateAndApplyDefaults([
            'localPath' => '/app/var/cache/cluster/pages',
            'metadataCacheIdentifier' => 'cluster_meta',
            'namespace' => ['environment' => 'prod', 'instance' => 'website-a'],
            'crossRequestMetadataMemo' => 'yes',
        ]);
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
