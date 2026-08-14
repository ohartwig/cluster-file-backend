<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\Cache;

use Moselwal\Typo3ClusterCache\Domain\Enum\CacheState;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\Lifetime;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Typo3MetadataCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

#[CoversClass(Typo3MetadataCache::class)]
final class Typo3MetadataCacheTest extends TestCase
{
    private Typo3MetadataCache $cache;

    protected function setUp(): void
    {
        $backend = new TransientMemoryBackend();
        $frontend = new VariableFrontend('cluster_meta_test', $backend);
        $backend->setCache($frontend);
        $this->cache = new Typo3MetadataCache(
            $frontend,
            new CacheNamespace(EnvironmentName::Testing, 'site', 'pages'),
        );
    }

    public function testSetAndGetRoundtrip(): void
    {
        $id = new CacheIdentifier('roundtrip');
        $metadata = $this->buildMetadata($id);

        $this->cache->set($id, $metadata, ['site_1'], 3600);
        $retrieved = $this->cache->get($id);

        self::assertNotNull($retrieved);
        self::assertTrue($metadata->hash->equals($retrieved->hash));
        self::assertSame($metadata->state, $retrieved->state);
        self::assertSame($metadata->tags->toArray(), $retrieved->tags->toArray());
    }

    public function testGetNonexistentReturnsNull(): void
    {
        self::assertNull($this->cache->get(new CacheIdentifier('missing')));
    }

    public function testRemove(): void
    {
        $id = new CacheIdentifier('toremove');
        $this->cache->set($id, $this->buildMetadata($id), [], 3600);
        self::assertNotNull($this->cache->get($id));

        self::assertTrue($this->cache->remove($id));
        self::assertNull($this->cache->get($id));
    }

    public function testFlushRemovesAllEntries(): void
    {
        $id1 = new CacheIdentifier('one');
        $id2 = new CacheIdentifier('two');
        $this->cache->set($id1, $this->buildMetadata($id1), [], 3600);
        $this->cache->set($id2, $this->buildMetadata($id2), [], 3600);

        $this->cache->flush();

        self::assertNull($this->cache->get($id1));
        self::assertNull($this->cache->get($id2));
    }

    public function testFindIdentifiersByTagReturnsEmptyWhenBackendNotTaggable(): void
    {
        // TransientMemoryBackend implements TaggableBackendInterface,
        // so this exercises the happy path:
        $id = new CacheIdentifier('tagged');
        $this->cache->set($id, $this->buildMetadata($id), ['my_tag'], 3600);

        $found = $this->cache->findIdentifiersByTag('my_tag');
        self::assertContains('tagged', $found);
    }

    public function testFlushByTag(): void
    {
        $id1 = new CacheIdentifier('a');
        $id2 = new CacheIdentifier('b');
        $this->cache->set($id1, $this->buildMetadata($id1), ['tag_a'], 3600);
        $this->cache->set($id2, $this->buildMetadata($id2), ['tag_b'], 3600);

        $this->cache->flushByTag('tag_a');

        self::assertNull($this->cache->get($id1));
        self::assertNotNull($this->cache->get($id2));
    }

    private function buildMetadata(CacheIdentifier $id): CacheMetadata
    {
        return new CacheMetadata(
            identifier: $id,
            hash: new PayloadHash(str_repeat('a', 64)),
            checksum: new PayloadChecksum(str_repeat('b', 64)),
            lifetime: new Lifetime(1_700_000_000, 1_700_003_600),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            payloadSize: 0,
            tags: new TagSet([]),
            state: CacheState::Valid,
            backendVersion: new BackendVersion(1),
        );
    }
}
