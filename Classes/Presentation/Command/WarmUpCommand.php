<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Presentation\Command;

use Moselwal\Typo3ClusterCache\Domain\Model\CacheNamespace;
use Moselwal\Typo3ClusterCache\Infrastructure\WarmUp\BackendWarmUpRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Triggers a pre-flight warm-up for one or more cluster cache namespaces.
 * Typically invoked at deployment time:
 *
 *   ./vendor/bin/typo3 clusterfilebackend:warmup \
 *       --namespace=cfb:prod:website-a:pages \
 *       --namespace=cfb:prod:website-a:pagesection
 *
 * Exits 0 if every namespace warmed up successfully, non-zero otherwise.
 * Emits one JSON line per namespace with health and probe stats so that
 * deployment automation can act on it.
 */
#[AsCommand(
    name: 'clusterfilebackend:warmup',
    description: 'Pre-flight warm-up of cluster-cache namespaces (health, local-path, optional probes).',
)]
final class WarmUpCommand extends Command
{
    private const int EXIT_OK = 0;
    private const int EXIT_FAILED = 1;
    private const int EXIT_ARG_ERROR = 64;

    public function __construct(
        private readonly BackendWarmUpRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'namespace',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Fully qualified cache namespace, may be repeated: cfb:{env}:{instance}:{cacheName}',
            )
            ->addOption(
                'identifiers',
                null,
                InputOption::VALUE_REQUIRED,
                'Optional CSV of cache identifiers to probe (presence check, no re-compute)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $namespaceArgs = $input->getOption('namespace');
        if (!\is_array($namespaceArgs) || [] === $namespaceArgs) {
            $output->writeln('<error>At least one --namespace is required</error>');

            return self::EXIT_ARG_ERROR;
        }

        $identifiers = $this->parseIdentifiers($input->getOption('identifiers'));
        $overallSucceeded = true;

        foreach ($namespaceArgs as $namespaceArg) {
            if (!\is_string($namespaceArg) || '' === $namespaceArg) {
                $output->writeln('<error>--namespace entries must be non-empty strings</error>');

                return self::EXIT_ARG_ERROR;
            }
            $namespace = $this->parseNamespace($namespaceArg, $output);
            if (null === $namespace) {
                return self::EXIT_ARG_ERROR;
            }
            try {
                $report = $this->runner->run($namespace, $identifiers);
                $output->writeln((string) json_encode($report->toArray(), \JSON_THROW_ON_ERROR));
                if (!$report->succeeded()) {
                    $overallSucceeded = false;
                }
            } catch (\Throwable $e) {
                $output->writeln(\sprintf('<error>Warm-up failed for %s: %s</error>', $namespaceArg, $e->getMessage()));
                $overallSucceeded = false;
            }
        }

        return $overallSucceeded ? self::EXIT_OK : self::EXIT_FAILED;
    }

    private function parseNamespace(string $value, OutputInterface $output): ?CacheNamespace
    {
        $namespace = CacheNamespace::fromString($value);
        if (null === $namespace) {
            $output->writeln(\sprintf('<error>Invalid --namespace "%s"</error>', $value));
        }

        return $namespace;
    }

    /**
     * @return list<string>
     */
    private function parseIdentifiers(mixed $value): array
    {
        if (!\is_string($value) || '' === $value) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn(string $part): bool => '' !== $part,
        ));
    }
}
