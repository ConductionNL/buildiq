<?php

/**
 * Resolve an OpenRegister ObjectEntity's register and schema SLUGS.
 *
 * OpenRegister object events carry the register and schema as **numeric ids**,
 * never as slugs. `ObjectEntity::$schema` is declared `?string` and typed as a
 * string, but `SaveObject` writes it as `(string) $schemaId` — so it holds
 * `"28"`, not `"application"`. `ObjectEntity` has no `getSchemaSlug()` method
 * and never has had one.
 *
 * openbuild's listeners each carried a private `extractSchemaSlug()` /
 * `schemaOf()` helper that probed for that non-existent `getSchemaSlug()` and
 * then fell back to `@self.schema` — the id again. Every one of those helpers
 * therefore returned an id, which was compared with `!==` against a slug
 * literal, so the comparison was always true and the handler body never ran.
 * No exception, no log line: the listeners simply did nothing, silently, for
 * their entire life.
 *
 * This service is the single place that turns the ids the event actually
 * carries into the slugs the handlers are written against.
 *
 * Both register AND schema are resolved, because a schema slug is **not**
 * unique across registers: this instance carries two distinct schemas with the
 * slug `automation` (ids 71 and 5103). Matching on the schema slug alone would
 * fire openbuild's handlers for another app's objects. This mirrors the
 * register+schema pair pattern already shipped in petstore and planix.
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://openbuild.nl
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns the register/schema ids an OpenRegister event carries into slugs.
 */
class ObjectSchemaSlugResolver {

	/**
	 * The register slug openbuild's own objects live in.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'openbuild';

	/**
	 * Resolved slugs keyed by "<mapperFqn>:<id>", for the request lifetime.
	 *
	 * The mappers cache too, but memoising here also caches the MISSES, so a
	 * payload referencing a schema this instance does not have costs one failed
	 * lookup per request rather than one per event. openbuild's listeners fire
	 * on every object write, so an unmemoised lookup is an N+1 on bulk imports
	 * (docudesk measured 1,471 SchemaMapper::find() calls per object save from
	 * exactly this shape).
	 *
	 * @var array<string, string>
	 */
	private array $slugs = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container, used to reach
	 *                                      OpenRegister's mappers lazily so
	 *                                      openbuild still boots without it.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the schema slug for an ObjectEntity.
	 *
	 * @param object $entity The OpenRegister ObjectEntity.
	 *
	 * @return string The schema slug, or '' when unresolvable. An empty string
	 *                never equals a slug literal, so an unresolvable schema
	 *                keeps the handler's existing fail-closed behaviour.
	 *
	 * @spec openspec/specs/openbuild-application-register/spec.md#requirement-application-carries-a-productionversion-relation
	 * @spec openspec/specs/automation-designer/spec.md#requirement-an-automation-is-managed-as-one-unit-with-provenance-req-autd-005
	 */
	public function schemaSlug(object $entity): string {
		$schemaId = $this->readAccessor(entity: $entity, accessor: 'getSchema');
		if ($schemaId === null) {
			return '';
		}

		return $this->resolve(
			mapper: 'OCA\OpenRegister\Db\SchemaMapper',
			id: $schemaId
		);
	}//end schemaSlug()

	/**
	 * Resolve the register slug for an ObjectEntity.
	 *
	 * @param object $entity The OpenRegister ObjectEntity.
	 *
	 * @return string The register slug, or '' when unresolvable.
	 *
	 * @spec openspec/specs/openbuild-application-register/spec.md#requirement-application-carries-a-productionversion-relation
	 * @spec openspec/specs/automation-designer/spec.md#requirement-an-automation-is-managed-as-one-unit-with-provenance-req-autd-005
	 */
	public function registerSlug(object $entity): string {
		$registerId = $this->readAccessor(entity: $entity, accessor: 'getRegister');
		if ($registerId === null) {
			return '';
		}

		return $this->resolve(
			mapper: 'OCA\OpenRegister\Db\RegisterMapper',
			id: $registerId
		);
	}//end registerSlug()

	/**
	 * Call a magic accessor on an OpenRegister entity, defensively.
	 *
	 * `method_exists()` MUST NOT be used to probe for these accessors.
	 * `ObjectEntity`, `Schema` and `Register` extend Nextcloud's
	 * `OCP\AppFramework\Db\Entity`, which serves every column accessor through
	 * `__call()` and declares it as an `@method` docblock only. `method_exists()`
	 * is therefore **false** for `getSchema()`, `getRegister()` and `getSlug()`
	 * on the real classes, while `is_callable()` is true — so a `method_exists()`
	 * guard here silently disabled the whole resolver, and with it both
	 * listeners that depend on it. Measured on this instance:
	 * `method_exists($objectEntity, 'getSchema')` false / `is_callable` true,
	 * against the concrete control `getObject()` which is true for both.
	 *
	 * Note `is_callable()` is not a membership test on a `__call` class — it is
	 * true for ANY name — so the call itself must be exception-safe. `Entity`
	 * throws `BadFunctionCallException` for a column it does not have.
	 *
	 * @param object $entity The OpenRegister entity.
	 * @param string $accessor The accessor to call.
	 *
	 * @return string|null The stringified value, or null when unreachable.
	 */
	private function readAccessor(object $entity, string $accessor): ?string {
		if (is_callable([$entity, $accessor]) === false) {
			return null;
		}

		try {
			$value = $entity->$accessor();
		} catch (\Throwable $e) {
			$this->logger->debug(
				'OpenBuild: ' . $accessor . '() unavailable on ' . $entity::class,
				['exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_scalar($value) === false) {
			return null;
		}

		return (string)$value;
	}//end readAccessor()

	/**
	 * Test whether an entity is an openbuild object of the given schema.
	 *
	 * @param object $entity The OpenRegister ObjectEntity.
	 * @param string $schemaSlug The schema slug to match.
	 *
	 * @return bool True when the entity is that schema in the openbuild register.
	 */
	public function isOpenBuildSchema(object $entity, string $schemaSlug): bool {
		if ($this->schemaSlug(entity: $entity) !== $schemaSlug) {
			return false;
		}

		// Guard the register too: `automation` is not a unique slug instance-wide.
		return $this->registerSlug(entity: $entity) === self::REGISTER_SLUG;
	}//end isOpenBuildSchema()

	/**
	 * Resolve a slug from an id via one of OpenRegister's mappers.
	 *
	 * @param string $mapper Fully-qualified mapper class name.
	 * @param string $id The register or schema id.
	 *
	 * @return string The slug, or '' when unresolvable.
	 */
	private function resolve(string $mapper, string $id): string {
		$id = trim($id);
		if ($id === '') {
			return '';
		}

		// A non-numeric value is already a slug; ids are always digits. This
		// keeps the resolver correct if OpenRegister ever starts emitting slugs.
		if (ctype_digit($id) === false) {
			return $id;
		}

		$key = $mapper . ':' . $id;
		if (array_key_exists($key, $this->slugs) === true) {
			return $this->slugs[$key];
		}

		$slug = '';

		try {
			// Signature is find($id, $_extend, $_rbac, $_multitenancy). RBAC and
			// multitenancy are off: this runs inside an event handler that may
			// have no active organisation, and a register/schema slug is
			// metadata rather than tenant data. An organisation-scoped read
			// would return nothing and silently reopen the same hole.
			$entity = $this->container->get($mapper)->find($id, [], false, false);
			// `getSlug()` is an `@method` docblock on Schema and Register, served
			// by Entity::__call — see readAccessor(). Probing it with
			// method_exists() returned '' for every real entity, which is what
			// kept isOpenBuildSchema() false even for openbuild's own objects.
			if (is_object($entity) === true) {
				$slug = (string)($this->readAccessor(entity: $entity, accessor: 'getSlug') ?? '');
			}
		} catch (\Throwable $e) {
			$this->logger->debug(
				'OpenBuild: could not resolve slug for ' . $mapper . ' id ' . $id,
				['exception' => $e->getMessage()]
			);
		}

		$this->slugs[$key] = $slug;

		return $slug;
	}//end resolve()
}//end class
