<?php

/**
 * Unit tests for the registration-forms register fragment.
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
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004, REQ-OBRF-007, REQ-OBRF-008)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the fragment declares everything the provider and the validator read.
 */
final class RegistrationFormFragmentTest extends TestCase {
	/**
	 * Absolute path to the fragment.
	 *
	 * @var string
	 */
	private string $path = __DIR__ . '/../../../lib/Settings/register.d/50-registration-forms.json';

	/**
	 * Decode the fragment's schemas.
	 *
	 * @return array<string, mixed> The schemas.
	 */
	private function schemas(): array {
		$data = json_decode((string)file_get_contents($this->path), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());

		return $data['components']['schemas'];
	}//end schemas()

	/**
	 * The fragment ships both schemas.
	 *
	 * @return void
	 */
	public function testTheFragmentDeclaresBothSchemas(): void {
		self::assertFileExists($this->path);

		$schemas = $this->schemas();
		self::assertArrayHasKey('registrationForm', $schemas);
		self::assertArrayHasKey('formDraft', $schemas);
	}//end testTheFragmentDeclaresBothSchemas()

	/**
	 * Every property the provider and the validator read is declared.
	 * OpenRegister DROPS an undeclared field in silence, so a form saved with a
	 * property the schema does not know would lose it with no error anywhere.
	 *
	 * @return void
	 */
	public function testEveryPropertyTheCodeReadsIsDeclared(): void {
		$properties = $this->schemas()['registrationForm']['properties'];

		foreach (
			[
				'name',
				'audience',
				'isDefault',
				'channel',
				'isPublic',
				'confirmationText',
				'targetApp',
				'register',
				'schema',
				'typeProperty',
				'typeValue',
				'sections',
				'fields',
				'steps',
				'formLogic',
				'presets',
				'allowSaveForLater',
				'status',
			] as $property
		) {
			self::assertArrayHasKey($property, $properties, $property . ' is missing from registrationForm');
		}
	}//end testEveryPropertyTheCodeReadsIsDeclared()

	/**
	 * A preset carries the field, the value and whether the filer sees it.
	 *
	 * @return void
	 */
	public function testAPresetCarriesItsFieldValueAndVisibility(): void {
		$presets = $this->schemas()['registrationForm']['properties']['presets'];

		self::assertSame('array', $presets['type']);
		self::assertSame(['field', 'value'], $presets['items']['required']);
		self::assertArrayHasKey('hidden', $presets['items']['properties']);
	}//end testAPresetCarriesItsFieldValueAndVisibility()

	/**
	 * A field carries its section and its order, which is what lets the form own
	 * its own arrangement (REQ-OBRF-008).
	 *
	 * @return void
	 */
	public function testAFieldCarriesItsSectionAndOrder(): void {
		$fields = $this->schemas()['registrationForm']['properties']['fields'];

		self::assertArrayHasKey('section', $fields['items']['properties']);
		self::assertArrayHasKey('order', $fields['items']['properties']);
	}//end testAFieldCarriesItsSectionAndOrder()

	/**
	 * The audience enum is exactly the three the validator accepts. A fourth in
	 * the schema would be an audience nothing resolves a default for.
	 *
	 * @return void
	 */
	public function testTheAudienceEnumMatchesTheValidator(): void {
		self::assertSame(
			['client', 'internal', 'supplier'],
			$this->schemas()['registrationForm']['properties']['audience']['enum']
		);
	}//end testTheAudienceEnumMatchesTheValidator()

	/**
	 * A draft is tied to a form and to a person, and both are required. A draft
	 * with no owner is a draft anybody can read.
	 *
	 * @return void
	 */
	public function testADraftIsTiedToAFormAndAPerson(): void {
		self::assertSame(['registrationFormId', 'userId'], $this->schemas()['formDraft']['required']);
	}//end testADraftIsTiedToAFormAndAPerson()

	/**
	 * Both schemas are audited: one is what a citizen was asked, the other holds
	 * their answers.
	 *
	 * @return void
	 */
	public function testBothSchemasAreAudited(): void {
		foreach (['registrationForm', 'formDraft'] as $slug) {
			self::assertTrue($this->schemas()[$slug]['x-openregister-audit-trail']['enabled'], $slug);
		}
	}//end testBothSchemasAreAudited()
}//end class
