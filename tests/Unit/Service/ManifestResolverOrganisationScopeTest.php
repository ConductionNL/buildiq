<?php

/**
 * Unit tests for the organisation scope at the manifest endpoint and the runtime.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppOrganisationGuard;
use OCA\Buildiq\Service\AppVisibilityResolver;
use OCA\Buildiq\Service\ManifestResolverService;
use OCA\Buildiq\Service\ManifestWidgetRoleFilter;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A scoped app answers as an unknown slug to anyone outside its organisation.
 *
 * @covers \OCA\Buildiq\Service\ManifestResolverService
 * @uses   \OCA\Buildiq\Service\AppOrganisationGuard
 * @uses   \OCA\Buildiq\Service\AppVisibilityResolver
 * @uses   \OCA\Buildiq\Service\ManifestWidgetRoleFilter
 * @uses   \OCA\Buildiq\Service\PermissionResolver
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
 */
class ManifestResolverOrganisationScopeTest extends TestCase {

	private const MANIFEST = ['version' => '1.0.0', 'pages' => []];

	/**
	 * Another organisation's scoped app: the manifest endpoint and the runtime find nothing.
	 *
	 * @return void
	 */
	public function testAScopedAppIsNotFoundForANonMember(): void {
		$guard = $this->createMock(AppOrganisationGuard::class);
		$guard->method('canSee')->willReturn(false);
		$service = $this->service(app: $this->app(scope: 'holder'), guard: $guard);

		$this->assertNull($service->resolve(appSlug: 'toezicht', versionSlug: null, caller: $this->user()));
		$this->assertNull($service->resolveVersionForCaller(appSlug: 'toezicht', versionSlug: null, caller: $this->user()));
	}//end testAScopedAppIsNotFoundForANonMember()

	/**
	 * A member of the holder gets the manifest; the guard is asked about that caller.
	 *
	 * @return void
	 */
	public function testAScopedAppOpensForAMember(): void {
		$caller = $this->user();
		$app = $this->app(scope: 'holder');
		$guard = $this->createMock(AppOrganisationGuard::class);
		$guard->expects($this->once())->method('canSee')->with($app, $caller)->willReturn(true);

		$this->assertSame(self::MANIFEST, $this->service(app: $app, guard: $guard)->resolve(appSlug: 'toezicht', versionSlug: null, caller: $caller));
	}//end testAScopedAppOpensForAMember()

	/**
	 * Without a guard a scoped app is hidden (fail closed) and a shared one still opens.
	 *
	 * @return void
	 */
	public function testWithoutAGuardOnlySharedAppsOpen(): void {
		$this->assertNull($this->service(app: $this->app(scope: 'holder'), guard: null)->resolve(appSlug: 'toezicht', versionSlug: null, caller: $this->user()));
		$this->assertSame(self::MANIFEST, $this->service(app: $this->app(scope: null), guard: null)->resolve(appSlug: 'toezicht', versionSlug: null, caller: null));
	}//end testWithoutAGuardOnlySharedAppsOpen()

	/**
	 * A shared app never asks the guard.
	 *
	 * @return void
	 */
	public function testASharedAppDoesNotAskTheGuard(): void {
		$guard = $this->createMock(AppOrganisationGuard::class);
		$guard->expects($this->never())->method('canSee');

		$this->assertSame(self::MANIFEST, $this->service(app: $this->app(scope: 'all'), guard: $guard)->resolve(appSlug: 'toezicht', versionSlug: null, caller: null));
	}//end testASharedAppDoesNotAskTheGuard()

	/**
	 * The `toezicht` app with its manifest and no production version.
	 *
	 * @param string|null $scope The organisation scope, null for none.
	 *
	 * @return array<string, mixed> The Application.
	 */
	private function app(?string $scope): array {
		$app = ['slug' => 'toezicht', 'manifest' => self::MANIFEST, 'productionVersion' => null, '@self' => ['organisation' => 'org-noord']];
		if ($scope !== null) {
			$app['organisationScope'] = $scope;
		}

		return $app;
	}//end app()

	/**
	 * A signed-in user.
	 *
	 * @return IUser The user.
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('zuid-user');
		return $user;
	}//end user()

	/**
	 * The resolver over an object service that finds the given app.
	 *
	 * @param array<string, mixed>      $app   The Application the slug resolves to.
	 * @param AppOrganisationGuard|null $guard The guard.
	 *
	 * @return ManifestResolverService The resolver.
	 */
	private function service(array $app, ?AppOrganisationGuard $guard): ManifestResolverService {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturn([$app]);

		$register = $this->getMockBuilder(Register::class)->onlyMethods(['getId'])->getMock();
		$register->method('getId')->willReturn(1);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$schema = $this->getMockBuilder(Schema::class)->onlyMethods(['getId'])->getMock();
		$schema->method('getId')->willReturn(2);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn([]);

		return new ManifestResolverService(
			objectService: $objectService,
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			logger: $this->createMock(LoggerInterface::class),
			permissionResolver: new PermissionResolver($groupManager, $this->createMock(LoggerInterface::class)),
			widgetRoleFilter: new ManifestWidgetRoleFilter(visibility: new AppVisibilityResolver(), groupManager: $groupManager),
			organisationGuard: $guard,
		);
	}//end service()
}//end class
