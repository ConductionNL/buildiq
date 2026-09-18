<?php

/**
 * Unit tests for PageLayoutValidator.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-001, REQ-OBPL-002, REQ-OBPL-004, REQ-OBPL-005, REQ-OBPL-009)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Buildiq\Service\PageLayoutValidator;
use PHPUnit\Framework\TestCase;

/**
 * Covers the uniqueness, the per-kind references, the widths and the
 * hidden-needs-a-default rule.
 */
final class PageLayoutValidatorTest extends TestCase {
	/**
	 * The validator under test.
	 *
	 * @var PageLayoutValidator
	 */
	private PageLayoutValidator $validator;

	/**
	 * Build the validator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->validator = new PageLayoutValidator();
	}//end setUp()

	/**
	 * A published layout for one case type.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The layout.
	 */
	private function layout(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'pl-1',
				'status' => 'published',
				'targetApp' => 'dossiq',
				'register' => 'dossiq',
				'schema' => 'Zaak',
				'typeProperty' => 'caseType',
				'typeValue' => 'bouwvergunning',
				'tabs' => [],
			],
			$overrides
		);
	}//end layout()

	/**
	 * A second published layout for the same type is refused, naming the one that
	 * already holds it (REQ-OBPL-001).
	 *
	 * @return void
	 */
	public function testASecondPublishedLayoutForOneTypeIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('pl-1');

		$this->validator->validate($this->layout(['id' => 'pl-2']), [$this->layout()]);
	}//end testASecondPublishedLayoutForOneTypeIsRefused()

	/**
	 * A draft may sit beside the published layout it will replace.
	 *
	 * @return void
	 */
	public function testADraftMaySitBesideThePublishedLayout(): void {
		$this->validator->validate($this->layout(['id' => 'pl-2', 'status' => 'draft']), [$this->layout()]);

		self::assertTrue(true);
	}//end testADraftMaySitBesideThePublishedLayout()

	/**
	 * A layout for another type does not collide.
	 *
	 * @return void
	 */
	public function testALayoutForAnotherTypeDoesNotCollide(): void {
		$this->validator->validate($this->layout(['id' => 'pl-2', 'typeValue' => 'melding']), [$this->layout()]);

		self::assertTrue(true);
	}//end testALayoutForAnotherTypeDoesNotCollide()

	/**
	 * A leaf tab with no leaf id is refused: it would render an empty panel and
	 * no error (REQ-OBPL-004).
	 *
	 * @return void
	 */
	public function testALeafTabWithNoLeafIdIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Documenten');

		$this->validator->validate(
			$this->layout(['tabs' => [['kind' => 'leaf', 'label' => 'Documenten']]])
		);
	}//end testALeafTabWithNoLeafIdIsRefused()

	/**
	 * A related list that does not say what to list is refused for the same
	 * reason.
	 *
	 * @return void
	 */
	public function testARelatedListWithNoTargetIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('register and schema');

		$this->validator->validate(
			$this->layout(['tabs' => [['kind' => 'relatedList', 'label' => 'Taken', 'relatedRegister' => 'dossiq']]])
		);
	}//end testARelatedListWithNoTargetIsRefused()

	/**
	 * An empty field group is refused.
	 *
	 * @return void
	 */
	public function testAnEmptyFieldGroupIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->validator->validate(
			$this->layout(['tabs' => [['kind' => 'fieldGroup', 'label' => 'Gegevens', 'fields' => []]]])
		);
	}//end testAnEmptyFieldGroupIsRefused()

	/**
	 * A well-formed set of three tabs passes.
	 *
	 * @return void
	 */
	public function testThreeWellFormedTabsPass(): void {
		$this->validator->validate(
			$this->layout(
				[
					'tabs' => [
						['kind' => 'leaf', 'label' => 'Documenten', 'ref' => 'filinq-documents', 'order' => 1],
						['kind' => 'widgets', 'label' => 'Overzicht', 'order' => 2, 'widgets' => [['id' => 'cn-timeline', 'width' => 'large']]],
						['kind' => 'fieldGroup', 'label' => 'Gegevens', 'fields' => ['location'], 'order' => 3],
					],
				]
			)
		);

		self::assertTrue(true);
	}//end testThreeWellFormedTabsPass()

	/**
	 * An unknown tab kind is refused, naming the four that exist.
	 *
	 * @return void
	 */
	public function testAnUnknownTabKindIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('leaf, widgets, fieldGroup, relatedList');

		$this->validator->validate($this->layout(['tabs' => [['kind' => 'iframe', 'label' => 'Extern']]]));
	}//end testAnUnknownTabKindIsRefused()

	/**
	 * A width the grid does not know is refused, naming the four allowed values.
	 * Such a width renders at zero, which looks exactly like a widget that failed
	 * to load (REQ-OBPL-005).
	 *
	 * @return void
	 */
	public function testAnUnknownWidgetWidthIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('small, medium, large, extraLarge');

		$this->validator->validate($this->layout(['widgets' => [['id' => 'cn-timeline', 'width' => 'huge']]]));
	}//end testAnUnknownWidgetWidthIsRefused()

	/**
	 * A condition operator nothing evaluates is refused at save, rather than
	 * quietly dropping the widget at serve time.
	 *
	 * @return void
	 */
	public function testAnUnknownConditionOperatorIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('nothing evaluates');

		$this->validator->validate(
			$this->layout(['widgets' => [['id' => 'cn-decision', 'conditions' => [['field' => 'x', 'operator' => 'startsWith']]]]])
		);
	}//end testAnUnknownConditionOperatorIsRefused()

	/**
	 * A hidden upload field with no default is refused: nothing could ever fill
	 * it, so every document on this type would be stored missing it
	 * (REQ-OBPL-009).
	 *
	 * @return void
	 */
	public function testAHiddenUploadFieldWithNoDefaultIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('hidden with no default');

		$this->validator->validate(
			$this->layout(['uploadFields' => [['field' => 'documentType', 'visibility' => 'hidden']]])
		);
	}//end testAHiddenUploadFieldWithNoDefaultIsRefused()

	/**
	 * A hidden field WITH a default is fine: the default is what fills it.
	 *
	 * @return void
	 */
	public function testAHiddenUploadFieldWithADefaultIsFine(): void {
		$this->validator->validate(
			$this->layout(['uploadFields' => [['field' => 'documentType', 'visibility' => 'hidden', 'default' => 'bijlage']]])
		);

		self::assertTrue(true);
	}//end testAHiddenUploadFieldWithADefaultIsFine()

	/**
	 * A read-only field with a default is the locked-and-prefilled case, and it
	 * passes.
	 *
	 * @return void
	 */
	public function testAReadOnlyUploadFieldWithADefaultPasses(): void {
		$this->validator->validate(
			$this->layout(['uploadFields' => [['field' => 'confidentiality', 'visibility' => 'readOnly', 'default' => 'intern']]])
		);

		self::assertTrue(true);
	}//end testAReadOnlyUploadFieldWithADefaultPasses()

	/**
	 * A leaf tab pointing at a leaf no installed app offers WARNS rather than
	 * refusing. Refusing would make a layout unsaveable on a machine that is
	 * simply missing an app (REQ-OBPL-002).
	 *
	 * @return void
	 */
	public function testAnUnknownLeafRefWarnsAndDoesNotRefuse(): void {
		$warnings = $this->validator->validate(
			$this->layout(['tabs' => [['kind' => 'leaf', 'label' => 'Documenten', 'ref' => 'filinq-documents']]]),
			[],
			['shillinq-payment-requests']
		);

		self::assertCount(1, $warnings);
		self::assertStringContainsString('filinq-documents', $warnings[0]);
	}//end testAnUnknownLeafRefWarnsAndDoesNotRefuse()

	/**
	 * A registry that cannot be read warns about nothing rather than warning
	 * about everything.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegistryWarnsAboutNothing(): void {
		$warnings = $this->validator->validate(
			$this->layout(['tabs' => [['kind' => 'leaf', 'label' => 'Documenten', 'ref' => 'filinq-documents']]]),
			[],
			null
		);

		self::assertSame([], $warnings);
	}//end testAnUnreadableRegistryWarnsAboutNothing()
}//end class
