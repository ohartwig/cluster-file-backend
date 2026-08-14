<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

return [
    'clusterfilebackend:gc' => [
        'class' => Moselwal\Typo3ClusterCache\Presentation\Command\GarbageCollectCommand::class,
        'schedulable' => true,
    ],
    'clusterfilebackend:warmup' => [
        'class' => Moselwal\Typo3ClusterCache\Presentation\Command\WarmUpCommand::class,
        'schedulable' => false,
    ],
];
