<?php

/**
 * Contract of BuildiqToolProvider
 *
 * The public surface of BuildiqToolProvider that other components call, so they depend
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
 * Contract of BuildiqToolProvider.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BuildiqToolProviderInterface {

	/**
	 * The MCP tool descriptors, each with its id, description and input schema.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public function getToolDescriptors(): array;

	/**
	 * Dispatch an MCP tool invocation to the matching handler.
	 *
	 * @param string $toolId Fully qualified tool id (e.g. "buildiq.listApps").
	 * @param array<string, mixed> $arguments Raw tool arguments as supplied by the MCP client.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public function invokeTool(string $toolId, array $arguments): array;
}//end interface
