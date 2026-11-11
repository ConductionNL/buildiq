<?php

/**
 * Buildiq LazyObjectService
 *
 * Resolves OpenRegister's object service at call time for the event listeners
 * that cannot constructor-inject it (DocumentGenerationListener,
 * AutomationApprovalTriggerListener and ApprovalOutcomeListener). Each listener
 * builds one from the container it is given.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\Buildiq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-document-action/tasks.md#3.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * OpenRegister's object service, reached for when it is first needed.
 *
 * @spec openspec/changes/automation-document-action/tasks.md#3.1
 */
final class LazyObjectService {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The container the listener was given.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Resolve OpenRegister's object service, at call time.
	 *
	 * ⚠️ An event listener CANNOT constructor-inject a published interface.
	 * Nextcloud's `ServiceEventListener` builds the listener from the SERVER
	 * container ("TODO: fetch from the app containers" — its own source), which
	 * never sees this app's `registerServiceAlias()`. The constructor parameter
	 * therefore could not be built, and the exception propagated out of
	 * `dispatch()` into whichever app emitted the event.
	 *
	 * Resolves the CONCRETE class on purpose — asking the same container for
	 * `ObjectServiceInterface::class` here would fail identically. Nextcloud
	 * autowires concrete classes across apps; the declared type stays the
	 * published contract.
	 *
	 * @return ObjectServiceInterface OpenRegister's published object contract.
	 *
	 * @throws ContainerExceptionInterface When OpenRegister is absent or disabled.
	 *
	 * @spec openspec/changes/automation-document-action/tasks.md#3.1
	 */
	public function get(): ObjectServiceInterface {
		// ADR-083: establish availability before reaching. class_exists()
		// rather than SettingsService, because this listener injects no
		// settings service and adding a constructor dependency purely to ask a
		// yes/no question is the wrong trade — it answers the same question the
		// container would otherwise have answered fatally.
		//
		// Behaviour is unchanged: every caller already wraps this in a catch
		// that logs and returns null, so an instance without OpenRegister
		// degrades exactly as it does today. What changes is that the
		// dependency is now declared where a reader — and the gate — can see
		// it, instead of being implied by a catch several methods away.
		//
		// Untestable in a unit test: the stub OpenRegister classes the test
		// bootstrap declares (tests/stubs/openregister-stubs.php) make
		// class_exists() true unconditionally in this suite, so this branch
		// only ever fires on a real instance genuinely missing OpenRegister.
		// @codeCoverageIgnoreStart
		if (class_exists('OCA\OpenRegister\Service\ObjectService') === false) {
			throw new RuntimeException(
				'buildiq requires the OpenRegister app, which is not installed on this instance.'
			);
		}
		// @codeCoverageIgnoreEnd

		$service = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		// An assert(), not a `/** @var */` docblock: phpcs forbids an inline doc
		// block inside a method body, while psalm needs the narrowing or the
		// declared return type is a MixedReturnStatement. assert() satisfies
		// both, and costs nothing in production where zend.assertions is off.
		assert($service instanceof ObjectServiceInterface);

		return $service;
	}//end get()
}//end class
