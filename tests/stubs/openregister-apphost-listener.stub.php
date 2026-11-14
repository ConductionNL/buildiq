<?php

/**
 * PHPStan scan stub for OpenRegister's AppHost deep-link listener (ADR-040).
 *
 * Analysis-only — referenced from phpstan.neon `scanFiles` and NEVER loaded at
 * runtime (the runtime stub lives in tests/stubs/openregister-stubs.php, guarded
 * by class_exists). Lets BuiltAppDeepLinkListener resolve the OR base class it
 * extends when the openregister sibling app is absent from the analysis path.
 *
 * @category Test
 * @package  OCA\OpenRegister\AppHost\Listener
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

namespace OCA\OpenRegister\AppHost\Listener;

use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * PHPStan-only stub for the manifest-driven deep-link listener.
 *
 * The real class lives in the openregister sibling app.
 *
 * @implements IEventListener<Event>
 */
class GenericDeepLinkRegistrationListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param string $appId The leaf app id whose manifest is read.
	 * @param IAppManager $appManager App path resolution.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		protected readonly string $appId,
		protected readonly IAppManager $appManager,
		protected readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the deep-link registration event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
	}//end handle()

	/**
	 * Load the `deepLinks` array from the leaf app's manifest.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function loadDeepLinks(): array {
		return [];
	}//end loadDeepLinks()
}//end class
