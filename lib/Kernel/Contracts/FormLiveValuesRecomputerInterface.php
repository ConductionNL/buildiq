<?php

/**
 * Contract of FormLiveValuesRecomputer
 *
 * The public surface of FormLiveValuesRecomputer that other components call, so they depend
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
 * Contract of FormLiveValuesRecomputer.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @psalm-import-type LiveBinding from FormLiveBindingIndexInterface
 * @phpstan-import-type LiveBinding from FormLiveBindingIndexInterface
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FormLiveValuesRecomputerInterface {

	/**
	 * Recompute an object's calculated fields and run its blocking checks.
	 *
	 * @param array<string,mixed> $object The object data being saved
	 * @param list<LiveBinding> $bindings The live forms writing here
	 *
	 * @return array{changes:array<string,mixed>,refusal:array{code:string,message:string}|null}
	 *
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-the-server-recomputes-before-a-save-req-bqlv-005
	 */
	public function recompute(array $object, array $bindings): array;
}//end interface
