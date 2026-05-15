<?php

/**
 * OpenRegister test stubs.
 *
 * Provides minimal class declarations for the OpenRegister types that
 * OpenBuilt's controllers, services, repair steps and listeners reference
 * by hard-typed constructor parameters or return types. These stubs are
 * only declared when the real OpenRegister sources are NOT present on the
 * autoload path (e.g. CI runs the out-of-container unit suite without the
 * sibling app); each `class_exists(..., autoload: false)` guard makes them
 * a no-op when the real classes ARE loaded (in-container PHPUnit run).
 *
 * The stub signatures intentionally mirror the real classes' shapes so that
 * a test written against the real types (`$this->createMock(...)`,
 * `getMockBuilder(...)->onlyMethods([...])`, `->addMethods([...])`) behaves
 * identically whether it runs against the stub or the real class:
 *   - `ObjectEntity`, `Register`, `Schema` extend NC's `Entity` so magic
 *     getters (`getId()`, `getSlug()`) resolve via `__call` and must be
 *     supplied through `MockBuilder::addMethods()` exactly as in-container.
 *   - `ObjectService::find()` returns `?ObjectEntity`, `saveObject()` returns
 *     `ObjectEntity` — same as the real service — so a test that wires those
 *     to return arrays fails the same way on both sides.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db {

    if (class_exists(ObjectEntity::class, autoload: false) === false) {
        /**
         * Stub ObjectEntity — real call surface (`getObject`, `jsonSerialize`,
         * plus `getUuid()`/`getRegister()`/`getSchema()` via Entity's `__call`).
         */
        class ObjectEntity extends \OCP\AppFramework\Db\Entity
        {
            /**
             * Stub uuid column.
             *
             * @var string|null
             */
            protected ?string $uuid = null;

            /**
             * Stub serialised object payload.
             *
             * @var array<string, mixed>|null
             */
            protected ?array $object = [];

            /**
             * @return array<string, mixed>
             */
            public function getObject(): array
            {
                return ($this->object ?? []);
            }

            /**
             * @return array<string, mixed>
             */
            public function jsonSerialize(): array
            {
                return ($this->object ?? []);
            }
        }
    }

    if (class_exists(Register::class, autoload: false) === false) {
        /**
         * Stub Register — `getId()`/`getSlug()` resolve via Entity's `__call`;
         * `getSchemas()`/`setSchemas()` are real methods on the OR class.
         */
        class Register extends \OCP\AppFramework\Db\Entity
        {
            /**
             * Stub slug column.
             *
             * @var string|null
             */
            protected ?string $slug = null;

            /**
             * Stub schema-id list column.
             *
             * @var array<int, int>|null
             */
            protected ?array $schemas = [];

            /**
             * @return array<int, int>
             */
            public function getSchemas(): array
            {
                return ($this->schemas ?? []);
            }

            /**
             * @param array<int, int>|string $schemas Schema id list.
             *
             * @return static
             */
            public function setSchemas($schemas): static
            {
                $this->schemas = (array) $schemas;
                return $this;
            }
        }
    }

    if (class_exists(Schema::class, autoload: false) === false) {
        /**
         * Stub Schema — `getId()`/`getSlug()` resolve via Entity's `__call`.
         */
        class Schema extends \OCP\AppFramework\Db\Entity
        {
            /**
             * Stub slug column.
             *
             * @var string|null
             */
            protected ?string $slug = null;
        }
    }

    if (class_exists(RegisterMapper::class, autoload: false) === false) {
        /**
         * Stub RegisterMapper — `find`/`createFromArray`/`update` call surface.
         *
         * Parameter names mirror the real OR mapper so callers passing
         * `_multitenancy:` as a named argument resolve identically on both.
         */
        class RegisterMapper
        {
            /**
             * @param array<int, string>|null $_extend Eager-load relations (ignored).
             *
             * @return Register
             */
            public function find(string|int $id, ?array $_extend = [], ?bool $published = null, bool $_rbac = true, bool $_multitenancy = true): Register
            {
                return new Register();
            }

            /**
             * @param array<string, mixed> $object
             *
             * @return Register
             */
            public function createFromArray(array $object): Register
            {
                return new Register();
            }

            /**
             * @return \OCP\AppFramework\Db\Entity
             */
            public function update(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
            {
                return $entity;
            }
        }
    }

    if (class_exists(SchemaMapper::class, autoload: false) === false) {
        /**
         * Stub SchemaMapper — `find`/`createFromArray` call surface.
         *
         * Parameter names mirror the real OR mapper (`_multitenancy:`, etc.).
         */
        class SchemaMapper
        {
            /**
             * @param array<int, string>|null $_extend Eager-load relations (ignored).
             *
             * @return Schema
             */
            public function find(string|int $id, ?array $_extend = [], ?bool $published = null, bool $_rbac = true, bool $_multitenancy = true): Schema
            {
                return new Schema();
            }

            /**
             * @param array<string, mixed> $object
             *
             * @return Schema
             */
            public function createFromArray(array $object): Schema
            {
                return new Schema();
            }
        }
    }

    if (class_exists(AuditTrail::class, autoload: false) === false) {
        /**
         * Stub AuditTrail entity — returned by AuditTrailMapper::createAuditTrailEntry.
         */
        class AuditTrail extends \OCP\AppFramework\Db\Entity
        {
        }
    }

    if (class_exists(AuditTrailMapper::class, autoload: false) === false) {
        /**
         * Stub AuditTrailMapper — `createAuditTrailEntry` call surface.
         */
        class AuditTrailMapper
        {
            /**
             * @param array<string, mixed> $context
             *
             * @return AuditTrail
             */
            public function createAuditTrailEntry(ObjectEntity $object, string $action, array $context = []): AuditTrail
            {
                return new AuditTrail();
            }
        }
    }
}

namespace OCA\OpenRegister\Service {

    if (class_exists(ObjectService::class, autoload: false) === false) {
        /**
         * Stub ObjectService — call surface only; tests mock the methods.
         *
         * Method + parameter names mirror the real OR service so callers passing
         * named arguments (`query:`, `id:`, `object:`, `register:`, `schema:`,
         * `config:`, `filters:`, `registerSlug:`, `schemaSlug:`) resolve the same
         * way against the stub and the real class, and so PHPUnit's return-type
         * checks (`find(): ?ObjectEntity`, `saveObject(): ObjectEntity`) catch a
         * test that wires those to return a plain array.
         */
        class ObjectService
        {
            /**
             * @param array<string, mixed> $query
             * @param array<int, int>|null $ids
             * @param array<int, string>|null $views
             *
             * @return array<int, mixed>|int
             */
            public function searchObjects(array $query = [], bool $_rbac = true, bool $_multitenancy = true, ?array $ids = null, ?string $uses = null, ?array $views = null): array|int
            {
                return [];
            }

            /**
             * @param array<string, mixed> $filters
             *
             * @return array<int, mixed>|int
             */
            public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array|int
            {
                return [];
            }

            /**
             * @param array<int, string>|null $_extend
             *
             * @return \OCA\OpenRegister\Db\ObjectEntity|null
             */
            public function find(int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): ?\OCA\OpenRegister\Db\ObjectEntity
            {
                return null;
            }

            /**
             * @param array<string, mixed> $config
             *
             * @return array<int, mixed>
             */
            public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array
            {
                return [];
            }

            /**
             * @param array<string, mixed>|\OCA\OpenRegister\Db\ObjectEntity $object
             * @param array<int, string>|null $extend
             * @param array<string, mixed>|null $uploadedFiles
             *
             * @return \OCA\OpenRegister\Db\ObjectEntity
             */
            public function saveObject(array|\OCA\OpenRegister\Db\ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true, bool $silent = false, ?array $uploadedFiles = null): \OCA\OpenRegister\Db\ObjectEntity
            {
                return new \OCA\OpenRegister\Db\ObjectEntity();
            }

            /**
             * @return bool
             */
            public function deleteObject(string $uuid, bool $_rbac = true, bool $_multitenancy = true): bool
            {
                return true;
            }
        }
    }

    if (class_exists(ConfigurationService::class, autoload: false) === false) {
        /**
         * Stub ConfigurationService — `importFromApp` call surface.
         */
        class ConfigurationService
        {
            /**
             * @param array<string, mixed> $data
             *
             * @return array<string, mixed>
             */
            public function importFromApp(string $appId, array $data, string $version, bool $force = false): array
            {
                return [];
            }
        }
    }

    if (class_exists(RegisterService::class, autoload: false) === false) {
        /**
         * Stub RegisterService — `find`/`delete` call surface used by
         * ApplicationVersionService and MigrateToVersionedModel.
         */
        class RegisterService
        {
            /**
             * @return \OCA\OpenRegister\Db\Register
             */
            public function delete(\OCA\OpenRegister\Db\Register $register): \OCA\OpenRegister\Db\Register
            {
                return $register;
            }

            /**
             * @param array<int, string>|null $_extend
             *
             * @return \OCA\OpenRegister\Db\Register
             */
            public function find(int|string $id, ?array $_extend = [], bool $_multitenancy = true): \OCA\OpenRegister\Db\Register
            {
                return new \OCA\OpenRegister\Db\Register();
            }
        }
    }
}

namespace OCA\OpenRegister\Event {

    if (class_exists(ObjectTransitionedEvent::class, autoload: false) === false) {
        /**
         * Stub ObjectTransitionedEvent — accessors the listener calls.
         */
        class ObjectTransitionedEvent extends \OCP\EventDispatcher\Event
        {
            /**
             * Constructor mirrors the real event so `disableOriginalConstructor()`
             * is unnecessary but harmless when a test builds a mock against it.
             *
             * @return void
             */
            public function __construct(
                private readonly \OCA\OpenRegister\Db\ObjectEntity $object,
                private readonly string $action = '',
                private readonly string $from = '',
                private readonly string $to = '',
                private readonly ?string $userId = null,
                private readonly string $register = '',
                private readonly string $schema = '',
            ) {
                parent::__construct();
            }

            /**
             * @return \OCA\OpenRegister\Db\ObjectEntity
             */
            public function getObject(): \OCA\OpenRegister\Db\ObjectEntity
            {
                return $this->object;
            }

            /**
             * @return string
             */
            public function getFrom(): string
            {
                return $this->from;
            }

            /**
             * @return string
             */
            public function getTo(): string
            {
                return $this->to;
            }

            /**
             * @return string|null
             */
            public function getUserId(): ?string
            {
                return $this->userId;
            }

            /**
             * @return string
             */
            public function getSchema(): string
            {
                return $this->schema;
            }

            /**
             * @return string
             */
            public function getRegister(): string
            {
                return $this->register;
            }

            /**
             * @return string
             */
            public function getAction(): string
            {
                return $this->action;
            }
        }
    }

    if (class_exists(ObjectCreatingEvent::class, autoload: false) === false) {
        /**
         * Stub ObjectCreatingEvent — supports `stopPropagation`/`setErrors`/`getObject`.
         */
        class ObjectCreatingEvent extends \OCP\EventDispatcher\Event implements \Psr\EventDispatcher\StoppableEventInterface
        {
            private bool $propagationStopped = false;

            /**
             * @var array<string, mixed>
             */
            private array $errors = [];

            public function __construct(private readonly \OCA\OpenRegister\Db\ObjectEntity $object)
            {
                parent::__construct();
            }

            public function getObject(): \OCA\OpenRegister\Db\ObjectEntity
            {
                return $this->object;
            }

            public function isPropagationStopped(): bool
            {
                return $this->propagationStopped;
            }

            public function stopPropagation(): void
            {
                $this->propagationStopped = true;
            }

            /**
             * @param array<string, mixed> $errors
             */
            public function setErrors(array $errors): void
            {
                $this->errors = $errors;
            }

            /**
             * @return array<string, mixed>
             */
            public function getErrors(): array
            {
                return $this->errors;
            }
        }
    }

    if (class_exists(ObjectUpdatingEvent::class, autoload: false) === false) {
        /**
         * Stub ObjectUpdatingEvent — same shape as ObjectCreatingEvent.
         */
        class ObjectUpdatingEvent extends \OCP\EventDispatcher\Event implements \Psr\EventDispatcher\StoppableEventInterface
        {
            private bool $propagationStopped = false;

            /**
             * @var array<string, mixed>
             */
            private array $errors = [];

            public function __construct(private readonly \OCA\OpenRegister\Db\ObjectEntity $object)
            {
                parent::__construct();
            }

            public function getObject(): \OCA\OpenRegister\Db\ObjectEntity
            {
                return $this->object;
            }

            public function isPropagationStopped(): bool
            {
                return $this->propagationStopped;
            }

            public function stopPropagation(): void
            {
                $this->propagationStopped = true;
            }

            /**
             * @param array<string, mixed> $errors
             */
            public function setErrors(array $errors): void
            {
                $this->errors = $errors;
            }

            /**
             * @return array<string, mixed>
             */
            public function getErrors(): array
            {
                return $this->errors;
            }
        }
    }

    if (class_exists(DeepLinkRegistrationEvent::class, autoload: false) === false) {
        /**
         * Stub DeepLinkRegistrationEvent — `register` call surface.
         */
        class DeepLinkRegistrationEvent extends \OCP\EventDispatcher\Event
        {
            /**
             * @param array<string, mixed> $metadata
             *
             * @return void
             */
            public function register(string $appId, string $route, string $label, array $metadata = []): void
            {
            }
        }
    }
}
