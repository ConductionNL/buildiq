<?php

/**
 * Unit tests for ManifestResolverService's version lookups: the production
 * manifest by UUID or inline, the application-level fallback, the PHP-side
 * slug fallback with its ownership guard, and resolveVersionForCaller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * Tests for the version lookups.
 *
 * @covers \OCA\Buildiq\Service\ManifestResolverService
 * @uses   \OCA\Buildiq\Service\PermissionResolver
 * @uses   \OCA\Buildiq\Service\ManifestWidgetRoleFilter
 * @uses   \OCA\Buildiq\Service\AppVisibilityResolver
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
final class ManifestResolverVersionLookupTest extends TestCase {

	private ObjectServiceInterface&MockObject $objectService;

	private LoggerInterface&MockObject $logger;

	private ManifestResolverService $service;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->logger        = $this->createMock(LoggerInterface::class);

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

		$this->service = new ManifestResolverService(
			objectService: $this->objectService,
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			logger: $this->logger,
			permissionResolver: new PermissionResolver($groupManager, $this->createMock(LoggerInterface::class)),
			widgetRoleFilter: new ManifestWidgetRoleFilter(visibility: new AppVisibilityResolver(), groupManager: $groupManager),
		);
	}//end setUp()

	/**
	 * A caller with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * Answer searchObjects by query: the application for a slug-only query,
	 * the direct version hit for slug + application, and the full list otherwise.
	 *
	 * @param array<string,mixed>            $application The application.
	 * @param array<int,array<string,mixed>> $direct      Rows for the slug + application query.
	 * @param array<int,array<string,mixed>> $all         Rows for the application-only query.
	 *
	 * @return void
	 */
	private function store(array $application, array $direct = [], array $all = []): void {
		$this->objectService->method('searchObjects')->willReturnCallback(
			static function (array $query) use ($application, $direct, $all): array {
				if (isset($query['application']) === false) {
					return [$application];
				}

				return (isset($query['slug']) === true) ? $direct : $all;
			}
		);
	}//end store()

	/**
	 * findApplication answers the application found by slug.
	 *
	 * @return void
	 */
	public function testFindApplicationAnswersTheRecordBySlug(): void {
		$this->store(['uuid' => 'app-1', 'slug' => 'crm']);

		self::assertSame(['uuid' => 'app-1', 'slug' => 'crm'], $this->service->findApplication('crm'));
	}//end testFindApplicationAnswersTheRecordBySlug()

	/**
	 * A production version referenced by UUID is fetched and its manifest returned.
	 *
	 * @return void
	 */
	public function testAProductionVersionByUuidIsFetched(): void {
		$this->store(['uuid' => 'app-1', 'productionVersion' => 'v-prod']);
		$entity = $this->createStub(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(['uuid' => 'v-prod', 'manifest' => ['pages' => ['p1']]]);
		$asked = [];
		$this->objectService->expects($this->once())->method('find')->willReturnCallback(
			static function (int|string $id, ?ObjectFindOptions $options = null) use (&$asked, $entity) {
				$_extend = $options?->extend ?? [];
				$files = $options?->files ?? false;
				$register = $options?->register;
				$schema = $options?->schema;
				$asked = [$id, $register, $schema];
				return $entity;
			}
		);

		self::assertSame(['pages' => ['p1']], $this->service->resolve('crm', null, null));
		self::assertSame(['v-prod', 'buildiq', 'applicationVersion'], $asked);
	}//end testAProductionVersionByUuidIsFetched()

	/**
	 * A production version that cannot be fetched falls back to the application's own manifest;
	 * the failure is logged at debug level.
	 *
	 * @return void
	 */
	public function testAnUnfetchableProductionVersionFallsBackToTheApplicationManifest(): void {
		$this->store(['uuid' => 'app-1', 'productionVersion' => 'v-gone', 'manifest' => ['pages' => ['legacy']]]);
		$this->objectService->method('find')->willThrowException(new RuntimeException('gone'));
		$this->logger->expects($this->exactly(2))->method('debug');

		self::assertSame(['pages' => ['legacy']], $this->service->resolve('crm', '', null));
	}//end testAnUnfetchableProductionVersionFallsBackToTheApplicationManifest()

	/**
	 * A missing production version, and an inline one with its manifest.
	 *
	 * @return void
	 */
	public function testAMissingOrInlineProductionVersion(): void {
		$this->store(['uuid' => 'app-1', 'productionVersion' => 'v-none']);
		$this->objectService->method('find')->willReturn(null);
		$this->logger->expects($this->once())->method('warning');

		self::assertNull($this->service->resolve('crm', null, null));

		$this->setUp();
		$this->store(['uuid' => 'app-1', 'productionVersion' => ['uuid' => 'v-1', 'manifest' => ['pages' => ['inline']]]]);
		$this->objectService->expects($this->never())->method('find');

		self::assertSame(['pages' => ['inline']], $this->service->resolve('crm', null, null));
	}//end testAMissingOrInlineProductionVersion()

	/**
	 * A direct hit owned by another application is not trusted: the PHP-side
	 * fallback finds the version whose relation (string or object) is this app's.
	 *
	 * @return void
	 */
	public function testTheSlugFallbackKeepsOnlyThisApplicationsVersion(): void {
		$application = ['uuid' => 'app-1', 'productionVersion' => 'v-prod', 'permissions' => ['owners' => ['user:alice'], 'editors' => []]];
		$this->store(
			$application,
			[['uuid' => 'v-foreign', 'slug' => 'beta', 'application' => 'app-2', 'manifest' => ['pages' => ['foreign']]]],
			[
				['uuid' => 'v-other', 'slug' => 'alpha', 'application' => 'app-1', 'manifest' => ['pages' => ['alpha']]],
				['uuid' => 'v-foreign', 'slug' => 'beta', 'application' => 'app-2', 'manifest' => ['pages' => ['foreign']]],
				['uuid' => 'v-foreign-2', 'slug' => 'beta', 'application' => ['uuid' => 'app-2'], 'manifest' => ['pages' => ['foreign']]],
				['uuid' => 'v-beta', 'slug' => 'beta', 'application' => ['uuid' => 'app-1'], 'manifest' => ['pages' => ['beta']]],
			]
		);

		self::assertSame(['pages' => ['beta']], $this->service->resolve('crm', 'beta', $this->user('alice')));
		self::assertNull($this->service->resolve('crm', 'gamma', $this->user('alice')));
	}//end testTheSlugFallbackKeepsOnlyThisApplicationsVersion()

	/**
	 * A failing fallback search is logged and reads as not found.
	 *
	 * @return void
	 */
	public function testAFailingFallbackSearchIsNotFound(): void {
		$this->objectService->method('searchObjects')->willReturnCallback(
			static function (array $query): array {
				if (isset($query['application']) === false) {
					return [['uuid' => 'app-1']];
				}

				if (isset($query['slug']) === true) {
					return [];
				}

				throw new RuntimeException('search down');
			}
		);
		$this->logger->expects($this->once())->method('error')
			->with('ManifestResolverService: findVersionBySlugFallback failed: {message}', ['message' => 'search down']);

		self::assertNull($this->service->resolve('crm', 'beta', $this->user('alice')));
	}//end testAFailingFallbackSearchIsNotFound()

	/**
	 * resolveVersionForCaller: no caller and an unknown app answer null; the
	 * production path answers both UUIDs, or null when one is missing.
	 *
	 * @return void
	 */
	public function testResolveVersionForCallerOnTheProductionPath(): void {
		self::assertNull($this->service->resolveVersionForCaller('crm', null, null));

		$this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls(
			[],
			[['uuid' => 'app-1', 'productionVersion' => ['id' => 'v-prod']]],
			[['uuid' => 'app-1']]
		);

		self::assertNull($this->service->resolveVersionForCaller('ghost', null, $this->user('alice')));
		self::assertSame(
			['applicationUuid' => 'app-1', 'versionUuid' => 'v-prod'],
			$this->service->resolveVersionForCaller('crm', '', $this->user('alice'))
		);
		self::assertNull($this->service->resolveVersionForCaller('crm', null, $this->user('alice')));
	}//end testResolveVersionForCallerOnTheProductionPath()

	/**
	 * resolveVersionForCaller on a named version: an editor gets both UUIDs,
	 * a stranger and an unknown version get null.
	 *
	 * @return void
	 */
	public function testResolveVersionForCallerOnANamedVersion(): void {
		$this->store(
			['id' => 'app-1', 'productionVersion' => 'v-prod', 'permissions' => ['owners' => [], 'editors' => ['user:alice']]],
			[['id' => 'v-beta', 'slug' => 'beta', 'application' => 'app-1']]
		);

		self::assertSame(
			['applicationUuid' => 'app-1', 'versionUuid' => 'v-beta'],
			$this->service->resolveVersionForCaller('crm', 'beta', $this->user('alice'))
		);
		self::assertNull($this->service->resolveVersionForCaller('crm', 'beta', $this->user('mallory')));
	}//end testResolveVersionForCallerOnANamedVersion()

	/**
	 * A named version that does not exist answers null.
	 *
	 * @return void
	 */
	public function testResolveVersionForCallerOnAnUnknownVersion(): void {
		$this->store(['uuid' => 'app-1', 'permissions' => ['owners' => ['user:alice']]]);

		self::assertNull($this->service->resolveVersionForCaller('crm', 'nope', $this->user('alice')));
	}//end testResolveVersionForCallerOnAnUnknownVersion()
}//end class
