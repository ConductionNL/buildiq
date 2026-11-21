<?php

/**
 * Unit tests for PageCodeGuard.
 *
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-only-owners-write-code-and-only-when-allowed-req-bqcc-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\PageCodeGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The code-bearing keys: config.code, and every expression and expressionDefault.
 */
#[CoversClass(PageCodeGuard::class)]
final class PageCodeGuardTest extends TestCase {

	/**
	 * A manifest with code in each of the places design D1 names.
	 *
	 * @return array<string, mixed>
	 */
	private function manifest(): array {
		return [
			'pages' => [
				['id' => 'home', 'config' => ['code' => ['html' => '<p/>', 'js' => 'x()']]],
				['id' => 'list', 'config' => [
					'columns' => [['key' => 'title'], ['key' => 'age', 'expression' => 'row.age']],
					'widgets' => [['id' => 'w', 'config' => ['code' => ['js' => 'y()']]]],
				]],
				['id' => 'form', 'config' => ['fields' => [['key' => 'a', 'expressionDefault' => 'user.id', 'visibleWhen' => ['expression' => 'values.a']]]]],
			],
			'code' => 'a top-level key named code is not a code component',
		];
	}//end manifest()

	/**
	 * Every code-bearing key is found by path, and nothing else.
	 *
	 * @return void
	 */
	public function testEveryCodeKeyIsFound(): void {
		$this->assertSame(
			[
				'/pages/0/config/code',
				'/pages/1/config/columns/1/expression',
				'/pages/1/config/widgets/0/config/code',
				'/pages/2/config/fields/0/expressionDefault',
				'/pages/2/config/fields/0/visibleWhen/expression',
			],
			array_keys((new PageCodeGuard())->codeKeys(manifest: $this->manifest()))
		);
	}//end testEveryCodeKeyIsFound()

	/**
	 * Adding, changing and removing code are changes; moving other things around is not.
	 *
	 * @return void
	 */
	public function testOnlyAChangeToCodeIsAChange(): void {
		$guard  = new PageCodeGuard();
		$before = $this->manifest();

		$other = $before;
		$other['pages'][1]['config']['columns'][0]['label'] = 'Title';
		$other['version'] = '2.0.0';
		$this->assertFalse($guard->changesCode(before: $before, after: $other));

		$changed = $before;
		$changed['pages'][1]['config']['columns'][1]['expression'] = 'row.age + 1';
		$this->assertTrue($guard->changesCode(before: $before, after: $changed));

		$removed = $before;
		unset($removed['pages'][0]['config']['code']);
		$this->assertTrue($guard->changesCode(before: $before, after: $removed));

		$this->assertTrue($guard->changesCode(before: null, after: $before));
		$this->assertFalse($guard->changesCode(before: null, after: ['pages' => [['id' => 'x', 'config' => []]]]));
	}//end testOnlyAChangeToCodeIsAChange()

	/**
	 * A config is stripped of its code-bearing keys and the removed paths are named.
	 *
	 * @return void
	 */
	public function testAConfigIsStrippedOfCode(): void {
		[$cleaned, $removed] = (new PageCodeGuard())->stripConfig(config: $this->manifest()['pages'][1]['config']);

		$this->assertSame(['columns' => [['key' => 'title'], ['key' => 'age']], 'widgets' => [['id' => 'w', 'config' => []]]], $cleaned);
		$this->assertSame(['config/columns/1/expression', 'config/widgets/0/config/code'], $removed);
	}//end testAConfigIsStrippedOfCode()
}//end class
