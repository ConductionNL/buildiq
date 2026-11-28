<?php

/**
 * The server scenarios of REQ-BQCV-027 against ApplicationVersionsController::release.
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
 * @spec openspec/changes/capability-requirements-backfill/specs/version-lifecycle-ui/spec.md#requirement-a-change-goes-live-only-when-an-owner-releases-it-req-bqcv-027
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationVersionsController;
use OCA\Buildiq\Service\ApplicationVersionService;
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

/**
 * An app owned by olivia and edited by eddie, with production 1.2.0 and draft 1.3.0.
 * Only olivia's release reaches the version service; eddie's leaves 1.2.0 in production.
 */
final class ApplicationVersionReleaseScenariosTest extends TestCase {

	/**
	 * The version service whose releaseVersion() moves the production pointer.
	 *
	 * @var ApplicationVersionService&MockObject
	 */
	private ApplicationVersionService&MockObject $versionService;

	/**
	 * Set up the version service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->versionService = $this->createMock(originalClassName: ApplicationVersionService::class);
	}//end setUp()

	/**
	 * An editor calling the release endpoint for 1.3.0 is refused, and 1.2.0 stays in production.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/version-lifecycle-ui/spec.md#requirement-a-change-goes-live-only-when-an-owner-releases-it-req-bqcv-027
	 */
	public function testAReleaseBySomeoneElseIsRefused(): void {
		$this->versionService->expects(self::never())->method('releaseVersion');

		$response = $this->controllerFor(uid: 'eddie')->release(appSlug: 'permits', versionSlug: 'draft-130');

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		self::assertSame(expected: 'buildiq.rbac.not_owner', actual: $response->getData()['error']);
	}//end testAReleaseBySomeoneElseIsRefused()

	/**
	 * The owner releases 1.3.0, and it becomes the production version.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/version-lifecycle-ui/spec.md#requirement-a-change-goes-live-only-when-an-owner-releases-it-req-bqcv-027
	 */
	public function testTheOwnerReleasesTheDraft(): void {
		$this->versionService->expects(self::once())
			->method('releaseVersion')
			->with(self::identicalTo(value: 'app-uuid'), self::identicalTo(value: 'v130'))
			->willReturn(['productionVersion' => 'v130', 'published' => 'v130', 'archived' => 'v120']);

		$response = $this->controllerFor(uid: 'olivia')->release(appSlug: 'permits', versionSlug: 'draft-130');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: 'v130', actual: $response->getData()['productionVersion']);
		self::assertSame(expected: 'v120', actual: $response->getData()['archived']);
	}//end testTheOwnerReleasesTheDraft()

	/**
	 * The controller as this user sees it, over the app and its two versions.
	 *
	 * @param string $uid The signed-in user.
	 *
	 * @return ApplicationVersionsController
	 */
	private function controllerFor(string $uid): ApplicationVersionsController {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('getUserGroups')->willReturn([]);

		$app = [
			'id' => 'app-uuid',
			'slug' => 'permits',
			'productionVersion' => 'v120',
			'permissions' => ['owners' => ['user:olivia'], 'editors' => ['user:eddie']],
		];
		$versions = [
			['id' => 'v120', 'slug' => 'production', 'application' => 'app-uuid', 'semver' => '1.2.0', 'status' => 'published'],
			['id' => 'v130', 'slug' => 'draft-130', 'application' => 'app-uuid', 'semver' => '1.3.0', 'status' => 'draft'],
		];
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			static fn (array $query): array => self::rowsFor(query: $query, app: $app, versions: $versions)
		);

		return new ApplicationVersionsController(
			request: $this->createMock(originalClassName: IRequest::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			objectService: $objects,
			registerMapper: $this->createMock(originalClassName: RegisterMapper::class),
			schemaMapper: $this->createMock(originalClassName: SchemaMapper::class),
			userSession: $session,
			groupManager: $groups,
			versionService: $this->versionService,
			auditTrailMapper: null,
		);
	}//end controllerFor()

	/**
	 * What a search answers: the app for a slug lookup, else the versions.
	 *
	 * @param array<string, mixed>             $query    The search query.
	 * @param array<string, mixed>             $app      The app.
	 * @param array<int, array<string, mixed>> $versions The versions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function rowsFor(array $query, array $app, array $versions): array {
		if (isset($query['slug']) === true) {
			return [$app];
		}

		return $versions;
	}//end rowsFor()
}//end class
