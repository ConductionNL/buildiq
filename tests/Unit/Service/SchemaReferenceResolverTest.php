<?php

/**
 * Unit tests for SchemaReferenceResolver: whether a schema id/slug is referenced, and ids held elsewhere.
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

use OCA\Buildiq\Service\SchemaReferenceResolver;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SchemaReferenceResolver.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Buildiq\Service\SchemaReferenceResolver
 */
class SchemaReferenceResolverTest extends TestCase {

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
	 * @var SchemaReferenceResolver
	 */
	private SchemaReferenceResolver $resolver;

	/**
	 * Build mocks + subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->resolver = new SchemaReferenceResolver($this->registerMapper, $this->schemaMapper);
	}//end setUp()

	/**
	 * Build a mock Register whose getSchemas() returns the given list.
	 *
	 * @param array<int, mixed> $schemas The schema id/slug list.
	 *
	 * @return Register&MockObject
	 */
	private function register(array $schemas): Register&MockObject {
		$register = $this->createMock(Register::class);
		$register->method('getSchemas')->willReturn($schemas);

		return $register;
	}//end register()

	/**
	 * A numeric id already in the referenced list is referenced, with no slug lookup.
	 *
	 * @return void
	 */
	public function testNumericIdInReferencedListIsReferenced(): void {
		$this->schemaMapper->expects($this->never())->method('findIdsBySlugs');

		$this->assertTrue($this->resolver->isReferenced(5, ['5', '6']));
	}//end testNumericIdInReferencedListIsReferenced()

	/**
	 * A numeric id absent from the referenced list is not referenced and is never resolved by slug.
	 *
	 * @return void
	 */
	public function testNumericIdNotInListShortCircuitsFalse(): void {
		$this->schemaMapper->expects($this->never())->method('findIdsBySlugs');

		$this->assertFalse($this->resolver->isReferenced(9, ['5', '6']));
	}//end testNumericIdNotInListShortCircuitsFalse()

	/**
	 * A slug is resolved to ids; a resolved id that intersects the referenced list counts as referenced.
	 *
	 * @return void
	 */
	public function testSlugResolvedToReferencedId(): void {
		$this->schemaMapper->expects($this->once())
			->method('findIdsBySlugs')
			->with(['permit'])
			->willReturn(['permit' => ['42']]);

		$this->assertTrue($this->resolver->isReferenced('permit', ['42', '43']));
	}//end testSlugResolvedToReferencedId()

	/**
	 * A slug whose resolved ids are not referenced returns false.
	 *
	 * @return void
	 */
	public function testSlugResolvedToUnreferencedIdIsFalse(): void {
		$this->schemaMapper->method('findIdsBySlugs')->willReturn(['permit' => ['99']]);

		$this->assertFalse($this->resolver->isReferenced('permit', ['42', '43']));
	}//end testSlugResolvedToUnreferencedIdIsFalse()

	/**
	 * heldByOtherRegisters() collects numeric ids directly and resolves slug ids, de-duplicating the result.
	 *
	 * @return void
	 */
	public function testHeldByOtherRegistersCollectsNumericAndSlugIds(): void {
		$this->registerMapper->expects($this->once())
			->method('findAll')
			->with(false, false)
			->willReturn([
				$this->register(['10', 'permit']),
				$this->register(['20']),
			]);

		$this->schemaMapper->expects($this->once())
			->method('findIdsBySlugs')
			->with(['permit'])
			->willReturn(['permit' => ['30', '10']]);

		$held = $this->resolver->heldByOtherRegisters();

		// Numeric ids 10 and 20, plus slug-resolved 30; 10 appears once after de-dup.
		sort($held);
		$this->assertSame(['10', '20', '30'], $held);
	}//end testHeldByOtherRegistersCollectsNumericAndSlugIds()

	/**
	 * With only numeric schema ids, no slug lookup is performed.
	 *
	 * @return void
	 */
	public function testHeldByOtherRegistersSkipsSlugLookupWhenNonePresent(): void {
		$this->registerMapper->method('findAll')->willReturn([$this->register(['1', '2'])]);
		$this->schemaMapper->expects($this->never())->method('findIdsBySlugs');

		$held = $this->resolver->heldByOtherRegisters();

		sort($held);
		$this->assertSame(['1', '2'], $held);
	}//end testHeldByOtherRegistersSkipsSlugLookupWhenNonePresent()
}//end class
