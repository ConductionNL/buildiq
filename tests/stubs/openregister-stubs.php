<?php

/**
 * OpenRegister test stubs.
 *
 * Provides minimal class declarations for the OpenRegister types that
 * OpenBuild's controllers, services, repair steps and listeners reference
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
 * @category Test
 * @package  OCA\OpenRegister\Stubs
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
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
            }//end getObject()

            /**
             * @return array<string, mixed>
             */
            public function jsonSerialize(): array
            {
                return ($this->object ?? []);
            }//end jsonSerialize()
        }//end class
    }//end if

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
            }//end getSchemas()

            /**
             * @param array<int, int>|string $schemas Schema id list.
             *
             * @return static
             */
            public function setSchemas($schemas): static
            {
                $this->schemas = (array) $schemas;
                return $this;
            }//end setSchemas()
        }//end class
    }//end if

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
        }//end class
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
            public function find(string|int $id, ?array $_extend=[], ?bool $published=null, bool $_rbac=true, bool $_multitenancy=true): Register
            {
                return new Register();
            }//end find()

            /**
             * @param array<string, mixed> $object
             *
             * @return Register
             */
            public function createFromArray(array $object): Register
            {
                return new Register();
            }//end createFromArray()

            /**
             * @return \OCP\AppFramework\Db\Entity
             */
            public function update(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
            {
                return $entity;
            }//end update()
        }//end class
    }//end if

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
            public function find(string|int $id, ?array $_extend=[], ?bool $published=null, bool $_rbac=true, bool $_multitenancy=true): Schema
            {
                return new Schema();
            }//end find()

            /**
             * @param array<string, mixed> $object
             *
             * @return Schema
             */
            public function createFromArray(array $object): Schema
            {
                return new Schema();
            }//end createFromArray()
        }//end class
    }//end if

    if (class_exists(AuditTrail::class, autoload: false) === false) {
        /**
         * Stub AuditTrail entity — returned by AuditTrailMapper::createAuditTrailEntry.
         */
        class AuditTrail extends \OCP\AppFramework\Db\Entity
        {
        }//end class
    }

    if (class_exists(AuditTrailMapper::class, autoload: false) === false) {
        /**
         * Stub AuditTrailMapper — call surface covering the methods used by
         * OpenBuild services. `getDistinctActorCount` is the new aggregation
         * delivered by `openregister-distinct-actor-aggregation`; declaring
         * it here lets the unit tests mock it before the OR floor lands.
         */
        class AuditTrailMapper
        {
            /**
             * @param array<string, mixed> $context
             *
             * @return AuditTrail
             */
            public function createAuditTrailEntry(ObjectEntity $object, string $action, array $context=[]): AuditTrail
            {
                return new AuditTrail();
            }//end createAuditTrailEntry()

            /**
             * @param array<int> $schemaIds Schema IDs to aggregate over.
             * @param int        $hours     Window in hours.
             *
             * @return int Distinct actor count.
             */
            public function getDistinctActorCount(array $schemaIds, int $hours): int
            {
                return 0;
            }//end getDistinctActorCount()

            /**
             * @param array<int> $schemaIds Schema IDs to aggregate over.
             *
             * @return array<int, array<string, int>>
             */
            public function getStatisticsGroupedBySchema(array $schemaIds): array
            {
                return [];
            }//end getStatisticsGroupedBySchema()

            /**
             * @return array{labels: array<int, string>, series: array<int, array{name: string, data: array<int, int>}>}
             */
            public function getActionChartData(?\DateTime $from=null, ?\DateTime $till=null, ?int $registerId=null, ?int $schemaId=null): array
            {
                return ['labels' => [], 'series' => []];
            }//end getActionChartData()
        }//end class
    }//end if
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
             * @param array<string, mixed>    $query
             * @param array<int, int>|null    $ids
             * @param array<int, string>|null $views
             *
             * @return array<int, mixed>|int
             */
            public function searchObjects(array $query=[], bool $_rbac=true, bool $_multitenancy=true, ?array $ids=null, ?string $uses=null, ?array $views=null): array|int
            {
                return [];
            }//end searchObjects()

            /**
             * @param array<string, mixed> $filters
             *
             * @return array<int, mixed>|int
             */
            public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters=[], bool $_rbac=true, bool $_multitenancy=true): array|int
            {
                return [];
            }//end searchObjectsBySlug()

            /**
             * @param array<int, string>|null $_extend
             *
             * @return \OCA\OpenRegister\Db\ObjectEntity|null
             */
            public function find(int|string $id, ?array $_extend=[], bool $files=false, mixed $register=null, mixed $schema=null, bool $_rbac=true, bool $_multitenancy=true): ?\OCA\OpenRegister\Db\ObjectEntity
            {
                return null;
            }//end find()

            /**
             * @param array<string, mixed> $config
             *
             * @return array<int, mixed>
             */
            public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array
            {
                return [];
            }//end findAll()

            /**
             * @param array<string, mixed>|\OCA\OpenRegister\Db\ObjectEntity $object
             * @param array<int, string>|null                                $extend
             * @param array<string, mixed>|null                              $uploadedFiles
             *
             * @return \OCA\OpenRegister\Db\ObjectEntity
             */
            public function saveObject(array|\OCA\OpenRegister\Db\ObjectEntity $object, ?array $extend=[], mixed $register=null, mixed $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true, bool $silent=false, ?array $uploadedFiles=null): \OCA\OpenRegister\Db\ObjectEntity
            {
                return new \OCA\OpenRegister\Db\ObjectEntity();
            }//end saveObject()

            /**
             * @return bool
             */
            public function deleteObject(string $uuid, bool $_rbac=true, bool $_multitenancy=true): bool
            {
                return true;
            }//end deleteObject()

            /**
             * @return array<string, mixed>
             */
            public function lockObject(string $identifier, ?string $process=null, ?int $duration=null): array
            {
                return [];
            }//end lockObject()

            /**
             * @return bool
             */
            public function unlockObject(string|int $identifier): bool
            {
                return true;
            }//end unlockObject()

            /**
             * @return array<string, mixed>|null
             */
            public function getLockInfo(string $identifier): ?array
            {
                return null;
            }//end getLockInfo()

            /**
             * Stub setter for the current register context. Real OR signature
             * is `setRegister(Register|string|int): static`.
             *
             * @param mixed $register Register reference.
             *
             * @return static
             */
            public function setRegister(mixed $register): static
            {
                return $this;
            }//end setRegister()

            /**
             * Stub setter for the current schema context. Real OR signature
             * is `setSchema(Schema|string|int): static`.
             *
             * @param mixed $schema Schema reference.
             *
             * @return static
             */
            public function setSchema(mixed $schema): static
            {
                return $this;
            }//end setSchema()

            /**
             * Stub object count — returns 0 by default.
             *
             * @param array<string, mixed> $config Optional config.
             *
             * @return int
             */
            public function count(array $config=[]): int
            {
                return 0;
            }//end count()
        }//end class
    }//end if

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
            public function importFromApp(string $appId, array $data, string $version, bool $force=false): array
            {
                return [];
            }//end importFromApp()
        }//end class
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
            }//end delete()

            /**
             * @param array<int, string>|null $_extend
             *
             * @return \OCA\OpenRegister\Db\Register
             */
            public function find(int|string $id, ?array $_extend=[], bool $_multitenancy=true): \OCA\OpenRegister\Db\Register
            {
                return new \OCA\OpenRegister\Db\Register();
            }//end find()
        }//end class
    }//end if

    if (class_exists(FileService::class, autoload: false) === false) {
        /**
         * Stub FileService — `getFile` call surface used by IconService.
         *
         * The real OR FileService wraps Nextcloud Files and returns an
         * OCP\Files\File node.  Tests mock `getFile()` to return a mock
         * File node or to throw, so only the method signature needs to
         * match here.
         */
        class FileService
        {
            /**
             * Retrieve a file attached to an object.
             *
             * @param string $object UUID of the owning object.
             * @param string $file   File name / path to retrieve.
             *
             * @return \OCP\Files\File The file node.
             */
            public function getFile(string $object, string $file): \OCP\Files\File
            {
                // Stub — tests mock this method; real implementation is in OR.
                throw new \RuntimeException('FileService::getFile stub — must be mocked in tests.');
            }//end getFile()
        }//end class
    }//end if
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
                private readonly string $action='',
                private readonly string $from='',
                private readonly string $to='',
                private readonly ?string $userId=null,
                private readonly string $register='',
                private readonly string $schema='',
            ) {
                parent::__construct();
            }//end __construct()

            /**
             * @return \OCA\OpenRegister\Db\ObjectEntity
             */
            public function getObject(): \OCA\OpenRegister\Db\ObjectEntity
            {
                return $this->object;
            }//end getObject()

            /**
             * @return string
             */
            public function getFrom(): string
            {
                return $this->from;
            }//end getFrom()

            /**
             * @return string
             */
            public function getTo(): string
            {
                return $this->to;
            }//end getTo()

            /**
             * @return string|null
             */
            public function getUserId(): ?string
            {
                return $this->userId;
            }//end getUserId()

            /**
             * @return string
             */
            public function getSchema(): string
            {
                return $this->schema;
            }//end getSchema()

            /**
             * @return string
             */
            public function getRegister(): string
            {
                return $this->register;
            }//end getRegister()

            /**
             * @return string
             */
            public function getAction(): string
            {
                return $this->action;
            }//end getAction()
        }//end class
    }//end if

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
            }//end __construct()

            public function getObject(): \OCA\OpenRegister\Db\ObjectEntity
            {
                return $this->object;
            }//end getObject()

            public function isPropagationStopped(): bool
            {
                return $this->propagationStopped;
            }//end isPropagationStopped()

            public function stopPropagation(): void
            {
                $this->propagationStopped = true;
            }//end stopPropagation()

            /**
             * @param array<string, mixed> $errors
             */
            public function setErrors(array $errors): void
            {
                $this->errors = $errors;
            }//end setErrors()

            /**
             * @return array<string, mixed>
             */
            public function getErrors(): array
            {
                return $this->errors;
            }//end getErrors()
        }//end class
    }//end if

    if (class_exists(ObjectUpdatingEvent::class, autoload: false) === false) {
        /**
         * Stub ObjectUpdatingEvent — exposes the new object via getNewObject()
         * (the real OR class signature is `__construct(ObjectEntity $newObject,
         * ?ObjectEntity $oldObject = null)`). Tests that previously called
         * `$event->getObject()` SHALL be updated to `getNewObject()`.
         */
        class ObjectUpdatingEvent extends \OCP\EventDispatcher\Event implements \Psr\EventDispatcher\StoppableEventInterface
        {

            private bool $propagationStopped = false;

            /**
             * @var array<string, mixed>
             */
            private array $errors = [];

            public function __construct(
                private readonly \OCA\OpenRegister\Db\ObjectEntity $newObject,
                private readonly ?\OCA\OpenRegister\Db\ObjectEntity $oldObject=null,
            ) {
                parent::__construct();
            }//end __construct()

            public function getNewObject(): \OCA\OpenRegister\Db\ObjectEntity
            {
                return $this->newObject;
            }//end getNewObject()

            public function getOldObject(): ?\OCA\OpenRegister\Db\ObjectEntity
            {
                return $this->oldObject;
            }//end getOldObject()

            public function isPropagationStopped(): bool
            {
                return $this->propagationStopped;
            }//end isPropagationStopped()

            public function stopPropagation(): void
            {
                $this->propagationStopped = true;
            }//end stopPropagation()

            /**
             * @param array<string, mixed> $errors
             */
            public function setErrors(array $errors): void
            {
                $this->errors = $errors;
            }//end setErrors()

            /**
             * @return array<string, mixed>
             */
            public function getErrors(): array
            {
                return $this->errors;
            }//end getErrors()
        }//end class
    }//end if

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
            public function register(string $appId, string $route, string $label, array $metadata=[]): void
            {
            }//end register()
        }//end class
    }
}

namespace OCA\OpenRegister\Lifecycle {

    if (class_exists(GuardResult::class, autoload: false) === false) {
        /**
         * Stub GuardResult — allow/deny verdict value object returned by guards.
         *
         * Mirrors the real OR value object so the ApplicationVersionOwnerGuard
         * under test can build verdicts and callers can inspect `isAllowed()` /
         * `getMessage()`.
         */
        final class GuardResult
        {
            /**
             * Construct a verdict value object.
             *
             * @param bool        $allowed Whether the transition is allowed.
             * @param string|null $message Optional deny message.
             *
             * @return void
             */
            private function __construct(private bool $allowed, private ?string $message)
            {
            }//end __construct()

            /**
             * Return an allow verdict.
             *
             * @return self Allow verdict.
             */
            public static function allow(): self
            {
                return new self(true, null);
            }//end allow()

            /**
             * Return a deny verdict with the given message.
             *
             * @param string $message Deny reason.
             *
             * @return self Deny verdict.
             */
            public static function deny(string $message): self
            {
                return new self(false, $message);
            }//end deny()

            /**
             * Return whether the verdict allows the transition.
             *
             * @return bool True when allowed.
             */
            public function isAllowed(): bool
            {
                return $this->allowed;
            }//end isAllowed()

            /**
             * Return the deny message, or null for allow verdicts.
             *
             * @return string|null Deny message, or null.
             */
            public function getMessage(): ?string
            {
                return $this->message;
            }//end getMessage()
        }//end class
    }//end if

    if (interface_exists(LifecycleGuardInterface::class, autoload: false) === false) {
        /**
         * Stub LifecycleGuardInterface — the contract apps implement to
         * authorise a lifecycle transition.
         */
        interface LifecycleGuardInterface
        {
            /**
             * Authorise a lifecycle transition.
             *
             * @param array<string, mixed> $object The object payload.
             * @param string               $action The transition action.
             * @param string               $userId The acting user UID.
             *
             * @return GuardResult Allow or deny verdict.
             */
            public function check(array $object, string $action, string $userId): GuardResult;
        }//end interface
    }
}

namespace OCA\OpenRegister\Lifecycle {

    if (class_exists(GuardResult::class, autoload: false) === false) {
        /**
         * Stub GuardResult — allow/deny verdict value object returned by guards.
         *
         * Mirrors the real OR value object so the ApplicationVersionOwnerGuard
         * under test can build verdicts and callers can inspect `isAllowed()` /
         * `getMessage()`.
         */
        final class GuardResult
        {
            /**
             * @param bool        $allowed Whether the transition is allowed.
             * @param string|null $message Optional deny message.
             *
             * @return void
             */
            private function __construct(private bool $allowed, private ?string $message)
            {
            }

            /**
             * @return self Allow verdict.
             */
            public static function allow(): self
            {
                return new self(true, null);
            }

            /**
             * @param string $message Deny reason.
             *
             * @return self Deny verdict.
             */
            public static function deny(string $message): self
            {
                return new self(false, $message);
            }

            /**
             * @return bool True when allowed.
             */
            public function isAllowed(): bool
            {
                return $this->allowed;
            }

            /**
             * @return string|null Deny message, or null.
             */
            public function getMessage(): ?string
            {
                return $this->message;
            }
        }
    }

    if (interface_exists(LifecycleGuardInterface::class, autoload: false) === false) {
        /**
         * Stub LifecycleGuardInterface — the contract apps implement to
         * authorise a lifecycle transition.
         */
        interface LifecycleGuardInterface
        {
            /**
             * @param array<string, mixed> $object The object payload.
             * @param string               $action The transition action.
             * @param string               $userId The acting user UID.
             *
             * @return GuardResult Allow or deny verdict.
             */
            public function check(array $object, string $action, string $userId): GuardResult;
        }
    }
}

namespace OCA\OpenRegister\AppHost {

    if (class_exists(Bootstrap::class, autoload: false) === false) {
        /**
         * Stub AppHost Bootstrap — the real engine wires every standard
         * plumbing class from the leaf app's Application::register(). The stub
         * is a no-op so unit tests that instantiate Application do not fatal
         * when the sibling openregister app is off the autoload path.
         */
        class Bootstrap
        {
            /**
             * No-op stub of the one-call registrar.
             *
             * @param mixed                $context Registration context.
             * @param string               $appId   Leaf app id.
             * @param array<string, mixed> $options Options.
             *
             * @return void
             */
            public static function register($context, string $appId, array $options=[]): void
            {
            }
        }
    }

    if (class_exists(Routes::class, autoload: false) === false) {
        /**
         * Stub AppHost Routes — pure array builder. The stub returns just the
         * passed $extra wrapped in the NC routes shape so a routes.php require
         * under the unit harness does not fatal.
         */
        class Routes
        {
            /**
             * Stub of the canonical route table builder.
             *
             * @param array<int, array<string, mixed>> $extra App-specific routes.
             *
             * @return array{routes: array<int, array<string, mixed>>}
             */
            public static function standard(array $extra=[]): array
            {
                return ['routes' => $extra];
            }
        }
    }
}

namespace OCA\OpenRegister\AppHost\Settings {

    if (class_exists(GenericAdminSettings::class, autoload: false) === false) {
        /**
         * Stub GenericAdminSettings — OpenBuild's AdminSettings extends this.
         * Implements IDelegatedSettings so the subclass satisfies the NC
         * settings framework's type expectations under the unit harness.
         */
        class GenericAdminSettings implements \OCP\Settings\IDelegatedSettings
        {

            /**
             * Constructor mirroring the real engine signature.
             *
             * @param string $appId     Leaf app id.
             * @param string $sectionId Section id.
             * @param int    $priority  Priority.
             * @param mixed  $appManager   App manager.
             * @param mixed  $initialState Initial state service.
             */
            public function __construct(
                protected readonly string $appId='',
                protected readonly string $sectionId='',
                protected readonly int $priority=10,
                protected readonly mixed $appManager=null,
                protected readonly mixed $initialState=null
            ) {
            }

            /**
             * @return mixed
             */
            public function getForm()
            {
                return null;
            }

            /**
             * @return string
             */
            public function getSection(): string
            {
                return $this->sectionId;
            }

            /**
             * @return int
             */
            public function getPriority(): int
            {
                return $this->priority;
            }

            /**
             * @return string|null
             */
            public function getName(): ?string
            {
                return null;
            }

            /**
             * @return array<string, string[]>
             */
            public function getAuthorizedAppConfig(): array
            {
                return [];
            }
        }
    }

    if (class_exists(GenericSettingsSection::class, autoload: false) === false) {
        /**
         * Stub GenericSettingsSection — OpenBuild's SettingsSection extends this.
         */
        class GenericSettingsSection implements \OCP\Settings\IIconSection
        {

            /**
             * Constructor mirroring the real engine signature.
             *
             * @param string $sectionId    Section id.
             * @param string $name         Display name.
             * @param string $appId        Owning app id.
             * @param string $iconFile     Icon file.
             * @param int    $priority     Priority.
             * @param mixed  $urlGenerator URL generator.
             */
            public function __construct(
                protected readonly string $sectionId='',
                protected readonly string $name='',
                protected readonly string $appId='',
                protected readonly string $iconFile='',
                protected readonly int $priority=75,
                protected readonly mixed $urlGenerator=null
            ) {
            }

            /**
             * @return string
             */
            public function getID(): string
            {
                return $this->sectionId;
            }

            /**
             * @return string
             */
            public function getName(): string
            {
                return $this->name;
            }

            /**
             * @return int
             */
            public function getPriority(): int
            {
                return $this->priority;
            }

            /**
             * @return string
             */
            public function getIcon(): string
            {
                return '';
            }
        }
    }
}
