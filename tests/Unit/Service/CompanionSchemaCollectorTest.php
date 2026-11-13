<?php

/**
 * Unit tests for CompanionSchemaCollector: resolving a register's schemas into companion JSON-schema shapes.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\CompanionSchemaCollector;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for CompanionSchemaCollector.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Buildiq\Service\CompanionSchemaCollector
 */
class CompanionSchemaCollectorTest extends TestCase {

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * The subject under test.
	 *
	 * @var CompanionSchemaCollector
	 */
	private CompanionSchemaCollector $collector;

	/**
	 * Build mocks + subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->collector = new CompanionSchemaCollector(
			$this->registerMapper,
			$this->schemaMapper,
			new NullLogger()
		);
	}//end setUp()

	/**
	 * Build a mock register whose getSchemas() returns the given ids.
	 *
	 * @param array<int, mixed> $schemas The schema ids.
	 *
	 * @return Register&MockObject
	 */
	private function register(array $schemas): Register&MockObject {
		$register = $this->createMock(Register::class);
		$register->method('getSchemas')->willReturn($schemas);

		return $register;
	}//end register()

	/**
	 * Build a mock schema with the given getters.
	 *
	 * @param string             $slug       The schema slug.
	 * @param string             $title      The title.
	 * @param string             $version    The version.
	 * @param array<int, string> $required   The required property names.
	 * @param array<mixed>       $properties The properties map.
	 *
	 * @return Schema&MockObject
	 */
	private function schema(
		string $slug,
		string $title = 'T',
		string $version = '1.2.3',
		array $required = [],
		array $properties = []
	): Schema&MockObject {
		$schema = $this->createMock(Schema::class);
		$schema->method('getSlug')->willReturn($slug);
		$schema->method('getTitle')->willReturn($title);
		$schema->method('getDescription')->willReturn('desc');
		$schema->method('getVersion')->willReturn($version);
		$schema->method('getRequired')->willReturn($required);
		$schema->method('getProperties')->willReturn($properties);

		return $schema;
	}//end schema()

	/**
	 * When no register resolves, an empty map is returned and no schema is touched.
	 *
	 * @return void
	 */
	public function testNoRegisterYieldsEmpty(): void {
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('absent'));
		$this->schemaMapper->expects($this->never())->method('find');

		$this->assertSame([], $this->collector->collect('myapp'));
	}//end testNoRegisterYieldsEmpty()

	/**
	 * The register is derived as "openbuild-<slug>" when no explicit version register is supplied.
	 *
	 * @return void
	 */
	public function testDerivedRegisterNameIsUsed(): void {
		$this->registerMapper->expects($this->once())
			->method('find')
			->with('openbuild-myapp', true, false)
			->willReturn($this->register([]));

		$this->assertSame([], $this->collector->collect('myapp'));
	}//end testDerivedRegisterNameIsUsed()

	/**
	 * An explicit version register is tried before the derived one.
	 *
	 * @return void
	 */
	public function testExplicitVersionRegisterPreferred(): void {
		$this->registerMapper->expects($this->once())
			->method('find')
			->with('myapp-v2', true, false)
			->willReturn($this->register([]));

		$this->assertSame([], $this->collector->collect('myapp', 'myapp-v2'));
	}//end testExplicitVersionRegisterPreferred()

	/**
	 * Each resolvable schema becomes a companion entry keyed by slug, carrying title, version and shape.
	 *
	 * @return void
	 */
	public function testCollectBuildsCompanionShapes(): void {
		$this->registerMapper->method('find')->willReturn($this->register(['11']));

		$this->schemaMapper->method('find')->willReturn(
			$this->schema(
				'contract',
				title: 'Contract',
				version: '2.0.0',
				required: ['name'],
				properties: ['name' => ['type' => 'string']]
			)
		);

		$out = $this->collector->collect('myapp');

		$this->assertArrayHasKey('contract', $out);
		$this->assertSame(
			[
				'slug'        => 'contract',
				'title'       => 'Contract',
				'description' => 'desc',
				'version'     => '2.0.0',
				'type'        => 'object',
				'required'    => ['name'],
				'properties'  => ['name' => ['type' => 'string']],
			],
			$out['contract']
		);
	}//end testCollectBuildsCompanionShapes()

	/**
	 * A blank schema version defaults to 0.1.0, and a blank slug is skipped.
	 *
	 * @return void
	 */
	public function testBlankVersionDefaultsAndBlankSlugSkipped(): void {
		$this->registerMapper->method('find')->willReturn($this->register(['1', '2']));

		$blank = $this->schema('');
		$kept = $this->schema('kept', version: '');
		$this->schemaMapper->method('find')->willReturnOnConsecutiveCalls($blank, $kept);

		$out = $this->collector->collect('myapp');

		$this->assertArrayNotHasKey('', $out);
		$this->assertSame('0.1.0', $out['kept']['version']);
	}//end testBlankVersionDefaultsAndBlankSlugSkipped()

	/**
	 * A schema that cannot be resolved is skipped, not fatal.
	 *
	 * @return void
	 */
	public function testUnresolvableSchemaIsSkipped(): void {
		$this->registerMapper->method('find')->willReturn($this->register(['7', '8']));

		$this->schemaMapper->method('find')->willReturnCallback(
			function (mixed $id): Schema {
				if ((string)$id === '7') {
					throw new RuntimeException('gone');
				}

				return $this->schema('present');
			}
		);

		$out = $this->collector->collect('myapp');

		$this->assertSame(['present'], array_keys($out));
	}//end testUnresolvableSchemaIsSkipped()
}//end class
