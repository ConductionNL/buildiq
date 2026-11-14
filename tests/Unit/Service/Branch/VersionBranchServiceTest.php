<?php

/**
 * Unit tests for VersionBranchService (lifecycle-git-branches T02).
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

use OCA\Buildiq\Service\Branch\VersionBranchRefused;
use OCA\Buildiq\Service\Branch\VersionBranchService;
use OCA\Buildiq\Service\Branch\VersionSchemaWriter;
use OCA\Buildiq\Service\Branch\VersionState;
use OCA\Buildiq\Service\RegisterStore;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;

/**
 * A branch is a draft version with its own register, the source's schemas
 * copied into it under its own slugs, the manifest pointed at them, and a
 * register-neutral snapshot of the source as its base.
 *
 * @covers \OCA\Buildiq\Service\Branch\VersionBranchService
 * @uses   \OCA\Buildiq\Service\Branch\VersionBranchRefused
 * @uses   \OCA\Buildiq\Service\Branch\VersionState
 * @uses   \OCA\Buildiq\Service\Branch\VersionSchemaWriter
 * @uses   \OCA\Buildiq\Service\VersionSchemaCarrier
 * @uses   \OCA\Buildiq\Service\ApplicationVersionService
 * @uses   \OCA\Buildiq\Service\RegisterStore
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 *
 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-starts-a-branch-from-a-version-req-bqgb-001
 */
final class VersionBranchServiceTest extends TestCase {

	/**
	 * The in-memory registers and schemas.
	 *
	 * @var BranchFixture
	 */
	private BranchFixture $fixture;

	/**
	 * Every object saved.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * The `development` version of app `shop`, with one own schema and one page on it.
	 *
	 * @var array<string,mixed>
	 */
	private const DEVELOPMENT = [
		'id' => 'v-dev',
		'slug' => 'development',
		'register' => 'openbuild-shop-development',
		'status' => 'draft',
		'manifest' => [
			'version' => '1.0.0',
			'pages' => [['id' => 'permits', 'type' => 'index', 'config' => ['register' => 'openbuild-shop-development', 'schema' => 'shop-development-permit']]],
		],
	];

	/**
	 * Set up the fixture with `development`'s register and its `permit` schema.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fixture = new BranchFixture();
		$this->fixture->register(slug: 'openbuild-shop-development', schemas: ['shop-development-permit' => ['fee' => ['type' => 'number']]]);
	}//end setUp()

	/**
	 * The service over the fixture.
	 *
	 * @return VersionBranchService
	 */
	private function service(): VersionBranchService {
		$registers = $this->fixture->registerMapper(mapper: $this->createMock(RegisterMapper::class));
		$schemas   = $this->fixture->schemaMapper(mapper: $this->createMock(SchemaMapper::class));
		$state     = new VersionState(registerMapper: $registers, schemaMapper: $schemas);
		$objects   = $this->createMock(ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->saved[] = $object;
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object + ['id' => 'v-new']);
				return $entity;
			}
		);

		return new VersionBranchService(
			store: new RegisterStore(objectService: $objects, registerMapper: $registers, schemaMapper: $schemas),
			schemas: new VersionSchemaWriter(registerMapper: $registers, schemaMapper: $schemas, state: $state),
			state: $state,
			versions: BranchFixture::versions(mock: fn (string $class): object => $this->createMock($class))
		);
	}//end service()

	/**
	 * The branch gets its own register and schema copy, and its manifest points at them.
	 *
	 * @return void
	 */
	public function testABranchCopiesTheSchemasAndManifestIntoItsOwnRegister(): void {
		$branch = $this->service()->start(application: ['id' => 'app-1', 'slug' => 'shop'], source: self::DEVELOPMENT, name: 'permit-fees');

		$this->assertArrayHasKey('openbuild-shop-permit-fees', $this->fixture->registers);
		$copy = array_values(array_filter($this->fixture->schemas, static fn ($s): bool => $s->getSlug() === 'shop-permit-fees-permit'));
		$this->assertCount(1, $copy);
		$this->assertSame(['fee' => ['type' => 'number']], $copy[0]->getProperties());
		$this->assertSame([$copy[0]->getId()], $this->fixture->registers['openbuild-shop-permit-fees']->getSchemas());

		$this->assertSame('permit-fees', $branch['slug']);
		$this->assertSame('draft', $branch['status']);
		$this->assertSame('app-1', $branch['application']);
		$this->assertSame('openbuild-shop-permit-fees', $branch['register']);
		$this->assertSame(['register' => 'openbuild-shop-permit-fees', 'schema' => 'shop-permit-fees-permit'], $branch['manifest']['pages'][0]['config']);
		$this->assertSame('0.1.0', $branch['semver']);
		$this->assertArrayNotHasKey('githubBranch', $branch);
		// The source is untouched: its register still lists only its own schema.
		$this->assertCount(1, $this->fixture->registers['openbuild-shop-development']->getSchemas());
	}//end testABranchCopiesTheSchemasAndManifestIntoItsOwnRegister()

	/**
	 * The base is the source's slug, its manifest hash and a register-neutral snapshot.
	 *
	 * @return void
	 */
	public function testTheBaseIsANeutralSnapshotOfTheSource(): void {
		$base = $this->service()->start(application: ['id' => 'app-1', 'slug' => 'shop'], source: self::DEVELOPMENT, name: 'permit-fees')['branchBase'];

		$this->assertSame('development', $base['source']);
		$this->assertSame(BranchFixture::versions(mock: fn (string $class): object => $this->createMock($class))->hashManifest(manifest: self::DEVELOPMENT['manifest']), $base['manifestHash']);
		$this->assertSame(['register' => '@version', 'schema' => '@schema:permit'], $base['manifest']['pages'][0]['config']);
		$this->assertSame(['permit'], array_keys($base['schemas']));
		$this->assertSame(['fee' => ['type' => 'number']], $base['schemas']['permit']['properties']);
	}//end testTheBaseIsANeutralSnapshotOfTheSource()

	/**
	 * A linked app gives the branch a Git branch of the same name.
	 *
	 * @return void
	 */
	public function testALinkedAppGivesTheBranchItsGitBranch(): void {
		$branch = $this->service()->start(
			application: ['id' => 'app-1', 'slug' => 'shop', 'githubRepo' => ['owner' => 'gemeente', 'name' => 'shop']],
			source: self::DEVELOPMENT,
			name: 'permit-fees'
		);

		$this->assertSame('permit-fees', $branch['githubBranch']);
	}//end testALinkedAppGivesTheBranchItsGitBranch()

	/**
	 * A name whose register already exists is refused and nothing is created.
	 *
	 * @return void
	 */
	public function testATakenNameIsRefused(): void {
		$this->fixture->register(slug: 'openbuild-shop-permit-fees');
		$schemasBefore = count($this->fixture->schemas);

		try {
			$this->service()->start(application: ['id' => 'app-1', 'slug' => 'shop'], source: self::DEVELOPMENT, name: 'permit-fees');
			$this->fail('A taken name must be refused.');
		} catch (VersionBranchRefused $e) {
			$this->assertSame('name_taken', $e->errorCode);
			$this->assertSame(409, $e->status);
		}

		$this->assertSame([], $this->saved);
		$this->assertCount($schemasBefore, $this->fixture->schemas);
	}//end testATakenNameIsRefused()

	/**
	 * A name that is not a slug is refused with 400 before anything is created.
	 *
	 * @return void
	 */
	public function testAnInvalidNameIsRefused(): void {
		$this->expectException(VersionBranchRefused::class);
		$this->expectExceptionMessage('lowercase letters, digits and dashes');

		try {
			$this->service()->start(application: ['id' => 'app-1', 'slug' => 'shop'], source: self::DEVELOPMENT, name: 'Permit Fees');
		} finally {
			$this->assertSame(['openbuild-shop-development'], array_keys($this->fixture->registers));
		}
	}//end testAnInvalidNameIsRefused()
}//end class
