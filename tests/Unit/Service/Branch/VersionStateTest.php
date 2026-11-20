<?php

/**
 * Unit tests for VersionState: a version's register-neutral manifest and schemas.
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

use OCA\Buildiq\Service\Branch\VersionState;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;

/**
 * A version's own register and schemas read as `@version` and `@schema:{name}`,
 * and a neutral manifest restores into any version's own register and slugs.
 *
 * @covers \OCA\Buildiq\Service\Branch\VersionState
 * @uses   \OCA\Buildiq\Service\VersionSchemaCarrier
 *
 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
 */
final class VersionStateTest extends TestCase {

	/**
	 * The in-memory registers and schemas.
	 *
	 * @var BranchFixture
	 */
	private BranchFixture $fixture;

	/**
	 * Set up `development` of app `shop` with two own schemas and a shared one.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fixture = new BranchFixture();
		$this->fixture->register(
			slug: 'openbuild-shop-development',
			schemas: [
				'shop-development-zone' => ['code' => ['type' => 'string']],
				'shop-development-permit' => ['fee' => ['type' => 'number']],
				'shared-address' => ['street' => ['type' => 'string']],
			]
		);
	}//end setUp()

	/**
	 * The state reader over the fixture.
	 *
	 * @return VersionState
	 */
	private function state(): VersionState {
		return new VersionState(
			registerMapper: $this->fixture->registerMapper(mapper: $this->createMock(originalClassName: RegisterMapper::class)),
			schemaMapper: $this->fixture->schemaMapper(mapper: $this->createMock(originalClassName: SchemaMapper::class))
		);
	}//end state()

	/**
	 * The id the fixture gave a schema slug.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return int
	 */
	private function idOf(string $slug): int {
		foreach ($this->fixture->schemas as $schema) {
			if ($schema->getSlug() === $slug) {
				return (int)$schema->getId();
			}
		}

		$this->fail(message: 'No schema ' . $slug);
	}//end idOf()

	/**
	 * Only schemas under the version's stem are its own, keyed by name and sorted.
	 *
	 * @return void
	 */
	public function testOwnSchemasAreThoseUnderTheStemSortedByName(): void {
		$own = $this->state()->ownSchemas(registerSlug: 'openbuild-shop-development');

		$this->assertSame(expected: ['permit', 'zone'], actual: array_keys($own));
		$this->assertSame(expected: 'shop-development-permit', actual: $own['permit']->getSlug());
		$this->assertSame(expected: 'shop-development-zone', actual: $own['zone']->getSlug());
	}//end testOwnSchemasAreThoseUnderTheStemSortedByName()

	/**
	 * A register that is missing, or that is no version register, has no own schemas.
	 *
	 * @return void
	 */
	public function testAMissingOrForeignRegisterHasNoOwnSchemas(): void {
		$this->fixture->register(slug: 'decidiq', schemas: ['decidiq-meeting' => []]);
		$state = $this->state();

		$this->assertSame(expected: [], actual: $state->ownSchemas(registerSlug: 'openbuild-shop-gone'));
		$this->assertSame(expected: [], actual: $state->ownSchemas(registerSlug: 'decidiq'));
		$this->assertSame(expected: [], actual: $state->ownSchemas(registerSlug: ''));
	}//end testAMissingOrForeignRegisterHasNoOwnSchemas()

	/**
	 * Reading writes the own register and own schemas, by slug or id, as neutral names at any depth.
	 *
	 * @return void
	 */
	public function testReadMakesTheOwnRegisterAndSchemasNeutral(): void {
		$permitId = $this->idOf(slug: 'shop-development-permit');
		$read     = $this->state()->read(
			version: [
				'register' => 'openbuild-shop-development',
				'manifest' => [
					'version' => '1.0.0',
					'pages' => [
						['id' => 'permits', 'config' => ['register' => 'openbuild-shop-development', 'schema' => 'shop-development-permit']],
						['id' => 'by-id', 'config' => ['register' => 'openbuild-shop-development', 'schema' => $permitId]],
						['id' => 'shared', 'config' => ['register' => 'addresses', 'schema' => 'shared-address']],
					],
				],
			]
		);

		$pages = $read['manifest']['pages'];
		$this->assertSame(expected: ['register' => '@version', 'schema' => '@schema:permit'], actual: $pages[0]['config']);
		$this->assertSame(expected: ['register' => '@version', 'schema' => '@schema:permit'], actual: $pages[1]['config']);
		$this->assertSame(expected: ['register' => 'addresses', 'schema' => 'shared-address'], actual: $pages[2]['config']);
		$this->assertSame(expected: '1.0.0', actual: $read['manifest']['version']);

		$this->assertSame(expected: ['permit', 'zone'], actual: array_keys($read['schemas']));
		$this->assertSame(expected: ['fee' => ['type' => 'number']], actual: $read['schemas']['permit']['properties']);
		$this->assertSame(expected: 'Permit', actual: $read['schemas']['permit']['title']);
		$this->assertArrayNotHasKey(key: 'slug', array: $read['schemas']['permit']);
	}//end testReadMakesTheOwnRegisterAndSchemasNeutral()

	/**
	 * A version without a register or a manifest reads as an empty state.
	 *
	 * @return void
	 */
	public function testAVersionWithoutRegisterOrManifestReadsEmpty(): void {
		$this->assertSame(expected: ['manifest' => [], 'schemas' => []], actual: $this->state()->read(version: []));
		$this->assertSame(
			expected: ['manifest' => [], 'schemas' => []],
			actual: $this->state()->read(version: ['register' => 'openbuild-shop-gone', 'manifest' => 'not a manifest'])
		);
	}//end testAVersionWithoutRegisterOrManifestReadsEmpty()

	/**
	 * Restoring writes `@version` and `@schema:{name}` into the target version's register and slugs.
	 *
	 * @return void
	 */
	public function testRestorePutsANeutralManifestIntoAVersionsOwnSlugs(): void {
		$restored = $this->state()->restore(
			manifest: [
				'pages' => [
					['id' => 'permits', 'config' => ['register' => '@version', 'schema' => '@schema:permit']],
					['id' => 'shared', 'config' => ['register' => 'addresses', 'schema' => 'shared-address']],
					['id' => 'numbered', 'config' => ['register' => 7, 'schema' => 12]],
				],
				'title' => 'Shop',
			],
			version: ['register' => 'openbuild-shop-permit-fees']
		);

		$pages = $restored['pages'];
		$this->assertSame(expected: ['register' => 'openbuild-shop-permit-fees', 'schema' => 'shop-permit-fees-permit'], actual: $pages[0]['config']);
		$this->assertSame(expected: ['register' => 'addresses', 'schema' => 'shared-address'], actual: $pages[1]['config']);
		$this->assertSame(expected: ['register' => 7, 'schema' => 12], actual: $pages[2]['config']);
		$this->assertSame(expected: 'Shop', actual: $restored['title']);
	}//end testRestorePutsANeutralManifestIntoAVersionsOwnSlugs()

	/**
	 * Reading one version and restoring into another moves the manifest across registers.
	 *
	 * @return void
	 */
	public function testReadThenRestoreMovesAManifestBetweenVersions(): void {
		$state   = $this->state();
		$neutral = $state->read(
			version: [
				'register' => 'openbuild-shop-development',
				'manifest' => ['pages' => [['id' => 'zones', 'config' => ['register' => 'openbuild-shop-development', 'schema' => 'shop-development-zone']]]],
			]
		)['manifest'];

		$restored = $state->restore(manifest: $neutral, version: ['register' => 'openbuild-shop-acceptance']);

		$this->assertSame(
			expected: ['register' => 'openbuild-shop-acceptance', 'schema' => 'shop-acceptance-zone'],
			actual: $restored['pages'][0]['config']
		);
	}//end testReadThenRestoreMovesAManifestBetweenVersions()

	/**
	 * The definition carries the compared schema fields and nothing that names the version.
	 *
	 * @return void
	 */
	public function testTheDefinitionCarriesTheComparedFields(): void {
		$schema = $this->fixture->schema(
			definition: ['slug' => 'shop-development-fee', 'title' => 'Fee', 'properties' => ['amount' => ['type' => 'number']], 'required' => ['amount']]
		);

		$definition = VersionState::definition(schema: $schema);

		$this->assertSame(expected: 'Fee', actual: $definition['title']);
		$this->assertSame(expected: ['amount' => ['type' => 'number']], actual: $definition['properties']);
		$this->assertSame(expected: ['amount'], actual: $definition['required']);
		$this->assertArrayNotHasKey(key: 'slug', array: $definition);
		$this->assertArrayNotHasKey(key: 'id', array: $definition);
	}//end testTheDefinitionCarriesTheComparedFields()
}//end class
