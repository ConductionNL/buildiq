<?php

/**
 * Unit tests for RuleObjectReader: rule-engine reads resolve the system-wide
 * register without the organisation filter and search its objects with it on
 * (REQ-BRE-007, buildiq#988).
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
 *
 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-007-per-tenant-isolation-and-multitenancy
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\RuleObjectReader;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for {@see RuleObjectReader}.
 */
final class RuleObjectReaderTest extends TestCase {

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * The schema entries the `buildiq` register holds.
	 *
	 * @var array<int,int|string>
	 */
	private array $held = [101, 105];

	/**
	 * Slug to matching schema ids across the whole instance.
	 *
	 * @var array<string,array<int,string>>
	 */
	private array $idsBySlug = ['rule-set' => ['101'], 'rule-test-case' => ['9001', '105']];

	/**
	 * Wire the mocked OpenRegister boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		$this->registerMapper->method('find')->willReturnCallback(
			function (string|int $id, bool $_rbac = true, bool $_multitenancy = true): Register {
				if ($_multitenancy === true) {
					throw new DoesNotExistException('Register not found in the caller organisation: ' . $id);
				}

				$register = new Register();
				$register->setId(7);
				$register->setSchemas($this->held);
				return $register;
			}
		);
		$this->schemaMapper->method('findIdsBySlugs')->willReturnCallback(
			fn (array $slugs): array => [strtolower($slugs[0]) => ($this->idsBySlug[$slugs[0]] ?? [])]
		);

	}//end setUp()

	/**
	 * The reader under test.
	 *
	 * @return RuleObjectReader
	 */
	private function reader(): RuleObjectReader {
		return new RuleObjectReader($this->objectService, $this->registerMapper, $this->schemaMapper, new NullLogger());

	}//end reader()

	/**
	 * The object search runs with RBAC and the organisation filter on, against
	 * the register's own schema id, even when another app holds the same slug.
	 *
	 * @return void
	 */
	public function testSearchIsOrganisationScopedOnTheRegistersOwnSchema(): void {
		$this->objectService->expects($this->once())
			->method('searchObjects')
			->with(['ruleSetId' => 'loan', '@self' => ['register' => 7, 'schema' => 105]], true, true)
			->willReturn([['name' => 'case-1']]);

		$this->assertSame([['name' => 'case-1']], $this->reader()->find(schema: 'rule-test-case', filters: ['ruleSetId' => 'loan']));

	}//end testSearchIsOrganisationScopedOnTheRegistersOwnSchema()

	/**
	 * A schema the register holds by slug (an older import) still resolves
	 * when exactly one schema carries that slug.
	 *
	 * @return void
	 */
	public function testSchemaHeldBySlugResolvesWhenUnambiguous(): void {
		$this->held = ['rule-set'];
		$this->objectService->expects($this->once())
			->method('searchObjects')
			->with(['slug' => 'loan', '@self' => ['register' => 7, 'schema' => 101]], true, true)
			->willReturn([]);

		$this->assertSame([], $this->reader()->find(schema: 'rule-set', filters: ['slug' => 'loan']));

	}//end testSchemaHeldBySlugResolvesWhenUnambiguous()

	/**
	 * A schema that is not in the register reads as nothing; the reader never
	 * falls back to another app's schema or to an unscoped read.
	 *
	 * @return void
	 */
	public function testSchemaOutsideTheRegisterReadsAsNothing(): void {
		$this->idsBySlug['rule-set'] = ['555'];
		$this->objectService->expects($this->never())->method('searchObjects');
		$this->objectService->expects($this->never())->method('searchObjectsBySlug');

		$this->assertSame([], $this->reader()->find(schema: 'rule-set', filters: ['slug' => 'loan']));

	}//end testSchemaOutsideTheRegisterReadsAsNothing()

	/**
	 * A failing search reads as nothing, and the row cap applies.
	 *
	 * @return void
	 */
	public function testFailureReadsAsNothingAndLimitApplies(): void {
		$this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls(
			[['n' => 1], ['n' => 2]],
			$this->throwException(new \RuntimeException('down'))
		);

		$reader = $this->reader();
		$this->assertSame([['n' => 1]], $reader->find(schema: 'rule-set', filters: [], limit: 1));
		$this->assertSame([], $reader->find(schema: 'rule-set', filters: []));

	}//end testFailureReadsAsNothingAndLimitApplies()
}//end class
