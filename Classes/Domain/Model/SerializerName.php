<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

final readonly class SerializerName
{
    public const string IGBINARY = 'igbinary';
    public const string PHP_NATIVE = 'php';

    public function __construct(
        public string $name,
        public string $version,
    ) {
        if (self::IGBINARY !== $name && self::PHP_NATIVE !== $name) {
            throw new \InvalidArgumentException(\sprintf('Unknown serializer "%s" — expected "%s" or "%s"', $name, self::IGBINARY, self::PHP_NATIVE));
        }
        if ('' === $version) {
            throw new \InvalidArgumentException('SerializerName.version must not be empty');
        }
    }

    public static function igbinary(): self
    {
        // Only fold the major version into the hash — igbinary patch
        // updates are binary-compatible, so a cluster-wide invalidation on
        // every patch would be disastrous. Format: "igbinary:N" where N is
        // the major version (e.g. "igbinary:3" for 3.x.y).
        $version = phpversion('igbinary');
        if (false === $version) {
            return new self(self::IGBINARY, 'igbinary:unknown');
        }
        $major = strtok($version, '.');

        return new self(self::IGBINARY, 'igbinary:' . (false === $major ? 'unknown' : $major));
    }

    public static function phpNative(): self
    {
        return new self(self::PHP_NATIVE, 'php:native');
    }

    public static function detect(): self
    {
        if (\extension_loaded('igbinary')) {
            return self::igbinary();
        }

        return self::phpNative();
    }
}
