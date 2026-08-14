<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Enum;

enum CacheState: string
{
    case Valid = 'valid';
    case Broken = 'broken';

    public function isValid(): bool
    {
        return self::Valid === $this;
    }
}
