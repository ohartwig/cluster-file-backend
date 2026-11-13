<?php

// SPDX-FileCopyrightText: 2026 Moselwal Digitalagentur GmbH
// SPDX-FileCopyrightText: 2026  Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Moselwal\Typo3ClusterCache\Infrastructure\Cache\Backend;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use TYPO3\CMS\Core\Cache\Exception\InvalidCacheException;

/**
 * Validates the `options[]` configuration of a ClusterFileBackend cache
 * record against the bundled JSON schema.
 */
final class OptionsValidator
{
    private const string SCHEMA_PATH = __DIR__ . '/../../../../Configuration/Backend/ClusterFileBackend.options.schema.json';

    /** Distinct option sets remembered per process; an installation has a handful. */
    private const int MAX_REMEMBERED = 64;

    /**
     * Option sets already validated in this process, by their serialized
     * form. TYPO3 builds a new CacheManager, and with it every backend, for
     * each request -- a FrankenPHP worker with a persistent bootstrap does not
     * keep it -- so without this each request reads and parses the schema and
     * validates the same options again for every ClusterFileBackend cache:
     * 144 of the 164 µs a backend took to construct. The schema is a file of
     * this package and the result depends on nothing else, so the same
     * options give the same result for the life of the process. Only valid
     * sets are remembered; an invalid one is rejected again every time.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $validated = [];

    /**
     * For tests: forget the validated option sets.
     *
     * @internal
     */
    public static function forgetValidated(): void
    {
        self::$validated = [];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed> Normalised options with defaults applied
     *
     * @throws InvalidCacheException
     */
    public function validateAndApplyDefaults(array $options): array
    {
        $key = serialize($options);
        if (isset(self::$validated[$key])) {
            return self::$validated[$key];
        }
        $schemaJson = @file_get_contents(self::SCHEMA_PATH);
        if (false === $schemaJson) {
            throw new InvalidCacheException(\sprintf('ClusterFileBackend options schema not found at %s', self::SCHEMA_PATH), 1747500001);
        }
        try {
            $schema = json_decode($schemaJson, false, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidCacheException('ClusterFileBackend options schema is invalid JSON: ' . $e->getMessage(), 1747500002, $e);
        }

        $data = json_decode(json_encode($options, \JSON_THROW_ON_ERROR), false);
        $validator = new Validator();
        $validator->validate($data, $schema, Constraint::CHECK_MODE_APPLY_DEFAULTS);

        if (!$validator->isValid()) {
            $messages = [];
            foreach ($validator->getErrors() as $error) {
                $messages[] = \sprintf('[%s] %s', $error['property'] ?? '?', $error['message'] ?? '?');
            }
            throw new InvalidCacheException("ClusterFileBackend options invalid:\n" . implode("\n", $messages), 1747500003);
        }

        /** @var array<string, mixed> $normalized */
        $normalized = json_decode(json_encode($data, \JSON_THROW_ON_ERROR), true);
        if (\count(self::$validated) < self::MAX_REMEMBERED) {
            self::$validated[$key] = $normalized;
        }

        return $normalized;
    }
}
