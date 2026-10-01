<?php

/**
 * Unit tests for ApplicationsController::copy (change apps-copy-app-and-page,
 * T02; REQ-BQCP-001, REQ-BQCP-002).
 *
 * The copy runs the source app through AppTemplateCapture and hands the
 * result to installFromTemplateArray(), the clone seam a template install
 * uses, which is covered by CreateFromTemplateTest. Here the seam is the one
 * method replaced, so the tests read exactly what copy() hands it, and every
 * refusal is asserted to reach it never.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/copy-app-page-and-form/spec.md#requirement-copying-an-app-has-the-gates-of-cloning-a-template-req-bqcp-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationsController;
use OCA\Buildiq\Service\AppChannelApplier;
use OCA\Buildiq\Service\ApplicationVersionService;
use OCA\Buildiq\Service\ManifestResolverService;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for copying an app.
 */
class CopyApplicationTest extends TestCase {

	/**
	 * The request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The group manager double.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The controller under test, its install seam replaced.
	 *
	 * @var ApplicationsController&MockObject
	 */
	private ApplicationsController&MockObject $controller;

	/**
	 * Build the controller over the source app "Permit tracker".
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$logger = $this->createMock(LoggerInterface::class);

		$shared = new Register();
		$shared->setId(926);
		$versionRegister = new Register();
		$versionRegister->setId(3001);
		$versionRegister->setSlug('openbuild-permit-tracker-production');
		$versionRegister->setSchemas([501]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			static function (...$args) use ($shared, $versionRegister): Register {
				$id = (string)($args['id'] ?? $args[0]);
				if ($id === ApplicationVersionService::REGISTER_SLUG) {
					return $shared;
				}

				if ($id === 'openbuild-permit-tracker-production') {
					return $versionRegister;
				}

				throw new \RuntimeException('register not found: ' . $id);
			}
		);

		$route = new Schema();
		$route->setId(40);
		$permit = new Schema();
		$permit->setId(501);
		$permit->setSlug('permit-tracker-permit');
		$permit->setTitle('Permit');
		$permit->setProperties(['status' => ['type' => 'string']]);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function (...$args) use ($route, $permit): Schema {
				$id = (string)($args['id'] ?? $args[0]);
				if ($id === '501') {
					return $permit;
				}

				return $route;
			}
		);

		$this->objectService->method('searchObjects')->willReturn([['slug' => 'permit-tracker', 'applicationUuid' => 'app-1']]);
		$this->objectService->method('find')->willReturnCallback(
			static function (...$args): ?ObjectEntity {
				$id = (string)($args['id'] ?? $args[0]);
				$entity = new ObjectEntity();
				$entity->setUuid($id);
				if ($id === 'app-1') {
					$entity->setObject([
						'id' => 'app-1',
						'slug' => 'permit-tracker',
						'name' => 'Permit tracker',
						'description' => 'Tracks permits',
						'productionVersion' => 'ver-1',
						'permissions' => ['owners' => ['user:anna'], 'editors' => ['user:ed'], 'viewers' => ['user:vic']],
					]);
					return $entity;
				}

				if ($id === 'ver-1') {
					$entity->setObject([
						'id' => 'ver-1',
						'register' => 'openbuild-permit-tracker-production',
						'manifest' => ['pages' => [['id' => 'index', 'type' => 'index', 'config' => ['schema' => 'permit-tracker-permit']]]],
					]);
					return $entity;
				}

				return null;
			}
		);

		$this->controller = $this->getMockBuilder(ApplicationsController::class)
			->setConstructorArgs(
				[
					$this->request,
					$logger,
					$this->objectService,
					$registerMapper,
					$schemaMapper,
					$this->userSession,
					$this->groupManager,
					$this->createMock(ManifestResolverService::class),
					new PermissionResolver(groupManager: $this->groupManager, logger: $logger),
					$this->createMock(AppChannelApplier::class),
				]
			)
			->onlyMethods(['installFromTemplateArray'])
			->getMock();

	}//end setUp()

	/**
	 * Sign a user in, as admin or not, asking for a copy.
	 *
	 * @param string $uid The user id
	 * @param bool $admin Whether they are a Nextcloud administrator
	 *
	 * @return void
	 */
	private function signIn(string $uid, bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isInGroup')->willReturn($admin);
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->request->method('getParams')->willReturn(['name' => 'Event permits', 'slug' => 'event-permits']);

	}//end signIn()

	/**
	 * REQ-BQCP-001: an editor who is an admin gets a new app from the source's
	 * schemas and current manifest, de-namespaced, as a template install.
	 *
	 * @return void
	 */
	public function testAnEditorCopiesTheApp(): void {
		$this->signIn(uid: 'ed', admin: true);
		$this->controller->expects($this->once())
			->method('installFromTemplateArray')
			->willReturnCallback(
				static function (array $template, string $name, string $newSlug, string $ownerUid): array {
					self::assertSame('Event permits', $name);
					self::assertSame('event-permits', $newSlug);
					self::assertSame('ed', $ownerUid);
					self::assertSame('permit', $template['companionSchemas'][0]['slug']);
					self::assertSame(['status' => ['type' => 'string']], $template['companionSchemas'][0]['properties']);
					self::assertSame('permit', $template['manifest']['pages'][0]['config']['schema']);
					self::assertSame('Tracks permits', $template['description']);
					return ['status' => Http::STATUS_CREATED, 'data' => ['slug' => 'event-permits']];
				}
			);

		$response = $this->controller->copy('permit-tracker');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());

	}//end testAnEditorCopiesTheApp()

	/**
	 * REQ-BQCP-002 scenario: a viewer cannot copy the app, even as admin.
	 *
	 * @return void
	 */
	public function testAViewerCannotCopyTheApp(): void {
		$this->signIn(uid: 'vic', admin: true);
		$this->controller->expects($this->never())->method('installFromTemplateArray');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->copy('permit-tracker')->getStatus());

	}//end testAViewerCannotCopyTheApp()

	/**
	 * REQ-BQCP-002: copying provisions a register, so a non-admin owner is refused.
	 *
	 * @return void
	 */
	public function testANonAdminOwnerIsRefused(): void {
		$this->signIn(uid: 'anna', admin: false);
		$this->controller->expects($this->never())->method('installFromTemplateArray');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->copy('permit-tracker')->getStatus());

	}//end testANonAdminOwnerIsRefused()

	/**
	 * REQ-BQCP-002: a taken slug is the install seam's 409, passed through.
	 *
	 * @return void
	 */
	public function testATakenSlugIsRefused(): void {
		$this->signIn(uid: 'anna', admin: true);
		$this->controller->method('installFromTemplateArray')->willReturn(
			['status' => Http::STATUS_CONFLICT, 'data' => ['error' => 'slug_taken']]
		);

		self::assertSame(Http::STATUS_CONFLICT, $this->controller->copy('permit-tracker')->getStatus());

	}//end testATakenSlugIsRefused()

	/**
	 * REQ-BQCP-002: rate limited like from-template.
	 *
	 * @return void
	 */
	public function testCopyIsRateLimitedLikeFromTemplate(): void {
		$copy = (new ReflectionMethod(ApplicationsController::class, 'copy'))->getAttributes(UserRateLimit::class);
		$clone = (new ReflectionMethod(ApplicationsController::class, 'createFromTemplate'))->getAttributes(UserRateLimit::class);

		self::assertCount(1, $copy);
		self::assertSame($clone[0]->getArguments(), $copy[0]->getArguments());

	}//end testCopyIsRateLimitedLikeFromTemplate()
}//end class
