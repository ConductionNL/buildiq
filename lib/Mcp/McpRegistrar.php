<?php

/**
 * Buildiq MCP registrar
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\Buildiq\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Mcp;

use OCA\Buildiq\Kernel\Contracts\BuildiqToolProviderInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the MCP tool provider.
 *
 * @category Mcp
 * @package  OCA\Buildiq\Mcp
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */
final class McpRegistrar {

	/**
	 * Register the MCP tool provider under its MCP alias and its contract.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registerToolProvider(IRegistrationContext $context): void {
		// Register BuildiqToolProvider as the MCP tool provider for the AI Chat Companion.
		// The alias key 'OCA\OpenRegister\Mcp\IMcpToolProvider::buildiq' is the format
		// that OR's McpToolsService enumerates to discover per-app providers (hydra ADR-035).
		// The interface ships in openregister PR #1466 (ai-chat-companion-orchestrator);
		// until then Buildiq implements the test stub at tests/Stubs/Mcp/IMcpToolProvider.php.
		$context->registerServiceAlias(
			'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::buildiq',
			BuildiqToolProvider::class
		);
		$context->registerServiceAlias(
			BuildiqToolProviderInterface::class,
			BuildiqToolProvider::class
		);
	}//end registerToolProvider()
}//end class
