<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\Cache;

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
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\CrossRequestMetadataMemo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CrossRequestMetadataMemo::class)]
final class CrossRequestMetadataMemoTest extends TestCase
{
    protected function tearDown(): void
    {
        CrossRequestMetadataMemo::resetRegistry();
    }

    private function metadata(string $id): CacheMetadata
    {
        return new CacheMetadata(
            identifier: new CacheIdentifier($id),
            hash: new PayloadHash(str_repeat('a', 64)),
            checksum: new PayloadChecksum(str_repeat('b', 64)),
            lifetime: new Lifetime(1, 2),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            payloadSize: 1,
            tags: new TagSet([]),
            state: CacheState::Valid,
            backendVersion: new BackendVersion(1),
        );
    }

    #[Test]
    public function thereIsOneMemoPerMetadataStoreAndProcess(): void
    {
        $memo = CrossRequestMetadataMemo::forStore('cluster_meta');

        self::assertSame($memo, CrossRequestMetadataMemo::forStore('cluster_meta'));
        self::assertNotSame($memo, CrossRequestMetadataMemo::forStore('other_meta'));

        CrossRequestMetadataMemo::resetRegistry();
        self::assertNotSame($memo, CrossRequestMetadataMemo::forStore('cluster_meta'));
    }

    #[Test]
    public function itNeedsRoomForAtLeastOneEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CrossRequestMetadataMemo(0);
    }

    #[Test]
    public function oneEntryIsEnough(): void
    {
        $memo = new CrossRequestMetadataMemo(1);

        self::assertTrue($memo->put('a', null));
        self::assertFalse($memo->put('b', null));
    }

    #[Test]
    public function itHoldsHitsAndMissesUpToItsCap(): void
    {
        $memo = new CrossRequestMetadataMemo(2);
        $a = $this->metadata('a');

        self::assertTrue($memo->put('a', $a));
        self::assertTrue($memo->put('absent', null));
        self::assertFalse($memo->put('c', $this->metadata('c')), 'full');
        self::assertTrue($memo->put('a', $a), 'replacing an entry needs no room');

        self::assertSame(2, $memo->count());
        self::assertSame($a, $memo->get('a'));
        self::assertTrue($memo->has('absent'));
        self::assertNull($memo->get('absent'));
        self::assertFalse($memo->has('c'));

        $memo->forget('a');
        self::assertFalse($memo->has('a'));
        self::assertTrue($memo->put('c', null), 'room again');
    }

    #[Test]
    public function aResetStartsOverUnderTheGivenGeneration(): void
    {
        $memo = new CrossRequestMetadataMemo();
        $memo->put('a', null);

        $memo->reset('g1', 42);

        self::assertSame(0, $memo->count());
        self::assertSame('g1', $memo->generation());
        self::assertSame(42, $memo->filledAt());
    }

    #[Test]
    public function itRemembersWhichRequestsItWasCheckedFor(): void
    {
        $memo = new CrossRequestMetadataMemo();
        $request = new \stdClass();
        $earlyCacheManagerOfTheSameRequest = new \stdClass();

        self::assertFalse($memo->isCheckedFor($request));
        self::assertFalse($memo->isUsableFor($request));

        $memo->markChecked($request, true);
        $memo->markChecked($earlyCacheManagerOfTheSameRequest, false);
        self::assertTrue($memo->isCheckedFor($request), 'a second scope does not displace the first');
        self::assertTrue($memo->isUsableFor($request));
        self::assertTrue($memo->isCheckedFor($earlyCacheManagerOfTheSameRequest));
        self::assertFalse($memo->isUsableFor($earlyCacheManagerOfTheSameRequest));
        self::assertFalse($memo->isCheckedFor(new \stdClass()));
    }

    #[Test]
    public function aFinishedRequestDoesNotPassForTheNextOne(): void
    {
        $memo = new CrossRequestMetadataMemo();
        $request = new \stdClass();
        $memo->markChecked($request, true);
        unset($request);

        // A new object may reuse the old one's id; the weak map does not.
        self::assertFalse($memo->isCheckedFor(new \stdClass()));
    }

    #[Test]
    public function invalidatingDropsEntriesGenerationAndTheCurrentRequest(): void
    {
        $memo = new CrossRequestMetadataMemo();
        $memo->reset('g1', 1);
        $memo->put('a', null);
        $first = new \stdClass();
        $second = new \stdClass();
        $memo->markChecked($first, true);
        $memo->markChecked($second, true);

        $memo->invalidate();

        self::assertSame(0, $memo->count());
        self::assertNull($memo->generation());
        self::assertTrue($memo->isCheckedFor($first), 'no second check in the same request');
        self::assertFalse($memo->isUsableFor($first));
        self::assertFalse($memo->isUsableFor($second));
    }
}
