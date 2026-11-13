<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Tests\Unit\Infrastructure\LocalStore;

use Moselwal\Typo3ClusterCache\Infrastructure\LocalStore\FileType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileType::class)]
final class FileTypeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cfb-filetype-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (['file', 'link', 'dangling'] as $name) {
            @unlink($this->dir . '/' . $name);
        }
        @rmdir($this->dir . '/sub');
        @rmdir($this->dir);
    }

    public function testOneLstatTellsTheKinds(): void
    {
        file_put_contents($this->dir . '/file', 'x');
        symlink($this->dir . '/file', $this->dir . '/link');
        symlink($this->dir . '/nowhere', $this->dir . '/dangling');
        mkdir($this->dir . '/sub');

        self::assertSame(FileType::Regular, FileType::of(@lstat($this->dir . '/file')));
        self::assertSame(FileType::Symlink, FileType::of(@lstat($this->dir . '/link')), 'a link to a file is a link');
        self::assertSame(FileType::Symlink, FileType::of(@lstat($this->dir . '/dangling')), 'a link to nothing is a link too');
        self::assertSame(FileType::Other, FileType::of(@lstat($this->dir . '/sub')));
        self::assertSame(FileType::Missing, FileType::of(@lstat($this->dir . '/absent')));
    }
}
