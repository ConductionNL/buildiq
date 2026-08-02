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
class ObjectSchemaSlugResolver
{

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
     * @param LoggerInterface    $logger    Logger.
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
     */
    public function schemaSlug(object $entity): string
    {
        if (method_exists($entity, 'getSchema') === false) {
            return '';
        }

        return $this->resolve(
            mapper: 'OCA\OpenRegister\Db\SchemaMapper',
            id: (string) $entity->getSchema()
        );
    }//end schemaSlug()

    /**
     * Resolve the register slug for an ObjectEntity.
     *
     * @param object $entity The OpenRegister ObjectEntity.
     *
     * @return string The register slug, or '' when unresolvable.
     */
    public function registerSlug(object $entity): string
    {
        if (method_exists($entity, 'getRegister') === false) {
            return '';
        }

        return $this->resolve(
            mapper: 'OCA\OpenRegister\Db\RegisterMapper',
            id: (string) $entity->getRegister()
        );
    }//end registerSlug()

    /**
     * Test whether an entity is an openbuild object of the given schema.
     *
     * @param object $entity     The OpenRegister ObjectEntity.
     * @param string $schemaSlug The schema slug to match.
     *
     * @return bool True when the entity is that schema in the openbuild register.
     */
    public function isOpenBuildSchema(object $entity, string $schemaSlug): bool
    {
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
     * @param string $id     The register or schema id.
     *
     * @return string The slug, or '' when unresolvable.
     */
    private function resolve(string $mapper, string $id): string
    {
        $id = trim($id);
        if ($id === '') {
            return '';
        }

        // A non-numeric value is already a slug; ids are always digits. This
        // keeps the resolver correct if OpenRegister ever starts emitting slugs.
        if (ctype_digit($id) === false) {
            return $id;
        }

        $key = $mapper.':'.$id;
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
            if (is_object($entity) === true && method_exists($entity, 'getSlug') === true) {
                $slug = (string) $entity->getSlug();
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                'OpenBuild: could not resolve slug for '.$mapper.' id '.$id,
                ['exception' => $e->getMessage()]
            );
        }

        $this->slugs[$key] = $slug;

        return $slug;
    }//end resolve()
}//end class
