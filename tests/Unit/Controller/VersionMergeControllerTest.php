<?php

/**
 * Unit tests for the branch and merge endpoints of ApplicationVersionsController
 * (lifecycle-git-branches T02 / T05).
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-git-branches/tasks.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationVersionsController;
use OCA\Buildiq\Service\ApplicationVersionService;
use OCA\Buildiq\Service\Branch\VersionBranchService;
use OCA\Buildiq\Service\Branch\VersionMergeRunner;
use OCA\Buildiq\Service\Branch\VersionSchemaWriter;
use OCA\Buildiq\Service\Branch\VersionState;
use OCA\Buildiq\Service\RegisterStore;
use OCA\Buildiq\Service\VersionMergeService;
use OCA\Buildiq\Tests\Unit\Service\Branch\BranchFixture;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Preview lists conflicts and the target hash; apply writes the target once;
 * production, a moved target, an open conflict and a viewer are refused with
 * nothing written.
 *
 * @covers \OCA\Buildiq\Controller\ApplicationVersionsController
 * @covers \OCA\Buildiq\Service\Branch\VersionMergeRunner
 * @covers \OCA\Buildiq\Service\Branch\VersionSchemaWriter
 * @covers \OCA\Buildiq\Service\Branch\VersionState
 * @uses   \OCA\Buildiq\Service\Branch\VersionBranchRefused
 * @uses   \OCA\Buildiq\Service\Branch\VersionBranchService
 * @uses   \OCA\Buildiq\Service\Branch\VersionMergeRequest
 * @uses   \OCA\Buildiq\Service\VersionMergeService
 * @uses   \OCA\Buildiq\Service\ApplicationVersionService
 * @uses   \OCA\Buildiq\Service\RegisterStore
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 *
 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-merge-only-writes-a-draft-that-has-not-moved-req-bqgb-004
 */
final class VersionMergeControllerTest extends TestCase {

	/**
	 * The in-memory registers and schemas.
	 *
	 * @var BranchFixture
	 */
	private BranchFixture $fixture;

	/**
	 * The version rows of app `vergunningen`.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $versions = [];

	/**
	 * Every version saved.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Set up `development`, its branch `permit-fees` (which added the page `fees`) and `production`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fixture = new BranchFixture();
		$this->fixture->register(slug: 'buildiq', schemas: ['built-app' => [], 'applicationVersion' => []]);
		$this->fixture->register(slug: 'openbuild-vergunningen-development', schemas: ['vergunningen-development-permit' => ['fee' => ['type' => 'number']]]);
		$this->fixture->register(slug: 'openbuild-vergunningen-permit-fees', schemas: ['vergunningen-permit-fees-permit' => ['fee' => ['type' => 'number']]]);

		$permits = static fn (string $register, array $columns): array => ['id' => 'permits', 'config' => ['register' => $register, 'columns' => $columns]];
		$state   = new VersionState(
			registerMapper: $this->fixture->registerMapper(mapper: $this->createMock(RegisterMapper::class)),
			schemaMapper: $this->fixture->schemaMapper(mapper: $this->createMock(SchemaMapper::class))
		);
		// The base: development as it was when permit-fees started, read the way a branch reads it.
		$snapshot = $state->read(version: ['register' => 'openbuild-vergunningen-development', 'manifest' => ['version' => '1.0.0', 'pages' => [$permits('openbuild-vergunningen-development', ['name'])]]]);
		$base     = $snapshot['manifest'];
		$this->versions = [
			[
				'id' => 'v-dev',
				'slug' => 'development',
				'status' => 'draft',
				'application' => 'app-1',
				'register' => 'openbuild-vergunningen-development',
				'manifest' => ['version' => '1.0.0', 'pages' => [$permits('openbuild-vergunningen-development', ['name'])]],
			],
			[
				'id' => 'v-fees',
				'slug' => 'permit-fees',
				'status' => 'draft',
				'application' => 'app-1',
				'register' => 'openbuild-vergunningen-permit-fees',
				'manifest' => ['version' => '1.0.0', 'pages' => [$permits('openbuild-vergunningen-permit-fees', ['name']), ['id' => 'fees', 'config' => []]]],
				'branchBase' => ['source' => 'development', 'manifestHash' => str_repeat('0', 64), 'manifest' => $base, 'schemas' => $snapshot['schemas']],
			],
			['id' => 'v-prod', 'slug' => 'production', 'status' => 'published', 'application' => 'app-1', 'register' => 'openbuild-vergunningen-production', 'manifest' => $base],
		];
	}//end setUp()

	/**
	 * The controller for a signed-in user, with the request carrying $params.
	 *
	 * @param string              $uid    The caller.
	 * @param array<string,mixed> $params The request parameters.
	 *
	 * @return ApplicationVersionsController
	 */
	private function controller(string $uid, array $params): ApplicationVersionsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroups')->willReturn([]);

		$registers = $this->fixture->registerMapper(mapper: $this->createMock(RegisterMapper::class));
		$schemas   = $this->fixture->schemaMapper(mapper: $this->createMock(SchemaMapper::class));
		$objects   = $this->objects();
		$versions  = BranchFixture::versions(mock: fn (string $class): object => $this->createMock($class));
		$state     = new VersionState(registerMapper: $registers, schemaMapper: $schemas);
		$writer    = new VersionSchemaWriter(registerMapper: $registers, schemaMapper: $schemas, state: $state);
		$branches  = new VersionBranchService(
			store: new RegisterStore(objectService: $objects, registerMapper: $registers, schemaMapper: $schemas),
			schemas: $writer,
			state: $state,
			versions: $versions
		);

		return new ApplicationVersionsController(
			request: $request,
			logger: new NullLogger(),
			objectService: $objects,
			registerMapper: $registers,
			schemaMapper: $schemas,
			userSession: $session,
			groupManager: $groups,
			versionService: $versions,
			merges: new VersionMergeRunner(merger: new VersionMergeService(), schemas: $writer, branches: $branches, objects: $objects)
		);
	}//end controller()

	/**
	 * An object service answering the application and its versions, recording saves.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objects(): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			function (array $query): array {
				if (isset($query['slug']) === true && $query['slug'] === 'vergunningen') {
					return [[
						'id' => 'app-1',
						'slug' => 'vergunningen',
						'productionVersion' => 'v-prod',
						'permissions' => ['owners' => ['user:olga'], 'viewers' => ['user:vic']],
					]];
				}

				return $this->versions;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->saved[] = $object;
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object);
				return $entity;
			}
		);

		return $objects;
	}//end objects()

	/**
	 * The target hash a preview of permit-fees into development returns.
	 *
	 * @return string
	 */
	private function previewHash(): string {
		return (string)$this->controller(uid: 'olga', params: ['preview' => true])->merge(appSlug: 'vergunningen', source: 'permit-fees', target: 'development')->getData()['targetHash'];
	}//end previewHash()

	/**
	 * A branch that only added a page previews with no conflicts and writes nothing.
	 *
	 * @return void
	 */
	public function testAPreviewOfACleanBranchListsNoConflicts(): void {
		$response = $this->controller(uid: 'olga', params: ['preview' => true])->merge(appSlug: 'vergunningen', source: 'permit-fees', target: 'development');

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame([], $data['conflicts']);
		$this->assertSame([], $data['breaking']);
		$this->assertSame(['permits', 'fees'], array_column($data['manifest']['pages'], 'id'));
		$this->assertSame(BranchFixture::versions(mock: fn (string $class): object => $this->createMock($class))->hashManifest(manifest: $this->versions[0]['manifest']), $data['targetHash']);
		$this->assertSame([], $this->saved);
	}//end testAPreviewOfACleanBranchListsNoConflicts()

	/**
	 * Applying writes development once: the page fees, its own register kept, the semver moved and the base moved.
	 *
	 * @return void
	 */
	public function testApplyWritesTheTargetOnce(): void {
		$hash     = $this->previewHash();
		$response = $this->controller(uid: 'olga', params: ['targetHash' => $hash, 'choices' => []])->merge(appSlug: 'vergunningen', source: 'permit-fees', target: 'development');

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $this->saved);
		$saved = $this->saved[0];
		$this->assertSame('v-dev', $saved['id']);
		$this->assertSame(['permits', 'fees'], array_column($saved['manifest']['pages'], 'id'));
		$this->assertSame('openbuild-vergunningen-development', $saved['manifest']['pages'][0]['config']['register']);
		$this->assertSame('permit-fees', $saved['branchBase']['source']);
		$this->assertSame('0.1.1', $saved['semver']);
	}//end testApplyWritesTheTargetOnce()

	/**
	 * Production cannot take a merge; nothing is written.
	 *
	 * @return void
	 */
	public function testProductionIsRefused(): void {
		$this->versions[2]['branchBase'] = $this->versions[1]['branchBase'];
		$response = $this->controller(uid: 'olga', params: ['preview' => true])->merge(appSlug: 'vergunningen', source: 'permit-fees', target: 'production');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('target_is_production', $response->getData()['error']);
		$this->assertStringContainsString('release and promotion only', $response->getData()['detail']);
		$this->assertSame([], $this->saved);
	}//end testProductionIsRefused()

	/**
	 * A target saved after the preview is refused with 409 and nothing is written.
	 *
	 * @return void
	 */
	public function testAStaleTargetHashIs409(): void {
		$hash = $this->previewHash();
		$this->versions[0]['manifest']['title'] = 'Saved by a colleague';
		$response = $this->controller(uid: 'olga', params: ['targetHash' => $hash])->merge(appSlug: 'vergunningen', source: 'permit-fees', target: 'development');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('target_changed', $response->getData()['error']);
		$this->assertSame([], $this->saved);
	}//end testAStaleTargetHashIs409()

	/**
	 * Both sides changed the page permits: the preview lists it, an apply without a side is 409.
	 *
	 * @return void
	 */
	public function testAConflictNeedsASideBeforeAnythingIsWritten(): void {
		$this->versions[0]['manifest']['pages'][0]['config']['columns'] = ['name', 'status'];
		$this->versions[1]['manifest']['pages'][0]['config']['columns'] = ['name', 'fee'];
		$preview = $this->controller(uid: 'olga', params: ['preview' => true])->merge(appSlug: 'vergunningen', source: 'permit-fees', target: 'development')->getData();
		$this->assertSame(['page:permits'], array_column($preview['conflicts'], 'unit'));

		$open = $this->controller(uid: 'olga', params: ['targetHash' => $preview['targetHash']])->merge(appSlug: 'vergunningen', source: 'permit-fees', target: 'development');
		$this->assertSame(409, $open->getStatus());
		$this->assertSame('unresolved_conflicts', $open->getData()['error']);
		$this->assertSame([], $this->saved);

		$chosen = $this->controller(uid: 'olga', params: ['targetHash' => $preview['targetHash'], 'choices' => ['page:permits' => 'source']])
			->merge(appSlug: 'vergunningen', source: 'permit-fees', target: 'development');
		$this->assertSame(200, $chosen->getStatus());
		$this->assertSame(['name', 'fee'], $this->saved[0]['manifest']['pages'][0]['config']['columns']);
	}//end testAConflictNeedsASideBeforeAnythingIsWritten()

	/**
	 * A removed schema field is a breaking change that needs acknowledgeBreaking.
	 *
	 * @return void
	 */
	public function testABreakingSchemaChangeNeedsConfirmation(): void {
		$branchSchema = array_values(array_filter($this->fixture->schemas, static fn ($s): bool => $s->getSlug() === 'vergunningen-permit-fees-permit'))[0];
		$branchSchema->setProperties([]);
		$hash = $this->previewHash();

		$refused = $this->controller(uid: 'olga', params: ['targetHash' => $hash])->merge(appSlug: 'vergunningen', source: 'permit-fees', target: 'development');
		$this->assertSame(409, $refused->getStatus());
		$this->assertSame('breaking_change', $refused->getData()['error']);
		$this->assertStringContainsString('"permit.fee" is removed', $refused->getData()['detail']);

		$confirmed = $this->controller(uid: 'olga', params: ['targetHash' => $hash, 'acknowledgeBreaking' => true])->merge(appSlug: 'vergunningen', source: 'permit-fees', target: 'development');
		$this->assertSame(200, $confirmed->getStatus());
		$devSchema = array_values(array_filter($this->fixture->schemas, static fn ($s): bool => $s->getSlug() === 'vergunningen-development-permit'))[0];
		$this->assertSame([], $devSchema->getProperties());
	}//end testABreakingSchemaChangeNeedsConfirmation()

	/**
	 * A viewer may neither branch nor merge.
	 *
	 * @return void
	 */
	public function testAViewerIsForbidden(): void {
		$merge  = $this->controller(uid: 'vic', params: ['preview' => true])->merge(appSlug: 'vergunningen', source: 'permit-fees', target: 'development');
		$branch = $this->controller(uid: 'vic', params: ['name' => 'more-fees'])->branch(appSlug: 'vergunningen', versionSlug: 'development');

		$this->assertSame(403, $merge->getStatus());
		$this->assertSame(403, $branch->getStatus());
		$this->assertSame([], $this->saved);
		$this->assertArrayNotHasKey('openbuild-vergunningen-more-fees', $this->fixture->registers);
	}//end testAViewerIsForbidden()

	/**
	 * An owner starts a branch; a name another version has is 409.
	 *
	 * @return void
	 */
	public function testAnOwnerStartsABranchAndATakenNameIs409(): void {
		$created = $this->controller(uid: 'olga', params: ['name' => 'more-fees'])->branch(appSlug: 'vergunningen', versionSlug: 'development');
		$this->assertSame(201, $created->getStatus());
		$this->assertSame('more-fees', $created->getData()['slug']);
		$this->assertSame('development', $created->getData()['branchBase']['source']);

		$taken = $this->controller(uid: 'olga', params: ['name' => 'permit-fees'])->branch(appSlug: 'vergunningen', versionSlug: 'development');
		$this->assertSame(409, $taken->getStatus());
		$this->assertSame('name_taken', $taken->getData()['error']);
	}//end testAnOwnerStartsABranchAndATakenNameIs409()
}//end class
