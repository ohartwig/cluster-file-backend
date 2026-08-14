<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Application\Read;

use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\CompressorPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\LocalPayloadStorePort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;
use Moselwal\Typo3ClusterCache\Domain\Enum\CacheState;
use Moselwal\Typo3ClusterCache\Domain\Exception\PayloadIntegrityException;
use Moselwal\Typo3ClusterCache\Domain\Exception\PayloadNotFoundException;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionAlgo;

final readonly class ReadCacheEntry
{
    /**
     * Lower TTL bound for the broken-state marker. Gives concurrent
     * readers a coherence window during which they share the "do not
     * touch this entry" signal — prevents thundering-herd re-reads of
     * the same corrupt blob across pods.
     */
    private const int BROKEN_STATE_MIN_TTL_SECONDS = 60;

    /**
     * Upper TTL bound for the broken-state marker. After this the entry
     * is eligible for re-population from a caller rebuild; we do not
     * want broken markers to linger indefinitely.
     */
    private const int BROKEN_STATE_MAX_TTL_SECONDS = 3600;

    /**
     * Default upper bound for the decompressed payload size when the
     * caller does not pass an explicit one. ClusterFileBackend always
     * passes its configured `maxPayloadBytes`; this default applies to
     * tests and ad-hoc usage.
     */
    private const int DEFAULT_MAX_DECOMPRESSED_BYTES = 256 * 1024 * 1024;

    /**
     * @param array<string, CompressorPort> $compressorsByAlgo Lookup from
     *                                                         CompressionAlgo->value to the codec that can decompress its
     *                                                         marker. Must contain every codec the writer might have used,
     *                                                         including NullCompressor for the skip-compress path.
     */
    public function __construct(
        private MetadataCachePort $metadataCache,
        private LocalPayloadStorePort $localStore,
        private array $compressorsByAlgo,
        private ClockPort $clock,
        private MetricsPort $metrics,
        private int $maxDecompressedBytes = self::DEFAULT_MAX_DECOMPRESSED_BYTES,
        /**
         * Mirror of {@see \Moselwal\Typo3ClusterCache\Application\Write\WriteCacheEntry}'s
         * `$bareBytes`. When true, the reader treats the on-disk bytes as
         * plain payload (no marker prefix); the codec is implicit `None`
         * because the writer guarantees uncompressed storage in this mode.
         */
        private bool $bareBytes = false,
    ) {}

    public function execute(CacheNamespace $namespace, CacheIdentifier $identifier): ?string
    {
        $labels = $this->labels($namespace);
        try {
            $metadata = $this->metadataCache->get($identifier);
        } catch (\Throwable) {
            // Metadata backend is unreachable (Redis outage, DB lock,
            // network blip). Treat as cache miss so the TYPO3 frontend
            // can trigger a caller rebuild instead of crashing the
            // request — at the cost of higher upstream load until the
            // backend recovers.
            $this->metrics->counter('cache_miss_total', $labels + ['reason' => 'metadata-error']);

            return null;
        }
        if (null === $metadata) {
            $this->metrics->counter('cache_miss_total', $labels + ['reason' => 'no-metadata']);

            return null;
        }
        if (CacheState::Broken === $metadata->state) {
            $this->metrics->counter('cache_miss_total', $labels + ['reason' => 'broken']);

            return null;
        }
        $now = $this->clock->now();
        if ($metadata->lifetime->isExpired($now)) {
            $this->metrics->counter('cache_miss_total', $labels + ['reason' => 'expired']);

            return null;
        }

        try {
            $bytes = $this->localStore->readVerified($metadata->hash, $metadata->checksum);
        } catch (PayloadNotFoundException) {
            $this->metrics->counter('blob_miss_total', $labels);

            return null;
        } catch (PayloadIntegrityException) {
            $this->localStore->delete($metadata->hash);
            $this->markBroken($metadata, $identifier, $now);
            $this->metrics->counter('cache_miss_total', $labels + ['reason' => 'broken']);

            return null;
        }

        // In bare-bytes mode (PhpFrontend caches) the file holds the raw
        // payload without a one-byte marker prefix. The writer guarantees
        // compression=None in that mode, so the codec is implicit.
        if ($this->bareBytes) {
            $compressor = $this->compressorsByAlgo[CompressionAlgo::None->value]
                ?? throw new \LogicException('NullCompressor not registered');
            $payload = $bytes;
        } else {
            // Payload byte zero is a CompressionAlgo marker (since v2.2.0).
            // BackendVersionInfo::CURRENT was bumped at the same time so any
            // pre-marker payload would already be unreachable via mismatched
            // hash — but if a corrupt or foreign payload slips through, treat
            // it as integrity failure to drive the broken-state flow.
            if ('' === $bytes) {
                $this->localStore->delete($metadata->hash);
                $this->markBroken($metadata, $identifier, $now);
                $this->metrics->counter('cache_miss_total', $labels + ['reason' => 'broken']);

                return null;
            }
            try {
                $algo = CompressionAlgo::fromMarker($bytes[0]);
            } catch (\InvalidArgumentException) {
                $this->localStore->delete($metadata->hash);
                $this->markBroken($metadata, $identifier, $now);
                $this->metrics->counter('cache_miss_total', $labels + ['reason' => 'broken']);

                return null;
            }
            $compressor = $this->compressorsByAlgo[$algo->value]
                ?? throw new \LogicException('compressor not registered for ' . $algo->value);
            $payload = substr($bytes, 1);
        }

        $this->metrics->counter('cache_hit_total', $labels);
        $this->metrics->counter('local_payload_hit_total', $labels);
        $this->metrics->histogram(
            'payload_size_bytes',
            $labels + ['direction' => 'read'],
            (float) \strlen($bytes),
        );

        return $compressor->decompress($payload, $this->maxDecompressedBytes);
    }

    private function markBroken(CacheMetadata $metadata, CacheIdentifier $identifier, int $now): void
    {
        $brokenMeta = new CacheMetadata(
            identifier: $metadata->identifier,
            hash: $metadata->hash,
            checksum: $metadata->checksum,
            lifetime: $metadata->lifetime,
            serializer: $metadata->serializer,
            compression: $metadata->compression,
            payloadSize: $metadata->payloadSize,
            tags: $metadata->tags,
            state: CacheState::Broken,
            backendVersion: $metadata->backendVersion,
        );
        $brokenTtl = max(
            self::BROKEN_STATE_MIN_TTL_SECONDS,
            min(
                self::BROKEN_STATE_MAX_TTL_SECONDS,
                $metadata->lifetime->remainingSeconds($now),
            ),
        );
        try {
            $this->metadataCache->set(
                $identifier,
                $brokenMeta,
                $metadata->tags->toArray(),
                $brokenTtl,
            );
        } catch (\Throwable) {
            // The metadata backend may have flickered exactly while
            // we are persisting the broken marker. That is recoverable
            // — the next read will simply re-detect the integrity
            // failure. Do not propagate; we already counted the miss.
        }
    }

    /**
     * @return array<string, string>
     */
    private function labels(CacheNamespace $namespace): array
    {
        return [
            'cacheName' => $namespace->cacheName,
            'namespace' => $namespace->toKvKeyPrefix(),
        ];
    }
}
