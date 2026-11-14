<?php

/**
 * Unit tests for VersionDataSourceResolver: the register/schema pairs a version reads from.
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

use OCA\Buildiq\Service\VersionDataSourceResolver;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for VersionDataSourceResolver.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Buildiq\Service\VersionDataSourceResolver
 */
class VersionDataSourceResolverTest extends TestCase {

	/**
	 * Schema slugs the schema mapper knows, by reference.
	 *
	 * @var array<string, array{0: int, 1: string}>
	 */
	private array $schemas = [
		'7' => [7, 'permit'],
		'permit' => [7, 'permit'],
		'8' => [8, 'decision'],
		'zero' => [0, 'zero'],
	];

	/**
	 * With the own register found, its schemas and the page schemas are resolved, de-duplicated, unknown ones skipped.
	 *
	 * @return void
	 */
	public function testTheOwnRegisterSchemasAndPageSchemasAreResolved(): void {
		$register = new Register();
		$register->setSchemas([7, 8, 'missing']);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->expects($this->once())->method('find')->with('permits', true, false)->willReturn($register);

		$manifest = ['pages' => [['config' => ['register' => 'x', 'schema' => '8']]]];
		$sources = $this->resolver($registerMapper)->resolve($manifest, 'permits', ['permit', 'zero']);

		$this->assertSame(
			[
				['register' => 'permits', 'schemaId' => 7, 'schemaSlug' => 'permit'],
				['register' => 'permits', 'schemaId' => 8, 'schemaSlug' => 'decision'],
			],
			$sources
		);
	}//end testTheOwnRegisterSchemasAndPageSchemasAreResolved()

	/**
	 * Without the own register, the pairs come from the manifest pages that name both a register and a schema.
	 *
	 * @return void
	 */
	public function testWithoutTheOwnRegisterThePagesAreRead(): void {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willThrowException(new RuntimeException('no register'));

		$manifest = [
			'pages' => [
				['config' => ['register' => 'a', 'schema' => 7]],
				['config' => ['register' => 'b', 'schema' => 'permit']],
				['config' => ['register' => 'a', 'schema' => 'permit']],
				['config' => ['register' => '', 'schema' => '8']],
				['config' => ['register' => 'c', 'schema' => '']],
				['config' => ['register' => 'c', 'schema' => ['8']]],
				['config' => 'none'],
				'not a page',
			],
		];

		$this->assertSame(
			[
				['register' => 'a', 'schemaId' => 7, 'schemaSlug' => 'permit'],
				['register' => 'b', 'schemaId' => 7, 'schemaSlug' => 'permit'],
			],
			$this->resolver($registerMapper)->resolve($manifest, 'permits', ['8'])
		);
	}//end testWithoutTheOwnRegisterThePagesAreRead()

	/**
	 * No register and no manifest resolves to no sources.
	 *
	 * @return void
	 */
	public function testNothingResolvesToNoSources(): void {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willThrowException(new RuntimeException('no register'));

		$this->assertSame([], $this->resolver($registerMapper)->resolve(null, 'permits', []));
	}//end testNothingResolvesToNoSources()

	/**
	 * Build the resolver over a schema mapper answering $this->schemas.
	 *
	 * @param RegisterMapper $registerMapper The register mapper.
	 *
	 * @return VersionDataSourceResolver
	 */
	private function resolver(RegisterMapper $registerMapper): VersionDataSourceResolver {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (mixed $ref, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): Schema {
				if (isset($this->schemas[(string)$ref]) === false || $_multitenancy !== false) {
					throw new RuntimeException('no schema ' . $ref);
				}

				[$id, $slug] = $this->schemas[(string)$ref];
				$schema = new Schema();
				$schema->setId($id);
				$schema->setSlug($slug);
				return $schema;
			}
		);

		return new VersionDataSourceResolver($registerMapper, $schemaMapper);
	}//end resolver()
}//end class
