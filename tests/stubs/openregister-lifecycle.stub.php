<?php

/**
 * PHPStan scan stub for OpenRegister's lifecycle-guard contract.
 *
 * Analysis-only — referenced from phpstan.neon `scanFiles` and NEVER loaded at
 * runtime (the runtime stubs live in tests/stubs/openregister-stubs.php, guarded
 * by class_exists). Lets the app-registered ApplicationVersionOwnerGuard resolve
 * the OR interface it implements when the openregister sibling app is absent from
 * the analysis path (buildiq-rbac, ADR-022/ADR-023).
 *
 * @category Test
 * @package  OCA\OpenRegister\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Lifecycle;

/**
 * Allow/deny verdict value object returned by a lifecycle guard.
 */
final class GuardResult {
	/**
	 * Construct a verdict value object.
	 *
	 * @param bool $allowed Whether the transition is allowed.
	 * @param string|null $message Optional deny message.
	 */
	private function __construct(
		private bool $allowed,
		private ?string $message,
	) {
	}//end __construct()

	/**
	 * Return an allow verdict.
	 *
	 * @return self
	 */
	public static function allow(): self {
		return new self(true, null);
	}//end allow()

	/**
	 * Return a deny verdict with the given message.
	 *
	 * @param string $message Deny reason.
	 *
	 * @return self
	 */
	public static function deny(string $message): self {
		return new self(false, $message);
	}//end deny()

	/**
	 * Return whether the verdict allows the transition.
	 *
	 * @return bool
	 */
	public function isAllowed(): bool {
		return $this->allowed;
	}//end isAllowed()

	/**
	 * Return the deny message, or null for allow verdicts.
	 *
	 * @return string|null
	 */
	public function getMessage(): ?string {
		return $this->message;
	}//end getMessage()
}//end class

/**
 * Contract apps implement to authorise a lifecycle transition.
 */
interface LifecycleGuardInterface {
	/**
	 * Authorise a lifecycle transition.
	 *
	 * @param array<string, mixed> $object The object payload.
	 * @param string $action The transition action.
	 * @param string $userId The acting user UID.
	 *
	 * @return GuardResult
	 */
	public function check(array $object, string $action, string $userId): GuardResult;
}//end interface
