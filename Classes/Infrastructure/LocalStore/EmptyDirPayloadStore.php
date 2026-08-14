<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\LocalStore;

use Moselwal\Typo3ClusterCache\Domain\Contract\LocalPayloadStorePort;
use Moselwal\Typo3ClusterCache\Domain\Exception\LocalStoreWriteException;
use Moselwal\Typo3ClusterCache\Domain\Exception\PayloadIntegrityException;
use Moselwal\Typo3ClusterCache\Domain\Exception\PayloadNotFoundException;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadChecksum;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadHash;
use Moselwal\Typo3ClusterCache\Domain\Model\PayloadReference;

final class EmptyDirPayloadStore implements LocalPayloadStorePort
{
    /**
     * @param string $fileSuffix Appended to every payload filename. The
     *                           default empty string keeps backwards compatibility for binary
     *                           caches; PhpFrontend caches inject `.php` here so OPcache can
     *                           ingest the file via `require_once`. The suffix is opaque to the
     *                           hash — it is purely a filesystem concern.
     */
    public function __construct(
        private readonly string $localPath,
        private readonly string $fileSuffix = '',
    ) {
        if ('' === $localPath || '/' !== $localPath[0]) {
            throw new \InvalidArgumentException(\sprintf('EmptyDirPayloadStore.localPath must be an absolute path, got "%s"', $localPath));
        }
        if ('' !== $fileSuffix && 1 !== preg_match('/^\.[a-z0-9]{1,16}$/', $fileSuffix)) {
            throw new \InvalidArgumentException(\sprintf('EmptyDirPayloadStore.fileSuffix must match /^\.[a-z0-9]{1,16}$/, got "%s"', $fileSuffix));
        }
    }

    public function pathFor(PayloadHash $hash): string
    {
        return PayloadReference::build($this->localPath, $hash)->path . $this->fileSuffix;
    }

    public function exists(PayloadHash $hash): bool
    {
        return is_file($this->pathFor($hash));
    }

    public function readVerified(PayloadHash $hash, PayloadChecksum $checksum): string
    {
        $path = $this->pathFor($hash);
        if (!is_file($path)) {
            throw new PayloadNotFoundException(\sprintf('payload file not found for hash %s', $hash->prefix(12)));
        }
        if (is_link($path)) {
            // A symlink as the payload-store entry means something or
            // somebody bypassed our atomic-write path. Treat as integrity
            // failure to trigger the broken-state-fan-out logic.
            throw new PayloadIntegrityException(\sprintf('payload path is a symlink for hash %s', $hash->prefix(12)));
        }
        $bytes = @file_get_contents($path);
        if (false === $bytes) {
            throw new PayloadNotFoundException(\sprintf('payload file unreadable for hash %s', $hash->prefix(12)));
        }
        $actual = PayloadChecksum::ofBytes($bytes);
        if (!$actual->equals($checksum)) {
            throw new PayloadIntegrityException(\sprintf('checksum mismatch for hash %s', $hash->prefix(12)));
        }

        return $bytes;
    }

    public function write(PayloadHash $hash, string $bytes): void
    {
        $reference = PayloadReference::build($this->localPath, $hash);
        $targetPath = $reference->path . $this->fileSuffix;
        $directory = $reference->directory();

        // Defense-in-depth against symlink-attack: if the shard directory
        // or the target file path is a symbolic link, refuse the write.
        // The K8s emptyDir convention forbids shared mounts for $localPath,
        // but this guards against operator misconfiguration (sidecar with
        // write access placing a symlink to /etc/typo3/...).
        if (is_link($directory)) {
            throw new LocalStoreWriteException(\sprintf('shard directory is a symlink and refused: %s', basename($directory)));
        }
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new LocalStoreWriteException(\sprintf('failed to create shard directory %s', basename($directory)));
        }
        if (is_link($targetPath)) {
            throw new LocalStoreWriteException(\sprintf('target path is a symlink and refused: %s', basename($targetPath)));
        }

        $tmp = @tempnam($directory, '.cfb.tmp.');
        if (false === $tmp) {
            throw new LocalStoreWriteException(\sprintf('tempnam() failed in shard %s', basename($directory)));
        }
        $written = @file_put_contents($tmp, $bytes, \LOCK_EX);
        if (false === $written || $written !== \strlen($bytes)) {
            @unlink($tmp);
            throw new LocalStoreWriteException(\sprintf('partial write: %d of %d bytes', (int) $written, \strlen($bytes)));
        }
        @chmod($tmp, 0o640);
        if (!@rename($tmp, $targetPath)) {
            @unlink($tmp);
            throw new LocalStoreWriteException(\sprintf('atomic rename failed for hash %s', $hash->prefix(12)));
        }
    }

    public function delete(PayloadHash $hash): void
    {
        $path = $this->pathFor($hash);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function probe(): bool
    {
        if (!is_dir($this->localPath) && !@mkdir($this->localPath, 0o750, true) && !is_dir($this->localPath)) {
            return false;
        }
        $sentinel = $this->localPath . '/.cfb-probe';
        $bytes = bin2hex(random_bytes(8));
        if (false === @file_put_contents($sentinel, $bytes, \LOCK_EX)) {
            return false;
        }
        $readBack = @file_get_contents($sentinel);
        @unlink($sentinel);

        return $bytes === $readBack;
    }

    public function iterateAll(): iterable
    {
        if (!is_dir($this->localPath)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->localPath,
                \FilesystemIterator::SKIP_DOTS,
            ),
        );
        $expectedSuffix = $this->fileSuffix;
        $suffixLen = \strlen($expectedSuffix);
        $hexPattern = '/^[a-f0-9]{64}$/';
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if ('' !== $expectedSuffix) {
                if (!str_ends_with($name, $expectedSuffix)) {
                    continue;
                }
                $name = substr($name, 0, -$suffixLen);
            }
            if (1 !== preg_match($hexPattern, $name)) {
                continue;
            }
            yield new PayloadHash($name);
        }
    }
}
