<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Domain\Model;

final readonly class PayloadReference
{
    public function __construct(
        public string $path,
        public PayloadHash $hash,
    ) {}

    public static function build(string $localPath, PayloadHash $hash): self
    {
        $shard = $hash->prefix(2);
        $absolute = rtrim($localPath, '/') . '/' . $shard . '/' . $hash->digest;

        return new self($absolute, $hash);
    }

    public function directory(): string
    {
        return \dirname($this->path);
    }
}
