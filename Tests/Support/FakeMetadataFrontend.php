<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Support;

use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Typo3MetadataCache;
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

    /**
     * Number of `get()` calls for metadata entries — each one is a round
     * trip in production. Reads of the generation key are counted apart.
     */
    public int $reads = 0;

    public int $generationReads = 0;

    public int $generationWrites = 0;

    /** @var array<string, array{tags: array<mixed>, lifetime: mixed}> tags and lifetime of the last write per key */
    public array $writeOptions = [];

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
        if (Typo3MetadataCache::GENERATION_KEY === $entryIdentifier) {
            ++$this->generationWrites;
        }
        $this->entries[$entryIdentifier] = $data;
        $this->writeOptions[$entryIdentifier] = ['tags' => $tags, 'lifetime' => $lifetime];
    }

    private function guard(): void
    {
        if ($this->offline) {
            throw new \RuntimeException('metadata cache offline', 1785350001);
        }
    }

    public function get($entryIdentifier): mixed
    {
        if (Typo3MetadataCache::GENERATION_KEY === $entryIdentifier) {
            ++$this->generationReads;
        } else {
            ++$this->reads;
        }
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
