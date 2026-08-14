<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

final readonly class CacheIdentifier
{
    // Mirrors TYPO3 Core's FrontendInterface::PATTERN_ENTRYIDENTIFIER (TYPO3 14):
    // `[a-zA-Z0-9_%\-&]{1,250}`. Strict adoption prevents the backend from
    // rejecting legitimate TYPO3 core identifiers.
    private const string PATTERN = '/^[a-zA-Z0-9_%\-&]{1,250}$/';

    public function __construct(
        public string $value,
    ) {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new \InvalidArgumentException(\sprintf('Cache identifier "%s" violates required pattern %s', $value, self::PATTERN));
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
