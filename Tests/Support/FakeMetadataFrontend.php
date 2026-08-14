<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Support;

use TYPO3\CMS\Core\Cache\Backend\BackendInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * In-memory stand-in for the TYPO3 cache frontend the backend keeps its
 * metadata in.
 *
 * `throwOnWrite` reproduces a metadata-cache outage, which is the condition
 * under which flushes are expected to fail silently — the one case where the
 * backend deliberately hides an error from its caller.
 */
final class FakeMetadataFrontend implements FrontendInterface
{
    /** @var array<string, mixed> */
    public array $entries = [];

    public bool $offline = false;

    public function __construct(private readonly string $identifier = 'cluster_meta') {}

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getBackend(): BackendInterface
    {
        throw new \RuntimeException('the backend is not reached through this double', 1785350000);
    }

    /** @param array<mixed> $tags */
    public function set($entryIdentifier, $data, array $tags = [], $lifetime = null): void
    {
        $this->guard();
        $this->entries[$entryIdentifier] = $data;
    }

    private function guard(): void
    {
        if ($this->offline) {
            throw new \RuntimeException('metadata cache offline', 1785350001);
        }
    }

    public function get($entryIdentifier): mixed
    {
        $this->guard();

        return $this->entries[$entryIdentifier] ?? false;
    }

    public function has($entryIdentifier): bool
    {
        return isset($this->entries[$entryIdentifier]);
    }

    public function remove($entryIdentifier): bool
    {
        $this->guard();
        unset($this->entries[$entryIdentifier]);

        return true;
    }

    public function flush(): void
    {
        $this->entries = [];
    }

    public function flushByTag($tag): void
    {
        $this->entries = [];
    }

    public function flushByTags(array $tags): void
    {
        $this->entries = [];
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
