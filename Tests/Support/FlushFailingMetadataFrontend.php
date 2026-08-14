<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Support;

use TYPO3\CMS\Core\Cache\Backend\BackendInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Metadata frontend that stores like FakeMetadataFrontend but fails every
 * flush.
 *
 * FakeMetadataFrontend's `offline` switch deliberately leaves the flushes
 * working, because they are the one group the backend must keep answering for
 * even when the metadata cache is gone. Testing that swallow needs the opposite
 * arrangement, and FakeMetadataFrontend is final, so this is a sibling rather
 * than a subclass.
 *
 * `$entries` stays public so a test can empty it mid-flight: after a failed
 * flush, that is what separates "the entry survived because the flush did not
 * happen" from "the entry survived because a stale L1 handed it out".
 */
final class FlushFailingMetadataFrontend implements FrontendInterface
{
    /** @var array<string, mixed> */
    public array $entries = [];

    public function __construct(private readonly string $identifier = 'cluster_meta') {}

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getBackend(): BackendInterface
    {
        throw new \RuntimeException('the backend is not reached through this double', 1785350005);
    }

    /** @param array<mixed> $tags */
    public function set($entryIdentifier, $data, array $tags = [], $lifetime = null): void
    {
        $this->entries[$entryIdentifier] = $data;
    }

    public function get($entryIdentifier): mixed
    {
        return $this->entries[$entryIdentifier] ?? false;
    }

    public function has($entryIdentifier): bool
    {
        return isset($this->entries[$entryIdentifier]);
    }

    public function remove($entryIdentifier): bool
    {
        unset($this->entries[$entryIdentifier]);

        return true;
    }

    public function flush(): void
    {
        throw new \RuntimeException('metadata flush unavailable', 1785350002);
    }

    public function flushByTag($tag): void
    {
        throw new \RuntimeException('metadata flush unavailable', 1785350003);
    }

    /** @param array<mixed> $tags */
    public function flushByTags(array $tags): void
    {
        throw new \RuntimeException('metadata flush unavailable', 1785350004);
    }

    public function collectGarbage(): void {}

    public function isValidEntryIdentifier($identifier): bool
    {
        return true;
    }

    public function isValidTag($tag): bool
    {
        return true;
    }
}
