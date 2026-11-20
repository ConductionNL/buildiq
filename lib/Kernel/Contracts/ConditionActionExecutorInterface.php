<?php

/**
 * Contract of ConditionActionExecutor
 *
 * The public surface of ConditionActionExecutor that other components call, so they depend
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
 * Contract of ConditionActionExecutor.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConditionActionExecutorInterface {

	/**
	 * Execute a set of condition-action rules.
	 *
	 * @param array<int,array<string,mixed>> $rules The ConditionActionRule objects.
	 * @param array<string,mixed> $payload The input payload.
	 * @param bool $dryRun When true, skip side-effecting actions.
	 * @param callable|null $dispatcher Optional fn(string $type, array $params, array $payload): mixed.
	 *
	 * @return array{triggeredRules:array<int,array<string,mixed>>,result:array<string,mixed>,errors:array<int,string>}
	 *
	 * @spec openspec/changes/archive/2026-06-14-business-rules-engine/tasks.md#4.1
	 */
	public function execute(array $rules, array $payload, bool $dryRun = false, ?callable $dispatcher = null): array;
}//end interface
