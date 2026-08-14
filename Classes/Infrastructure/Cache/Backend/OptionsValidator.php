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

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed> Normalised options with defaults applied
     *
     * @throws InvalidCacheException
     */
    public function validateAndApplyDefaults(array $options): array
    {
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

        return $normalized;
    }
}
