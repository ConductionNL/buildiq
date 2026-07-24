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
             * Stub register-slug column.
             *
             * @var string|null
             */
            protected ?string $register = null;

            /**
             * Stub schema-slug column.
             *
             * @var string|null
             */
            protected ?string $schema = null;

            /**
             * Stub serialised object payload.
             *
             * @var array<string, mixed>|null
             */
            protected ?array $object = [];

            /**
             * Stub owner column (Nextcloud UID of the object's creator).
             * Real OR stamps this via applyOwnerAttribution() at
             * saveObject()-time; ExportJobService::impersonateJobOwner()
             * (#105) reads it back via getOwner() to impersonate the
             * ExportJob submitter for background-job lifecycle transitions.
             *
             * @var string|null
             */
            protected ?string $owner = null;

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

            /**
             * Declared explicitly (not left to Entity::__call magic) so
             * PHPUnit 10's `createMock()->method('getUuid')` can configure it
             * without `addMethods()` (removed in PHPUnit 10) — same rationale
             * as Schema::getId()/Register::getId() above (automation-approval-
             * steps AutomationApprovalTriggerListenerTest).
             *
             * @return string|null
             */
            public function getUuid(): ?string
            {
                return $this->uuid;
            }//end getUuid()

            /**
             * @param string|null $uuid The object uuid.
             *
             * @return void
             */
            public function setUuid(?string $uuid): void
            {
                $this->uuid = $uuid;
            }//end setUuid()

            /**
             * @return string|null
             */
            public function getRegister(): ?string
            {
                return $this->register;
            }//end getRegister()

            /**
             * @param string|null $register The register slug.
             *
             * @return void
             */
            public function setRegister(?string $register): void
            {
                $this->register = $register;
            }//end setRegister()

            /**
             * @return string|null
             */
            public function getSchema(): ?string
            {
                return $this->schema;
            }//end getSchema()

            /**
             * @param string|null $schema The schema slug.
             *
             * @return void
             */
            public function setSchema(?string $schema): void
            {
                $this->schema = $schema;
            }//end setSchema()

            /**
             * @return string|null
             */
            public function getOwner(): ?string
            {
                return $this->owner;
            }//end getOwner()

            /**
             * @param string|null $owner The NC uid of the object's owner/creator.
             *
             * @return void
             */
            public function setOwner(?string $owner): void
            {
                $this->owner = $owner;
            }//end setOwner()
        }//end class
    }//end if

    if (class_exists(Register::class, autoload: false) === false) {
        /**
         * Stub Register — declares getId()/getSlug() explicitly so PHPUnit
         * MockObject::method() can configure them without requiring addMethods().
         * The real OR class resolves these via Entity::__call; explicit
         * declarations here satisfy PHPUnit 10's MethodCannotBeConfiguredException.
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
             * Return the entity id.
             *
             * @return int
             */
            public function getId(): int
            {
                return (int) ($this->id ?? 0);
            }//end getId()

            /**
             * Return the register slug.
             *
             * @return string
             */
            public function getSlug(): string
            {
                return (string) ($this->slug ?? '');
            }//end getSlug()

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
         * Stub Schema — declares getId()/getSlug() explicitly so PHPUnit
         * MockObject::method() can configure them (same reason as Register).
         *
         * getTitle()/getDescription()/getRequired()/getProperties() mirror
         * the real OR Schema entity's own explicit/magic-getter shape
         * (lib/Db/Schema.php) — declared here (not left to Entity's magic
         * __call) for the same PHPUnit-10 MethodCannotBeConfiguredException
         * reason as getId()/getSlug(). Added for data-registers-runtime's
         * ExportService::bundleDataRegisterSchemas(), which reads a bound
         * data register's schema definitions.
         */
        class Schema extends \OCP\AppFramework\Db\Entity
        {

            /**
             * Stub slug column.
             *
             * @var string|null
             */
            protected ?string $slug = null;

            /**
             * Stub title column.
             *
             * @var string|null
             */
            protected ?string $title = null;

            /**
             * Stub description column.
             *
             * @var string|null
             */
            protected ?string $description = null;

            /**
             * Stub required-fields column.
             *
             * @var array<int, string>|null
             */
            protected ?array $required = [];

            /**
             * Stub properties column.
             *
             * @var array<string, mixed>|null
             */
            protected ?array $properties = [];

            /**
             * Stub version column.
             *
             * @var string|null
             */
            protected ?string $version = null;

            /**
             * Stub configuration column — holds `x-openregister-*` extension
             * annotations (e.g. `x-openregister-notifications`,
             * `x-openregister-lifecycle`) exactly like the real OR Schema
             * entity (automation-designer AutomationCompilerService reads
             * and rewrites this map in place).
             *
             * @var array<string, mixed>|null
             */
            protected ?array $configuration = null;

            /**
             * Return the entity id.
             *
             * @return int
             */
            public function getId(): int
            {
                return (int) ($this->id ?? 0);
            }//end getId()

            /**
             * Return the schema's `configuration` map (mirrors the real OR
             * Schema::getConfiguration()).
             *
             * @return array<string, mixed>|null
             */
            public function getConfiguration(): ?array
            {
                return $this->configuration;
            }//end getConfiguration()

            /**
             * Set the schema's `configuration` map (mirrors the real OR
             * Schema::setConfiguration() — no validation in the stub).
             *
             * @param array<string, mixed>|string|null $configuration Configuration payload.
             *
             * @return void
             */
            public function setConfiguration($configuration): void
            {
                if (is_string($configuration) === true) {
                    $decoded = json_decode($configuration, true);
                    $configuration = (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
                }

                $this->configuration = is_array($configuration) ? $configuration : null;
            }//end setConfiguration()

            /**
             * Return the schema slug.
             *
             * @return string
             */
            public function getSlug(): string
            {
                return (string) ($this->slug ?? '');
            }//end getSlug()

            /**
             * Return the schema title.
             *
             * @return string|null
             */
            public function getTitle(): ?string
            {
                return $this->title;
            }//end getTitle()

            /**
             * Return the schema description.
             *
             * @return string|null
             */
            public function getDescription(): ?string
            {
                return $this->description;
            }//end getDescription()

            /**
             * Return the schema's required property names.
             *
             * @return array<int, string>
             */
            public function getRequired(): array
            {
                return ($this->required ?? []);
            }//end getRequired()

            /**
             * Return the schema's JSON Schema `properties` map.
             *
             * @return array<string, mixed>
             */
            public function getProperties(): array
            {
                return ($this->properties ?? []);
            }//end getProperties()

            /**
             * Return the schema version (mirrors the real OR Schema::getVersion).
             *
             * @return string
             */
            public function getVersion(): string
            {
                return (string) ($this->version ?? '');
            }//end getVersion()
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
             * Signature mirrors the real OR RegisterMapper::find so callers
             * passing `_rbac:` / `_multitenancy:` as named arguments resolve
             * identically (the real mapper takes NO `_extend`/`published`).
             *
             * @return Register
             */
            public function find(string|int $id, bool $_rbac=true, bool $_multitenancy=true): Register
            {
                return new Register();
            }//end find()

            /**
             * Signature mirrors the real OR mapper so callers passing
             * `_rbac:` / `_multitenancy:` as named arguments resolve identically.
             *
             * @param array<string, mixed>|null $filters          Filter map (ignored).
             * @param array<int, string>|null   $searchConditions Search conditions (ignored).
             * @param array<string, mixed>|null $searchParams     Search params (ignored).
             *
             * @return array<int, Register>
             */
            public function findAll(?int $limit=null, ?int $offset=null, ?array $filters=[], ?array $searchConditions=[], ?array $searchParams=[], bool $_rbac=true, bool $_multitenancy=true): array
            {
                return [];
            }//end findAll()

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
             * Signature mirrors the real OR SchemaMapper::find (which takes
             * `_extend` but NO `published` param), so callers passing
             * `_multitenancy:` as a named argument resolve identically.
             *
             * @param array<int, string>|null $_extend Eager-load relations (ignored).
             *
             * @return Schema
             */
            public function find(string|int $id, ?array $_extend=[], bool $_rbac=true, bool $_multitenancy=true): Schema
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

            /**
             * Delete a schema (mirrors the real SchemaMapper::delete signature).
             *
             * @param \OCP\AppFramework\Db\Entity $entity The schema entity.
             *
             * @return Schema
             */
            public function delete(\OCP\AppFramework\Db\Entity $entity): Schema
            {
                return new Schema();
            }//end delete()

            /**
             * Update a schema entity (mirrors the real SchemaMapper::update
             * signature — used by AutomationCompilerService to rewrite a
             * schema's `configuration['x-openregister-notifications'/
             * 'x-openregister-lifecycle']` in place).
             *
             * @param \OCP\AppFramework\Db\Entity $entity The schema entity.
             *
             * @return \OCP\AppFramework\Db\Entity
             */
            public function update(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
            {
                return $entity;
            }//end update()
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

    if (class_exists(ApprovalChain::class, autoload: false) === false) {
        /**
         * Stub ApprovalChain (automation-approval-steps) — declares
         * getId()/getName()/getStepsArray()/getEnabled() explicitly (real
         * methods, not Entity::__call magic) so PHPUnit 10's
         * `createMock()->method(...)` can configure them without
         * `addMethods()` (removed in PHPUnit 10) — same rationale as
         * Schema/Register above.
         */
        class ApprovalChain extends \OCP\AppFramework\Db\Entity
        {
            /**
             * Stub name column.
             *
             * @var string|null
             */
            protected ?string $name = null;

            /**
             * Stub schema-id column.
             *
             * @var int|null
             */
            protected ?int $schemaId = null;

            /**
             * Stub steps column (JSON-encoded).
             *
             * @var string|null
             */
            protected ?string $steps = null;

            /**
             * Stub enabled column.
             *
             * @var bool
             */
            protected bool $enabled = true;

            /**
             * @return int
             */
            public function getId(): int
            {
                return (int) ($this->id ?? 0);
            }//end getId()

            /**
             * @return string|null
             */
            public function getName(): ?string
            {
                return $this->name;
            }//end getName()

            /**
             * @param string|null $name The chain name.
             *
             * @return void
             */
            public function setName(?string $name): void
            {
                $this->name = $name;
            }//end setName()

            /**
             * @return int|null
             */
            public function getSchemaId(): ?int
            {
                return $this->schemaId;
            }//end getSchemaId()

            /**
             * @param int|null $schemaId The owning schema id.
             *
             * @return void
             */
            public function setSchemaId(?int $schemaId): void
            {
                $this->schemaId = $schemaId;
            }//end setSchemaId()

            /**
             * @return array<int, array<string, mixed>>
             */
            public function getStepsArray(): array
            {
                if ($this->steps === null) {
                    return [];
                }

                return (json_decode($this->steps, true) ?? []);
            }//end getStepsArray()

            /**
             * @param array<int, array<string, mixed>>|string|null $steps The chain's step definitions.
             *
             * @return void
             */
            public function setSteps($steps): void
            {
                $this->steps = is_array($steps) ? json_encode($steps) : $steps;
            }//end setSteps()

            /**
             * @return bool
             */
            public function getEnabled(): bool
            {
                return $this->enabled;
            }//end getEnabled()

            /**
             * @param bool $enabled Whether the chain is active.
             *
             * @return void
             */
            public function setEnabled(bool $enabled): void
            {
                $this->enabled = $enabled;
            }//end setEnabled()
        }//end class
    }//end if

    if (class_exists(ApprovalChainMapper::class, autoload: false) === false) {
        /**
         * Stub ApprovalChainMapper — call surface only; tests mock the methods.
         */
        class ApprovalChainMapper
        {
            /**
             * @return ApprovalChain
             */
            public function find(int $id): ApprovalChain
            {
                return new ApprovalChain();
            }//end find()

            /**
             * @return array<int, ApprovalChain>
             */
            public function findAll(?int $limit=null, ?int $offset=null): array
            {
                return [];
            }//end findAll()

            /**
             * @return array<int, ApprovalChain>
             */
            public function findBySchema(int $schemaId): array
            {
                return [];
            }//end findBySchema()

            /**
             * @return ApprovalChain|null
             */
            public function findBySchemaAndName(int $schemaId, string $name): ?ApprovalChain
            {
                return null;
            }//end findBySchemaAndName()

            /**
             * @param array<string, mixed> $data
             *
             * @return ApprovalChain
             */
            public function createFromArray(array $data): ApprovalChain
            {
                return new ApprovalChain();
            }//end createFromArray()

            /**
             * @param array<string, mixed> $data
             *
             * @return ApprovalChain
             */
            public function updateFromArray(int $id, array $data): ApprovalChain
            {
                return new ApprovalChain();
            }//end updateFromArray()

            /**
             * @return ApprovalChain
             */
            public function delete(\OCP\AppFramework\Db\Entity $entity): ApprovalChain
            {
                return new ApprovalChain();
            }//end delete()
        }//end class
    }//end if

    if (class_exists(ApprovalStep::class, autoload: false) === false) {
        /**
         * Stub ApprovalStep (automation-approval-steps) — declares real
         * getters (see ApprovalChain docblock above for rationale).
         */
        class ApprovalStep extends \OCP\AppFramework\Db\Entity
        {
            /**
             * Stub chain-id column.
             *
             * @var int|null
             */
            protected ?int $chainId = null;

            /**
             * Stub object-uuid column.
             *
             * @var string|null
             */
            protected ?string $objectUuid = null;

            /**
             * Stub step-order column.
             *
             * @var int
             */
            protected int $stepOrder = 0;

            /**
             * Stub role column.
             *
             * @var string|null
             */
            protected ?string $role = null;

            /**
             * Stub status column.
             *
             * @var string|null
             */
            protected ?string $status = 'pending';

            /**
             * Stub decided-by column.
             *
             * @var string|null
             */
            protected ?string $decidedBy = null;

            /**
             * Stub comment column.
             *
             * @var string|null
             */
            protected ?string $comment = null;

            /**
             * Stub requester-id column.
             *
             * @var string|null
             */
            protected ?string $requesterId = null;

            /**
             * @return int
             */
            public function getId(): int
            {
                return (int) ($this->id ?? 0);
            }//end getId()

            /**
             * @return int|null
             */
            public function getChainId(): ?int
            {
                return $this->chainId;
            }//end getChainId()

            /**
             * @param int|null $chainId The owning chain id.
             *
             * @return void
             */
            public function setChainId(?int $chainId): void
            {
                $this->chainId = $chainId;
            }//end setChainId()

            /**
             * @return string|null
             */
            public function getObjectUuid(): ?string
            {
                return $this->objectUuid;
            }//end getObjectUuid()

            /**
             * @param string|null $objectUuid The target object's uuid.
             *
             * @return void
             */
            public function setObjectUuid(?string $objectUuid): void
            {
                $this->objectUuid = $objectUuid;
            }//end setObjectUuid()

            /**
             * @return int
             */
            public function getStepOrder(): int
            {
                return $this->stepOrder;
            }//end getStepOrder()

            /**
             * @param int $stepOrder The step's order within its chain.
             *
             * @return void
             */
            public function setStepOrder(int $stepOrder): void
            {
                $this->stepOrder = $stepOrder;
            }//end setStepOrder()

            /**
             * @return string|null
             */
            public function getRole(): ?string
            {
                return $this->role;
            }//end getRole()

            /**
             * @param string|null $role The required NC group id.
             *
             * @return void
             */
            public function setRole(?string $role): void
            {
                $this->role = $role;
            }//end setRole()

            /**
             * @return string|null
             */
            public function getStatus(): ?string
            {
                return $this->status;
            }//end getStatus()

            /**
             * @param string|null $status The step's decision status.
             *
             * @return void
             */
            public function setStatus(?string $status): void
            {
                $this->status = $status;
            }//end setStatus()

            /**
             * @return string|null
             */
            public function getDecidedBy(): ?string
            {
                return $this->decidedBy;
            }//end getDecidedBy()

            /**
             * @return string|null
             */
            public function getComment(): ?string
            {
                return $this->comment;
            }//end getComment()

            /**
             * @return string|null
             */
            public function getRequesterId(): ?string
            {
                return $this->requesterId;
            }//end getRequesterId()

            /**
             * @param string|null $requesterId Uid of the user whose transition provisioned this step.
             *
             * @return void
             */
            public function setRequesterId(?string $requesterId): void
            {
                $this->requesterId = $requesterId;
            }//end setRequesterId()
        }//end class
    }//end if

    if (class_exists(ApprovalStepMapper::class, autoload: false) === false) {
        /**
         * Stub ApprovalStepMapper — call surface only; tests mock the methods.
         */
        class ApprovalStepMapper
        {
            /**
             * @return ApprovalStep
             */
            public function find(int $id): ApprovalStep
            {
                return new ApprovalStep();
            }//end find()

            /**
             * @return array<int, ApprovalStep>
             */
            public function findByChainAndObject(int $chainId, string $objectUuid): array
            {
                return [];
            }//end findByChainAndObject()

            /**
             * @return array<int, ApprovalStep>
             */
            public function findPendingByRole(string $role): array
            {
                return [];
            }//end findPendingByRole()

            /**
             * @return array<int, ApprovalStep>
             */
            public function findByObjectUuid(string $objectUuid): array
            {
                return [];
            }//end findByObjectUuid()

            /**
             * @param array<string, mixed> $filters
             *
             * @return array<int, ApprovalStep>
             */
            public function findAllFiltered(array $filters=[], ?int $limit=null, ?int $offset=null): array
            {
                return [];
            }//end findAllFiltered()

            /**
             * @return array<int, ApprovalStep>
             */
            public function findByChain(int $chainId): array
            {
                return [];
            }//end findByChain()

            /**
             * @param array<string, mixed> $data
             *
             * @return ApprovalStep
             */
            public function createFromArray(array $data): ApprovalStep
            {
                return new ApprovalStep();
            }//end createFromArray()

            /**
             * @return int
             */
            public function deleteByChainAndObject(int $chainId, string $objectUuid): int
            {
                return 0;
            }//end deleteByChainAndObject()
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
            public function find(int|string $id, ?array $_extend=[], bool $files=false, \OCA\OpenRegister\Db\Register|string|int|null $register=null, \OCA\OpenRegister\Db\Schema|string|int|null $schema=null, bool $_rbac=true, bool $_multitenancy=true): ?\OCA\OpenRegister\Db\ObjectEntity
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
            public function saveObject(array|\OCA\OpenRegister\Db\ObjectEntity $object, ?array $extend=[], \OCA\OpenRegister\Db\Register|string|int|null $register=null, \OCA\OpenRegister\Db\Schema|string|int|null $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true, bool $silent=false, ?array $uploadedFiles=null, ?\OCP\IUser $currentUser=null): \OCA\OpenRegister\Db\ObjectEntity
            {
                return new \OCA\OpenRegister\Db\ObjectEntity();
            }//end saveObject()

            /**
             * @return bool
             */
            public function deleteObject(string $uuid, \OCA\OpenRegister\Db\Register|string|int|null $register=null, \OCA\OpenRegister\Db\Schema|string|int|null $schema=null, bool $_rbac=true, bool $_multitenancy=true, bool $_retentionSweep=false): bool
            {
                return true;
            }//end deleteObject()

            /**
             * @return array<string, mixed>
             */
            public function lockObject(string $identifier, ?string $process=null, ?int $duration=null, bool $advisory=false): array
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
             * Stub setter for the current register context. Signature mirrors
             * the real OR service `setRegister(Register|string|int): static`.
             *
             * @param \OCA\OpenRegister\Db\Register|string|int $register Register reference.
             *
             * @return static
             */
            public function setRegister(\OCA\OpenRegister\Db\Register|string|int $register): static
            {
                return $this;
            }//end setRegister()

            /**
             * Stub setter for the current schema context. Signature mirrors
             * the real OR service `setSchema(Schema|string|int): static`.
             *
             * @param \OCA\OpenRegister\Db\Schema|string|int $schema Schema reference.
             *
             * @return static
             */
            public function setSchema(\OCA\OpenRegister\Db\Schema|string|int $schema): static
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

            /**
             * Stub system-context elevation. Mirrors the real OR
             * `ObjectService::runAsSystem(callable): mixed` — the stub simply
             * invokes the callable and returns its result, matching the real
             * implementation's contract from the caller's point of view (no
             * elevation is actually enforced by the stub since it has no RBAC
             * to bypass).
             *
             * @param callable $operation The trusted operation to execute.
             *
             * @return mixed Whatever the callable returns.
             */
            public function runAsSystem(callable $operation): mixed
            {
                return $operation();
            }//end runAsSystem()
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

    if (class_exists(SecurityService::class, autoload: false) === false) {
        /**
         * Stub SecurityService — no-op SSRF guard for unit-test isolation.
         *
         * RemoteTemplateStoreService calls `SecurityService::assertSafeFetchUrl()`
         * at runtime via `class_exists()` + `call_user_func()`. In unit tests the
         * mock HttpClient already controls what "returns", but the guard fires
         * BEFORE the mock client is invoked — and performs real DNS lookups that
         * fail for the `.test` / `.example.test` hostnames used in fixtures.
         *
         * Defining the stub here (in the Service namespace, loaded by bootstrap-unit.php
         * BEFORE the OR PSR-4 path is searched) satisfies the `class_exists()` check
         * without triggering a DNS lookup, so the mock HttpClient layer is reached.
         */
        class SecurityService
        {
            /**
             * Scheme-only SSRF guard for unit-test isolation.
             *
             * Mirrors the real SecurityService logic for scheme validation (rejects
             * non-http/https URLs with the same exception) but skips DNS resolution,
             * which fails for `.test` / `.example.test` hostnames used in fixtures.
             *
             * @param string $url The URL to guard.
             *
             * @return void
             *
             * @throws \InvalidArgumentException For non-http/https or malformed URLs.
             */
            public static function assertSafeFetchUrl(string $url): void
            {
                $parts = parse_url($url);
                if ($parts === false || empty($parts['scheme']) === true || empty($parts['host']) === true) {
                    throw new \InvalidArgumentException('Invalid or malformed URL.');
                }

                $scheme = strtolower($parts['scheme']);
                if (in_array($scheme, ['http', 'https'], true) === false) {
                    throw new \InvalidArgumentException('Only http and https URLs are allowed.');
                }

                // DNS resolution intentionally skipped in unit-test stub.
            }//end assertSafeFetchUrl()
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
             * Signature mirrors the real OCA\OpenRegister\Service\FileService::getFile:
             * `$object` is an ObjectEntity OR a UUID string (passing the entity lets OR
             * skip a scan of every magic table), and the return is nullable. The stub
             * previously declared `string $object`, so passing the entity — the whole
             * point of the performance fix — threw a TypeError in tests only while prod
             * worked. A stub that narrows the real signature hides the bug it should catch.
             *
             * @param \OCA\OpenRegister\Db\ObjectEntity|string|null $object The owning object or its UUID.
             * @param string|int                                    $file   File name / path, or NC file id.
             *
             * @return \OCP\Files\File|null The file node, or null when not found.
             */
            public function getFile(\OCA\OpenRegister\Db\ObjectEntity|string|null $object=null, string|int $file=''): ?\OCP\Files\File
            {
                // Stub — tests mock this method; real implementation is in OR.
                throw new \RuntimeException('FileService::getFile stub — must be mocked in tests.');
            }//end getFile()
        }//end class
    }//end if

    if (class_exists(ApprovalService::class, autoload: false) === false) {
        /**
         * Stub ApprovalService (automation-approval-steps) — call surface
         * only; tests mock the methods.
         */
        class ApprovalService
        {
            /**
             * @param array<int, array<string, mixed>>|null $stepsOverride
             *
             * @return array<int, \OCA\OpenRegister\Db\ApprovalStep>
             */
            public function initializeChain(
                \OCA\OpenRegister\Db\ApprovalChain $chain,
                string $objectUuid,
                ?string $requesterId=null,
                ?array $stepsOverride=null
            ): array {
                return [];
            }//end initializeChain()

            /**
             * @return array{step: \OCA\OpenRegister\Db\ApprovalStep, nextStep: \OCA\OpenRegister\Db\ApprovalStep|null, statusOnApprove: string}
             */
            public function approveStep(int $stepId, string $userId, string $comment=''): array
            {
                return ['step' => new \OCA\OpenRegister\Db\ApprovalStep(), 'nextStep' => null, 'statusOnApprove' => 'approved'];
            }//end approveStep()

            /**
             * @return array{step: \OCA\OpenRegister\Db\ApprovalStep, statusOnReject: string}
             */
            public function rejectStep(int $stepId, string $userId, string $comment=''): array
            {
                return ['step' => new \OCA\OpenRegister\Db\ApprovalStep(), 'statusOnReject' => 'rejected'];
            }//end rejectStep()
        }//end class
    }//end if
}

namespace OCA\OpenRegister\Service\Credential {

    if (class_exists(CredentialBrokerService::class, autoload: false) === false) {
        /**
         * Stub CredentialBrokerService — the `request()` call surface OpenBuild's
         * GitHubAppSyncService routes every outbound GitHub call through (resolved
         * lazily via `Server::get()`). The signature mirrors the real OR broker
         * (`request(string $credentialId, string $appId, string $method, string
         * $path, array $headers=[], ?string $body=null, ?string $actingUserId=null):
         * array`) so a caller passing the wrong argument shape fails identically
         * against the stub and the real class.
         */
        class CredentialBrokerService
        {
            /**
             * Broker a single outbound HTTP call for an allowed credential.
             *
             * @param string                $credentialId The credential UUID.
             * @param string                $appId        The calling app id.
             * @param string                $method       The HTTP method.
             * @param string                $path         The provider-relative path.
             * @param array<string, string> $headers      Request headers.
             * @param string|null           $body         Optional request body.
             * @param string|null           $actingUserId The acting user UID (owner guard).
             *
             * @return array<string, mixed> The `{status, headers, body}` response shape.
             */
            public function request(string $credentialId, string $appId, string $method, string $path, array $headers=[], ?string $body=null, ?string $actingUserId=null): array
            {
                return [];
            }//end request()
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

    if (class_exists(ObjectDeletedEvent::class, autoload: false) === false) {
        /**
         * Stub ObjectDeletedEvent — post-delete notification (non-cancellable,
         * mirrors the real OR class: `__construct(ObjectEntity $object)`, only
         * `getObject()`). Used by AutomationCleanupListener (automation-designer
         * REQ-AUTD-005) to remove compiled artifacts after the OR object itself
         * has already been deleted.
         */
        class ObjectDeletedEvent extends \OCP\EventDispatcher\Event
        {
            public function __construct(private readonly \OCA\OpenRegister\Db\ObjectEntity $object)
            {
                parent::__construct();
            }//end __construct()

            public function getObject(): \OCA\OpenRegister\Db\ObjectEntity
            {
                return $this->object;
            }//end getObject()
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

    if (class_exists(ObjectCreatedEvent::class, autoload: false) === false) {
        /**
         * Stub ObjectCreatedEvent (automation-approval-steps trigger-fire
         * listener) — mirrors the real OR class: `__construct(ObjectEntity $object)`.
         */
        class ObjectCreatedEvent extends \OCP\EventDispatcher\Event
        {
            public function __construct(private readonly \OCA\OpenRegister\Db\ObjectEntity $object)
            {
                parent::__construct();
            }//end __construct()

            public function getObject(): \OCA\OpenRegister\Db\ObjectEntity
            {
                return $this->object;
            }//end getObject()
        }//end class
    }//end if

    if (class_exists(ObjectUpdatedEvent::class, autoload: false) === false) {
        /**
         * Stub ObjectUpdatedEvent (automation-approval-steps trigger-fire
         * listener) — mirrors the real OR class:
         * `__construct(ObjectEntity $newObject, ?ObjectEntity $oldObject = null)`.
         */
        class ObjectUpdatedEvent extends \OCP\EventDispatcher\Event
        {
            public function __construct(
                private readonly \OCA\OpenRegister\Db\ObjectEntity $newObject,
                private readonly ?\OCA\OpenRegister\Db\ObjectEntity $oldObject=null,
            ) {
                parent::__construct();
            }//end __construct()

            public function getObject(): \OCA\OpenRegister\Db\ObjectEntity
            {
                return $this->newObject;
            }//end getObject()

            public function getNewObject(): \OCA\OpenRegister\Db\ObjectEntity
            {
                return $this->newObject;
            }//end getNewObject()

            public function getOldObject(): ?\OCA\OpenRegister\Db\ObjectEntity
            {
                return $this->oldObject;
            }//end getOldObject()
        }//end class
    }//end if

    if (class_exists(ApprovalStepApprovedEvent::class, autoload: false) === false) {
        /**
         * Stub ApprovalStepApprovedEvent (automation-approval-steps
         * ApprovalOutcomeListener) — mirrors the real OR class's accessors.
         */
        class ApprovalStepApprovedEvent extends \OCP\EventDispatcher\Event
        {
            public function __construct(
                private readonly \OCA\OpenRegister\Db\ApprovalChain $chain,
                private readonly \OCA\OpenRegister\Db\ApprovalStep $step,
                private readonly string $userId,
                private readonly string $statusOnApprove,
                private readonly ?\OCA\OpenRegister\Db\ApprovalStep $nextStep=null,
            ) {
                parent::__construct();
            }//end __construct()

            public function getChain(): \OCA\OpenRegister\Db\ApprovalChain
            {
                return $this->chain;
            }//end getChain()

            public function getStep(): \OCA\OpenRegister\Db\ApprovalStep
            {
                return $this->step;
            }//end getStep()

            public function getUserId(): string
            {
                return $this->userId;
            }//end getUserId()

            public function getStatusOnApprove(): string
            {
                return $this->statusOnApprove;
            }//end getStatusOnApprove()

            public function getNextStep(): ?\OCA\OpenRegister\Db\ApprovalStep
            {
                return $this->nextStep;
            }//end getNextStep()

            public function isFinalStep(): bool
            {
                return $this->nextStep === null;
            }//end isFinalStep()

            public function getObjectUuid(): string
            {
                return ($this->step->getObjectUuid() ?? '');
            }//end getObjectUuid()
        }//end class
    }//end if

    if (class_exists(ApprovalStepRejectedEvent::class, autoload: false) === false) {
        /**
         * Stub ApprovalStepRejectedEvent (automation-approval-steps
         * ApprovalOutcomeListener) — mirrors the real OR class's accessors.
         */
        class ApprovalStepRejectedEvent extends \OCP\EventDispatcher\Event
        {
            public function __construct(
                private readonly \OCA\OpenRegister\Db\ApprovalChain $chain,
                private readonly \OCA\OpenRegister\Db\ApprovalStep $step,
                private readonly string $userId,
                private readonly string $statusOnReject,
            ) {
                parent::__construct();
            }//end __construct()

            public function getChain(): \OCA\OpenRegister\Db\ApprovalChain
            {
                return $this->chain;
            }//end getChain()

            public function getStep(): \OCA\OpenRegister\Db\ApprovalStep
            {
                return $this->step;
            }//end getStep()

            public function getUserId(): string
            {
                return $this->userId;
            }//end getUserId()

            public function getStatusOnReject(): string
            {
                return $this->statusOnReject;
            }//end getStatusOnReject()

            public function getObjectUuid(): string
            {
                return ($this->step->getObjectUuid() ?? '');
            }//end getObjectUuid()
        }//end class
    }//end if
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
