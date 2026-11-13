<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Support;

use Moselwal\KeyValueStore\Cache\Backend\GenerationBackendInterface;
use Moselwal\KeyValueStore\Cache\Backend\GenerationResult;
use TYPO3\CMS\Core\Cache\Backend\TaggableBackendInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * In-memory TYPO3 cache backend that keeps generations the way
 * moselwal/keyvalue-store's KeyValueBackend does: raw, outside the entry
 * key space, with the same rules for dirty markers and the guard entry.
 *
 * Every public call that would be one round trip to the server counts as
 * one in `$roundTrips`; `$log` names them in order.
 */
final class InMemoryGenerationBackend implements TaggableBackendInterface, GenerationBackendInterface
{
    /** @var array<string, string> */
    public array $entries = [];

    /** @var array<string, list<string>> */
    public array $entryTags = [];

    /** @var array<string, string> */
    public array $generations = [];

    public int $roundTrips = 0;

    /** @var list<string> */
    public array $log = [];

    /** Number of upcoming generation calls that fail (-1: all of them). */
    public int $failGenerationCalls = 0;

    /** @var array{name: string, generation: string, guard: string|null}|null */
    private ?array $pending = null;

    private ?GenerationResult $pendingResult = null;

    public function setCache(FrontendInterface $cache): void {}

    /** @param array<mixed> $tags */
    public function set(string $entryIdentifier, string $data, array $tags = [], ?int $lifetime = null): void
    {
        $pending = $this->pending;
        $this->pending = null;
        if (null !== $pending) {
            $this->roundTrip('setWithGeneration');
            $this->failIfAsked();
            $current = $this->generations[$pending['name']] ?? null;
            if (null !== $pending['guard'] && isset($this->entries[$pending['guard']])) {
                $this->pendingResult = new GenerationResult($current, false, true);

                return;
            }
            $written = null === $current || !str_starts_with($current, self::DIRTY_PREFIX);
            if ($written) {
                $this->generations[$pending['name']] = $current = $pending['generation'];
            }
            $this->pendingResult = new GenerationResult($current, $written, false);
        } else {
            $this->roundTrip('set');
        }
        $this->entries[$entryIdentifier] = $data;
        $this->entryTags[$entryIdentifier] = array_values($tags);
    }

    public function get(string $entryIdentifier): mixed
    {
        $this->roundTrip('get');

        return $this->entries[$entryIdentifier] ?? false;
    }

    public function has(string $entryIdentifier): bool
    {
        $this->roundTrip('has');

        return isset($this->entries[$entryIdentifier]);
    }

    public function remove(string $entryIdentifier): bool
    {
        $this->roundTrip('remove');
        $existed = isset($this->entries[$entryIdentifier]);
        unset($this->entries[$entryIdentifier], $this->entryTags[$entryIdentifier]);

        return $existed;
    }

    public function flush(): void
    {
        $this->roundTrip('flush');
        $this->entries = [];
        $this->entryTags = [];
        $this->generations = [];
    }

    public function collectGarbage(): void
    {
        $this->roundTrip('collectGarbage');
    }

    public function flushByTag(string $tag): void
    {
        $this->flushByTags([$tag]);
    }

    /** @param array<mixed> $tags */
    public function flushByTags(array $tags): void
    {
        $this->roundTrip('flushByTags');
        foreach ($this->entryTags as $identifier => $entryTags) {
            if ([] !== array_intersect($tags, $entryTags)) {
                unset($this->entries[$identifier], $this->entryTags[$identifier]);
            }
        }
    }

    /** @return list<string> */
    public function findIdentifiersByTag(string $tag): array
    {
        $this->roundTrip('findIdentifiersByTag');
        $found = [];
        foreach ($this->entryTags as $identifier => $entryTags) {
            if (\in_array($tag, $entryTags, true)) {
                $found[] = $identifier;
            }
        }

        return $found;
    }

    public function readGeneration(string $name, ?string $guardEntryIdentifier = null): GenerationResult
    {
        $this->roundTrip('readGeneration');
        $this->failIfAsked();

        return new GenerationResult($this->generations[$name] ?? null, false, $this->guardExists($guardEntryIdentifier));
    }

    public function writeGeneration(string $name, string $generation, ?string $guardEntryIdentifier = null): GenerationResult
    {
        $this->roundTrip('writeGeneration');
        $this->failIfAsked();
        $this->generations[$name] = $generation;

        return new GenerationResult($generation, true, $this->guardExists($guardEntryIdentifier));
    }

    public function publishGeneration(string $name, string $generation, string $ownMarker, ?string $guardEntryIdentifier = null): GenerationResult
    {
        $this->roundTrip('publishGeneration');
        $this->failIfAsked();
        $current = $this->generations[$name] ?? null;
        $guard = $this->guardExists($guardEntryIdentifier);
        if (null !== $current && $current !== $ownMarker && str_starts_with($current, self::DIRTY_PREFIX)) {
            return new GenerationResult($current, false, $guard);
        }
        $this->generations[$name] = $generation;

        return new GenerationResult($generation, true, $guard);
    }

    public function setWithGeneration(string $name, string $generation, \Closure $write, ?string $guardEntryIdentifier = null): GenerationResult
    {
        $this->pending = ['name' => $name, 'generation' => $generation, 'guard' => $guardEntryIdentifier];
        $this->pendingResult = null;
        try {
            $write();
            $result = $this->pendingResult;
        } finally {
            $this->pending = null;
            $this->pendingResult = null;
        }
        if (null === $result) {
            throw new \LogicException('no set() was issued', 1794300001);
        }

        return $result;
    }

    private function guardExists(?string $guardEntryIdentifier): bool
    {
        return null !== $guardEntryIdentifier && isset($this->entries[$guardEntryIdentifier]);
    }

    private function roundTrip(string $what): void
    {
        ++$this->roundTrips;
        $this->log[] = $what;
    }

    private function failIfAsked(): void
    {
        if (0 === $this->failGenerationCalls) {
            return;
        }
        if ($this->failGenerationCalls > 0) {
            --$this->failGenerationCalls;
        }
        throw new \RuntimeException('generation call failed', 1794300002);
    }
}
