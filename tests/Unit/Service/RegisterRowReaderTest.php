<?php

/**
 * Unit tests for RegisterRowReader.
 *
 * The defect these cover: `ObjectService::searchObjects()` resolves its
 * target table from the PAIR `@self.register` + `@self.schema`. A query
 * naming only the register satisfies neither half of the mapper's guard,
 * so OpenRegister logs a warning and answers `[]`. Three buildiq callers
 * read that empty answer as "the register holds nothing" and deleted,
 * copied and exported nothing while reporting success.
 *
 * Every assertion here is about the QUERY the reader emits, because the
 * value that came back was never the problem.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Exception\RegisterRowReadFailedException;
use OCA\Buildiq\Service\RegisterRowReader;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for RegisterRowReader.
 */
final class RegisterRowReaderTest extends TestCase {
	/**
	 * Every query the reader emitted, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $queries = [];

	/**
	 * A register with the given id, slug and schema ids.
	 *
	 * @param int               $id      Register id.
	 * @param string            $slug    Register slug.
	 * @param array<int,string> $schemas Schema ids the register owns.
	 *
	 * @return Register The register.
	 */
	private function buildRegister(int $id, string $slug, array $schemas): Register {
		$register = new class() extends Register {
			/**
			 * @var int
			 */
			public int $entityId = 0;

			/**
			 * @var string
			 */
			public string $entitySlug = '';

			/**
			 * @var array<int,string>
			 */
			public array $entitySchemas = [];

			/**
			 * @return int
			 */
			public function getId(): int {
				return $this->entityId;
			}

			/**
			 * @return string
			 */
			public function getSlug(): string {
				return $this->entitySlug;
			}

			/**
			 * @return array<int,string>
			 */
			public function getSchemas(): array {
				return $this->entitySchemas;
			}
		};

		$register->entityId = $id;
		$register->entitySlug = $slug;
		$register->entitySchemas = $schemas;

		return $register;
	}//end buildRegister()

	/**
	 * A reader whose store records each query and answers `$rows` per schema.
	 *
	 * @param array<string,array<int,string>> $rowsBySchema Schema id to row list.
	 * @param string|null                     $throwOn      Schema id whose read fails.
	 *
	 * @return RegisterRowReader The reader.
	 */
	private function reader(array $rowsBySchema, ?string $throwOn=null): RegisterRowReader {
		$this->queries = [];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(
			function (array $query) use ($rowsBySchema, $throwOn): array {
				$this->queries[] = $query;
				$schema = (string)($query['@self']['schema'] ?? '');
				if ($throwOn !== null && $schema === $throwOn) {
					throw new RuntimeException('driver is down');
				}

				return ($rowsBySchema[$schema] ?? []);
			}
		);

		return new RegisterRowReader($objectService);
	}//end reader()

	/**
	 * THE regression. Every query the reader emits names a schema.
	 *
	 * Mutation check: drop `'schema' => $schemaId` from the `@self` block in
	 * RegisterRowReader::rowsInRegister() and this assertion reddens with
	 * "query 0 reached searchObjects() without a schema", which is the exact
	 * query OpenRegister answers `[]` to regardless of what the register holds.
	 *
	 * @return void
	 */
	public function testEveryEmittedQueryNamesBothARegisterAndASchema(): void {
		$reader = $this->reader(rowsBySchema: []);

		$reader->rowsInRegister(
			register: $this->buildRegister(id: 7, slug: 'shop', schemas: ['12', '99'])
		);

		self::assertCount(2, $this->queries, 'one query per schema the register owns');

		foreach ($this->queries as $index => $query) {
			self::assertArrayHasKey(
				'schema',
				($query['@self'] ?? []),
				'query ' . $index . ' reached searchObjects() without a schema, which OpenRegister answers [] to'
			);
			self::assertSame(
				7,
				($query['@self']['register'] ?? null),
				'query ' . $index . ' must still name the register'
			);
		}

		self::assertSame(
			['12', '99'],
			array_map(static fn (array $q): string => (string)$q['@self']['schema'], $this->queries),
			'the register\'s own schema list is the authority for which schemas to ask about'
		);
	}//end testEveryEmittedQueryNamesBothARegisterAndASchema()

	/**
	 * Rows from every schema come back, concatenated.
	 *
	 * @return void
	 */
	public function testRowsFromAllSchemasAreReturned(): void {
		$reader = $this->reader(rowsBySchema: ['12' => ['a', 'b'], '99' => ['c']]);

		self::assertSame(
			['a', 'b', 'c'],
			$reader->rowsInRegister(
				register: $this->buildRegister(id: 7, slug: 'shop', schemas: ['12', '99'])
			),
			'a register holds the rows of every schema it owns, not of one of them'
		);
	}//end testRowsFromAllSchemasAreReturned()

	/**
	 * A register owning no schemas holds no rows, and asks nothing.
	 *
	 * @return void
	 */
	public function testARegisterWithoutSchemasReadsEmptyWithoutAskingTheStore(): void {
		$reader = $this->reader(rowsBySchema: ['12' => ['a']]);

		self::assertSame(
			[],
			$reader->rowsInRegister(register: $this->buildRegister(id: 7, slug: 'shop', schemas: []))
		);
		self::assertSame([], $this->queries, 'no schemas means no question to ask');
	}//end testARegisterWithoutSchemasReadsEmptyWithoutAskingTheStore()

	/**
	 * A failed read RAISES rather than reporting an empty register.
	 *
	 * This is the second half of the defect: the callers act on the row list
	 * by deleting it, copying it or writing it to an export fixture, so
	 * "could not read" and "nothing there" must not be the same answer.
	 *
	 * Mutation check: replace the `throw` in rowsInRegister()'s catch with
	 * `continue` and this test reddens on the expectException, having
	 * silently returned the rows of the schemas that did answer.
	 *
	 * @return void
	 */
	public function testAFailedReadRaisesInsteadOfReportingAnEmptyRegister(): void {
		$reader = $this->reader(rowsBySchema: ['12' => ['a']], throwOn: '99');

		$this->expectException(RegisterRowReadFailedException::class);
		$this->expectExceptionMessage('Could not read rows of register "shop" for schema 99');

		$reader->rowsInRegister(
			register: $this->buildRegister(id: 7, slug: 'shop', schemas: ['12', '99'])
		);
	}//end testAFailedReadRaisesInsteadOfReportingAnEmptyRegister()

	/**
	 * The RBAC / multi-tenancy flags reach the store unchanged.
	 *
	 * The three callers all read as the system, so a reader that quietly
	 * re-enabled RBAC would filter rows an admin operation must see.
	 *
	 * @return void
	 */
	public function testTheRbacAndMultitenancyFlagsReachTheStore(): void {
		$seen = [];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturnCallback(
			static function (array $query, bool $_rbac = true, bool $_multitenancy = true) use (&$seen): array {
				$seen[] = [$_rbac, $_multitenancy];
				return [];
			}
		);

		(new RegisterRowReader($objectService))->rowsInRegister(
			register: $this->buildRegister(id: 7, slug: 'shop', schemas: ['12']),
			_rbac: false,
			_multitenancy: false
		);

		self::assertSame([[false, false]], $seen, 'a system read must not be re-filtered by RBAC');
	}//end testTheRbacAndMultitenancyFlagsReachTheStore()
}//end class
