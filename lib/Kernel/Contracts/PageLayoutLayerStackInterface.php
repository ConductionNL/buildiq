<?php

/**
 * Contract of PageLayoutLayerStack
 *
 * The public surface of PageLayoutLayerStack that other components call, so they depend
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
 * Contract of PageLayoutLayerStack.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PageLayoutLayerStackInterface {

	/**
	 * The audience of a layout, with an unset audience reading as `everyone` so
	 * every layout stored before overrides existed keeps exactly its reach
	 * (REQ-OBSO-004).
	 *
	 * @param array<string, mixed> $layout The layout.
	 *
	 * @return array<string, string> The audience.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-004)
	 */
	public function audienceOf(array $layout): array;

	/**
	 * Describe one applied layer for the answer.
	 *
	 * @param array<string, mixed> $layer The layer.
	 *
	 * @return array<string, mixed> The description.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-006)
	 */
	public function describeLayer(array $layer): array;

	/**
	 * The published layers that apply to this caller and this object, in the
	 * order each patches the one before it: the base named by `baseRef`, the
	 * schema-wide layout, the type layout, the audience override, the user
	 * override (REQ-OBSO-005).
	 *
	 * @param array<int, array<string, mixed>> $published Every published layout for the register and schema.
	 * @param array<string, mixed> $object The host object.
	 *
	 * @return array<int, array<string, mixed>> The ordered layers.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-004, REQ-OBSO-005)
	 */
	public function orderLayers(array $published, array $object): array;

	/**
	 * The parts of a whole layout that can be patched, so a later layer merges
	 * over them rather than over the whole record.
	 *
	 * @param array<string, mixed> $layout The layout.
	 *
	 * @return array<string, mixed> The patchable parts plus the identity.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-001)
	 */
	public function patchableOf(array $layout): array;

	/**
	 * The schema-wide layer among the ordered layers, for the parts a narrower
	 * layer does not declare at all.
	 *
	 * @param array<int, array<string, mixed>> $layers The ordered layers.
	 *
	 * @return array<string, mixed>|null The schema-wide layer.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-007)
	 */
	public function schemaWideOf(array $layers): ?array;
}//end interface
