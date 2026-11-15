<?php

/**
 * Buildiq lifecycle guard registrar
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Lifecycle
 * @package  OCA\Buildiq\Lifecycle
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

namespace OCA\Buildiq\Lifecycle;

use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Registers the lifecycle guards.
 *
 * @category Lifecycle
 * @package  OCA\Buildiq\Lifecycle
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */
final class LifecycleRegistrar {

	/**
	 * Bind the per-Application RBAC lifecycle guard.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registerOwnerGuard(IRegistrationContext $context): void {
		// Per-Application RBAC lifecycle guard (ADR-022/ADR-023; buildiq-rbac).
		// OpenRegister's LifecycleGuardRegistry resolves the destructive
		// ApplicationVersion transitions' `requires` tag — keyed by this guard's
		// FQCN in the schema's x-openregister-lifecycle.transitions[*].requires —
		// to this concrete instance. It is the real, default-secure, fail-closed
		// ownership rule the descriptive `authorization` block cannot express
		// (issue #1). Registered explicitly because the guard's dependencies
		// (PermissionResolver, ObjectService) need wiring through the container.
		$context->registerService(
			ApplicationVersionOwnerGuard::class,
			static function ($c): ApplicationVersionOwnerGuard {
				return new ApplicationVersionOwnerGuard(
					objectService: $c->get(ObjectServiceInterface::class),
					permissionResolver: $c->get(PermissionResolverInterface::class),
					userManager: $c->get(IUserManager::class),
					logger: $c->get(LoggerInterface::class)
				);
			}
		);
	}//end registerOwnerGuard()
}//end class
