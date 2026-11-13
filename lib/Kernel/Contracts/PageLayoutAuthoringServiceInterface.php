<?php

/**
 * Contract of PageLayoutAuthoringService
 *
 * The public surface of PageLayoutAuthoringService that other components call, so they depend
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

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract of PageLayoutAuthoringService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PageLayoutAuthoringServiceInterface {

	/**
	 * Every stored layout for a register and schema, with drift resolved, so the
	 * editor can show which overrides are waiting to be re-cut.
	 *
	 * @param string $register The consuming app's register.
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>> The layouts, each with `drifted` and `orphanedPaths`.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-003)
	 */
	public function listFor(string $register, string $schema): array;

	/**
	 * Re-cut a drifted override against the base it has now.
	 *
	 * The parts of the patch that still name something the base has are kept
	 * exactly as the maintainer wrote them. The orphans are dropped, because a
	 * patch that removes a tab the base no longer has is a patch that will never
	 * do anything again, and carrying it forward would make the next drift
	 * report list it once more.
	 *
	 * What was dropped is returned rather than logged: a re-cut that quietly
	 * loses half an override is the same surprise as an override that quietly
	 * stops applying.
	 *
	 * @param string $layoutId The override's id.
	 *
	 * @return array<string, mixed> `{layout, dropped}` where dropped lists the orphaned paths.
	 *
	 * @throws RuntimeException When no such override is stored.
	 * @throws InvalidArgumentException When the re-cut result would not be a valid override.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-003)
	 */
	public function recut(string $layoutId): array;

	/**
	 * Save a layout or an override.
	 *
	 * @param array<string, mixed> $layout The layout to store.
	 * @param string $author The uid saving it, recorded as the maintainer of a new override.
	 *
	 * @return array<string, mixed> `{layout, warnings}`.
	 *
	 * @throws InvalidArgumentException When a rule refuses it.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-001)
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002)
	 */
	public function save(array $layout, string $author): array;
}//end interface
