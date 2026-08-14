<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Application\Invalidate;

use Moselwal\Typo3ClusterCache\Application\Invalidate\RemoveCacheEntry;
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
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoveCacheEntry::class)]
final class RemoveCacheEntryTest extends TestCase
{
    public function testRemoveExistingEntryReturnsTrue(): void
    {
        $cache = new FakeMetadataCache();
        $id = new CacheIdentifier('existing');
        $cache->set($id, $this->metadata($id), [], 60);

        self::assertTrue(new RemoveCacheEntry($cache)->execute($id));
        self::assertNull($cache->get($id));
    }

    public function testRemoveMissingEntryReturnsFalse(): void
    {
        $cache = new FakeMetadataCache();
        self::assertFalse(
            new RemoveCacheEntry($cache)->execute(new CacheIdentifier('missing')),
        );
    }

    private function metadata(CacheIdentifier $id): CacheMetadata
    {
        return new CacheMetadata(
            identifier: $id,
            hash: new PayloadHash(str_repeat('a', 64)),
            checksum: new PayloadChecksum(str_repeat('b', 64)),
            lifetime: new Lifetime(1_700_000_000, 1_700_003_600),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            payloadSize: 0,
            tags: new TagSet(),
            state: CacheState::Valid,
            backendVersion: new BackendVersion(1),
        );
    }
}
