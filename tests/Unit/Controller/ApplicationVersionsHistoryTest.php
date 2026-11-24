<?php

/**
 * Tests for ApplicationVersionsController::history(): an app's change history
 * is read only by a user with a role on the app (REQ-BQCV-014).
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
use OCA\Buildiq\Controller\ApplicationVersionsController;
use OCA\Buildiq\Service\ApplicationVersionService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the change history endpoint.
 *
 * @covers \OCA\Buildiq\Controller\ApplicationVersionsController
 * @uses   \OCA\Buildiq\Service\AppChangeHistory
 */
final class ApplicationVersionsHistoryTest extends TestCase {
	/**
	 * Audit trail double.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper&MockObject $trails;

	/**
	 * Set up the audit trail double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->trails = $this->createMock(AuditTrailMapper::class);
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

		$response = $this->controller(uid: 'olaf')->history(appSlug: 'permits');

		self::assertSame(expected: 200, actual: $response->getStatus());
		$entries = $response->getData()['entries'];
		self::assertCount(expectedCount: 1, haystack: $entries);
		self::assertSame(
			expected: ['sanne', '2026-11-24T10:15:00+00:00', 'development'],
			actual: [$entries[0]['user'], $entries[0]['at'], $entries[0]['version']]
		);
	}//end testAnOwnerReadsTheDesignHistory()

	/**
	 * A user without a role is refused and the audit trail is never read.
	 *
	 * @return void
	 */
	public function testAUserWithoutARoleIsRefused(): void {
		$this->trails->expects($this->never())->method('findAll');

		$response = $this->controller(uid: 'mallory')->history(appSlug: 'permits');

		self::assertSame(expected: 403, actual: $response->getStatus());
		self::assertSame(expected: 'buildiq.rbac.no_role', actual: $response->getData()['error']);
	}//end testAUserWithoutARoleIsRefused()

	/**
	 * Build the controller with the given user signed in; olaf owns the app.
	 *
	 * @param string $uid The signed-in user.
	 *
	 * @return ApplicationVersionsController
	 */
	private function controller(string $uid): ApplicationVersionsController {
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

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroups')->willReturn([]);
		$groups->method('isInGroup')->willReturn(false);

		return new ApplicationVersionsController(
			request: $this->createMock(IRequest::class),
			logger: $this->createMock(LoggerInterface::class),
			objectService: $objects,
			registerMapper: $registers,
			schemaMapper: $schemas,
			userSession: $session,
			groupManager: $groups,
			versionService: $this->createMock(ApplicationVersionService::class),
			auditTrailMapper: $this->trails,
		);
	}//end controller()
}//end class
