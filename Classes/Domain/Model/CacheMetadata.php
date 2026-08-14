<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

use Moselwal\Typo3ClusterCache\Domain\Enum\CacheState;

final readonly class CacheMetadata
{
    public function __construct(
        public CacheIdentifier $identifier,
        public PayloadHash $hash,
        public PayloadChecksum $checksum,
        public Lifetime $lifetime,
        public SerializerName $serializer,
        public CompressionName $compression,
        public int $payloadSize,
        public TagSet $tags,
        public CacheState $state,
        public BackendVersion $backendVersion,
    ) {
        if ($payloadSize < 0) {
            throw new \InvalidArgumentException(\sprintf('CacheMetadata.payloadSize must be >= 0, got %d', $payloadSize));
        }
    }

    public function isValid(int $now): bool
    {
        return $this->state->isValid()
            && !$this->lifetime->isExpired($now);
    }

    /**
     * @return array<string, mixed>
     */
    public function toKvPayload(): array
    {
        return [
            'identifier' => $this->identifier->value,
            'hash' => 'sha256:' . $this->hash->digest,
            'checksum' => 'sha256:' . $this->checksum->digest,
            'createdAt' => $this->lifetime->createdAt,
            'expiresAt' => $this->lifetime->expiresAt,
            'serializer' => $this->serializer->version,
            'compression' => $this->compression->name->value,
            'payloadSize' => $this->payloadSize,
            'tags' => $this->tags->toArray(),
            'state' => $this->state->value,
            'backendVersion' => $this->backendVersion->value,
        ];
    }

    /**
     * Defensive parser for metadata-cache payloads. Tolerates extra fields
     * (e.g. legacy `generation` from v1.x) and validates every required
     * field's type. Returns `null` on any structural problem so the caller
     * can treat the entry as a cache miss without crashing.
     *
     * @param array<string, mixed> $raw
     */
    public static function fromKvPayload(array $raw): self
    {
        self::requireString($raw, 'identifier');
        self::requireString($raw, 'hash');
        self::requireString($raw, 'checksum');
        self::requireInt($raw, 'createdAt');
        self::requireInt($raw, 'expiresAt');
        self::requireString($raw, 'serializer');
        self::requireString($raw, 'compression');
        self::requireInt($raw, 'payloadSize');
        self::requireArray($raw, 'tags');
        self::requireString($raw, 'state');
        self::requireInt($raw, 'backendVersion');

        $hashDigest = self::stripAlgoPrefix((string) $raw['hash']);
        $checksumDigest = self::stripAlgoPrefix((string) $raw['checksum']);

        $serializer = (string) $raw['serializer'];
        $serializerParts = explode(':', $serializer, 2);
        $serializerName = 2 === \count($serializerParts) ? $serializerParts[0] : $serializer;

        return new self(
            identifier: new CacheIdentifier((string) $raw['identifier']),
            hash: new PayloadHash($hashDigest),
            checksum: new PayloadChecksum($checksumDigest),
            lifetime: new Lifetime((int) $raw['createdAt'], (int) $raw['expiresAt']),
            serializer: new SerializerName($serializerName, $serializer),
            compression: CompressionName::fromString((string) $raw['compression']),
            payloadSize: (int) $raw['payloadSize'],
            tags: new TagSet(self::normalizeTags($raw['tags'])),
            state: CacheState::from((string) $raw['state']),
            backendVersion: new BackendVersion((int) $raw['backendVersion']),
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function requireString(array $raw, string $field): void
    {
        if (!\array_key_exists($field, $raw) || !\is_string($raw[$field])) {
            throw new \RuntimeException(\sprintf('CacheMetadata payload field "%s" missing or not a string', $field));
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function requireInt(array $raw, string $field): void
    {
        if (!\array_key_exists($field, $raw) || !\is_int($raw[$field])) {
            throw new \RuntimeException(\sprintf('CacheMetadata payload field "%s" missing or not an int', $field));
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function requireArray(array $raw, string $field): void
    {
        if (!\array_key_exists($field, $raw) || !\is_array($raw[$field])) {
            throw new \RuntimeException(\sprintf('CacheMetadata payload field "%s" missing or not an array', $field));
        }
    }

    private static function stripAlgoPrefix(string $value): string
    {
        $colon = strpos($value, ':');

        return false === $colon ? $value : substr($value, $colon + 1);
    }

    /**
     * @return list<string>
     */
    private static function normalizeTags(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $result = [];
        foreach ($raw as $tag) {
            if (\is_string($tag)) {
                $result[] = $tag;
            }
        }

        return $result;
    }
}
