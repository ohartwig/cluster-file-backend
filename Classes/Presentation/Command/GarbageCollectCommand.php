<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Presentation\Command;

use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Infrastructure\GarbageCollect\BackendGarbageCollectRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'clusterfilebackend:gc',
    description: 'Triggers garbage collection of the metadata cache for a ClusterFileBackend namespace.',
)]
final class GarbageCollectCommand extends Command
{
    private const int EXIT_OK = 0;
    private const int EXIT_GENERAL_ERROR = 1;
    private const int EXIT_ARG_ERROR = 64;

    public function __construct(
        private readonly BackendGarbageCollectRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'namespace',
                null,
                InputOption::VALUE_REQUIRED,
                'Fully qualified cache namespace: cfb:{env}:{instance}:{cacheName}',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No write operations')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $namespaceArg = $input->getOption('namespace');
        if (!\is_string($namespaceArg) || '' === $namespaceArg) {
            $output->writeln('<error>--namespace is required</error>');

            return self::EXIT_ARG_ERROR;
        }

        $namespace = $this->parseNamespace($namespaceArg, $output);
        if (null === $namespace) {
            return self::EXIT_ARG_ERROR;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        try {
            $report = $this->runner->run($namespace, $dryRun);
            $output->writeln((string) json_encode($report->toArray(), \JSON_THROW_ON_ERROR));

            return self::EXIT_OK;
        } catch (\Throwable $e) {
            $output->writeln('<error>GC failed: ' . $e->getMessage() . '</error>');

            return self::EXIT_GENERAL_ERROR;
        }
    }

    private function parseNamespace(string $value, OutputInterface $output): ?CacheNamespace
    {
        $namespace = CacheNamespace::fromString($value);
        if (null === $namespace) {
            $output->writeln(\sprintf('<error>Invalid --namespace "%s"</error>', $value));
        }

        return $namespace;
    }
}
