<?php

/**
 * Buildiq middleware registrar
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\Buildiq\Middleware
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

namespace OCA\Buildiq\Middleware;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the middleware this component owns.
 *
 * @category Middleware
 * @package  OCA\Buildiq\Middleware
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */
final class MiddlewareRegistrar {

	/**
	 * Register the API contract middleware.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registerApiContractMiddleware(IRegistrationContext $context): void {
		// The API contract (version header, deprecation of unversioned paths,
		// problem details). Registered first: Nextcloud runs afterController in
		// reverse order, so it sees the final response of every other middleware.
		$context->registerMiddleware(class: ApiContractMiddleware::class);
	}//end registerApiContractMiddleware()
}//end class
