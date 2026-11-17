<?php

/**
 * Unit tests for VersionMergeRunner: preview a version merge, then write it once.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Branch
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

namespace OCA\Buildiq\Tests\Unit\Service\Branch;

use OCA\Buildiq\Kernel\Contracts\ApplicationVersionServiceInterface;
use OCA\Buildiq\Service\ApplicationVersionService;
use OCA\Buildiq\Service\Branch\VersionBranchRefused;
use OCA\Buildiq\Service\Branch\VersionBranchService;
use OCA\Buildiq\Service\Branch\VersionMergeRunner;
use OCA\Buildiq\Service\Branch\VersionSchemaWriter;
use OCA\Buildiq\Service\Branch\VersionState;
use OCA\Buildiq\Service\RegisterStore;
use OCA\Buildiq\Service\VersionMergeService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * A merge only writes a draft that is not production, has a common base with
 * the source, has not moved since the preview, has every conflict decided and,
 * when the merge breaks a schema, has that confirmed.
 *
 * @covers \OCA\Buildiq\Service\Branch\VersionMergeRunner
 * @uses   \OCA\Buildiq\Service\Branch\VersionMergeRequest
 * @uses   \OCA\Buildiq\Service\Branch\VersionBranchRefused
 * @uses   \OCA\Buildiq\Service\Branch\VersionBranchService
 * @uses   \OCA\Buildiq\Service\Branch\VersionSchemaWriter
 * @uses   \OCA\Buildiq\Service\Branch\VersionState
 * @uses   \OCA\Buildiq\Service\VersionMergeService
 * @uses   \OCA\Buildiq\Service\VersionSchemaCarrier
 * @uses   \OCA\Buildiq\Service\ApplicationVersionService
 * @uses   \OCA\Buildiq\Service\RegisterStore
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 *
 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-merge-only-writes-a-draft-that-has-not-moved-req-bqgb-004
 */
final class VersionMergeRunnerTest extends TestCase {

	/**
	 * The application: `shop`, with `v-prod` in production.
	 *
	 * @var array<string,mixed>
	 */
	private const APPLICATION = ['id' => 'app-1', 'slug' => 'shop', 'productionVersion' => 'v-prod'];

	/**
	 * The in-memory registers and schemas.
	 *
	 * @var BranchFixture
	 */
	private BranchFixture $fixture;

	/**
	 * Every object saved, with its options.
	 *
	 * @var list<array{object: array<string,mixed>, options: ObjectSaveOptions|null}>
	 */
	private array $saved = [];

	/**
	 * Set up `development` (target) and its branch `permit-fees` (source), each with its own `permit` schema.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fixture = new BranchFixture();
		$this->fixture->register(slug: 'openbuild-shop-development', schemas: ['shop-development-permit' => ['fee' => ['type' => 'number']]]);
		$this->fixture->register(
			slug: 'openbuild-shop-permit-fees',
			schemas: ['shop-permit-fees-permit' => ['fee' => ['type' => 'number'], 'reason' => ['type' => 'string']]]
		);
	}//end setUp()

	/**
	 * The `development` version: the merge target.
	 *
	 * @return array<string,mixed>
	 */
	private static function development(): array {
		return [
			'id' => 'v-dev',
			'slug' => 'development',
			'register' => 'openbuild-shop-development',
			'status' => 'draft',
			'semver' => '1.0.0',
			'manifest' => [
				'title' => 'Shop',
				'pages' => [['id' => 'permits', 'config' => ['register' => 'openbuild-shop-development', 'schema' => 'shop-development-permit']]],
			],
		];
	}//end development()

	/**
	 * The `permit-fees` branch of `development`: the merge source, with a new page and a new field.
	 *
	 * @param array<string,mixed> $baseSchemas The neutral schemas recorded as the branch's base.
	 *
	 * @return array<string,mixed>
	 */
	private static function branch(array $baseSchemas = ['permit' => ['title' => 'Permit', 'required' => [], 'properties' => ['fee' => ['type' => 'number']]]]): array {
		return [
			'id' => 'v-fees',
			'slug' => 'permit-fees',
			'register' => 'openbuild-shop-permit-fees',
			'status' => 'draft',
			'manifest' => [
				'title' => 'Shop',
				'pages' => [
					['id' => 'permits', 'config' => ['register' => 'openbuild-shop-permit-fees', 'schema' => 'shop-permit-fees-permit']],
					['id' => 'fees', 'config' => ['register' => 'openbuild-shop-permit-fees', 'schema' => 'shop-permit-fees-permit']],
				],
			],
			'branchBase' => [
				'source' => 'development',
				'manifestHash' => 'irrelevant',
				'manifest' => ['title' => 'Shop', 'pages' => [['id' => 'permits', 'config' => ['register' => '@version', 'schema' => '@schema:permit']]]],
				'schemas' => $baseSchemas,
			],
		];
	}//end branch()

	/**
	 * The real version service over mocks.
	 *
	 * @return ApplicationVersionService
	 */
	private function versions(): ApplicationVersionService {
		return BranchFixture::versions(mock: fn (string $class): object => $this->createMock($class));
	}//end versions()

	/**
	 * The runner over the fixture, recording every saved object.
	 *
	 * @return VersionMergeRunner
	 */
	private function runner(): VersionMergeRunner {
		$registers = $this->fixture->registerMapper(mapper: $this->createMock(RegisterMapper::class));
		$schemas   = $this->fixture->schemaMapper(mapper: $this->createMock(SchemaMapper::class));
		$state     = new VersionState(registerMapper: $registers, schemaMapper: $schemas);
		$writer    = new VersionSchemaWriter(registerMapper: $registers, schemaMapper: $schemas, state: $state);
		$objects   = $this->createMock(ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?ObjectSaveOptions $options = null): ObjectEntityInterface {
				$this->saved[] = ['object' => $object, 'options' => $options];
				$entity        = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object + ['id' => 'v-saved']);
				return $entity;
			}
		);

		return new VersionMergeRunner(
			merger: new VersionMergeService(),
			schemas: $writer,
			branches: new VersionBranchService(
				store: new RegisterStore(objectService: $objects, registerMapper: $registers, schemaMapper: $schemas),
				schemas: $writer,
				state: $state,
				versions: $this->versions()
			),
			objects: $objects
		);
	}//end runner()

	/**
	 * The properties of a stored schema, by slug.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<string,mixed>
	 */
	private function propertiesOf(string $slug): array {
		foreach ($this->fixture->schemas as $schema) {
			if ($schema->getSlug() === $slug) {
				return $schema->getProperties();
			}
		}

		$this->fail('No schema ' . $slug);
	}//end propertiesOf()

	/**
	 * Assert that running the callable is refused with the given code and status, and nothing is saved.
	 *
	 * @param callable $run    The call.
	 * @param string   $code   The expected error code.
	 * @param int      $status The expected HTTP status.
	 *
	 * @return VersionBranchRefused
	 */
	private function assertRefused(callable $run, string $code, int $status): VersionBranchRefused {
		try {
			$run();
		} catch (VersionBranchRefused $e) {
			$this->assertSame($code, $e->errorCode);
			$this->assertSame($status, $e->status);
			$this->assertSame([], $this->saved);
			return $e;
		}

		$this->fail('Expected the refusal ' . $code);
	}//end assertRefused()

	/**
	 * A preview returns the merged manifest and schemas and the target's hash, and writes nothing.
	 *
	 * @return void
	 */
	public function testAPreviewMergesWithoutWriting(): void {
		$preview = $this->runner()->previewMerge(application: self::APPLICATION, source: self::branch(), target: self::development());

		$this->assertSame([], $preview['conflicts']);
		$this->assertSame([], $preview['breaking']);
		$this->assertSame(['permits', 'fees'], array_column($preview['manifest']['pages'], 'id'));
		$this->assertSame(['register' => '@version', 'schema' => '@schema:permit'], $preview['manifest']['pages'][1]['config']);
		$this->assertSame(['fee' => ['type' => 'number'], 'reason' => ['type' => 'string']], $preview['schemas']['permit']['properties']);
		$this->assertSame($this->versions()->hashManifest(manifest: self::development()['manifest']), $preview['targetHash']);

		$this->assertSame([], $this->saved);
		$this->assertSame(['fee' => ['type' => 'number']], $this->propertiesOf(slug: 'shop-development-permit'));
	}//end testAPreviewMergesWithoutWriting()

	/**
	 * An apply writes the merged schema, restores the manifest into the target's slugs and moves its base.
	 *
	 * @return void
	 */
	public function testAnApplyWritesTheTargetOnce(): void {
		$target = self::development();
		$saved  = $this->runner()->applyMerge(
			application: self::APPLICATION,
			source: self::branch(),
			target: $target,
			resolution: ['targetHash' => $this->versions()->hashManifest(manifest: $target['manifest'])]
		);

		$this->assertSame(['fee' => ['type' => 'number'], 'reason' => ['type' => 'string']], $this->propertiesOf(slug: 'shop-development-permit'));

		$this->assertCount(1, $this->saved);
		$object = $this->saved[0]['object'];
		$this->assertSame(
			['register' => 'openbuild-shop-development', 'schema' => 'shop-development-permit'],
			$object['manifest']['pages'][1]['config']
		);
		$this->assertSame('permit-fees', $object['branchBase']['source']);
		$this->assertSame($this->versions()->hashManifest(manifest: self::branch()['manifest']), $object['branchBase']['manifestHash']);
		$this->assertSame($this->versions()->hashManifest(manifest: $object['manifest']), $object['manifestHash']);
		$this->assertSame('1.0.1', $object['semver']);

		$options = $this->saved[0]['options'];
		$this->assertInstanceOf(ObjectSaveOptions::class, $options);
		$this->assertSame('v-dev', $options->uuid);
		$this->assertSame(ApplicationVersionServiceInterface::REGISTER_SLUG, $options->target->register);
		$this->assertSame(ApplicationVersionServiceInterface::APPLICATION_VERSION_SCHEMA, $options->target->schema);

		$this->assertSame('v-dev', $saved['id']);
	}//end testAnApplyWritesTheTargetOnce()

	/**
	 * A target whose manifest moved after the preview is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testATargetThatMovedSinceThePreviewIsRefused(): void {
		$runner = $this->runner();
		$this->assertRefused(
			run: fn () => $runner->applyMerge(
				application: self::APPLICATION,
				source: self::branch(),
				target: self::development(),
				resolution: ['targetHash' => 'a-hash-from-before']
			),
			code: 'target_changed',
			status: 409
		);

		$this->assertSame(['fee' => ['type' => 'number']], $this->propertiesOf(slug: 'shop-development-permit'));
	}//end testATargetThatMovedSinceThePreviewIsRefused()

	/**
	 * The production version and a version that is not a draft take no merge.
	 *
	 * @return void
	 */
	public function testOnlyADraftThatIsNotProductionTakesAMerge(): void {
		$runner     = $this->runner();
		$production = ['id' => 'v-prod'] + self::development();
		$this->assertRefused(
			run: fn () => $runner->previewMerge(application: self::APPLICATION, source: self::branch(), target: $production),
			code: 'target_is_production',
			status: 422
		);

		$released = ['status' => 'released'] + self::development();
		$this->assertRefused(
			run: fn () => $runner->previewMerge(application: self::APPLICATION, source: self::branch(), target: $released),
			code: 'target_not_draft',
			status: 422
		);
	}//end testOnlyADraftThatIsNotProductionTakesAMerge()

	/**
	 * Two versions neither of which was branched from the other have no common base.
	 *
	 * @return void
	 */
	public function testVersionsWithoutACommonBaseAreRefused(): void {
		$stranger = self::branch();
		unset($stranger['branchBase']);
		$runner = $this->runner();

		$refusal = $this->assertRefused(
			run: fn () => $runner->previewMerge(application: self::APPLICATION, source: $stranger, target: self::development()),
			code: 'no_common_base',
			status: 422
		);
		$this->assertStringContainsString('"permit-fees" and "development"', $refusal->getMessage());
	}//end testVersionsWithoutACommonBaseAreRefused()

	/**
	 * A conflict without a chosen side is refused; with a side chosen the merge writes that side.
	 *
	 * @return void
	 */
	public function testAConflictMustHaveASideChosen(): void {
		// Both versions changed the `fee` field since the base, each differently.
		$this->fixture->register(
			slug: 'openbuild-shop-permit-fees',
			schemas: ['shop-permit-fees-permit' => ['fee' => ['type' => 'string'], 'reason' => ['type' => 'string']]]
		);
		$base   = ['permit' => ['title' => 'Permit', 'required' => [], 'properties' => ['fee' => ['type' => 'integer']]]];
		$target = self::development();
		$hash   = $this->versions()->hashManifest(manifest: $target['manifest']);

		$preview = $this->runner()->previewMerge(application: self::APPLICATION, source: self::branch(baseSchemas: $base), target: $target);
		$this->assertSame(['property:permit.fee'], array_column($preview['conflicts'], 'unit'));

		$runner = $this->runner();
		$this->assertRefused(
			run: fn () => $runner->applyMerge(
				application: self::APPLICATION,
				source: self::branch(baseSchemas: $base),
				target: $target,
				resolution: ['targetHash' => $hash]
			),
			code: 'unresolved_conflicts',
			status: 409
		);

		$this->runner()->applyMerge(
			application: self::APPLICATION,
			source: self::branch(baseSchemas: $base),
			target: $target,
			resolution: ['targetHash' => $hash, 'choices' => ['property:permit.fee' => VersionMergeService::TAKE_TARGET]]
		);
		$this->assertCount(1, $this->saved);
		$this->assertSame(['type' => 'number'], $this->propertiesOf(slug: 'shop-development-permit')['fee']);
	}//end testAConflictMustHaveASideChosen()

	/**
	 * A merge that removes a field is refused until the breaking change is confirmed.
	 *
	 * @return void
	 */
	public function testABreakingMergeNeedsConfirmation(): void {
		// The branch dropped `fee`; development kept it unchanged since the base.
		$this->fixture->register(slug: 'openbuild-shop-permit-fees', schemas: ['shop-permit-fees-permit' => ['reason' => ['type' => 'string']]]);
		$target = self::development();
		$hash   = $this->versions()->hashManifest(manifest: $target['manifest']);

		$preview = $this->runner()->previewMerge(application: self::APPLICATION, source: self::branch(), target: $target);
		$this->assertSame(['The field "permit.fee" is removed.'], $preview['breaking']);

		$runner  = $this->runner();
		$refusal = $this->assertRefused(
			run: fn () => $runner->applyMerge(application: self::APPLICATION, source: self::branch(), target: $target, resolution: ['targetHash' => $hash]),
			code: 'breaking_change',
			status: 409
		);
		$this->assertStringContainsString('The field "permit.fee" is removed.', $refusal->getMessage());

		$this->runner()->applyMerge(
			application: self::APPLICATION,
			source: self::branch(),
			target: $target,
			resolution: ['targetHash' => $hash, 'acknowledgeBreaking' => 'true']
		);
		$this->assertCount(1, $this->saved);
		$this->assertSame(['reason' => ['type' => 'string']], $this->propertiesOf(slug: 'shop-development-permit'));
	}//end testABreakingMergeNeedsConfirmation()

	/**
	 * Starting a branch goes through the branch service: the new version gets its own register.
	 *
	 * @return void
	 */
	public function testStartBranchCreatesTheBranchThroughTheBranchService(): void {
		$branch = $this->runner()->startBranch(application: self::APPLICATION, source: self::development(), name: 'zones');

		$this->assertSame('zones', $branch['slug']);
		$this->assertSame('openbuild-shop-zones', $branch['register']);
		$this->assertSame('development', $branch['branchBase']['source']);
		$this->assertArrayHasKey('openbuild-shop-zones', $this->fixture->registers);
	}//end testStartBranchCreatesTheBranchThroughTheBranchService()
}//end class
