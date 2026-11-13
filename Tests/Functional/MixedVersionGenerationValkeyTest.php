<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Functional;

use Moselwal\KeyValueStore\Cache\Backend\GenerationBackendInterface;
use Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\AtomicGenerationMetadataCache;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\GenerationGuardedMetadataCache;
use Moselwal\Typo3ClusterCache\Infrastructure\Cache\Typo3MetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\AtomicGenerationScenarios;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\BackendInterface;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * {@see AtomicGenerationScenarios} against a real Valkey/Redis through
 * moselwal/keyvalue-store's KeyValueBackend: the scripts, the raw key and
 * the signed legacy entry as they are in production.
 *
 * Needs a server at REDIS_HOST:REDIS_PORT (default 127.0.0.1:6379) and
 * skips itself without one; database 15 is emptied before and after each
 * test. No TYPO3 instance is booted.
 */
#[CoversClass(AtomicGenerationMetadataCache::class)]
#[CoversClass(GenerationGuardedMetadataCache::class)]
#[RequiresPhpExtension('redis')]
final class MixedVersionGenerationValkeyTest extends AtomicGenerationScenarios
{
    private const int DATABASE = 15;

    private \Redis $observer;

    /** @var resource */
    private $monitor;

    protected function createBackend(): BackendInterface&GenerationBackendInterface
    {
        if (!class_exists(KeyValueBackend::class)) {
            self::markTestSkipped('moselwal/keyvalue-store is not installed');
        }
        $host = getenv('REDIS_HOST');
        $host = \is_string($host) && '' !== $host ? $host : '127.0.0.1';
        $port = getenv('REDIS_PORT');
        $port = \is_string($port) && '' !== $port ? (int) $port : 6379;
        try {
            $this->observer = new \Redis();
            $this->observer->connect($host, $port, 1.0);
            $this->observer->select(self::DATABASE);
            $this->observer->flushDB();
        } catch (\RedisException) {
            self::markTestSkipped('Redis is not available at ' . $host . ':' . $port);
        }
        $monitor = @stream_socket_client('tcp://' . $host . ':' . $port, $errorCode, $errorMessage, 1.0);
        if (false === $monitor) {
            self::markTestSkipped('Redis is not available at ' . $host . ':' . $port);
        }
        stream_set_timeout($monitor, 5);
        fwrite($monitor, "MONITOR\r\n");
        fgets($monitor);
        $this->monitor = $monitor;
        $backend = new KeyValueBackend(['hostname' => $host, 'port' => $port, 'database' => self::DATABASE]);
        $backend->initializeObject();
        // Connect and select now, so that neither is counted later.
        $backend->has('warm-up');

        return $backend;
    }

    protected function tearDown(): void
    {
        try {
            $this->observer->flushDB();
            fclose($this->monitor);
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    /**
     * Commands the server received from clients while $work ran, read from
     * a MONITOR connection. MONITOR names the source of every command:
     * what a script issues is marked `lua` and is not a round trip.
     */
    protected function roundTrips(\Closure $work): int
    {
        $this->drainMonitorUntil('before');
        $work();

        return $this->drainMonitorUntil('after');
    }

    /**
     * Reads the monitor up to an ECHO the observer sends now and returns
     * the number of client commands seen before it.
     */
    private function drainMonitorUntil(string $label): int
    {
        $sentinel = 'sentinel-' . $label . '-' . bin2hex(random_bytes(4));
        $this->observer->echo($sentinel);
        $commands = 0;
        while (false !== ($line = fgets($this->monitor))) {
            if (str_contains($line, $sentinel)) {
                return $commands;
            }
            if (1 === preg_match('/^\+[\d.]+ \[\d+ (\S+)\] /', $line, $source) && 'lua' !== $source[1]) {
                ++$commands;
            }
        }
        self::fail('the monitor connection ended before ' . $sentinel);
    }

    #[Test]
    public function theServerSeesOneScriptPerSetAndNothingElseFromTheClient(): void
    {
        $frontend = new VariableFrontend('cluster_meta', $this->backend);
        $store = AtomicGenerationMetadataCache::forFrontend($frontend, new CacheNamespace(EnvironmentName::Testing, 'cluster', 'pages'));
        $guarded = new GenerationGuardedMetadataCache($store, $store, new FakeClock(1_700_000_000), new FakeMetrics());
        $legacyStore = new Typo3MetadataCache($frontend, new CacheNamespace(EnvironmentName::Testing, 'cluster', 'pages'));
        $legacy = new GenerationGuardedMetadataCache($legacyStore, $legacyStore, new FakeClock(1_700_000_000), new FakeMetrics());
        $metadata = \Closure::bind(fn() => $this->metadata('x'), $this, AtomicGenerationScenarios::class)();

        $this->observer->rawcommand('CONFIG', 'RESETSTAT');
        $guarded->set(new CacheIdentifier('x'), $metadata, ['t'], 60);
        $atomic = $this->commandCalls();

        self::assertSame(1, $atomic['eval'] ?? 0);
        // What the script did on the server: guard check, generation read
        // and write, entry write, tag index.
        self::assertSame(['eval' => 1, 'exists' => 1, 'get' => 1, 'sadd' => 4, 'set' => 1, 'setex' => 1, 'smembers' => 1], $atomic);

        $this->observer->flushDB();
        $this->observer->rawcommand('CONFIG', 'RESETSTAT');
        $legacy->set(new CacheIdentifier('x'), $metadata, ['t'], 60);
        $before = $this->commandCalls();

        // The three-step protocol through the frontend: dirty marker (a
        // set script), the entry (a set script), the read, the clean
        // generation (a set script).
        self::assertSame(3, $before['eval'] ?? 0);
        self::assertSame(1, $before['get'] ?? 0);
    }

    /** @return array<string, int> calls per command since the last reset */
    private function commandCalls(): array
    {
        $calls = [];
        foreach ($this->observer->info('commandstats') as $command => $stats) {
            if (1 === preg_match('/calls=(\d+)/', (string) $stats, $m)) {
                $calls[substr((string) $command, \strlen('cmdstat_'))] = (int) $m[1];
            }
        }
        unset($calls['info'], $calls['config|resetstat'], $calls['flushdb'], $calls['echo'], $calls['monitor']);
        ksort($calls);

        return $calls;
    }
}
