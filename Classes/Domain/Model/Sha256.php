<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

/**
 * sha256 as lowercase hex, through OpenSSL where it is available and through
 * ext-hash otherwise. Both give the same digest; only the speed differs.
 *
 * ext-hash computes sha256 in portable C. OpenSSL uses the CPU's SHA
 * instructions where the CPU has them: measured on aarch64 (the production
 * image, PHP 8.5.12), a 2.2 MB payload takes 15.9 ms with hash() and 1.0 ms
 * with openssl_digest(). Every payload write and every full payload
 * verification hashes the whole payload, so this is the backend's largest
 * single cost on a cold cache.
 *
 * ext-openssl is optional: the package requires only ext-hash, and hash() is
 * the answer wherever OpenSSL is missing, lacks sha256 (a restricted
 * provider), or fails a known-answer check once per process. A digest that
 * fails returns false from openssl_digest() and falls back too, so the
 * result never depends on which path ran.
 */
final class Sha256
{
    private const string KNOWN_INPUT = 'cluster-file-backend';

    private static ?bool $openSsl = null;

    private function __construct() {}

    public static function hex(string $bytes): string
    {
        if (self::usesOpenSsl()) {
            $digest = openssl_digest($bytes, 'sha256');
            if (\is_string($digest)) {
                return $digest;
            }
        }

        return hash('sha256', $bytes);
    }

    /** Whether hex() goes through OpenSSL in this process. */
    public static function usesOpenSsl(): bool
    {
        return self::$openSsl ??= self::openSslAgrees();
    }

    /**
     * For tests: force a path (true: OpenSSL if it agrees, false: hash()),
     * or null to detect again.
     *
     * @internal
     */
    public static function forceOpenSsl(?bool $use): void
    {
        self::$openSsl = null === $use ? null : ($use && self::openSslAgrees());
    }

    private static function openSslAgrees(): bool
    {
        if (!\function_exists('openssl_digest') || !\function_exists('openssl_get_md_methods')) {
            return false;
        }
        if (!\in_array('sha256', openssl_get_md_methods(), true)) {
            return false;
        }
        $digest = openssl_digest(self::KNOWN_INPUT, 'sha256');

        return \is_string($digest) && hash_equals(hash('sha256', self::KNOWN_INPUT), $digest);
    }
}
