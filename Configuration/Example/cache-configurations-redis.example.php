<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

/*
 * Redis/Valkey-backed cluster cache configuration with TLS / Sentinel
 * support via `moselwal/keyvalue-store`.
 *
 * Requires:
 *   composer require moselwal/keyvalue-store
 *
 * Use this variant when you want sub-millisecond metadata-cache latency
 * and high throughput. For the zero-dependency default with
 * Typo3DatabaseBackend, see `cache-configurations.example.php`.
 *
 * Copy this file's contents into your `config/system/settings.php` and
 * adjust environment variables, hostnames, and TLS paths to your
 * deployment.
 */

$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['cluster_meta'] = [
    'frontend' => TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend'  => Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend::class,
    'options'  => [
        // === Plain connection ===
        'hostname' => getenv('VALKEY_HOST') ?: 'valkey',
        'port'     => (int) (getenv('VALKEY_PORT') ?: 6379),
        'database' => 0,

        // === Sentinel (optional) ===
        // 'sentinel'         => true,
        // 'sentinel_host'    => 'redis-sentinel',
        // 'sentinel_service' => 'cluster-meta-master',

        // === TLS / mTLS (optional) ===
        // 'tls'              => true,
        // 'ca_file'          => '/run/tls/ca.crt',
        // 'cert_file'        => '/run/tls/client.crt',
        // 'key_file'         => '/run/tls/client.key',
        // 'verify_peer'      => true,

        // Namespace separation against other caches on the same Redis
        'keyPrefix'       => 'cfb_meta_',
        'defaultLifetime' => 0,
    ],
    'groups' => ['system'],
];

$clusterBackend = Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend::class;
$clusterDefaults = [
    'metadataCacheIdentifier' => 'cluster_meta',
    'namespace' => [
        'environment' => getenv('TYPO3_ENV') ?: 'prod',
        'instance'    => getenv('TYPO3_INSTANCE') ?: 'default',
    ],
];

foreach (['pages', 'pagesection', 'rootline', 'imagesizes', 'assets', 'hash'] as $cacheName) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$cacheName] = [
        'frontend' => TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend'  => $clusterBackend,
        'options'  => $clusterDefaults + [
            'localPath' => '/app/var/cache/cluster/' . $cacheName,
        ],
        'groups'   => ['pages'],
    ];
}
