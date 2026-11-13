<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Cache;

use Moselwal\Typo3ClusterCache\Domain\Contract\AtomicMetadataGenerationPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataGenerationPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;
use Moselwal\Typo3ClusterCache\Domain\Enum\AtomicSetOutcome;
use Moselwal\Typo3ClusterCache\Domain\Enum\CacheState;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Keeps the store-wide generation current on every mutation and, when a
 * {@see CrossRequestMetadataMemo} is given, serves metadata from that
 * process-wide memo for as long as the generation has not changed.
 *
 * Writing (always, memo or not) takes one of two forms. Both keep the one
 * invariant readers rely on: **no mutation completes without the
 * generation taking a value it never had before — or holding a dirty
 * marker.**
 *
 * A. Three steps, for every mutation on every store — set, remove, flush,
 *    flushByTag(s), collectGarbage:
 *   1. write a fresh "dirty" generation (`dirty-<random>`); if that fails,
 *      the mutation is not attempted and the error propagates,
 *   2. the mutation itself,
 *   3. publish: unless the generation is another writer's dirty marker
 *      (that writer is still mid-mutation and will publish itself), write
 *      a fresh clean generation (`<random>`). Failures here are logged and
 *      counted, not thrown: the generation then stays dirty, which is safe.
 *      On a store with {@see AtomicMetadataGenerationPort} the check and
 *      the write of step 3 are one step on the server; otherwise a read
 *      followed by a write.
 *
 * B. One step, for `set` on a store with {@see AtomicMetadataGenerationPort}:
 *    the entry and a fresh clean generation are written together,
 *    atomically. No dirty marker is needed because there is no moment at
 *    which the entry has changed and the generation has not: the step
 *    either happened as a whole or not at all, and an error propagates
 *    like a failed set. Two things can make it differ:
 *   - the generation is another writer's dirty marker: the entry is
 *     written, the marker is left untouched. That writer is in step A.2
 *     and publishes after it; replacing its marker with a clean value
 *     would leave a clean generation over its half-applied mutation if its
 *     publish then failed.
 *   - the store refuses because a writer that does not know its generation
 *     is active (a pod of an older release, see
 *     {@see AtomicGenerationMetadataCache}): nothing is written and the
 *     set runs as A.
 *
 * While a generation is dirty or absent, no pod uses its memo.
 *
 * Reading (memo given):
 * the first metadata access in a request reads the generation once. A
 * clean generation equal to the one the memo was filled under, with the
 * memo younger than {@see MAX_AGE_SECONDS}, lets the memo serve; a
 * different clean generation empties the memo and starts it under the new
 * value; a dirty, absent or unreadable one empties it and turns it off for
 * the request. Entries are only memoised after that check, so every entry
 * in a memo under generation G was read after G was observed; and G, being
 * random, was written exactly once. Every mutation is followed by — or, in
 * form B, happens together with — a write of a value other than G: its own
 * publish, the atomic step itself, or, when it defers or leaves a foreign
 * marker, the publish of the writer whose marker it saw, which comes later
 * still. So if a read at the start of a later request still returns G,
 * every mutation that had completed by then wrote its metadata no later
 * than G was written, and hence before any memoised entry was read. That
 * is the cross-pod guarantee: a change that completed on pod B is seen by
 * pod A at A's next request — the same promise the per-entry reads gave.
 *
 * Entries are honoured only until their own expiry; `broken` markers are
 * never memoised (the store keeps them for a shorter TTL than their
 * lifetime says). The process's own mutations empty the memo and switch it
 * off for the rest of the request; the {@see RequestScopedMetadataCache} on
 * top keeps read-your-writes within the request.
 *
 * Without a memo (option off), the decorator only maintains the generation,
 * which costs nothing on reads and, per mutation, nothing extra for a set
 * in form B, two small operations in form A with the capability and three
 * without. Running every pod with a version that maintains the generation
 * first is what makes switching the memo on safe (see README,
 * "Cross-request metadata memo").
 */
final class GenerationGuardedMetadataCache implements MetadataCachePort
{
    public const string DIRTY_PREFIX = 'dirty-';

    /**
     * Backstop: a memo is refilled at least this often even if the
     * generation never changed. Bounds the damage of the one case the
     * protocol cannot rule out — two pods mutating concurrently while the
     * final generation write of one of them fails — to minutes.
     */
    public const int MAX_AGE_SECONDS = 300;

    /**
     * The store's one-step capability, when it has one. Only taken from
     * the object that is both the entry store and the generation store: a
     * set through it must reach the same entries `$inner` reads.
     */
    private readonly ?AtomicMetadataGenerationPort $atomic;

    /**
     * @param array<string, string> $labels       Metric labels of the owning cache
     * @param object|null           $requestScope Object that lives no longer than one
     *                                            request (the metadata cache frontend, which TYPO3 creates with
     *                                            each CacheManager). All decorators sharing it share one
     *                                            generation read. Null: this decorator instance is the scope.
     */
    public function __construct(
        private readonly MetadataCachePort $inner,
        private readonly MetadataGenerationPort $generation,
        private readonly ClockPort $clock,
        private readonly MetricsPort $metrics,
        private readonly array $labels = [],
        private readonly ?CrossRequestMetadataMemo $memo = null,
        private readonly ?object $requestScope = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->atomic = $inner instanceof AtomicMetadataGenerationPort && $inner === $generation ? $inner : null;
    }

    /**
     * Whether a set is one step on this store (form B of the class comment).
     */
    public function isAtomic(): bool
    {
        return null !== $this->atomic;
    }

    public function isMemoEnabled(): bool
    {
        return null !== $this->memo;
    }

    public function get(CacheIdentifier $identifier): ?CacheMetadata
    {
        $memo = $this->memo;
        if (null === $memo || !$this->memoServesThisRequest($memo)) {
            return $this->inner->get($identifier);
        }

        $key = $identifier->value;
        if ($memo->has($key)) {
            $metadata = $memo->get($key);
            // An entry past its own expiry is read again (and replaced, or
            // left to be read again next time if the store has nothing
            // memoisable either).
            if (null === $metadata || !$metadata->lifetime->isExpired($this->clock->now())) {
                $this->metrics->counter('metadata_memo_hit_total', $this->labels);

                return $metadata;
            }
        }

        $this->metrics->counter('metadata_memo_miss_total', $this->labels);
        $metadata = $this->inner->get($identifier);
        if ($this->isMemoisable($metadata) && !$memo->put($key, $metadata)) {
            $this->metrics->counter('metadata_memo_full_total', $this->labels);
        }

        return $metadata;
    }

    public function set(CacheIdentifier $identifier, CacheMetadata $metadata, array $tags, int $ttlSeconds): void
    {
        if (null !== $this->atomic) {
            // As in mutate(): our own memo is about to be outdated.
            $this->memo?->invalidate();
            $outcome = $this->atomic->setWithGeneration($identifier, $metadata, $tags, $ttlSeconds, self::randomToken());
            if (AtomicSetOutcome::Refused !== $outcome) {
                $this->metrics->counter('metadata_generation_publish_total', $this->labels + [
                    'result' => AtomicSetOutcome::Published === $outcome ? 'published' : 'deferred',
                ]);

                return;
            }
            // A writer that only knows the legacy generation is active:
            // nothing was written, take the steps that writer understands.
            $this->metrics->counter('metadata_generation_atomic_refused_total', $this->labels);
        }
        $this->mutate(fn() => $this->inner->set($identifier, $metadata, $tags, $ttlSeconds));
    }

    public function remove(CacheIdentifier $identifier): bool
    {
        return $this->mutate(fn(): bool => $this->inner->remove($identifier));
    }

    public function flush(): void
    {
        $this->mutate(fn() => $this->inner->flush());
    }

    public function flushByTag(string $tag): void
    {
        $this->mutate(fn() => $this->inner->flushByTag($tag));
    }

    public function flushByTags(array $tags): void
    {
        $this->mutate(fn() => $this->inner->flushByTags($tags));
    }

    public function findIdentifiersByTag(string $tag): array
    {
        return $this->inner->findIdentifiersByTag($tag);
    }

    public function collectGarbage(): void
    {
        $this->mutate(fn() => $this->inner->collectGarbage());
    }

    /**
     * Performs the generation check once per request and reports whether
     * the memo may serve it.
     */
    private function memoServesThisRequest(CrossRequestMetadataMemo $memo): bool
    {
        $scope = $this->requestScope ?? $this;
        if ($memo->isCheckedFor($scope)) {
            return $memo->isUsableFor($scope);
        }

        $now = $this->clock->now();
        try {
            $current = $this->generation->readGeneration();
        } catch (\Throwable $e) {
            $this->logger->warning('ClusterFileBackend: reading the metadata generation failed; memo bypassed', [
                'exceptionClass' => $e::class,
                'exceptionMessage' => $e->getMessage(),
            ]);
            $current = null;
        }

        if (null === $current || str_starts_with($current, self::DIRTY_PREFIX)) {
            $memo->reset(null, $now);
            $result = 'bypass';
        } elseif ($current === $memo->generation() && $now - $memo->filledAt() < self::MAX_AGE_SECONDS) {
            $result = 'reuse';
        } else {
            $memo->reset($current, $now);
            $result = 'reset';
        }
        $usable = 'bypass' !== $result;
        $memo->markChecked($scope, $usable);
        $this->metrics->counter('metadata_memo_check_total', $this->labels + ['result' => $result]);

        return $usable;
    }

    private function isMemoisable(?CacheMetadata $metadata): bool
    {
        if (null === $metadata) {
            return true;
        }

        return CacheState::Valid === $metadata->state
            && !$metadata->lifetime->isExpired($this->clock->now());
    }

    /**
     * @template T
     *
     * @param \Closure(): T $mutation
     *
     * @return T
     */
    private function mutate(\Closure $mutation): mixed
    {
        // Our own memo is about to be outdated; empty it and stop serving
        // from it for the rest of this request.
        $this->memo?->invalidate();

        $dirty = self::DIRTY_PREFIX . self::randomToken();
        // Throws if the store is unreachable: then the mutation is not
        // attempted either, and no pod can be left with a memo that misses
        // it.
        $this->generation->writeGeneration($dirty);
        try {
            return $mutation();
        } finally {
            $this->publish($dirty);
        }
    }

    /**
     * Replaces the dirty marker of a finished mutation with a clean
     * generation — unless another writer's dirty marker has replaced ours in
     * the meantime: that writer is still mutating, readers bypass their
     * memos until it publishes.
     */
    private function publish(string $dirty): void
    {
        try {
            if (null !== $this->atomic) {
                $published = $this->atomic->publishGeneration($dirty, self::randomToken());
            } else {
                $current = $this->generation->readGeneration();
                $published = null === $current || $current === $dirty || !str_starts_with($current, self::DIRTY_PREFIX);
                if ($published) {
                    $this->generation->writeGeneration(self::randomToken());
                }
            }
            $this->metrics->counter('metadata_generation_publish_total', $this->labels + ['result' => $published ? 'published' : 'deferred']);
        } catch (\Throwable $e) {
            // The generation stays dirty: every pod bypasses its memo until
            // the next mutation publishes. Safe, only slower.
            $this->logger->warning('ClusterFileBackend: publishing the metadata generation failed; memos stay bypassed until the next mutation', [
                'exceptionClass' => $e::class,
                'exceptionMessage' => $e->getMessage(),
            ]);
            $this->metrics->counter('metadata_generation_publish_total', $this->labels + ['result' => 'error']);
        }
    }

    private static function randomToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
