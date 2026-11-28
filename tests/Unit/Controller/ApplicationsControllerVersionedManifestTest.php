<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for ApplicationsController::getManifest() on its `?_version=` path.
 *
 * A requested version the resolver cannot hand out answers the same 404 as an
 * unknown one, and a resolver that throws turns into a 500 envelope carrying a
 * correlation id that is also written to the error log.
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationsController;
use OCA\Buildiq\Exception\InvalidServedManifestException;
use OCA\Buildiq\Kernel\Contracts\AppChannelApplierInterface;
use OCA\Buildiq\Kernel\Contracts\ManifestResolverServiceInterface;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\RegisterStore;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the versioned branch of ApplicationsController::getManifest().
 *
 * @covers \OCA\Buildiq\Controller\ApplicationsController
 * @uses   \OCA\Buildiq\Service\RegisterStore
 * @uses   \OCA\Buildiq\Service\CallerContext
 * @uses   \OCA\Buildiq\Service\PermissionResolver
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */
class ApplicationsControllerVersionedManifestTest extends TestCase {

	/**
	 * Request mock answering `_version`.
	 *
	 * @var IRequest&MockObject
	 */
	private $request;

	/**
	 * Manifest resolver mock.
	 *
	 * @var ManifestResolverServiceInterface&MockObject
	 */
	private $manifestResolver;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * OpenRegister object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private $objectService;

	/**
	 * The signed-in caller.
	 *
	 * @var IUser&MockObject
	 */
	private $user;

	/**
	 * Wire the collaborator mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === '_version' ? 'v-2' : $default)
		);
		$this->manifestResolver = $this->createMock(ManifestResolverServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('alice');
	}//end setUp()

	/**
	 * A version the resolver does not hand out answers 404 "Version not found"
	 * without looking the Application up.
	 *
	 * @return void
	 */
	public function testAnUnresolvedVersionIsNotFound(): void {
		$this->manifestResolver->expects($this->once())
			->method('resolveServed')
			->with('crm', 'v-2', $this->user)
			->willReturn(null);
		$this->objectService->expects($this->never())->method('searchObjects');
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller()->getManifest(slug: 'crm');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(
			['status' => Http::STATUS_NOT_FOUND, 'message' => 'Version not found'],
			$response->getData()
		);
	}//end testAnUnresolvedVersionIsNotFound()

	/**
	 * A resolver failure becomes a 500 envelope whose correlation id is the one
	 * written to the error log, together with the slug.
	 *
	 * @return void
	 */
	public function testAResolverFailureIsAnInternalErrorWithACorrelationId(): void {
		$failure = new RuntimeException('register offline');
		$this->manifestResolver->method('resolveServed')->willThrowException($failure);

		$logged = null;
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'Buildiq: getManifest failed for slug crm: register offline',
				$this->callback(
					static function (array $context) use (&$logged, $failure): bool {
						$logged = $context;
						return $context['exception'] === $failure && $context['slug'] === 'crm';
					}
				)
			);

		$response = $this->controller()->getManifest(slug: 'crm');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('internal_error', $data['error']);
		$this->assertSame('Failed to resolve manifest', $data['message']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $data['correlationId']);
		$this->assertSame($data['correlationId'], $logged['correlationId']);
	}//end testAResolverFailureIsAnInternalErrorWithACorrelationId()

	/**
	 * A merged manifest the renderer cannot draw answers the same correlation-id envelope, not a stack.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-4.3
	 */
	public function testAnUnservableMergedManifestIsTheCorrelationIdError(): void {
		$this->manifestResolver->method('resolveServed')->willThrowException(
			new InvalidServedManifestException(message: 'The merged manifest cannot be served: `pages[0].route` is not a string')
		);
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller()->getManifest(slug: 'crm');
		$data = $response->getData();

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		$this->assertSame(expected: ['error', 'message', 'correlationId'], actual: array_keys($data));
		$this->assertSame(expected: 'Failed to resolve manifest', actual: $data['message']);
	}//end testAnUnservableMergedManifestIsTheCorrelationIdError()

	/**
	 * The controller under test, signed in as alice.
	 *
	 * @return ApplicationsController
	 */
	private function controller(): ApplicationsController {
		$groupManager = $this->createMock(IGroupManager::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($this->user);

		return new ApplicationsController(
			request: $this->request,
			logger: $this->logger,
			store: new RegisterStore(
				objectService: $this->objectService,
				registerMapper: $this->createMock(RegisterMapper::class),
				schemaMapper: $this->createMock(SchemaMapper::class),
				auditTrailMapper: null
			),
			caller: new CallerContext(
				userSession: $userSession,
				groupManager: $groupManager,
				permissionResolver: new PermissionResolver($groupManager, $this->logger)
			),
			manifestResolver: $this->manifestResolver,
			channelApplier: $this->createMock(AppChannelApplierInterface::class),
		);
	}//end controller()
}//end class
