<?php

/**
 * OpenRegister test stubs.
 *
 * Provides minimal class declarations for OpenRegister types referenced by
 * hard-typed constructor parameters on OpenBuilt controllers/services. These
 * stubs are only declared when the real OpenRegister sources are NOT present
 * on the autoload path (e.g. CI runs the unit suite without the sibling app).
 *
 * Each stub declares the call surface used by OpenBuilt — `searchObjects`,
 * `find`, `findAll`, `saveObject` on ObjectService; `find` on the mappers —
 * so PHPUnit's `createMock()` produces a usable test double for each.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service {

    if (class_exists(ObjectService::class, autoload: false) === false) {
        /**
         * Stub ObjectService — call surface only; tests mock the methods.
         *
         * @SuppressWarnings(PHPMD.ShortClassName)
         */
        class ObjectService
        {
            /**
             * @param array<string, mixed> $query
             *
             * @return array<int, mixed>
             */
            public function searchObjects(array $query = []): array
            {
                return [];
            }

            /**
             * @return mixed
             */
            public function find(string $id, string $register = '', string $schema = ''): mixed
            {
                return null;
            }

            /**
             * @param array<string, mixed> $config
             *
             * @return array<int, mixed>
             */
            public function findAll(array $config = []): array
            {
                return [];
            }

            /**
             * @param array<string, mixed> $object
             *
             * @return mixed
             */
            public function saveObject(array $object, string $register = '', string $schema = ''): mixed
            {
                return null;
            }
        }
    }
}

namespace OCA\OpenRegister\Db {

    if (class_exists(RegisterMapper::class, autoload: false) === false) {
        /**
         * Stub RegisterMapper.
         */
        class RegisterMapper
        {
            /**
             * @return mixed
             */
            public function find(string|int $idOrSlug, bool $_multitenancy = true): mixed
            {
                return null;
            }
        }
    }

    if (class_exists(SchemaMapper::class, autoload: false) === false) {
        /**
         * Stub SchemaMapper.
         */
        class SchemaMapper
        {
            /**
             * @return mixed
             */
            public function find(string|int $idOrSlug, bool $_multitenancy = true): mixed
            {
                return null;
            }
        }
    }
}
