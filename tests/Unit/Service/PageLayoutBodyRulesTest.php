<?php

/**
 * Unit tests for PageLayoutBodyRules: the tab, widget and upload-field checks of a page layout.
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

use InvalidArgumentException;
use OCA\Buildiq\Service\PageLayoutBodyRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PageLayoutBodyRules.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Buildiq\Service\PageLayoutBodyRules
 */
class PageLayoutBodyRulesTest extends TestCase {

	/**
	 * A well-formed layout passes every check; non-array entries are skipped.
	 *
	 * @return void
	 */
	public function testAWellFormedLayoutPasses(): void {
		$layout = [
			'tabs' => [
				'not a tab',
				['label' => 'Leaf', 'kind' => 'leaf', 'ref' => 'leaf-1'],
				['label' => 'Fields', 'kind' => 'fieldGroup', 'fields' => ['name']],
				['label' => 'Related', 'kind' => 'relatedList', 'relatedRegister' => 'r', 'relatedSchema' => 's'],
				['label' => 'Widgets', 'kind' => 'widgets', 'widgets' => [['id' => 'w', 'width' => 'large']]],
			],
			'widgets' => ['x', ['id' => 'w1', 'conditions' => ['skip', ['operator' => 'contains']]]],
			'uploadFields' => ['x', ['field' => 'f'], ['field' => 'g', 'visibility' => 'hidden', 'default' => 'v']],
		];
		$rules = new PageLayoutBodyRules();

		$rules->assertTabs($layout);
		$rules->assertWidgets($layout);
		$rules->assertUploadFields($layout);
		$rules->assertWidgetList('not a list', 'nowhere');
		$rules->assertTabs([]);

		$this->addToAssertionCount(1);
	}//end testAWellFormedLayoutPasses()

	/**
	 * Every malformed tab is refused with a message naming it.
	 *
	 * @param array<string, mixed> $tab     The tab.
	 * @param string               $message The expected message.
	 *
	 * @return void
	 */
	#[DataProvider('badTabs')]
	public function testABadTabIsRefused(array $tab, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		(new PageLayoutBodyRules())->assertTabs(['tabs' => [$tab]]);
	}//end testABadTabIsRefused()

	/**
	 * Malformed tabs and their messages.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function badTabs(): array {
		return [
			'unknown kind' => [['label' => 'T', 'kind' => 'grid'], 'T has an unknown kind "grid"; expected one of leaf, widgets, fieldGroup, relatedList.'],
			'no label, no kind' => [[], 'a tab has an unknown kind ""'],
			'leaf without ref' => [['label' => 'L', 'kind' => 'leaf'], 'L is a leaf tab with no leaf id, so it would render an empty panel and no error.'],
			'field group empty' => [['label' => 'F', 'kind' => 'fieldGroup', 'fields' => []], 'F is a field group with no fields in it.'],
			'field group not a list' => [['label' => 'F', 'kind' => 'fieldGroup', 'fields' => 'name'], 'F is a field group with no fields in it.'],
			'related list without schema' => [
				['label' => 'R', 'kind' => 'relatedList', 'relatedRegister' => 'r'],
				'R is a related list that does not say which register and schema to list.',
			],
			'widget inside a tab' => [
				['label' => 'W', 'kind' => 'widgets', 'widgets' => [['width' => 'small']]],
				'A widget on W does not say which widget it is.',
			],
		];
	}//end badTabs()

	/**
	 * Every malformed widget is refused with a message naming it.
	 *
	 * @param array<string, mixed> $widget  The widget.
	 * @param string               $message The expected message.
	 *
	 * @return void
	 */
	#[DataProvider('badWidgets')]
	public function testABadWidgetIsRefused(array $widget, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		(new PageLayoutBodyRules())->assertWidgets(['widgets' => [$widget]]);
	}//end testABadWidgetIsRefused()

	/**
	 * Malformed widgets and their messages.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function badWidgets(): array {
		return [
			'no id' => [['id' => ''], 'A widget on the page does not say which widget it is.'],
			'unknown width' => [['id' => 'w', 'width' => 'huge'], '"w" has width "huge"; the grid knows small, medium, large, extraLarge.'],
			'unknown operator' => [
				['id' => 'w', 'conditions' => [['operator' => 'like']]],
				'"w" has a condition with operator "like", which nothing evaluates; expected one of equals, notEquals, isEmpty, isNotEmpty, contains.',
			],
			'missing operator' => [['id' => 'w', 'conditions' => [[]]], '"w" has a condition with operator ""'],
		];
	}//end badWidgets()

	/**
	 * An unknown visibility, or a hidden field with no default, is refused.
	 *
	 * @return void
	 */
	public function testBadUploadFieldsAreRefused(): void {
		$rules = new PageLayoutBodyRules();

		try {
			$rules->assertUploadFields(['uploadFields' => [['field' => 'f', 'visibility' => 'secret']]]);
			$this->fail('An unknown visibility was accepted.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('"f" has visibility "secret"; expected one of editable, readOnly, hidden.', $e->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('"g" is hidden with no default, so nothing can ever fill it. Give it a default or make it read-only.');
		$rules->assertUploadFields(['uploadFields' => [['field' => 'g', 'visibility' => 'hidden', 'default' => '']]]);
	}//end testBadUploadFieldsAreRefused()
}//end class
