<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\LocalStore;

/**
 * What one lstat() says a payload path is. One call answers what
 * is_file(), is_link() and filemtime() asked one after another before, each
 * a path walk of its own -- and with open_basedir set PHP keeps no realpath
 * cache, so each walk is paid in full (measured 20 µs per is_file() in the
 * production image).
 *
 * lstat() does not follow a symlink, so a link is reported as a link, never
 * as the file it points to.
 *
 * @internal
 */
enum FileType
{
    case Missing;
    case Regular;
    case Symlink;
    case Other;

    /**
     * @param array<int|string, int>|false $stat
     */
    public static function of(array|false $stat): self
    {
        if (false === $stat) {
            return self::Missing;
        }

        return match ($stat['mode'] & 0o170000) {
            0o100000 => self::Regular,
            0o120000 => self::Symlink,
            default => self::Other,
        };
    }
}
