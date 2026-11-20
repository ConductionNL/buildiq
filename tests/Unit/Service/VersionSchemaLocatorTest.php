<?php

/**
 * Unit tests for VersionSchemaLocator: finding version schemas that exist but are no longer listed on a register.
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

use OCA\Buildiq\Service\VersionSchemaLocator;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for VersionSchemaLocator.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Buildiq\Service\VersionSchemaLocator
 */
class VersionSchemaLocatorTest extends TestCase {

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
	 * @var VersionSchemaLocator
	 */
	private VersionSchemaLocator $locator;

	/**
	 * Build mocks + subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->locator = new VersionSchemaLocator(
			$this->registerMapper,
			$this->schemaMapper,
			new NullLogger()
		);
	}//end setUp()

	/**
	 * Build a mock register whose getSchemas() returns the listed ids.
	 *
	 * @param array<int, mixed> $schemas Listed schema ids.
	 *
	 * @return Register&MockObject
	 */
	private function register(array $schemas): Register&MockObject {
		$register = $this->createMock(Register::class);
		$register->method('getSchemas')->willReturn($schemas);

		return $register;
	}//end register()

	/**
	 * Build a mock schema returning the given slug.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return Schema&MockObject
	 */
	private function schema(string $slug): Schema&MockObject {
		$schema = $this->createMock(Schema::class);
		$schema->method('getSlug')->willReturn($slug);

		return $schema;
	}//end schema()

	/**
	 * An empty app slug yields no detached ids regardless of versions.
	 *
	 * @return void
	 */
	public function testEmptyAppSlugYieldsNothing(): void {
		$this->assertSame([], $this->locator->findDetachedSchemaIds('', [['slug' => 'v1', 'register' => 'r1']]));
	}//end testEmptyAppSlugYieldsNothing()

	/**
	 * A version missing its slug or register is ignored; with no usable version the result is empty.
	 *
	 * @return void
	 */
	public function testVersionsMissingSlugOrRegisterAreIgnored(): void {
		$this->registerMapper->expects($this->never())->method('find');

		$this->assertSame(
			[],
			$this->locator->findDetachedSchemaIds('myapp', [['slug' => '', 'register' => 'r1'], ['slug' => 'v1']])
		);
	}//end testVersionsMissingSlugOrRegisterAreIgnored()

	/**
	 * A register that cannot be resolved contributes no listed schemas, so nothing is detached.
	 *
	 * @return void
	 */
	public function testUnresolvableRegisterYieldsNothing(): void {
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('no register'));

		$this->assertSame(
			[],
			$this->locator->findDetachedSchemaIds('myapp', [['slug' => 'v1', 'register' => 'r1']])
		);
	}//end testUnresolvableRegisterYieldsNothing()

	/**
	 * A version-named schema that exists under the version prefix but is not listed on the register is detached.
	 *
	 * @return void
	 */
	public function testDetachedSchemaIsReported(): void {
		// The register lists schema id 100 only.
		$this->registerMapper->method('find')->willReturn($this->register(['100']));

		// Schema 100's slug is the version-prefixed "myapp-v1-contract".
		$this->schemaMapper->method('find')->willReturn($this->schema('myapp-v1-contract'));

		// Resolving the candidate slug finds ids 200 (detached) and 100 (still listed).
		$this->schemaMapper->method('findIdsBySlugs')
			->with(['myapp-v1-contract'])
			->willReturn(['myapp-v1-contract' => ['200', '100']]);

		$detached = $this->locator->findDetachedSchemaIds('myapp', [['slug' => 'v1', 'register' => 'r1']]);

		$this->assertSame(['v1' => ['200']], $detached);
	}//end testDetachedSchemaIsReported()

	/**
	 * A schema whose slug does not carry the version prefix produces no candidate names, so nothing is looked up.
	 *
	 * @return void
	 */
	public function testNonPrefixedSchemaProducesNoCandidates(): void {
		$this->registerMapper->method('find')->willReturn($this->register(['100']));
		$this->schemaMapper->method('find')->willReturn($this->schema('unrelated-slug'));
		$this->schemaMapper->expects($this->never())->method('findIdsBySlugs');

		$this->assertSame(
			[],
			$this->locator->findDetachedSchemaIds('myapp', [['slug' => 'v1', 'register' => 'r1']])
		);
	}//end testNonPrefixedSchemaProducesNoCandidates()

	/**
	 * A failing candidate lookup is logged and swallowed, returning an empty result.
	 *
	 * @return void
	 */
	public function testCandidateLookupFailureYieldsNothing(): void {
		$this->registerMapper->method('find')->willReturn($this->register(['100']));
		$this->schemaMapper->method('find')->willReturn($this->schema('myapp-v1-contract'));
		$this->schemaMapper->method('findIdsBySlugs')->willThrowException(new RuntimeException('db down'));

		$this->assertSame(
			[],
			$this->locator->findDetachedSchemaIds('myapp', [['slug' => 'v1', 'register' => 'r1']])
		);
	}//end testCandidateLookupFailureYieldsNothing()
}//end class
