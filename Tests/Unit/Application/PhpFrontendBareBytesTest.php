<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Application;

use Moselwal\Typo3ClusterCache\Application\Hash\ComputePayloadHash;
use Moselwal\Typo3ClusterCache\Application\Read\ReadCacheEntry;
use Moselwal\Typo3ClusterCache\Application\Write\WriteCacheEntry;
use Moselwal\Typo3ClusterCache\Domain\Enum\EnvironmentName;
use Moselwal\Typo3ClusterCache\Domain\Model\BackendVersion;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheIdentifier;
use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Domain\Model\CompressionName;
use Moselwal\Typo3ClusterCache\Domain\Model\SerializerName;
use Moselwal\Typo3ClusterCache\Domain\Model\TagSet;
use Moselwal\Typo3ClusterCache\Tests\Support\CodecRegistry;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeClock;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetadataCache;
use Moselwal\Typo3ClusterCache\Tests\Support\FakeMetrics;
use Moselwal\Typo3ClusterCache\Infrastructure\LocalStore\EmptyDirPayloadStore;
use Moselwal\Typo3ClusterCache\Tests\Support\InMemoryLocalPayloadStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Regression-Suite für den v2.3.2-CRITICAL-Bug: PhpFrontend-Files
 * dürfen keine Bytes vor `<?php` haben. Bei `require_once` echo't PHP
 * alle Bytes vor `<?php` als raw output, was Content-Length-Mismatches
 * und HTTP/2-Stream-Abbrüche verursacht.
 *
 * Die Tests prüfen *beide* Symptome:
 *   1. Byte-Identität: die ersten Bytes auf Disk sind `<?php`, kein
 *      Marker, kein BOM, kein anderer Prefix.
 *   2. Output-Verhalten: `require_once` erzeugt **keinen** Output vor
 *      dem PHP-Code-Ergebnis. Das ist der eigentliche Bug-Vektor —
 *      eine Byte-gleichheit allein erwischt z.B. ein UTF-8-BOM-
 *      Regression nicht, der Output-Test schon.
 */
#[CoversClass(WriteCacheEntry::class)]
#[CoversClass(ReadCacheEntry::class)]
final class PhpFrontendBareBytesTest extends TestCase
{
    private CacheNamespace $namespace;
    private FakeMetadataCache $metadataCache;
    private FakeClock $clock;
    private FakeMetrics $metrics;
    private ?string $tmpDir = null;

    protected function setUp(): void
    {
        $this->namespace = new CacheNamespace(EnvironmentName::Testing, 'site', 'typoscript');
        $this->metadataCache = new FakeMetadataCache();
        $this->clock = new FakeClock(1_700_000_000);
        $this->metrics = new FakeMetrics();
    }

    protected function tearDown(): void
    {
        if (null !== $this->tmpDir && is_dir($this->tmpDir)) {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iter as $entry) {
                /* @var \SplFileInfo $entry */
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($this->tmpDir);
        }
    }

    public function testBareBytesModeWritesNoMarkerPrefix(): void
    {
        $store = new InMemoryLocalPayloadStore();
        $writer = $this->buildWriter($store, bareBytes: true);
        $php = "<?php\nreturn ['x' => 1];\n";

        $writer->execute($this->namespace, new CacheIdentifier('bare'), $php, new TagSet([]), 3600);

        $bytes = $this->capturedDiskBytes($store);
        self::assertSame('<', $bytes[0], 'first byte must be `<` so `require_once` parses as PHP without leaking output');
        self::assertSame('<?php', substr($bytes, 0, 5), 'no marker, no BOM, no debug-prefix allowed before `<?php`');
        self::assertSame($php, $bytes, 'bare-bytes write must store the raw payload byte-for-byte');
    }

    public function testNormalModeStillWritesMarker(): void
    {
        // Regression: VariableFrontend-Pfad darf NICHT versehentlich
        // bareBytes erben — wir brauchen den Marker für die
        // Codec-Dispatch beim Read.
        $store = new InMemoryLocalPayloadStore();
        $writer = $this->buildWriter($store, bareBytes: false);
        $payload = 'value';

        $writer->execute($this->namespace, new CacheIdentifier('normal'), $payload, new TagSet([]), 3600);

        $bytes = $this->capturedDiskBytes($store);
        self::assertSame("\x00", $bytes[0], 'VariableFrontend payloads keep the CompressionAlgo::None marker prefix');
        self::assertSame($payload, substr($bytes, 1), 'rest after marker = raw payload');
    }

    public function testBareBytesReaderReturnsPayloadWithoutMarkerStripping(): void
    {
        $store = new InMemoryLocalPayloadStore();
        $writer = $this->buildWriter($store, bareBytes: true);
        $reader = $this->buildReader($store, bareBytes: true);
        $php = "<?php\nreturn 42;\n";

        $writer->execute($this->namespace, new CacheIdentifier('rt'), $php, new TagSet([]), 3600);
        $result = $reader->execute($this->namespace, new CacheIdentifier('rt'));

        self::assertSame($php, $result, 'bare-bytes round-trip must return the raw payload unchanged');
    }

    /**
     * The actual exploit-shape: write a PHP file via the bare-bytes path,
     * `require_once` it, and assert nothing was echoed to stdout before
     * the included file's own output. A byte-prefix would surface here
     * as raw output (which is what triggered the HTTP/2 Content-Length
     * mismatch in production).
     */
    public function testRequireProducesNoOutputBeforePhpCode(): void
    {
        // Real filesystem store with the same `.php` suffix that
        // ClusterFileBackend uses for PhpFrontend caches. The whole point
        // of this test is to catch the regression at the PHP-parser level
        // — InMemoryLocalPayloadStore would let any byte-prefix through
        // without it being parsed.
        $this->tmpDir = sys_get_temp_dir() . '/cfb-php-bare-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0o755, true);
        $store = new EmptyDirPayloadStore($this->tmpDir, '.php');

        $writer = $this->buildWriter($store, bareBytes: true);
        $php = "<?php\necho 'BODY';\n";

        $writer->execute($this->namespace, new CacheIdentifier('exec'), $php, new TagSet([]), 3600);

        $metadata = $this->metadataCache->get(new CacheIdentifier('exec'));
        self::assertNotNull($metadata);
        $path = $store->pathFor($metadata->hash);
        self::assertFileExists($path);

        ob_start();
        require $path; // intentionally `require` (not require_once) for repeatability across tests
        $output = ob_get_clean();

        self::assertSame('BODY', $output, 'require must produce only the PHP code\'s own output, no leaked prefix bytes');
    }

    private function buildWriter(\Moselwal\Typo3ClusterCache\Domain\Contract\LocalPayloadStorePort $store, bool $bareBytes): WriteCacheEntry
    {
        return new WriteCacheEntry(
            metadataCache: $this->metadataCache,
            localStore: $store,
            compressorsByAlgo: CodecRegistry::all(),
            clock: $this->clock,
            metrics: $this->metrics,
            hasher: new ComputePayloadHash(),
            serializer: SerializerName::phpNative(),
            compression: CompressionName::none(),
            backendVersion: new BackendVersion(1),
            minCompressedBytes: 1024,
            bareBytes: $bareBytes,
        );
    }

    private function buildReader(\Moselwal\Typo3ClusterCache\Domain\Contract\LocalPayloadStorePort $store, bool $bareBytes): ReadCacheEntry
    {
        return new ReadCacheEntry(
            metadataCache: $this->metadataCache,
            localStore: $store,
            compressorsByAlgo: CodecRegistry::all(),
            clock: $this->clock,
            metrics: $this->metrics,
            bareBytes: $bareBytes,
        );
    }

    private function capturedDiskBytes(InMemoryLocalPayloadStore $store): string
    {
        $hashes = iterator_to_array($store->iterateAll());
        self::assertCount(1, $hashes);
        $reflection = new \ReflectionClass($store);
        /** @var array<string, string> $map */
        $map = $reflection->getProperty('files')->getValue($store);

        return $map[$hashes[0]->digest];
    }
}
