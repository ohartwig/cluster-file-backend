<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Presentation\EventListener;

use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend\ClusterFileBackend;
use Moselwal\Typo3ClusterCache\Infrastructure\WarmUp\BackendWarmUpRunner;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Event\CacheWarmupEvent;

/**
 * Hooks into TYPO3's standard `cache:warmup` command (and any other
 * dispatcher of {@see CacheWarmupEvent}). Iterates every registered cache,
 * picks those backed by {@see ClusterFileBackend}, and runs our warm-up
 * routine on each. Errors are reported through the event's error list so
 * the CLI exit code reflects them; the event is intentionally not aborted
 * — a single broken cluster backend should not block other cache groups
 * from warming up.
 *
 * Wired via `Configuration/Services.yaml` (event.listener tag); no PHP
 * attribute is used so we stay portable across Symfony major versions.
 */
final readonly class CacheWarmupListener
{
    public function __construct(
        private CacheManager $cacheManager,
        private BackendWarmUpRunner $runner,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function __invoke(CacheWarmupEvent $event): void
    {
        foreach ($this->resolveCacheIdentifiers() as $cacheIdentifier) {
            if (!$this->cacheManager->hasCache($cacheIdentifier)) {
                continue;
            }
            $cache = $this->cacheManager->getCache($cacheIdentifier);
            $backend = $cache->getBackend();
            if (!$backend instanceof ClusterFileBackend) {
                continue;
            }

            $namespace = $this->buildNamespace($backend, (string) $cacheIdentifier, $event);
            if (null === $namespace) {
                continue;
            }

            try {
                $report = $this->runner->run($namespace);
                if (!$report->succeeded()) {
                    $event->addError(\sprintf(
                        'Cluster warm-up degraded for %s (metadataHealthy=%s, localWritable=%s)',
                        $namespace->toKvKeyPrefix(),
                        $report->metadataCacheHealthy ? 'yes' : 'no',
                        $report->localStoreWritable ? 'yes' : 'no',
                    ));
                }
            } catch (\Throwable $e) {
                $this->logger->error('Cluster warm-up threw for cache "{cache}"', [
                    'cache' => $cacheIdentifier,
                    'exception' => $e,
                ]);
                $event->addError(\sprintf('Cluster warm-up failed for %s: %s', $cacheIdentifier, $e->getMessage()));
            }
        }
    }

    /**
     * Pull cache identifiers from $GLOBALS['TYPO3_CONF_VARS']; the CacheManager
     * does not expose a public enumeration API in TYPO3 14, so we read the
     * cache configuration registry directly.
     *
     * @return list<string>
     */
    private function resolveCacheIdentifiers(): array
    {
        $configurations = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'] ?? [];
        if (!\is_array($configurations)) {
            return [];
        }
        $identifiers = [];
        foreach (\array_keys($configurations) as $identifier) {
            if (\is_string($identifier) && '' !== $identifier) {
                $identifiers[] = $identifier;
            }
        }

        return $identifiers;
    }

    private function buildNamespace(
        ClusterFileBackend $backend,
        string $cacheIdentifier,
        CacheWarmupEvent $event,
    ): ?CacheNamespace {
        try {
            // We need access to the backend's configured namespace; the
            // backend rebuilds it inside setCache() once the cache frontend
            // is attached. We reconstruct from its public getters.
            return new CacheNamespace(
                $backend->getNamespaceEnvironment(),
                $backend->getNamespaceInstance(),
                $cacheIdentifier,
            );
        } catch (\Throwable $e) {
            $event->addError(\sprintf(
                'Could not build namespace for cluster cache "%s": %s',
                $cacheIdentifier,
                $e->getMessage(),
            ));

            return null;
        }
    }
}
