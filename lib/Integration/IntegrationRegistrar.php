<?php

/**
 * Buildiq integration registrar
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Integration
 * @package  OCA\Buildiq\Integration
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

namespace OCA\Buildiq\Integration;

use OCA\Buildiq\Kernel\Contracts\PageLayoutLeafProviderInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the contracts this component implements.
 *
 * @category Integration
 * @package  OCA\Buildiq\Integration
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */
final class IntegrationRegistrar {

	/**
	 * Alias the Kernel contracts this component implements.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registerContractBindings(IRegistrationContext $context): void {
		$context->registerServiceAlias(PageLayoutLeafProviderInterface::class, PageLayoutLeafProvider::class);
	}//end registerContractBindings()
}//end class
