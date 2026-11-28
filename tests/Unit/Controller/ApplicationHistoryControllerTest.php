<?php

/**
 * Tests for ApplicationHistoryController: an app's change history is read
 * only by a user with a role on the app (REQ-BQCV-014).
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
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use DateTime;
use OCA\Buildiq\Controller\ApplicationHistoryController;
use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCA\Buildiq\Service\AppChangeHistory;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the change history endpoint.
 *
 * @covers \OCA\Buildiq\Controller\ApplicationHistoryController
 * @uses   \OCA\Buildiq\Service\AppChangeHistory
 */
final class ApplicationHistoryControllerTest extends TestCase {
	/**
	 * Audit trail double.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper&MockObject $trails;

	/**
	 * The role arguments the resolver was asked with.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $asked = [];

	/**
	 * Set up the audit trail double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->trails = $this->createMock(AuditTrailMapper::class);
		$this->asked  = [];
	}//end setUp()

	/**
	 * An owner sees sanne's save of the development version at 10:15.
	 *
	 * @return void
	 */
	public function testAnOwnerReadsTheDesignHistory(): void {
		$save = new AuditTrail();
		$save->setAction('update');
		$save->setUser('sanne');
		$save->setUserName('Sanne');
		$save->setCreated(new DateTime('2026-11-24T10:15:00+00:00'));
		$this->trails->method('findAll')->willReturnCallback(
			static fn (?int $limit=null, ?int $offset=null, ?array $filters=[]): array => ($filters['object_uuid'] ?? '') === 'v-dev' ? [$save] : []
		);

		$response = $this->controller(uid: 'olaf')->index(appSlug: 'permits');

		self::assertSame(expected: 200, actual: $response->getStatus());
		$entries = $response->getData()['entries'];
		self::assertCount(expectedCount: 1, haystack: $entries);
		self::assertSame(
			expected: ['sanne', '2026-11-24T10:15:00+00:00', 'development'],
			actual: [$entries[0]['user'], $entries[0]['at'], $entries[0]['version']]
		);
		self::assertSame(
			expected: [['owners', 'editors', 'viewers'], false, ['owners' => ['user:olaf']]],
			actual: [$this->asked[0]['roles'], $this->asked[0]['allowAdminBypass'], $this->asked[0]['permissions']]
		);
	}//end testAnOwnerReadsTheDesignHistory()

	/**
	 * A user without a role is refused and the audit trail is never read.
	 *
	 * @return void
	 */
	public function testAUserWithoutARoleIsRefused(): void {
		$this->trails->expects($this->never())->method('findAll');

		$response = $this->controller(uid: 'mallory')->index(appSlug: 'permits');

		self::assertSame(expected: [403, ['error' => 'buildiq.rbac.no_role']], actual: [$response->getStatus(), $response->getData()]);
	}//end testAUserWithoutARoleIsRefused()

	/**
	 * Another organisation's scoped app answers like an unknown one.
	 *
	 * @return void
	 */
	public function testAnAppTheCallerCannotSeeIsNotFound(): void {
		$this->trails->expects($this->never())->method('findAll');

		$response = $this->controller(uid: 'olaf', sees: false)->index(appSlug: 'permits');

		self::assertSame(expected: 404, actual: $response->getStatus());
	}//end testAnAppTheCallerCannotSeeIsNotFound()

	/**
	 * Nobody signed in is 401.
	 *
	 * @return void
	 */
	public function testNobodySignedInIsUnauthorised(): void {
		$response = $this->controller(uid: null)->index(appSlug: 'permits');

		self::assertSame(expected: 401, actual: $response->getStatus());
	}//end testNobodySignedInIsUnauthorised()

	/**
	 * Build the controller; olaf owns the app, and the resolver grants only him.
	 *
	 * @param string|null $uid  The signed-in user, or null for nobody.
	 * @param bool        $sees Whether the caller's organisation sees the app.
	 *
	 * @return ApplicationHistoryController
	 */
	private function controller(?string $uid, bool $sees=true): ApplicationHistoryController {
		$application = ['id' => 'app-uuid', 'slug' => 'permits', 'permissions' => ['owners' => ['user:olaf']]];
		$versions    = [
			['id' => 'v-dev', 'slug' => 'development', 'application' => 'app-uuid'],
			['id' => 'v-other', 'slug' => 'development', 'application' => 'other-uuid'],
		];
		$objects     = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			static fn (array $query): array => isset($query['slug']) === true ? [$application] : $versions
		);

		$register = new Register();
		$register->setId(1);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);
		$schema = new Schema();
		$schema->setId(2);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$resolver = $this->createMock(PermissionResolverInterface::class);
		$resolver->method('seesApplication')->willReturn($sees);
		$resolver->method('resolveUserGroups')->willReturn([]);
		$resolver->method('matchesCaller')->willReturnCallback(
			function (array $permissions, IUser $caller, array $userGroups, ?bool $allowAdminBypass, array $roles): bool {
				$this->asked[] = ['permissions' => $permissions, 'allowAdminBypass' => $allowAdminBypass, 'roles' => $roles];
				return $caller->getUID() === 'olaf';
			}
		);

		return new ApplicationHistoryController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			permissionResolver: $resolver,
			history: new AppChangeHistory(objectService: $objects, registerMapper: $registers, schemaMapper: $schemas, trails: $this->trails),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()
}//end class
