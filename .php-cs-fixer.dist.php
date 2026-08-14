<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

// Delegiert vollständig an die zentrale moselwal/dev-Konvention
// (@Symfony + @PER-CS3x0 + @PHP85Migration + @DoctrineAnnotation).
// Die moselwal/dev-Konfig liest MOSELWAL_FRAMEWORK aus der Umgebung.
\putenv('MOSELWAL_FRAMEWORK=typo3');
$_ENV['MOSELWAL_FRAMEWORK'] = 'typo3';

return require __DIR__ . '/vendor/moselwal/dev/.php-cs-fixer.dist.php';
