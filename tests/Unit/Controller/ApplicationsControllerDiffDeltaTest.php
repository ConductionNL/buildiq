<?php

/**
 * ApplicationsController::diffVersions() diffs the manifest a version serves.
 *
 * A delta-mode version (baseRef + manifestDelta) is diffed as its manifest
 * merged over its base, which the manifest resolver computes; its baseRef and
 * manifestDelta are not in the response. A version the resolver has nothing
 * for keeps its stored blob.
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

use OCA\Buildiq\Controller\ApplicationsController;
use OCA\Buildiq\Service\AppChannelApplier;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\ManifestResolverService;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\RegisterStore;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Buildiq\Controller\ApplicationsController
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-4.2
 */
final class ApplicationsControllerDiffDeltaTest extends TestCase {

	private const DELTA_UUID = '11111111-1111-4111-8111-111111111111';

	private const BLOB_UUID = '22222222-2222-4222-8222-222222222222';

	/**
	 * An entity serialising to the payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = new class() extends ObjectEntity {
			/**
			 * The payload.
			 *
			 * @var array<string, mixed>
			 */
			public array $payload = [];

			/**
			 * The payload.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->payload;
			}//end jsonSerialize()
		};
		$entity->payload = $payload;
		return $entity;
	}//end entity()

	/**
	 * A delta-mode version is diffed as the resolver's merged manifest; a blob version keeps its blob.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-4.2
	 */
	public function testADeltaVersionIsDiffedAsItsMergedManifest(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('getUserGroups')->willReturn([]);

		$register = $this->createMock(originalClassName: Register::class);
		$register->method('getId')->willReturn(1);
		$registers = $this->createMock(originalClassName: RegisterMapper::class);
		$registers->method('find')->willReturn($register);
		$schema = $this->createMock(originalClassName: Schema::class);
		$schema->method('getId')->willReturn(2);
		$schemas = $this->createMock(originalClassName: SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$deltaVersion = [
			'id' => self::DELTA_UUID,
			'application' => 'app-uuid-1',
			'semver' => '0.2.0',
			'manifest' => [],
			'baseRef' => ['kind' => 'template', 'id' => 'permits'],
			'manifestDelta' => ['pages' => [['id' => 'list', 'title' => 'Permits']]],
		];
		$objects = [
			'app-uuid-1' => $this->entity(payload: [
				'id' => 'app-uuid-1',
				'slug' => 'crm',
				'permissions' => ['owners' => ['user:bob'], 'editors' => [], 'viewers' => []],
			]),
			self::DELTA_UUID => $this->entity(payload: $deltaVersion),
			self::BLOB_UUID => $this->entity(payload: [
				'id' => self::BLOB_UUID,
				'application' => 'app-uuid-1',
				'semver' => '0.1.0',
				'manifest' => ['pages' => [['id' => 'old']]],
			]),
		];
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturn([$this->entity(payload: ['slug' => 'crm', 'applicationUuid' => 'app-uuid-1'])]);
		$objectService->method('find')->willReturnCallback(static fn (string $id): ObjectEntity => $objects[$id]);

		$merged = ['pages' => [['id' => 'home', 'title' => 'Home'], ['id' => 'list', 'title' => 'Permits']]];
		$resolver = $this->createMock(originalClassName: ManifestResolverService::class);
		$resolver->method('servedManifest')->willReturnCallback(
			static function (array $version, ?IUser $caller) use ($merged, $user): ?array {
				self::assertSame(expected: $user, actual: $caller);
				if (isset($version['baseRef']) === false) {
					return null;
				}
				return $merged;
			}
		);

		$controller = new ApplicationsController(
			request: $this->createMock(originalClassName: IRequest::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			store: new RegisterStore(objectService: $objectService, registerMapper: $registers, schemaMapper: $schemas, auditTrailMapper: null),
			caller: new CallerContext(
				userSession: $session,
				groupManager: $groups,
				permissionResolver: new PermissionResolver(
					groupManager: $groups,
					logger: $this->createMock(originalClassName: LoggerInterface::class)
				)
			),
			manifestResolver: $resolver,
			channelApplier: $this->createMock(originalClassName: AppChannelApplier::class)
		);

		$response = $controller->diffVersions(slug: 'crm', from: self::DELTA_UUID, to: self::BLOB_UUID);

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$data = $response->getData();
		self::assertSame(expected: $merged, actual: $data['from']['manifest']);
		self::assertSame(expected: ['pages' => [['id' => 'old']]], actual: $data['to']['manifest']);
		self::assertStringNotContainsString(needle: 'manifestDelta', haystack: (string)json_encode(value: $data));
		self::assertStringNotContainsString(needle: 'baseRef', haystack: (string)json_encode(value: $data));
	}//end testADeltaVersionIsDiffedAsItsMergedManifest()
}//end class
