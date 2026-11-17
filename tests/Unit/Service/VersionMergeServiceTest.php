<?php

/**
 * Unit tests for the keyed three-way version merge.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
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
 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Buildiq\Service\VersionMergeService;
use PHPUnit\Framework\TestCase;

/**
 * One-sided changes are taken, equal changes taken once, different changes and
 * delete-against-change are conflicts, key order is never a change, and a
 * pair with no common base is refused.
 *
 * @covers \OCA\Buildiq\Service\VersionMergeService
 *
 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
 */
final class VersionMergeServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var VersionMergeService
	 */
	private VersionMergeService $merger;

	/**
	 * Set up the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->merger = new VersionMergeService();
	}//end setUp()

	/**
	 * A version state.
	 *
	 * @param array<int,array<string,mixed>> $pages   The pages.
	 * @param array<string,mixed>            $extra   Other manifest keys.
	 * @param array<string,mixed>            $schemas The schemas by slug.
	 *
	 * @return array{manifest: array<string,mixed>, schemas: array<string,mixed>}
	 */
	private function state(array $pages, array $extra = [], array $schemas = []): array {
		return ['manifest' => ['version' => '1.0.0', 'pages' => $pages] + $extra, 'schemas' => $schemas];
	}//end state()

	/**
	 * The `permits` page with the given columns.
	 *
	 * @param array<int,string> $columns The columns.
	 *
	 * @return array<string,mixed>
	 */
	private function permits(array $columns): array {
		return ['id' => 'permits', 'type' => 'index', 'config' => ['columns' => $columns, 'register' => 'vergunningen']];
	}//end permits()

	/**
	 * A page the branch added is taken, a page only the target changed stays, and nothing conflicts.
	 *
	 * @return void
	 */
	public function testOneSidedChangesAreTaken(): void {
		$base = $this->state([$this->permits(['name']), ['id' => 'home', 'title' => 'Home']]);
		$source = $this->state([$this->permits(['name']), ['id' => 'home', 'title' => 'Home'], ['id' => 'fees', 'title' => 'Fees']]);
		$target = $this->state([$this->permits(['name']), ['id' => 'home', 'title' => 'Start']]);

		$result = $this->merger->merge(base: $base, source: $source, target: $target);

		$this->assertSame([], $result['conflicts']);
		$this->assertSame(['permits', 'home', 'fees'], array_column($result['manifest']['pages'], 'id'));
		$this->assertSame('Start', $result['manifest']['pages'][1]['title']);
	}//end testOneSidedChangesAreTaken()

	/**
	 * The same change on both sides is taken once, without a conflict.
	 *
	 * @return void
	 */
	public function testTheSameChangeOnBothSidesIsNoConflict(): void {
		$base = $this->state([$this->permits(['name'])]);
		$both = $this->state([$this->permits(['name', 'status'])]);

		$result = $this->merger->merge(base: $base, source: $both, target: $both);

		$this->assertSame([], $result['conflicts']);
		$this->assertSame(['name', 'status'], $result['manifest']['pages'][0]['config']['columns']);
		$this->assertCount(1, $result['manifest']['pages']);
	}//end testTheSameChangeOnBothSidesIsNoConflict()

	/**
	 * Both sides changed the columns of `permits` differently: a conflict with
	 * both sides, the target kept until a side is chosen, and apply refusing
	 * without a choice.
	 *
	 * @return void
	 */
	public function testDifferentChangesOnBothSidesAreAConflict(): void {
		$base = $this->state([$this->permits(['name'])]);
		$source = $this->state([$this->permits(['name', 'fee'])]);
		$target = $this->state([$this->permits(['name', 'status'])]);

		$result = $this->merger->merge(base: $base, source: $source, target: $target);

		$this->assertCount(1, $result['conflicts']);
		$conflict = $result['conflicts'][0];
		$this->assertSame('page:permits', $conflict['unit']);
		$this->assertSame('page', $conflict['kind']);
		$this->assertSame(['name', 'fee'], $conflict['source']['config']['columns']);
		$this->assertSame(['name', 'status'], $conflict['target']['config']['columns']);
		$this->assertSame(['name'], $conflict['base']['config']['columns']);
		$this->assertSame(['name', 'status'], $result['manifest']['pages'][0]['config']['columns']);

		try {
			$this->merger->apply(base: $base, source: $source, target: $target, choices: []);
			$this->fail('A merge with an unresolved conflict was applied.');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('page:permits', $e->getMessage());
		}

		$taken = $this->merger->apply(base: $base, source: $source, target: $target, choices: ['page:permits' => VersionMergeService::TAKE_SOURCE]);
		$this->assertSame(['name', 'fee'], $taken['manifest']['pages'][0]['config']['columns']);
		$kept = $this->merger->apply(base: $base, source: $source, target: $target, choices: ['page:permits' => VersionMergeService::TAKE_TARGET]);
		$this->assertSame(['name', 'status'], $kept['manifest']['pages'][0]['config']['columns']);
	}//end testDifferentChangesOnBothSidesAreAConflict()

	/**
	 * Deleted on one side and changed on the other is a conflict; deleted on one side only is deleted.
	 *
	 * @return void
	 */
	public function testDeleteAgainstChangeIsAConflict(): void {
		$base = $this->state([$this->permits(['name']), ['id' => 'home', 'title' => 'Home']]);
		$source = $this->state([['id' => 'home', 'title' => 'Home']]);
		$target = $this->state([$this->permits(['name', 'status'])]);

		$result = $this->merger->merge(base: $base, source: $source, target: $target);

		$this->assertSame(['page:permits'], array_column($result['conflicts'], 'unit'));
		$this->assertNull($result['conflicts'][0]['source']);
		$this->assertSame([], array_values(array_filter($result['manifest']['pages'], static fn (array $page): bool => $page['id'] === 'home')), 'home was deleted by the target only.');

		$deleted = $this->merger->apply(base: $base, source: $source, target: $target, choices: ['page:permits' => VersionMergeService::TAKE_SOURCE]);
		$this->assertSame([], $deleted['manifest']['pages']);
	}//end testDeleteAgainstChangeIsAConflict()

	/**
	 * Key order and formatting are never a change.
	 *
	 * @return void
	 */
	public function testKeyOrderIsNoChange(): void {
		$base = $this->state([$this->permits(['name'])], ['theme' => ['primary' => '#00f', 'logo' => 'a.svg']]);
		$source = $this->state(
			[['config' => ['register' => 'vergunningen', 'columns' => ['name']], 'type' => 'index', 'id' => 'permits']],
			['theme' => ['logo' => 'a.svg', 'primary' => '#00f']]
		);
		$target = $this->state([$this->permits(['name'])], ['theme' => ['primary' => '#f00', 'logo' => 'a.svg']]);

		$result = $this->merger->merge(base: $base, source: $source, target: $target);

		$this->assertSame([], $result['conflicts']);
		$this->assertSame('#f00', $result['manifest']['theme']['primary']);
		$this->assertSame($this->permits(['name']), $result['manifest']['pages'][0], 'The target keeps its own form of an unchanged page.');
	}//end testKeyOrderIsNoChange()

	/**
	 * Schemas merge property by property; the same property changed differently is a conflict.
	 *
	 * @return void
	 */
	public function testSchemasMergePropertyByProperty(): void {
		$permit = ['title' => 'Permit', 'properties' => ['name' => ['type' => 'string'], 'status' => ['type' => 'string']]];
		$base = $this->state([], [], ['permit' => $permit]);
		$source = $this->state([], [], ['permit' => ['title' => 'Permit', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 80], 'status' => ['type' => 'string'], 'fee' => ['type' => 'number']]]]);
		$target = $this->state([], [], ['permit' => ['title' => 'Vergunning', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 120], 'status' => ['type' => 'string']]]]);

		$result = $this->merger->merge(base: $base, source: $source, target: $target);

		$this->assertSame(['property:permit.name'], array_column($result['conflicts'], 'unit'));
		$schema = $result['schemas']['permit'];
		$this->assertSame('Vergunning', $schema['title']);
		$this->assertSame(['name', 'status', 'fee'], array_keys($schema['properties']));
		$this->assertSame(['type' => 'number'], $schema['properties']['fee']);
		$this->assertSame(120, $schema['properties']['name']['maxLength']);
	}//end testSchemasMergePropertyByProperty()

	/**
	 * Deleting a schema on one side while the other changed one of its properties is a conflict.
	 *
	 * @return void
	 */
	public function testDeletingASchemaAgainstAChangedPropertyIsAConflict(): void {
		$permit = ['title' => 'Permit', 'properties' => ['name' => ['type' => 'string']]];
		$base = $this->state([], [], ['permit' => $permit]);
		$source = $this->state([], [], []);
		$target = $this->state([], [], ['permit' => ['title' => 'Permit', 'properties' => ['name' => ['type' => 'string', 'maxLength' => 9]]]]);

		$result = $this->merger->merge(base: $base, source: $source, target: $target);

		$this->assertSame(['schema:permit'], array_column($result['conflicts'], 'unit'));
		$this->assertArrayHasKey('permit', $result['schemas']);
	}//end testDeletingASchemaAgainstAChangedPropertyIsAConflict()

	/**
	 * The base is the branchBase of whichever version was branched from the
	 * other; a pair with no recorded common base is refused.
	 *
	 * @return void
	 */
	public function testThePairNeedsARecordedCommonBase(): void {
		$snapshot = ['source' => 'development', 'manifestHash' => str_repeat('a', 64), 'manifest' => ['pages' => []], 'schemas' => ['permit' => []]];
		$branch = ['slug' => 'permit-fees', 'branchBase' => $snapshot];
		$development = ['slug' => 'development'];

		$this->assertSame(['manifest' => ['pages' => []], 'schemas' => ['permit' => []]], $this->merger->baseFor(source: $branch, target: $development));
		$this->assertSame(['manifest' => ['pages' => []], 'schemas' => ['permit' => []]], $this->merger->baseFor(source: $development, target: $branch));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('no recorded common base');
		$this->merger->baseFor(source: $branch, target: ['slug' => 'staging']);
	}//end testThePairNeedsARecordedCommonBase()
}//end class
