<?php

/**
 * Golden tests for ApplicationVersionsController's Application and version lookups.
 *
 * show() runs both lookups the controller has: the parent Application by slug
 * (which also feeds the role gate) and the version by slug, scoped to that
 * Application. These pin the exact queries sent to OpenRegister and the
 * allowed and refused outcomes, so the shared lookup cannot change either.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationVersionsController;
use OCA\Buildiq\Service\ApplicationVersionService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the lookups behind show().
 */
class ApplicationVersionsLookupTest extends TestCase {
	/**
	 * The queries sent to searchObjects(), in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * User session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * A viewer gets the version, through one Application query per lookup and one version query.
	 *
	 * @return void
	 */
	public function testViewerGetsTheVersion(): void {
		$this->answer(rows: [[$this->application()], [$this->application()], [['id' => 'v-1', 'slug' => 'staging', 'application' => 'app-uuid']]]);

		$response = $this->show(uid: 'vera');

		self::assertSame([200, ['id' => 'v-1', 'slug' => 'staging', 'application' => 'app-uuid']], $this->outcome(response: $response));
		$app = ['@self' => ['register' => 1, 'schema' => 2], 'slug' => 'hello-world'];
		$version = ['@self' => ['register' => 1, 'schema' => 2], 'slug' => 'staging', 'application' => 'app-uuid'];
		self::assertSame([$app, $app, $version], $this->queries);
	}//end testViewerGetsTheVersion()

	/**
	 * A caller without a role is refused before the version is read.
	 *
	 * @return void
	 */
	public function testStrangerIsRefused(): void {
		$this->answer(rows: [[$this->application()]]);

		$response = $this->show(uid: 'carol');

		self::assertSame([403, ['error' => 'buildiq.rbac.no_role']], $this->outcome(response: $response));
		self::assertCount(1, $this->queries);
	}//end testStrangerIsRefused()

	/**
	 * An unknown Application is a 404 naming it.
	 *
	 * @return void
	 */
	public function testUnknownApplicationIsNotFound(): void {
		$this->answer(rows: [[]]);

		$response = $this->show(uid: 'vera');

		self::assertSame([404, ['error' => 'not_found', 'detail' => 'Application hello-world not found']], $this->outcome(response: $response));
	}//end testUnknownApplicationIsNotFound()

	/**
	 * An unknown version is a 404 naming it.
	 *
	 * @return void
	 */
	public function testUnknownVersionIsNotFound(): void {
		$this->answer(rows: [[$this->application()], [$this->application()], []]);

		$response = $this->show(uid: 'vera');

		self::assertSame([404, ['error' => 'not_found', 'detail' => 'staging']], $this->outcome(response: $response));
	}//end testUnknownVersionIsNotFound()

	/**
	 * A count (non-array) search answer counts as no row.
	 *
	 * @return void
	 */
	public function testNonArrayAnswerIsNotFound(): void {
		$this->objectService->method('searchObjects')->willReturn(0);
		$this->signIn(uid: 'vera');

		self::assertSame(404, $this->show(uid: 'vera')->getStatus());
	}//end testNonArrayAnswerIsNotFound()

	/**
	 * Call show() for staging of hello-world as the given caller.
	 *
	 * @param string $uid The caller.
	 *
	 * @return JSONResponse
	 */
	private function show(string $uid): JSONResponse {
		$this->signIn(uid: $uid);
		return $this->controller()->show(appSlug: 'hello-world', versionSlug: 'staging');
	}//end show()

	/**
	 * Answer successive searches with the given row lists, recording each query.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $rows The answers, in order.
	 *
	 * @return void
	 */
	private function answer(array $rows): void {
		$this->objectService->method('searchObjects')->willReturnCallback(
			function (array $query) use (&$rows): array {
				$this->queries[] = $query;
				return (array)array_shift($rows);
			}
		);
	}//end answer()

	/**
	 * Sign the caller in (once).
	 *
	 * @param string $uid The caller.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * The Application: alice owns it, vera views it.
	 *
	 * @return array<string, mixed>
	 */
	private function application(): array {
		return ['id' => 'app-uuid', 'slug' => 'hello-world', 'permissions' => ['owners' => ['user:alice'], 'viewers' => ['user:vera']]];
	}//end application()

	/**
	 * Reduce a response to status and body.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0: int, 1: mixed}
	 */
	private function outcome(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end outcome()

	/**
	 * Build the controller under test.
	 *
	 * @return ApplicationVersionsController
	 */
	private function controller(): ApplicationVersionsController {
		$register = new Register();
		$register->setId(1);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$schema = new Schema();
		$schema->setId(2);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(false);
		$groupManager->method('getUserGroups')->willReturn([]);
		return new ApplicationVersionsController(
			request: $this->createMock(IRequest::class),
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->objectService,
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			userSession: $this->userSession,
			groupManager: $groupManager,
			versionService: $this->createMock(ApplicationVersionService::class),
			auditTrailMapper: null,
		);
	}//end controller()
}//end class
