<?php

/**
 * Golden tests for ManifestResolverService's owner/editor write-role check.
 *
 * The same "owner or editor of this Application" check decides two things:
 * whether a caller may open a non-production version (resolve()), and whether
 * a caller sees the manifest unfiltered (filterManifestForCaller()). These pin
 * its allowed and refused outcomes on both paths, including a role granted
 * through a group and a malformed permissions block.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppVisibilityResolver;
use OCA\Buildiq\Service\ManifestResolverService;
use OCA\Buildiq\Service\ManifestWidgetRoleFilter;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Allowed and refused outcomes of the write-role check.
 */
class ManifestResolverWriteRoleTest extends TestCase {

	/**
	 * Object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Service under test.
	 *
	 * @var ManifestResolverService
	 */
	private ManifestResolverService $service;

	/**
	 * Build the service; alice is a member of the `devs` group.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$register = $this->getMockBuilder(Register::class)->onlyMethods(['getId'])->getMock();
		$register->method('getId')->willReturn(1);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$schema = $this->getMockBuilder(Schema::class)->onlyMethods(['getId'])->getMock();
		$schema->method('getId')->willReturn(2);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);
		$groupManager = $this->createMock(IGroupManager::class);
		$devs = $this->createMock(IGroup::class);
		$devs->method('getGID')->willReturn('devs');
		$groupManager->method('getUserGroups')->willReturn([$devs]);
		$groupManager->method('isAdmin')->willReturn(false);
		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new ManifestResolverService(
			objectService: $this->objectService,
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			logger: $logger,
			permissionResolver: new PermissionResolver($groupManager, $logger),
			widgetRoleFilter: new ManifestWidgetRoleFilter(visibility: new AppVisibilityResolver(), groupManager: $groupManager),
		);
	}//end setUp()

	/**
	 * Permission blocks and whether they let alice in.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function grants(): array {
		return [
			'owner'               => [['owners' => ['user:alice'], 'editors' => []], true],
			'editor'              => [['owners' => [], 'editors' => ['user:alice']], true],
			'editor via group'    => [['owners' => [], 'editors' => ['group:devs']], true],
			'viewer only'         => [['owners' => [], 'editors' => [], 'viewers' => ['user:alice']], false],
			'someone else'        => [['owners' => ['user:bob'], 'editors' => []], false],
			'malformed block'     => ['owners', false],
			'no block'            => [null, false],
		];
	}//end grants()

	/**
	 * A non-production version opens only for an owner or editor.
	 *
	 * @param mixed $permissions The Application's permissions block.
	 * @param bool  $allowed     Whether alice may open the version.
	 *
	 * @return void
	 */
	#[DataProvider('grants')]
	public function testNonProductionVersionAccess(mixed $permissions, bool $allowed): void {
		$this->wire(permissions: $permissions);

		$result = $this->service->resolve(appSlug: 'hello-world', versionSlug: 'staging', caller: $this->alice());

		self::assertSame($allowed ? $this->manifest() : null, $result);
	}//end testNonProductionVersionAccess()

	/**
	 * An anonymous caller never opens a non-production version.
	 *
	 * @return void
	 */
	public function testAnonymousCallerCannotOpenANonProductionVersion(): void {
		$this->wire(permissions: ['owners' => ['user:alice'], 'editors' => []]);

		self::assertNull($this->service->resolve(appSlug: 'hello-world', versionSlug: 'staging', caller: null));
	}//end testAnonymousCallerCannotOpenANonProductionVersion()

	/**
	 * Only an owner or editor sees a gated menu item unfiltered.
	 *
	 * @param mixed $permissions The Application's permissions block.
	 * @param bool  $allowed     Whether alice bypasses the filter.
	 *
	 * @return void
	 */
	#[DataProvider('grants')]
	public function testFilterBypass(mixed $permissions, bool $allowed): void {
		$application = ['uuid' => 'app-uuid'];
		if ($permissions !== null) {
			$application['permissions'] = $permissions;
		}

		$manifest = ['menu' => [['id' => 'ops', 'permission' => 'group:ops'], ['id' => 'home']]];
		$filtered = $this->service->filterManifestForCaller(manifest: $manifest, application: $application, caller: $this->alice());

		self::assertSame($allowed ? ['ops', 'home'] : ['home'], array_column($filtered['menu'], 'id'));
	}//end testFilterBypass()

	/**
	 * The staging version's manifest.
	 *
	 * @return array<string, mixed>
	 */
	private function manifest(): array {
		return ['version' => '1.0.0', 'pages' => ['staging-home']];
	}//end manifest()

	/**
	 * Alice, the caller.
	 *
	 * @return IUser&MockObject
	 */
	private function alice(): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		return $user;
	}//end alice()

	/**
	 * Wire the Application lookup and its staging version.
	 *
	 * @param mixed $permissions The permissions block, or null for none.
	 *
	 * @return void
	 */
	private function wire(mixed $permissions): void {
		$application = ['uuid' => 'app-uuid', 'slug' => 'hello-world', 'productionVersion' => 'prod-uuid'];
		if ($permissions !== null) {
			$application['permissions'] = $permissions;
		}

		$version = ['uuid' => 'staging-uuid', 'slug' => 'staging', 'application' => 'app-uuid', 'manifest' => $this->manifest()];
		$this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls([$application], [$version]);
	}//end wire()
}//end class
