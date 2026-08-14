<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

final readonly class TagSet
{
    // Mirrors TYPO3 Core's FrontendInterface::PATTERN_TAG (TYPO3 14):
    // `[a-zA-Z0-9_%\-&]{1,250}`. The tag count limit is a defensive guard
    // against unbounded tag growth on the WRITE path (a single entry's own
    // tags) — core itself has none. Tag-based FLUSH queries carry no such
    // ceiling and must batch into MAX_TAGS-sized chunks instead of tripping
    // this guard (see Typo3MetadataCache::flushByTags). Public so the flush
    // path can size its chunks against the single source of truth.
    public const int MAX_TAGS = 64;
    private const string TAG_PATTERN = '/^[a-zA-Z0-9_%\-&]{1,250}$/';

    /** @var list<string> */
    public array $tags;

    /**
     * @param list<string> $tags
     */
    public function __construct(array $tags = [])
    {
        $unique = array_values(array_unique($tags));
        if (\count($unique) > self::MAX_TAGS) {
            throw new \InvalidArgumentException(\sprintf('TagSet exceeds maximum of %d tags (got %d)', self::MAX_TAGS, \count($unique)));
        }
        foreach ($unique as $tag) {
            if (1 !== preg_match(self::TAG_PATTERN, $tag)) {
                throw new \InvalidArgumentException(\sprintf('Tag "%s" violates pattern %s', $tag, self::TAG_PATTERN));
            }
        }
        sort($unique, \SORT_STRING);
        $this->tags = $unique;
    }

    public function with(string $tag): self
    {
        $tags = $this->tags;
        $tags[] = $tag;

        return new self($tags);
    }

    public function without(string $tag): self
    {
        return new self(array_values(array_filter(
            $this->tags,
            static fn(string $existing): bool => $existing !== $tag,
        )));
    }

    public function contains(string $tag): bool
    {
        return \in_array($tag, $this->tags, true);
    }

    /**
     * @return list<string>
     */
    public function toArray(): array
    {
        return $this->tags;
    }

    public function isEmpty(): bool
    {
        return [] === $this->tags;
    }
}
