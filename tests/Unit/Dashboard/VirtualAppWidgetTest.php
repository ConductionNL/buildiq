<?php

/**
 * Unit tests for VirtualAppWidget.
 *
 * Covers the IWidget / IIconWidget surface and the full IConditionalWidget
 * permission matrix, which reuses AppNavigationService's check order through
 * the shared AppVisibilityResolver.
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

use OCA\Buildiq\Dashboard\VirtualAppWidget;
use OCA\Buildiq\Dashboard\VirtualAppWidgetContext;
use OCA\Buildiq\Dashboard\WidgetDescriptor;
use OCA\Buildiq\Service\AppVisibilityResolver;
use OCA\Buildiq\Service\Dashboard\WidgetItemProjector;
use OCP\AppFramework\Services\IInitialState;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see VirtualAppWidget}.
 */
class VirtualAppWidgetTest extends TestCase {
	/**
	 * A stable, obviously fake Application UUID.
	 *
	 * @var string
	 */
	private const APP_UUID = '11111111-2222-3333-4444-555555555555';

	/**
	 * The widget reports the id, title, icon url, order and link it was given.
	 *
	 * @return void
	 */
	public function testReportsItsDescriptorsIdTitleIconOrderAndLink(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->willReturnCallback(
				static fn (string $route, array $params): string => '/' . $route . '/' . $params['slug']
			);

		$widget = new VirtualAppWidget(
			descriptor: $this->descriptor(panel: ['title' => 'Open cases', 'order' => 12]),
			context: $this->context(urlGenerator: $urlGenerator)
		);

		$this->assertSame('buildiq-' . self::APP_UUID . '-open-cases', $widget->getId());
		$this->assertSame('Open cases', $widget->getTitle());
		$this->assertSame(12, $widget->getOrder());
		$this->assertSame('/buildiq.icon.iconLight/pet-store', $widget->getIconUrl());
		$this->assertSame('/buildiq.dashboard.builder/pet-store', $widget->getUrl());
	}//end testReportsItsDescriptorsIdTitleIconOrderAndLink()

	/**
	 * A declared ncDashboard.link wins over the generated app link.
	 *
	 * @return void
	 */
	public function testADeclaredLinkWinsOverTheGeneratedOne(): void {
		$widget = new VirtualAppWidget(
			descriptor: $this->descriptor(panel: ['link' => '/apps/buildiq/builder/pet-store/cases']),
			context: $this->context()
		);

		$this->assertSame('/apps/buildiq/builder/pet-store/cases', $widget->getUrl());
	}//end testADeclaredLinkWinsOverTheGeneratedOne()

	/**
	 * load() provides this user's VISIBLE descriptors as initial state, so the
	 * first paint needs no extra request, and never ships a descriptor the
	 * user may not see.
	 *
	 * @return void
	 */
	public function testLoadProvidesOnlyTheDescriptorsThisUserMaySee(): void {
		$visible = $this->descriptor(principals: ['group:*'], entryId: 'open-cases');
		$hidden = $this->descriptor(principals: ['group:controllers'], entryId: 'salary-totals');

		$provided = [];
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')
			->willReturnCallback(function (string $key, $value) use (&$provided): void {
				$provided[$key] = $value;
			});

		$context = $this->context(
			initialState: $initialState,
			user: $this->signedInUser(uid: 'alice', groups: [], isAdmin: false)
		);
		$context->addDescriptor(descriptor: $visible);
		$context->addDescriptor(descriptor: $hidden);

		try {
			(new VirtualAppWidget(descriptor: $visible, context: $context))->load();
		} catch (\Error $e) {
			// `Util::addScript()` reaches \OC::$server, which only exists inside
			// a booted Nextcloud. This suite runs out of container on purpose
			// (tests/bootstrap-unit.php), so the static call is expected to
			// fail HERE and nowhere earlier. The initial state is provided
			// before it, which is what this test is about; the script tag
			// itself is covered by the live pin test.
			$this->assertStringContainsString('OC', $e->getMessage());
		}

		$this->assertArrayHasKey(
			'ncDashboardWidgets',
			$provided,
			'load() must provide the visible descriptors as initial state BEFORE it adds the script.'
		);
		$this->assertSame(
			['open-cases'],
			array_column($provided['ncDashboardWidgets'], 'entryId')
		);
	}//end testLoadProvidesOnlyTheDescriptorsThisUserMaySee()

	/**
	 * The permission matrix. Branch 1: the group:* sentinel makes the widget
	 * visible to every signed-in user.
	 *
	 * @return void
	 */
	public function testTheGroupWildcardMakesTheWidgetVisibleToEveryoneSignedIn(): void {
		$this->assertTrue(
			$this->isEnabledFor(
				principals: ['group:*'],
				uid: 'nobody-in-particular',
				groups: [],
				isAdmin: false
			)
		);
	}//end testTheGroupWildcardMakesTheWidgetVisibleToEveryoneSignedIn()

	/**
	 * Branch 2: a user:<uid> match.
	 *
	 * @return void
	 */
	public function testADirectUserMatchMakesTheWidgetVisible(): void {
		$this->assertTrue(
			$this->isEnabledFor(principals: ['user:alice'], uid: 'alice', groups: [], isAdmin: false)
		);
	}//end testADirectUserMatchMakesTheWidgetVisible()

	/**
	 * Branch 3: a group:<gid> match, and the bare-gid back-compat spelling.
	 *
	 * @return void
	 */
	public function testAGroupMatchMakesTheWidgetVisibleInBothSpellings(): void {
		$this->assertTrue(
			$this->isEnabledFor(
				principals: ['group:controllers'],
				uid: 'alice',
				groups: ['controllers'],
				isAdmin: false
			)
		);
		$this->assertTrue(
			$this->isEnabledFor(
				principals: ['controllers'],
				uid: 'alice',
				groups: ['controllers'],
				isAdmin: false
			)
		);
	}//end testAGroupMatchMakesTheWidgetVisibleInBothSpellings()

	/**
	 * Branch 4: the Nextcloud admin bypass.
	 *
	 * @return void
	 */
	public function testTheNextcloudAdminSeesEveryWidget(): void {
		$this->assertTrue(
			$this->isEnabledFor(
				principals: ['group:controllers'],
				uid: 'admin',
				groups: [],
				isAdmin: true
			)
		);
	}//end testTheNextcloudAdminSeesEveryWidget()

	/**
	 * A user outside every listed role is not offered the widget, so the id is
	 * never registered for them and a stored id from an earlier grant renders
	 * nothing.
	 *
	 * @return void
	 */
	public function testAUserOutsideEveryListedRoleIsNotOfferedTheWidget(): void {
		$this->assertFalse(
			$this->isEnabledFor(
				principals: ['group:controllers', 'user:bob'],
				uid: 'alice',
				groups: ['readers'],
				isAdmin: false
			)
		);
	}//end testAUserOutsideEveryListedRoleIsNotOfferedTheWidget()

	/**
	 * An unauthenticated session sees nothing.
	 *
	 * @return void
	 */
	public function testAnUnauthenticatedSessionSeesNothing(): void {
		$context = $this->context(user: null);
		$widget = new VirtualAppWidget(
			descriptor: $this->descriptor(principals: ['group:*']),
			context: $context
		);

		$this->assertFalse($widget->isEnabled());
	}//end testAnUnauthenticatedSessionSeesNothing()

	/**
	 * A placement carrying visibleWhen is promoted and gated on roles alone.
	 * The expression reads page context that does not exist on the dashboard,
	 * so it is never evaluated and never blocks the widget.
	 *
	 * @return void
	 */
	public function testAPlacementCarryingVisibleWhenIsShownOnRolesAlone(): void {
		$descriptor = new WidgetDescriptor(
			applicationUuid: self::APP_UUID,
			applicationSlug: 'pet-store',
			applicationName: 'Pet Store',
			principals: ['group:controllers'],
			entryId: 'open-cases',
			widgetKey: 'stat',
			panel: ['title' => 'Open cases'],
			placement: ['pageRoute' => '/overview']
		);

		// visibleWhen is not carried on the descriptor at all: there is no
		// field for it, so nothing downstream can evaluate it by accident.
		$this->assertObjectNotHasProperty('visibleWhen', $descriptor);

		$context = $this->context(
			user: $this->signedInUser(uid: 'alice', groups: ['controllers'], isAdmin: false)
		);
		$this->assertTrue(
			(new VirtualAppWidget(descriptor: $descriptor, context: $context))->isEnabled()
		);
	}//end testAPlacementCarryingVisibleWhenIsShownOnRolesAlone()

	/**
	 * getItemsV2() delegates to the projector and forwards the limit.
	 *
	 * @return void
	 */
	public function testGetItemsV2DelegatesToTheProjector(): void {
		$expected = new WidgetItems(items: [], emptyContentMessage: 'Open Pet Store to see this widget.');

		$projector = $this->createMock(WidgetItemProjector::class);
		$projector->expects($this->once())
			->method('project')
			->with($this->anything(), 3)
			->willReturn($expected);

		$widget = new VirtualAppWidget(
			descriptor: $this->descriptor(),
			context: $this->context(projector: $projector)
		);

		$this->assertSame($expected, $widget->getItemsV2('alice', null, 3));
	}//end testGetItemsV2DelegatesToTheProjector()

	/**
	 * Run isEnabled() for one permission-matrix case.
	 *
	 * @param array<int,string> $principals The descriptor's principals.
	 * @param string $uid The signed-in user's id.
	 * @param array<int,string> $groups The user's group memberships.
	 * @param bool $isAdmin Whether the user is a Nextcloud admin.
	 *
	 * @return bool The widget's isEnabled() answer.
	 */
	private function isEnabledFor(array $principals, string $uid, array $groups, bool $isAdmin): bool {
		$context = $this->context(
			user: $this->signedInUser(uid: $uid, groups: $groups, isAdmin: $isAdmin)
		);

		return (new VirtualAppWidget(
			descriptor: $this->descriptor(principals: $principals),
			context: $context
		))->isEnabled();
	}//end isEnabledFor()

	/**
	 * A descriptor for one promoted placement.
	 *
	 * @param array<int,string> $principals The principals allowed to see it.
	 * @param array<string,mixed> $panel The ncDashboard object.
	 * @param string $entryId The placement's own id.
	 *
	 * @return WidgetDescriptor The descriptor.
	 */
	private function descriptor(
		array $principals = ['group:*'],
		array $panel = ['title' => 'Open cases'],
		string $entryId = 'open-cases',
	): WidgetDescriptor {
		return new WidgetDescriptor(
			applicationUuid: self::APP_UUID,
			applicationSlug: 'pet-store',
			applicationName: 'Pet Store',
			principals: $principals,
			entryId: $entryId,
			widgetKey: 'stat',
			panel: $panel,
			placement: ['pageRoute' => '/overview']
		);
	}//end descriptor()

	/**
	 * Build a widget context, defaulting every collaborator to a mock.
	 *
	 * @param IURLGenerator|null $urlGenerator URL generator.
	 * @param IInitialState|null $initialState Initial state.
	 * @param WidgetItemProjector|null $projector Item projector.
	 * @param IUser|null $user The signed-in user, or null for no session.
	 *
	 * @return VirtualAppWidgetContext The context.
	 */
	private function context(
		?IURLGenerator $urlGenerator = null,
		?IInitialState $initialState = null,
		?WidgetItemProjector $projector = null,
		?IUser $user = null,
	): VirtualAppWidgetContext {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')
			->willReturn($user === null ? [] : ($this->groupsByUid[$user->getUID()] ?? []));
		$groupManager->method('isAdmin')
			->willReturn($user === null ? false : ($this->adminByUid[$user->getUID()] ?? false));

		return new VirtualAppWidgetContext(
			urlGenerator: ($urlGenerator ?? $this->createMock(IURLGenerator::class)),
			userSession: $userSession,
			groupManager: $groupManager,
			visibility: new AppVisibilityResolver(),
			projector: ($projector ?? $this->createMock(WidgetItemProjector::class)),
			initialState: ($initialState ?? $this->createMock(IInitialState::class))
		);
	}//end context()

	/**
	 * Group memberships by uid, read back by the group-manager mock.
	 *
	 * @var array<string,array<int,string>>
	 */
	private array $groupsByUid = [];

	/**
	 * Admin flags by uid, read back by the group-manager mock.
	 *
	 * @var array<string,bool>
	 */
	private array $adminByUid = [];

	/**
	 * A signed-in user with the given memberships.
	 *
	 * @param string $uid The user id.
	 * @param array<int,string> $groups Group memberships.
	 * @param bool $isAdmin Whether the user is a Nextcloud admin.
	 *
	 * @return IUser The user mock.
	 */
	private function signedInUser(string $uid, array $groups, bool $isAdmin): IUser {
		$this->groupsByUid[$uid] = $groups;
		$this->adminByUid[$uid] = $isAdmin;

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end signedInUser()
}//end class
