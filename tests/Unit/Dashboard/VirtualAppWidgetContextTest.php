<?php

/**
 * Unit tests for the shared virtual-app widget context.
 *
 * Covers the descriptor registry (insertion order kept) and the visibility
 * filter: an app principal admits the caller, a placement's roles narrow it
 * further, an anonymous caller sees nothing, and visible descriptors come out
 * serialised.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Dashboard
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Dashboard;

use OCA\Buildiq\Dashboard\VirtualAppWidgetContext;
use OCA\Buildiq\Dashboard\WidgetApplication;
use OCA\Buildiq\Dashboard\WidgetDescriptor;
use OCA\Buildiq\Service\AppVisibilityResolver;
use OCA\Buildiq\Service\Dashboard\WidgetItemProjector;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for VirtualAppWidgetContext.
 *
 * @covers \OCA\Buildiq\Dashboard\VirtualAppWidgetContext
 * @uses   \OCA\Buildiq\Dashboard\WidgetApplication
 * @uses   \OCA\Buildiq\Dashboard\WidgetDescriptor
 * @uses   \OCA\Buildiq\Service\AppVisibilityResolver
 */
class VirtualAppWidgetContextTest extends TestCase {

	/**
	 * A context for caller `alice` (member of `sales`, not an admin), or anonymous.
	 *
	 * @param bool $signedIn Whether alice is signed in.
	 *
	 * @return VirtualAppWidgetContext
	 */
	private function context(bool $signedIn = true): VirtualAppWidgetContext {
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['sales']);
		$groupManager->method('isAdmin')->willReturn(false);

		return new VirtualAppWidgetContext(
			urlGenerator: $this->createMock(originalClassName: IURLGenerator::class),
			userSession: $session,
			groupManager: $groupManager,
			visibility: new AppVisibilityResolver(),
			projector: $this->createMock(originalClassName: WidgetItemProjector::class),
			initialState: $this->createMock(originalClassName: IInitialState::class)
		);
	}//end context()

	/**
	 * A descriptor of app `crm` with the given principals and placement roles.
	 *
	 * @param string             $entryId    The widget entry id.
	 * @param array<int, string> $principals The app's principals.
	 * @param array<int, string> $roles      The placement's roles.
	 *
	 * @return WidgetDescriptor
	 */
	private function descriptor(string $entryId, array $principals, array $roles = []): WidgetDescriptor {
		return new WidgetDescriptor(
			application: new WidgetApplication(uuid: 'app-uuid', slug: 'crm', name: 'CRM'),
			principals: $principals,
			entryId: $entryId,
			widgetKey: 'stats',
			panel: ['title' => ucfirst($entryId)],
			placement: ['roles' => $roles]
		);
	}//end descriptor()

	/**
	 * Descriptors are kept in the order they were added.
	 *
	 * @return void
	 */
	public function testDescriptorsKeepInsertionOrder(): void {
		$context = $this->context();
		$first = $this->descriptor(entryId: 'first', principals: ['group:*']);
		$second = $this->descriptor(entryId: 'second', principals: ['group:*']);

		$this->assertSame(expected: [], actual: $context->descriptors());

		$context->addDescriptor(descriptor: $second);
		$context->addDescriptor(descriptor: $first);

		$this->assertSame(expected: [$second, $first], actual: $context->descriptors());
	}//end testDescriptorsKeepInsertionOrder()

	/**
	 * An app principal naming the caller or one of their groups admits them; others do not.
	 *
	 * @return void
	 */
	public function testAppPrincipalsDecideVisibility(): void {
		$context = $this->context();

		$this->assertTrue(condition: $context->isVisible(descriptor: $this->descriptor(entryId: 'a', principals: ['user:alice'])));
		$this->assertTrue(condition: $context->isVisible(descriptor: $this->descriptor(entryId: 'b', principals: ['group:sales'])));
		$this->assertTrue(condition: $context->isVisible(descriptor: $this->descriptor(entryId: 'c', principals: ['group:*'])));
		$this->assertFalse(condition: $context->isVisible(descriptor: $this->descriptor(entryId: 'd', principals: ['user:bob', 'group:hr'])));
		$this->assertFalse(condition: $context->isVisible(descriptor: $this->descriptor(entryId: 'e', principals: [])));
	}//end testAppPrincipalsDecideVisibility()

	/**
	 * Placement roles narrow an app the caller may use.
	 *
	 * @return void
	 */
	public function testPlacementRolesNarrowVisibility(): void {
		$context = $this->context();

		$this->assertTrue(condition: $context->isVisible(descriptor: $this->descriptor(entryId: 'a', principals: ['group:*'], roles: ['group:sales'])));
		$this->assertFalse(condition: $context->isVisible(descriptor: $this->descriptor(entryId: 'b', principals: ['group:*'], roles: ['group:finance'])));
		$this->assertFalse(condition: $context->isVisible(descriptor: $this->descriptor(entryId: 'c', principals: ['user:bob'], roles: ['group:sales'])));
	}//end testPlacementRolesNarrowVisibility()

	/**
	 * An anonymous caller sees nothing, not even a wildcard widget.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerSeesNothing(): void {
		$context = $this->context(signedIn: false);
		$context->addDescriptor(descriptor: $this->descriptor(entryId: 'open', principals: ['group:*']));

		$this->assertFalse(condition: $context->isVisible(descriptor: $context->descriptors()[0]));
		$this->assertSame(expected: [], actual: $context->visibleDescriptors());
	}//end testAnAnonymousCallerSeesNothing()

	/**
	 * Only the visible descriptors are returned, serialised and in order.
	 *
	 * @return void
	 */
	public function testVisibleDescriptorsAreSerialisedInOrder(): void {
		$context = $this->context();
		$shown = $this->descriptor(entryId: 'shown', principals: ['group:sales']);
		$hidden = $this->descriptor(entryId: 'hidden', principals: ['group:hr']);
		$also = $this->descriptor(entryId: 'also', principals: ['user:alice']);
		$context->addDescriptor(descriptor: $shown);
		$context->addDescriptor(descriptor: $hidden);
		$context->addDescriptor(descriptor: $also);

		$this->assertSame(
			expected: [$shown->jsonSerialize(), $also->jsonSerialize()],
			actual: $context->visibleDescriptors()
		);
		$this->assertSame(expected: ['Shown', 'Also'], actual: array_column(array: $context->visibleDescriptors(), column_key: 'title'));
	}//end testVisibleDescriptorsAreSerialisedInOrder()
}//end class
