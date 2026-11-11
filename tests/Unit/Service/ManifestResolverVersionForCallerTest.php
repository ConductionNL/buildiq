<?php

/**
 * Golden tests for ManifestResolverService::resolveVersionForCaller().
 *
 * The health endpoint asks which Application and version a slug pair names,
 * for this caller. It must answer exactly what resolve() would let the caller
 * open: nothing for an anonymous caller, an unknown app or an unknown version,
 * the production version for anyone, and a non-production version only for an
 * owner or editor. These pin every one of those answers.
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
 * Allowed and refused outcomes of resolveVersionForCaller().
 */
class ManifestResolverVersionForCallerTest extends TestCase {

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
	 * An anonymous caller is refused before any lookup.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsRefused(): void {
		$this->objectService->expects(self::never())->method('searchObjects');

		self::assertNull($this->service->resolveVersionForCaller(appSlug: 'hello-world', versionSlug: 'staging', caller: null));
	}//end testAnonymousCallerIsRefused()

	/**
	 * An unknown app is refused.
	 *
	 * @return void
	 */
	public function testUnknownAppIsRefused(): void {
		$this->objectService->method('searchObjects')->willReturn([]);

		self::assertNull($this->service->resolveVersionForCaller(appSlug: 'nope', versionSlug: null, caller: $this->caller(uid: 'carol')));
	}//end testUnknownAppIsRefused()

	/**
	 * Without a version slug, anyone gets the production version.
	 *
	 * @return array<string, array{0: string|null}>
	 */
	public static function productionSlugs(): array {
		return ['null' => [null], 'empty' => ['']];
	}//end productionSlugs()

	/**
	 * The production version is named for any signed-in caller.
	 *
	 * @param string|null $slug The version slug.
	 *
	 * @return void
	 */
	#[DataProvider('productionSlugs')]
	public function testProductionIsNamedForAnyCaller(?string $slug): void {
		$this->objectService->method('searchObjects')->willReturn([$this->application()]);

		$result = $this->service->resolveVersionForCaller(appSlug: 'hello-world', versionSlug: $slug, caller: $this->caller(uid: 'carol'));

		self::assertSame(['applicationUuid' => 'app-uuid', 'versionUuid' => 'prod-uuid'], $result);
	}//end testProductionIsNamedForAnyCaller()

	/**
	 * An app without a production version has nothing to name.
	 *
	 * @return void
	 */
	public function testAppWithoutProductionIsRefused(): void {
		$application = $this->application();
		unset($application['productionVersion']);
		$this->objectService->method('searchObjects')->willReturn([$application]);

		self::assertNull($this->service->resolveVersionForCaller(appSlug: 'hello-world', versionSlug: null, caller: $this->caller(uid: 'alice')));
	}//end testAppWithoutProductionIsRefused()

	/**
	 * Callers and whether they may open the staging version.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function stagingCallers(): array {
		return ['owner' => ['alice', true], 'editor' => ['ed', true], 'viewer' => ['vera', false], 'stranger' => ['carol', false]];
	}//end stagingCallers()

	/**
	 * A non-production version is named only for an owner or editor.
	 *
	 * @param string $uid     The caller.
	 * @param bool   $allowed Whether the version is named.
	 *
	 * @return void
	 */
	#[DataProvider('stagingCallers')]
	public function testStagingIsNamedOnlyForWriters(string $uid, bool $allowed): void {
		$this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls([$this->application()], [$this->staging()]);

		$result = $this->service->resolveVersionForCaller(appSlug: 'hello-world', versionSlug: 'staging', caller: $this->caller(uid: $uid));

		self::assertSame($allowed ? ['applicationUuid' => 'app-uuid', 'versionUuid' => 'staging-uuid'] : null, $result);
	}//end testStagingIsNamedOnlyForWriters()

	/**
	 * An unknown version is refused.
	 *
	 * @return void
	 */
	public function testUnknownVersionIsRefused(): void {
		$this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls([$this->application()], [], [], []);

		self::assertNull($this->service->resolveVersionForCaller(appSlug: 'hello-world', versionSlug: 'ghost', caller: $this->caller(uid: 'alice')));
	}//end testUnknownVersionIsRefused()

	/**
	 * The Application: alice owns it, ed edits it, vera views it.
	 *
	 * @return array<string, mixed>
	 */
	private function application(): array {
		return [
			'uuid' => 'app-uuid',
			'slug' => 'hello-world',
			'productionVersion' => 'prod-uuid',
			'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:ed'], 'viewers' => ['user:vera']],
		];
	}//end application()

	/**
	 * The staging version.
	 *
	 * @return array<string, mixed>
	 */
	private function staging(): array {
		return ['uuid' => 'staging-uuid', 'slug' => 'staging', 'application' => 'app-uuid', 'manifest' => ['pages' => []]];
	}//end staging()

	/**
	 * A signed-in caller.
	 *
	 * @param string $uid The UID.
	 *
	 * @return IUser&MockObject
	 */
	private function caller(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end caller()
}//end class
