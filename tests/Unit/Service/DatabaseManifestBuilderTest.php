<?php

/**
 * Unit tests for DatabaseManifestBuilder.
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
 *
 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Buildiq\Service\DatabaseManifestBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DatabaseManifestBuilder.
 *
 * @covers \OCA\Buildiq\Service\DatabaseManifestBuilder
 */
class DatabaseManifestBuilderTest extends TestCase {

	/**
	 * The permits table of the scenario.
	 *
	 * @var array<string, mixed>
	 */
	private const PERMITS = [
		'slug' => 'permits',
		'properties' => [
			'number' => ['type' => 'string'],
			'issued' => ['type' => 'string', 'format' => 'date'],
			'fee' => ['type' => ['number', 'null']],
			'documents' => ['type' => 'array', 'items' => ['type' => 'string']],
		],
	];

	/**
	 * The applicants table of the scenario.
	 *
	 * @var array<string, mixed>
	 */
	private const APPLICANTS = [
		'slug' => 'permit_applicants',
		'title' => 'Applicants',
		'properties' => [
			'name' => ['type' => 'string'],
			'address' => ['type' => 'object'],
			'active' => ['type' => 'boolean'],
		],
	];

	/**
	 * Two tables give two menu entries, each with a list page and a detail page on the database register.
	 *
	 * @return void
	 */
	public function testTwoTablesGiveTwoMenuEntriesWithTheirPages(): void {
		$manifest = (new DatabaseManifestBuilder())->build(register: 'permits-db', schemas: [self::PERMITS, self::APPLICANTS], writable: true);

		$this->assertSame(
			[
				['id' => 'permits', 'label' => 'Permits', 'route' => 'PermitsIndex', 'order' => 10],
				['id' => 'permit-applicants', 'label' => 'Applicants', 'route' => 'PermitApplicantsIndex', 'order' => 20],
			],
			$manifest['menu']
		);
		$this->assertSame(
			[
				[
					'id' => 'PermitsIndex', 'route' => '/permits', 'type' => 'index', 'title' => 'Permits',
					'config' => ['register' => 'permits-db', 'schema' => 'permits', 'columns' => ['number', 'issued', 'fee']],
				],
				[
					'id' => 'PermitsDetail', 'route' => '/permits/:id', 'type' => 'detail', 'title' => 'Permits',
					'config' => ['register' => 'permits-db', 'schema' => 'permits'],
				],
				[
					'id' => 'PermitApplicantsIndex', 'route' => '/permit-applicants', 'type' => 'index', 'title' => 'Applicants',
					'config' => ['register' => 'permits-db', 'schema' => 'permit_applicants', 'columns' => ['name', 'active']],
				],
				[
					'id' => 'PermitApplicantsDetail', 'route' => '/permit-applicants/:id', 'type' => 'detail', 'title' => 'Applicants',
					'config' => ['register' => 'permits-db', 'schema' => 'permit_applicants'],
				],
			],
			$manifest['pages']
		);
		$this->assertSame('1.0.0', $manifest['version']);
	}//end testTwoTablesGiveTwoMenuEntriesWithTheirPages()

	/**
	 * A wide table starts with its first eight scalar columns, in table order.
	 *
	 * @return void
	 */
	public function testAWideTableStartsWithItsFirstEightScalarColumns(): void {
		$properties = ['blob' => ['type' => 'object']];
		foreach (range(1, 12) as $n) {
			$properties['c' . $n] = ['type' => 'integer'];
		}

		$manifest = (new DatabaseManifestBuilder())->build(register: 'db', schemas: [['slug' => 'wide', 'properties' => $properties]], writable: true);

		$this->assertSame(['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7', 'c8'], $manifest['pages'][0]['config']['columns']);
	}//end testAWideTableStartsWithItsFirstEightScalarColumns()

	/**
	 * A read-only source gives pages without add, edit or delete.
	 *
	 * @return void
	 */
	public function testAReadOnlySourceGivesPagesWithoutWrites(): void {
		$pages = (new DatabaseManifestBuilder())->build(register: 'permits-db', schemas: [self::PERMITS], writable: false)['pages'];

		$this->assertFalse($pages[0]['config']['showAdd']);
		$this->assertFalse($pages[0]['config']['showEditAction']);
		$this->assertFalse($pages[0]['config']['showDeleteAction']);
		$this->assertSame(['register' => 'permits-db', 'schema' => 'permits', 'showEditAction' => false], $pages[1]['config']);
	}//end testAReadOnlySourceGivesPagesWithoutWrites()

	/**
	 * A table without a slug or with one already used is left out; no usable table at all is refused.
	 *
	 * @return void
	 */
	public function testUnusableTablesAreLeftOutAndNoTableIsRefused(): void {
		$schemas  = [self::PERMITS, ['slug' => 'Permits'], ['title' => 'No slug'], ['slug' => '--'], 'permits'];
		$manifest = (new DatabaseManifestBuilder())->build(register: 'db', schemas: $schemas, writable: true);
		$this->assertSame(['PermitsIndex', 'PermitsDetail'], array_column($manifest['pages'], 'id'));

		try {
			(new DatabaseManifestBuilder())->build(register: 'db', schemas: [['slug' => ' ']], writable: true);
			$this->fail('A build without a table was accepted.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Choose at least one table of the database.', $e->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A database register is required.');
		(new DatabaseManifestBuilder())->build(register: ' ', schemas: [self::PERMITS], writable: true);
	}//end testUnusableTablesAreLeftOutAndNoTableIsRefused()
}//end class
