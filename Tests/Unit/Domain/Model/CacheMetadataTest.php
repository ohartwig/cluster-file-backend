<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Enum\CacheState;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\Lifetime;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheMetadata::class)]
final class CacheMetadataTest extends TestCase
{
    public function testToKvPayloadAndFromKvPayloadRoundtrip(): void
    {
        $metadata = $this->buildMetadata();
        $kv = $metadata->toKvPayload();
        $restored = CacheMetadata::fromKvPayload($kv);

        self::assertTrue($metadata->identifier->equals($restored->identifier));
        self::assertTrue($metadata->hash->equals($restored->hash));
        self::assertTrue($metadata->checksum->equals($restored->checksum));
        self::assertSame($metadata->lifetime->createdAt, $restored->lifetime->createdAt);
        self::assertSame($metadata->lifetime->expiresAt, $restored->lifetime->expiresAt);
        self::assertSame($metadata->payloadSize, $restored->payloadSize);
        self::assertSame($metadata->tags->toArray(), $restored->tags->toArray());
        self::assertSame($metadata->state, $restored->state);
        self::assertSame($metadata->backendVersion->value, $restored->backendVersion->value);
    }

    public function testIsValidRequiresValidStateAndUnexpired(): void
    {
        $metadata = $this->buildMetadata();

        self::assertTrue($metadata->isValid(now: 1500));
        self::assertFalse($metadata->isValid(now: 9999));
    }

    public function testIsValidReturnsFalseForBrokenState(): void
    {
        $broken = new CacheMetadata(
            identifier: new CacheIdentifier('x'),
            hash: new PayloadHash(str_repeat('a', 64)),
            checksum: new PayloadChecksum(str_repeat('b', 64)),
            lifetime: new Lifetime(1000, 2000),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            payloadSize: 0,
            tags: new TagSet(),
            state: CacheState::Broken,
            backendVersion: new BackendVersion(1),
        );
        self::assertFalse($broken->isValid(1500));
    }

    public function testFromKvPayloadFailsOnMissingField(): void
    {
        $this->expectException(\RuntimeException::class);
        CacheMetadata::fromKvPayload(['identifier' => 'a']);
    }

    public function testFromKvPayloadFailsOnTypeMismatch(): void
    {
        // expiresAt as string instead of int — a corrupted metadata cache
        // entry must NOT propagate as a valid CacheMetadata.
        $this->expectException(\RuntimeException::class);
        $payload = $this->buildMetadata()->toKvPayload();
        $payload['expiresAt'] = 'not-an-int';
        CacheMetadata::fromKvPayload($payload);
    }

    public function testFromKvPayloadTolersLegacyGenerationField(): void
    {
        // v1.x stored a `generation` field that v2 no longer uses. The
        // parser must accept the extra key without error.
        $payload = $this->buildMetadata()->toKvPayload();
        $payload['generation'] = 7;
        $restored = CacheMetadata::fromKvPayload($payload);
        self::assertSame(4096, $restored->payloadSize);
    }

    private function buildMetadata(): CacheMetadata
    {
        return new CacheMetadata(
            identifier: new CacheIdentifier('page_42'),
            hash: new PayloadHash(str_repeat('a', 64)),
            checksum: new PayloadChecksum(str_repeat('b', 64)),
            lifetime: new Lifetime(1000, 2000),
            serializer: new SerializerName(SerializerName::IGBINARY, 'igbinary:3'),
            compression: CompressionName::zstd(),
            payloadSize: 4096,
            tags: new TagSet(['pages_42', 'site_1']),
            state: CacheState::Valid,
            backendVersion: new BackendVersion(1),
        );
    }
}
