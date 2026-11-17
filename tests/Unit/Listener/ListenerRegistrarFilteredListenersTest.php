<?php

/**
 * Unit tests for ListenerRegistrar::filteredObjectListeners().
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Listener;

use OCA\Buildiq\Kernel\Contracts\ApplicationVersionServiceInterface;
use OCA\Buildiq\Kernel\Contracts\AutomationCompilerServiceInterface;
use OCA\Buildiq\Listener\AutomationCleanupListener;
use OCA\Buildiq\Listener\ListenerRegistrar;
use OCA\Buildiq\Listener\ProductionVersionGuardListener;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCP\EventDispatcher\IEventListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The narrowed object listeners Application::boot() subscribes declare the
 * same schema their handler's first guard checks, and no register.
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */
#[CoversClass(ListenerRegistrar::class)]
final class ListenerRegistrarFilteredListenersTest extends TestCase {

	/**
	 * Exactly the production-version guard and the automation cleanup, in that order.
	 *
	 * @return void
	 */
	public function testTheTwoNarrowedListenersAreDeclaredInOrder(): void {
		$subscriptions = (new ListenerRegistrar())->filteredObjectListeners();

		self::assertSame(
			expected: [
				[ObjectCreatingEvent::class, ProductionVersionGuardListener::class],
				[ObjectDeletedEvent::class, AutomationCleanupListener::class],
			],
			actual: array_map(
				static fn (array $subscription): array => [$subscription['event'], $subscription['listener']],
				$subscriptions
			)
		);
	}//end testTheTwoNarrowedListenersAreDeclaredInOrder()

	/**
	 * Each subscription names the schema its handler guards on.
	 *
	 * @return void
	 */
	public function testEachSchemaInterestMatchesItsHandlersGuard(): void {
		$byListener = array_column((new ListenerRegistrar())->filteredObjectListeners(), 'schemas', 'listener');

		self::assertSame(
			expected: [ApplicationVersionServiceInterface::APPLICATION_SCHEMA],
			actual: $byListener[ProductionVersionGuardListener::class]
		);
		self::assertSame(
			expected: [AutomationCompilerServiceInterface::AUTOMATION_SCHEMA],
			actual: $byListener[AutomationCleanupListener::class]
		);
	}//end testEachSchemaInterestMatchesItsHandlersGuard()

	/**
	 * No register is declared: buildiq creates its registers per app and environment.
	 *
	 * @return void
	 */
	public function testNoRegisterIsDeclaredAndEveryListenerIsAnEventListener(): void {
		foreach ((new ListenerRegistrar())->filteredObjectListeners() as $subscription) {
			self::assertNull(actual: $subscription['registers']);
			self::assertTrue(
				condition: is_subclass_of($subscription['listener'], IEventListener::class),
				message: $subscription['listener'] . ' is not an event listener'
			);
		}
	}//end testNoRegisterIsDeclaredAndEveryListenerIsAnEventListener()
}//end class
