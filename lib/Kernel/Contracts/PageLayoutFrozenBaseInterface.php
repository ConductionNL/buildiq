<?php

/**
 * Contract of PageLayoutFrozenBase
 *
 * The public surface of PageLayoutFrozenBase that other components call, so they depend
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
 * Contract of PageLayoutFrozenBase.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PageLayoutFrozenBaseInterface {

	/**
	 * The layers an override's frozen base is composed from, in the order they
	 * compose.
	 *
	 * Whole layouts only, and only those for everyone: a layout bound to one
	 * group still composes into what that group is served, but letting it move
	 * the frozen base would make the same override read as current for one
	 * colleague and drifted for the next.
	 *
	 * The layer the override names in `baseRef` leads, then the schema-wide
	 * layout, then the one for this type value. A layer already in the list is
	 * not added twice.
	 *
	 * @param array<int, array<string, mixed>> $published The published layouts for this register and schema.
	 * @param array<string, mixed> $override The override about to be saved.
	 *
	 * @return array<int, array<string, mixed>> The ordered layers.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002)
	 */
	public function layersFor(array $published, array $override): array;
}//end interface
