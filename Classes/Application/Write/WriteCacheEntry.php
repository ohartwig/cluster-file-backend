<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Application\Write;

use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Contract\ClockPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\CompressorPort;
use Moselwal\Typo3ClusterCache\Domain\Contract\LocalPayloadStorePort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetadataCachePort;
use Moselwal\Typo3ClusterCache\Domain\Contract\MetricsPort;
use Moselwal\Typo3ClusterCache\Domain\Enum\CacheState;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheMetadata;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionAlgo;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\Lifetime;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;

final class WriteCacheEntry
{
    /**
     * @param array<string, CompressorPort> $compressorsByAlgo Lookup from
     *                                                         CompressionAlgo->value to compressor. Always contains at least
     *                                                         the configured codec (`$compression`) and a NullCompressor so the
     *                                                         skip-compress path is always available.
     */
    public function __construct(
        private readonly MetadataCachePort $metadataCache,
        private readonly LocalPayloadStorePort $localStore,
        private readonly array $compressorsByAlgo,
        private readonly ClockPort $clock,
        private readonly MetricsPort $metrics,
        private readonly ComputePayloadHash $hasher,
        private readonly SerializerName $serializer,
        private readonly CompressionName $compression,
        private readonly BackendVersion $backendVersion,
        private readonly int $minCompressedBytes,
        /**
         * When true, the writer skips the one-byte compression-algo marker
         * prefix on the on-disk payload. Required for PhpFrontend caches:
         * `require_once` parses the file as PHP source code, and any byte
         * before `<?php` is echoed as raw output by PHP — corrupting the
         * response body and crashing HTTP/2 streams with a content-length
         * mismatch (the EarlyHints/`Content-Length` says N bytes, the body
         * gets N+1 bytes including the leaked null byte → reverse-proxy
         * truncates the stream → ERR_HTTP2_PROTOCOL_ERROR in the browser).
         *
         * Only safe with `$compression === CompressionAlgo::None`: without
         * the marker the reader cannot tell which decompressor to use.
         * ClusterFileBackend enforces both invariants together for
         * PhpFrontend caches in `setCache()`.
         */
        private readonly bool $bareBytes = false,
    ) {}

    public function execute(
        CacheNamespace $namespace,
        CacheIdentifier $identifier,
        string $rawBytes,
        TagSet $tags,
        int $lifetimeSeconds,
    ): void {
        // Skip-compress path for small payloads and for caches that have
        // turned compression off entirely (e.g. PhpFrontend caches that
        // must keep plain text on disk so OPcache can ingest them).
        $effectiveAlgo = (\strlen($rawBytes) < $this->minCompressedBytes || CompressionAlgo::None === $this->compression->name)
            ? CompressionAlgo::None
            : $this->compression->name;
        $compressor = $this->compressorsByAlgo[$effectiveAlgo->value]
            ?? throw new \LogicException('compressor not registered for ' . $effectiveAlgo->value);
        $compressed = $compressor->compress($rawBytes);
        // PhpFrontend caches must contain a parseable PHP source on disk;
        // the marker prefix would leak a NUL byte to the response body via
        // `require_once`. The reader side knows the encoding from metadata.
        $payload = $this->bareBytes ? $compressed : ($effectiveAlgo->marker() . $compressed);
        $hash = $this->hasher->fromRawBytes(
            $rawBytes,
            $this->serializer,
            $this->compression,
            $this->backendVersion,
        );
        $checksum = PayloadChecksum::ofBytes($payload);
        $lifetime = 0 === $lifetimeSeconds
            ? Lifetime::unlimited($this->clock)
            : Lifetime::fromSeconds($lifetimeSeconds, $this->clock);

        $existing = $this->metadataCache->get($identifier);
        if (null !== $existing && $existing->hash->equals($hash)) {
            // Repair path: same order — local first, then metadata.
            $this->localStore->write($hash, $payload);
            $this->metadataCache->set(
                $identifier,
                $this->buildMetadata($identifier, $hash, $checksum, $lifetime, $tags, $payload),
                $tags->toArray(),
                $this->ttlForBackend($lifetime),
            );
            $this->metrics->counter('payload_rebuild_total', $this->labels($namespace));
            $this->metrics->counter('repair_success_total', $this->labels($namespace));

            return;
        }

        // Write local FIRST: on disk-failure (ENOSPC, lost permissions) no
        // inconsistent metadata cluster state appears. If the metadata set
        // then fails, at worst an orphan local file remains, which the next
        // pod restart (emptyDir reset) or GC removes. Both kinds of orphan
        // are harmless compared to "metadata says valid, every pod sees an
        // endless blob-miss".
        $metadata = $this->buildMetadata($identifier, $hash, $checksum, $lifetime, $tags, $payload);
        $this->localStore->write($hash, $payload);
        $this->metadataCache->set(
            $identifier,
            $metadata,
            $tags->toArray(),
            $this->ttlForBackend($lifetime),
        );
        $this->metrics->counter('cache_write_total', $this->labels($namespace));
        $this->metrics->histogram(
            'payload_size_bytes',
            $this->labels($namespace) + ['direction' => 'write'],
            (float) \strlen($payload),
        );
    }

    private function buildMetadata(
        CacheIdentifier $identifier,
        PayloadHash $hash,
        PayloadChecksum $checksum,
        Lifetime $lifetime,
        TagSet $tags,
        string $payload,
    ): CacheMetadata {
        return new CacheMetadata(
            identifier: $identifier,
            hash: $hash,
            checksum: $checksum,
            lifetime: $lifetime,
            serializer: $this->serializer,
            compression: $this->compression,
            payloadSize: \strlen($payload),
            tags: $tags,
            state: CacheState::Valid,
            backendVersion: $this->backendVersion,
        );
    }

    /**
     * Translates the domain Lifetime into the integer TTL semantics of the
     * TYPO3 metadata cache API: `0` means "cache forever", `>0` means
     * "expire after N seconds". Matches `Typo3DatabaseBackend::set()`.
     */
    private function ttlForBackend(Lifetime $lifetime): int
    {
        if ($lifetime->isUnlimited()) {
            return 0;
        }

        return $lifetime->remainingSeconds($this->clock->now());
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
