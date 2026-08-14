<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Enum;

enum EnvironmentName: string
{
    case Production = 'prod';
    case Staging = 'staging';
    case Testing = 'testing';
    case Development = 'development';
}
