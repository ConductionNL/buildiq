<?php

/**
 * Unit tests for ApplicationConnectionsController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationConnectionsController;
use OCA\Buildiq\Kernel\Contracts\ManifestResolverServiceInterface;
use OCA\Buildiq\Service\ApplicationConnectionsService;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\PermissionResolver;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who may read an app's connections, checked before anything is read.
 */
final class ApplicationConnectionsControllerTest extends TestCase {

	/**
	 * @var ApplicationConnectionsService&MockObject
	 */
	private ApplicationConnectionsService&MockObject $connections;

	/**
	 * @var ManifestResolverServiceInterface&MockObject
	 */
	private ManifestResolverServiceInterface&MockObject $manifests;

	/**
	 * The controller for this user, admin or not.
	 *
	 * @param string|null $uid   The signed-in user, or null for none.
	 * @param bool        $admin Whether that user is an admin.
	 *
	 * @return ApplicationConnectionsController
	 */
	private function controller(?string $uid, bool $admin = false): ApplicationConnectionsController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);
		$groups->method('getUserGroups')->willReturn([]);

		$this->connections = $this->createMock(ApplicationConnectionsService::class);
		$this->manifests = $this->createMock(ManifestResolverServiceInterface::class);

		return new ApplicationConnectionsController(
			request: $this->createMock(IRequest::class),
			caller: new CallerContext(
				userSession: $session,
				groupManager: $groups,
				permissionResolver: new PermissionResolver(groupManager: $groups, logger: $this->createMock(LoggerInterface::class))
			),
			manifestResolver: $this->manifests,
			connections: $this->connections
		);
	}//end controller()

	/**
	 * The Vergunningen application, with alice as owner and bob as editor.
	 *
	 * @return array<string, mixed>
	 */
	private static function application(): array {
		return ['slug' => 'vergunningen', 'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob'], 'viewers' => ['user:carol']]];
	}//end application()

	/**
	 * A user who may not edit the app gets 403 before any connection or manifest is read.
	 *
	 * @return void
	 */
	public function testAUserWhoMayNotEditTheAppIsRefusedBeforeAnyRead(): void {
		$controller = $this->controller(uid: 'carol');
		$this->manifests->method('findApplication')->willReturn(self::application());
		$this->manifests->expects(self::never())->method('resolve');
		$this->connections->expects(self::never())->method('list');

		$response = $controller->index(id: 'vergunningen');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('buildiq.rbac.no_role', $response->getData()['code']);
	}//end testAUserWhoMayNotEditTheAppIsRefusedBeforeAnyRead()

	/**
	 * An editor gets the list for the production manifest.
	 *
	 * @return void
	 */
	public function testAnEditorGetsTheConnections(): void {
		$controller = $this->controller(uid: 'bob');
		$this->manifests->method('findApplication')->with('vergunningen')->willReturn(self::application());
		$this->manifests->method('resolve')->willReturn(['pages' => []]);
		$listed = ['healthNeedsIntegriq' => false, 'connections' => [['kind' => 'integration', 'id' => 'con-1']]];
		$this->connections->expects(self::once())->method('list')->with('vergunningen', ['pages' => []])->willReturn($listed);

		$response = $controller->index(id: 'vergunningen');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($listed, $response->getData());
	}//end testAnEditorGetsTheConnections()

	/**
	 * An admin without a role on the app gets the list too.
	 *
	 * @return void
	 */
	public function testAnAdminGetsTheConnections(): void {
		$controller = $this->controller(uid: 'dave', admin: true);
		$this->manifests->method('findApplication')->willReturn(self::application());
		$this->manifests->method('resolve')->willReturn(null);
		$this->connections->expects(self::once())->method('list')->with('vergunningen', [])->willReturn(['healthNeedsIntegriq' => true, 'connections' => []]);

		self::assertSame(Http::STATUS_OK, $controller->index(id: 'vergunningen')->getStatus());
	}//end testAnAdminGetsTheConnections()

	/**
	 * No session is 401 and an unknown app 404, and neither reads connections.
	 *
	 * @return void
	 */
	public function testNoSessionAndUnknownApp(): void {
		$anonymous = $this->controller(uid: null);
		$this->connections->expects(self::never())->method('list');
		self::assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->index(id: 'vergunningen')->getStatus());

		$controller = $this->controller(uid: 'bob');
		$this->manifests->method('findApplication')->willReturn(null);
		$this->connections->expects(self::never())->method('list');
		self::assertSame(Http::STATUS_NOT_FOUND, $controller->index(id: 'missing')->getStatus());
	}//end testNoSessionAndUnknownApp()
}//end class
