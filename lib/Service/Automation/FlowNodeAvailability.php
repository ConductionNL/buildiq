<?php

/**
 * Flow Node Availability
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Whether OpenRegister's flow engine knows a node type, asked of its node
 * registry at use time (ai-llm-steps-and-computed-fields D6). The registry is
 * resolved through the container by name, so buildiq keeps loading on an
 * OpenRegister without it; anything that goes wrong reads as "not there", and
 * the caller refuses rather than saving a flow that cannot run.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Automation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-ai-steps-need-the-hermiq-node-req-bqls-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Asks OpenRegister's node registry whether a node type is registered.
 *
 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-ai-steps-need-the-hermiq-node-req-bqls-005
 */
final class FlowNodeAvailability {

	/**
	 * OpenRegister's node registry service id.
	 *
	 * @var string
	 */
	public const REGISTRY = 'OCA\\OpenRegister\\Service\\Flow\\FlowNodeRegistryInterface';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the registry at use time.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether the engine has a node of this type.
	 *
	 * @param string $type The node type id, e.g. `hermiq.agent-step`.
	 *
	 * @return bool False when the registry is absent, refuses the type or fails.
	 *
	 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-ai-steps-need-the-hermiq-node-req-bqls-005
	 */
	public function has(string $type): bool {
		try {
			$registry = $this->container->get(self::REGISTRY);
			if (is_object($registry) === false || method_exists($registry, 'get') === false) {
				return false;
			}

			// The registry throws for a type nobody registered.
			$registry->get(type: $type);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end has()
}//end class
