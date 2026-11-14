<?php

/**
 * Contract of ObjectSchemaSlugResolver
 *
 * The public surface of ObjectSchemaSlugResolver that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Buildiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Contracts;

/**
 * Contract of ObjectSchemaSlugResolver.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ObjectSchemaSlugResolverInterface {

	/**
	 * The register slug buildiq's own objects live in.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'buildiq';

	/**
	 * Test whether an entity is an buildiq object of the given schema.
	 *
	 * @param object $entity The OpenRegister ObjectEntity.
	 * @param string $schemaSlug The schema slug to match.
	 *
	 * @return bool True when the entity is that schema in the buildiq register.
	 *
	 * @spec openspec/specs/data-scopes-authoring/spec.md
	 */
	public function isBuildiqSchema(object $entity, string $schemaSlug): bool;

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
	public function registerSlug(object $entity): string;

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
	public function schemaSlug(object $entity): string;
}//end interface
