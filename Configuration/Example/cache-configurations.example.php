<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

/*
 * Zero-dependency default: full TYPO3 14 cache configuration for stateless
 * Kubernetes pods with moselwal/cluster-file-backend.
 *
 * This example uses only TYPO3 core backends. No extra Composer package is
 * required — the metadata cache lives in the TYPO3 database
 * (`Typo3DatabaseBackend`). For sub-millisecond metadata latency in
 * production, switch to the Redis/Valkey variant in
 * `cache-configurations-redis.example.php` (requires moselwal/keyvalue-store).
 *
 * Copy this block into your `config/system/settings.php` and adjust
 * `environment`, `instance`, and `localPath` to your infrastructure.
 *
 * Order matters: `cluster_meta` MUST be defined before any
 * ClusterFileBackend-backed cache so the backend constructor can resolve
 * the frontend via `CacheManager::getCache(...)`. PHP arrays preserve
 * insertion order; this file keeps the correct order.
 */

// ---------------------------------------------------------------------------
// 1) CENTRAL METADATA CACHE — zero-dependency variant on the TYPO3 database.
//    Works out of the box in any TYPO3 installation. For higher throughput,
//    swap this single block for the Redis variant.
// ---------------------------------------------------------------------------

$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['cluster_meta'] = [
    'frontend' => TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend'  => TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
    'options'  => [
        // 0 = no default TTL; the per-entry TTL is supplied on each set()
        'defaultLifetime' => 0,
    ],
    'groups' => ['system'],
];

// ---------------------------------------------------------------------------
// 2) THE FILE-BASED CACHES WE REPLACE
//    pages, pagesection, rootline, imagesizes, assets, hash, runtime …
//    Which caches you switch to ClusterFileBackend is a per-site
//    performance / footprint decision.
// ---------------------------------------------------------------------------

$clusterBackend = Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend::class;

$clusterCacheDefaults = [
    'metadataCacheIdentifier' => 'cluster_meta',
    'namespace' => [
        'environment' => getenv('TYPO3_ENV') ?: 'prod',
        'instance'    => getenv('TYPO3_INSTANCE') ?: 'website-a',
    ],
    // Optional (defaults from the JSON schema):
    // 'compression'            => 'zstd',     // zstd | gzip | none
    // 'serializer'             => 'igbinary', // igbinary | php
    // 'defaultLifetimeSeconds' => 3600,
    // 'maxPayloadBytes'        => 10_485_760, // 10 MB
    // 'backendVersionEnvVar'   => 'IMAGE_TAG', // see "Rolling deploys" in README
];

foreach ([
    'pages',
    'pagesection',
    'rootline',
    'imagesizes',
    'assets',
    'hash',
] as $cacheName) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$cacheName] = [
        'frontend' => TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend'  => $clusterBackend,
        'options'  => $clusterCacheDefaults + [
            'localPath' => '/app/var/cache/cluster/' . $cacheName,
        ],
        'groups'   => ['pages'],
    ];
}
