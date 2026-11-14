<?php

/**
 * Unit tests for the task panel of a page layout: where the task list sits.
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
use OCA\Buildiq\Service\PageLayoutPresenter;
use OCA\Buildiq\Service\PageLayoutValidator;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The panel is declared in the register, checked on save and served as declared.
 *
 * @spec openspec/changes/pages-task-panel-beside-case-tabs/specs/page-layout-task-panel/spec.md
 */
#[CoversClass(PageLayoutBodyRules::class)]
#[CoversClass(PageLayoutPresenter::class)]
#[CoversClass(PageLayoutValidator::class)]
class PageLayoutTaskPanelTest extends TestCase {

	/**
	 * The bouwvergunning layout with three tabs and a task panel.
	 *
	 * @param array<string, mixed> $panel The panel.
	 *
	 * @return array<string, mixed> The layout.
	 */
	private static function layout(array $panel): array {
		return [
			'register' => 'dossiq',
			'schema' => 'case',
			'typeProperty' => 'caseType',
			'typeValue' => 'bouwvergunning',
			'tabs' => [
				['id' => 'overzicht', 'label' => 'Overzicht', 'kind' => 'fieldGroup', 'fields' => ['title']],
				['id' => 'documenten', 'label' => 'Documenten', 'kind' => 'leaf', 'ref' => 'documents'],
				['id' => 'besluiten', 'label' => 'Besluiten', 'kind' => 'leaf', 'ref' => 'decisions'],
			],
			'taskList' => ['columns' => [['field' => 'title']], 'panel' => $panel],
		];
	}//end layout()

	/**
	 * A side panel beside two of the layout's tabs, a tab panel and none are all accepted.
	 *
	 * @return void
	 */
	public function testTheThreePlacementsAreAccepted(): void {
		$rules = new PageLayoutBodyRules();

		$rules->assertTaskPanel(self::layout(['placement' => 'side', 'tabs' => ['overzicht', 'documenten'], 'collapsed' => true]));
		$rules->assertTaskPanel(self::layout(['placement' => 'tab']));
		$rules->assertTaskPanel(self::layout(['placement' => 'none']));
		$rules->assertTaskPanel(['taskList' => ['columns' => []]]);
		$rules->assertTaskPanel([]);

		$this->assertSame(['side', 'tab', 'none'], PageLayoutBodyRules::PANEL_PLACEMENTS);
	}//end testTheThreePlacementsAreAccepted()

	/**
	 * Each panel the save path refuses, with the message it names.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedPanels(): array {
		return [
			'unknown placement' => [self::layout(['placement' => 'floating']), 'unknown placement "floating"'],
			'no placement' => [self::layout(['tabs' => []]), 'unknown placement ""'],
			'removed tab' => [self::layout(['placement' => 'side', 'tabs' => ['overzicht', 'adviezen']]), 'a tab that no longer exists: "adviezen"'],
			'label instead of id' => [self::layout(['placement' => 'side', 'tabs' => ['Overzicht']]), 'a tab that no longer exists: "Overzicht"'],
			'not an object' => [['taskList' => ['panel' => 'side']], 'has to be an object'],
		];
	}//end refusedPanels()

	/**
	 * A panel with an unknown placement or tab is refused, naming it.
	 *
	 * @param array<string, mixed> $layout  The layout.
	 * @param string               $message A part of the expected message.
	 *
	 * @return void
	 */
	#[DataProvider('refusedPanels')]
	public function testARefusedPanelNamesWhy(array $layout, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		(new PageLayoutBodyRules())->assertTaskPanel($layout);
	}//end testARefusedPanelNamesWhy()

	/**
	 * An override's tabs are its base's, so only its placement is checked here.
	 *
	 * @return void
	 */
	public function testAnOverrideIsCheckedForItsPlacementOnly(): void {
		$override = [
			'layoutDelta' => ['taskList' => ['panel' => ['placement' => 'side', 'tabs' => ['besluiten']]]],
			'taskList' => ['panel' => ['placement' => 'side', 'tabs' => ['besluiten']]],
		];
		(new PageLayoutBodyRules())->assertTaskPanel($override);

		$override['taskList']['panel']['placement'] = 'floating';
		$this->expectException(InvalidArgumentException::class);
		(new PageLayoutBodyRules())->assertTaskPanel($override);
	}//end testAnOverrideIsCheckedForItsPlacementOnly()

	/**
	 * The validator every save goes through runs the panel check.
	 *
	 * @return void
	 */
	public function testTheSavePathRefusesARemovedTab(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('besluiten');

		$layout = self::layout(['placement' => 'side', 'tabs' => ['besluiten']]);
		array_pop($layout['tabs']);
		(new PageLayoutValidator())->validate($layout);
	}//end testTheSavePathRefusesARemovedTab()

	/**
	 * The register declares the panel: a side panel validates, an unknown placement does not.
	 *
	 * @return void
	 */
	public function testTheRegisterDeclaresThePanel(): void {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__.'/../../../lib/Settings/register.d/51-page-layouts.json')
		);
		$taskList = $fragment->components->schemas->pageLayout->properties->taskList;
		$validator = new Validator();

		$side = json_decode((string)json_encode(['panel' => ['placement' => 'side', 'tabs' => ['overzicht'], 'collapsed' => false]]));
		$floating = json_decode((string)json_encode(['panel' => ['placement' => 'floating']]));
		$noPlacement = json_decode((string)json_encode(['panel' => ['tabs' => []]]));

		$this->assertTrue($validator->validate($side, $taskList)->isValid());
		$this->assertFalse($validator->validate($floating, $taskList)->isValid());
		$this->assertFalse($validator->validate($noPlacement, $taskList)->isValid());
	}//end testTheRegisterDeclaresThePanel()

	/**
	 * The served task list carries the panel; `none` on a type layout does not fall
	 * through to the schema-wide side panel; a type layout without a task list inherits it.
	 *
	 * @return void
	 */
	public function testThePanelIsServedAsDeclared(): void {
		$presenter = new PageLayoutPresenter();
		$schemaWide = ['taskList' => ['panel' => ['placement' => 'side', 'tabs' => ['overzicht']]]];

		$served = $presenter->serve(['id' => 'l-1', 'taskList' => ['panel' => ['placement' => 'none']]], $schemaWide, []);
		$this->assertSame(['panel' => ['placement' => 'none']], $served['taskList']);

		$inherited = $presenter->serve(['id' => 'l-2'], $schemaWide, []);
		$this->assertSame(['placement' => 'side', 'tabs' => ['overzicht']], $inherited['taskList']['panel']);

		$this->assertNull($presenter->serve(['id' => 'l-3'], null, [])['taskList']);
	}//end testThePanelIsServedAsDeclared()

}//end class
