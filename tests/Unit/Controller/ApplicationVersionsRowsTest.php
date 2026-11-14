<?php

/**
 * Golden tests for how ApplicationVersionsController lists an Application's versions.
 *
 * index() and the release lookup both read every ApplicationVersion row and keep
 * the ones whose `application` is this Application, because OpenRegister's
 * relation filter is unreliable. These pin that scoping (no other app's version
 * leaks), the order kept, and the answers when the schema is missing or the
 * search answers a count.
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins the version-row scan.
 */
class ApplicationVersionsRowsTest extends TestCase {
	/**
	 * Object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Schema mapper double.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
	}//end setUp()

	/**
	 * index() answers this Application's versions only, in stored order.
	 *
	 * @return void
	 */
	public function testIndexListsOnlyThisApplicationsVersions(): void {
		$this->schemas(missing: '');
		$this->answer(versions: $this->rows());

		$response = $this->controller()->index(appSlug: 'hello-world');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['v-1', 'v-3'], array_column($response->getData(), 'id'));
	}//end testIndexListsOnlyThisApplicationsVersions()

	/**
	 * A count answer is an empty list, not an error.
	 *
	 * @return void
	 */
	public function testIndexTreatsACountAsNoVersions(): void {
		$this->schemas(missing: '');
		$this->answer(versions: 3);

		$response = $this->controller()->index(appSlug: 'hello-world');

		self::assertSame([200, []], [$response->getStatus(), $response->getData()]);
	}//end testIndexTreatsACountAsNoVersions()

	/**
	 * A missing version schema is a 404 naming the Application.
	 *
	 * @return void
	 */
	public function testIndexWithoutTheVersionSchemaIsNotFound(): void {
		$this->schemas(missing: ApplicationVersionService::APPLICATION_VERSION_SCHEMA);
		$this->answer(versions: $this->rows());

		$response = $this->controller()->index(appSlug: 'hello-world');

		self::assertSame([404, ['error' => 'not_found', 'detail' => 'Application hello-world not found']], [$response->getStatus(), $response->getData()]);
	}//end testIndexWithoutTheVersionSchemaIsNotFound()

	/**
	 * The release lookup finds the slug among this Application's versions only.
	 *
	 * @return void
	 */
	public function testReleaseLookupIsScopedToTheApplication(): void {
		$this->schemas(missing: '');
		$lookup = function (string $slug, mixed $versions): ?array {
			$this->setUp();
			$this->schemas(missing: '');
			$this->objectService->method('searchObjects')->willReturn($versions);
			$controller = $this->controller();
			return (new ReflectionMethod($controller, 'findVersionRowBySlug'))->invoke($controller, ['id' => 'app-uuid'], $slug);
		};

		self::assertSame('v-3', $lookup('staging', $this->rows())['id']);
		self::assertNull($lookup('other-staging', $this->rows()));
		self::assertNull($lookup('staging', 3));
	}//end testReleaseLookupIsScopedToTheApplication()

	/**
	 * Version rows: two of this app, one of another, one without a parent.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(): array {
		return [
			['id' => 'v-1', 'slug' => 'development', 'application' => 'app-uuid'],
			['id' => 'v-2', 'slug' => 'other-staging', 'application' => 'other-uuid'],
			['id' => 'v-3', 'slug' => 'staging', 'application' => 'app-uuid'],
			['id' => 'v-4', 'slug' => 'staging'],
		];
	}//end rows()

	/**
	 * Answer the Application lookups with the Application, and the version scan with these rows.
	 *
	 * @param mixed $versions The version scan's answer.
	 *
	 * @return void
	 */
	private function answer(mixed $versions): void {
		$application = ['id' => 'app-uuid', 'slug' => 'hello-world', 'permissions' => ['viewers' => ['user:vera']]];
		$this->objectService->method('searchObjects')->willReturnCallback(
			static fn (array $query): array|int => isset($query['slug']) ? [$application] : $versions
		);
	}//end answer()

	/**
	 * Resolve every schema except the missing one.
	 *
	 * @param string $missing The schema slug that does not exist, or ''.
	 *
	 * @return void
	 */
	private function schemas(string $missing): void {
		$this->schemaMapper->method('find')->willReturnCallback(static function (string $slug) use ($missing): Schema {
			if ($slug === $missing) {
				throw new DoesNotExistException('no ' . $slug);
			}

			$schema = new Schema();
			$schema->setId(2);
			return $schema;
		});
	}//end schemas()

	/**
	 * Build the controller under test, with vera signed in.
	 *
	 * @return ApplicationVersionsController
	 */
	private function controller(): ApplicationVersionsController {
		$register = new Register();
		$register->setId(1);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('vera');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn([]);
		return new ApplicationVersionsController(
			request: $this->createMock(IRequest::class),
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->objectService,
			registerMapper: $registerMapper,
			schemaMapper: $this->schemaMapper,
			userSession: $session,
			groupManager: $groupManager,
			versionService: $this->createMock(ApplicationVersionService::class),
			auditTrailMapper: null,
		);
	}//end controller()
}//end class
