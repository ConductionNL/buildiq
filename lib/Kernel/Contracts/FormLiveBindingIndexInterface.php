<?php

/**
 * Contract of FormLiveBindingIndex
 *
 * The public surface of FormLiveBindingIndex that other components call, so they depend
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
 * Contract of FormLiveBindingIndex.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @psalm-type LiveCalc = array{field:string,ruleSet:string,output:string,inputs:list<string>}
 * @psalm-type LiveBinding = array{
 *     register:string,
 *     schema:string,
 *     calculate:list<LiveCalc>,
 *     eligibility:array<string,mixed>|null,
 *     formats?:array<string,string>
 * }
 * @phpstan-type LiveCalc array{field:string,ruleSet:string,output:string,inputs:list<string>}
 * @phpstan-type LiveBinding array{
 *     register:string,
 *     schema:string,
 *     calculate:list<LiveCalc>,
 *     eligibility:array<string,mixed>|null,
 *     formats?:array<string,string>
 * }
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FormLiveBindingIndexInterface {

	/**
	 * The bindings for an object in a register and schema.
	 *
	 * Both lists hold every name the register or schema goes by (its id and
	 * its slug), since a manifest may name either.
	 *
	 * @param array<int,string> $registerKeys The register's id and slug
	 * @param array<int,string> $schemaKeys The schema's id and slug
	 *
	 * @return list<LiveBinding>
	 *
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-the-server-recomputes-before-a-save-req-bqlv-005
	 */
	public function bindingsFor(array $registerKeys, array $schemaKeys): array;

	/**
	 * Whether no app version has a live form, so a save has nothing to do.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-the-server-recomputes-before-a-save-req-bqlv-005
	 */
	public function isEmpty(): bool;

	/**
	 * Rebuild the entry of one app version from its manifest.
	 *
	 * @param string $versionId The ApplicationVersion uuid (or slug when new)
	 * @param array<string,mixed> $version The ApplicationVersion object data
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-the-server-recomputes-before-a-save-req-bqlv-005
	 */
	public function reindexVersion(string $versionId, array $version): void;
}//end interface
