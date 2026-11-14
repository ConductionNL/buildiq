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
				'minTrust',
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
	 * A draft is the owner's alone: a signed-in user may start one, only the user
	 * it belongs to reads it, and update and delete are left out so only the owner
	 * may change it. It expires 30 days after it was created (REQ-OBRF-001).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registration-form-builder/specs/registration-form-builder/spec.md#requirement-a-registration-form-is-bound-to-one-type-value-req-obrf-001
	 */
	public function testADraftIsOwnerOnlyAndExpiresAfterThirtyDays(): void {
		$draft = $this->schemas()['formDraft'];

		self::assertSame(
			[
				'create' => ['authenticated'],
				'read' => [['group' => 'authenticated', 'match' => ['userId' => '$userId']]],
			],
			$draft['authorization']
		);
		self::assertSame(['retention' => ['default' => 'P30D']], $draft['x-openregister-archival']);
	}//end testADraftIsOwnerOnlyAndExpiresAfterThirtyDays()

	/**
	 * The demo data seeds one published form for the dossiq bouwvergunning case
	 * type, with two phases and one visibleWhen rule over a field it asks.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/registration-form-builder/specs/registration-form-builder/spec.md#requirement-the-form-builder-groups-questions-per-phase-with-rules-and-steps-req-obrf-002
	 */
	public function testABouwvergunningFormIsSeededWithTwoPhasesAndARule(): void {
		$demo = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/buildiq_mock_register.json'), true);
		$forms = array_values(
			array_filter(
				$demo['components']['objects'],
				static fn (array $object): bool => ($object['@self']['schema'] ?? '') === 'registrationForm'
					&& ($object['typeValue'] ?? '') === 'bouwvergunning'
			)
		);

		self::assertCount(1, $forms);
		$form = $forms[0];
		self::assertSame(['published', 'dossiq', 'dossiq', 'case', 'caseType'], [$form['status'], $form['targetApp'], $form['register'], $form['schema'], $form['typeProperty']]);
		self::assertSame(['Aanvrager', 'Bouwwerk'], array_column($form['steps'], 'phase'));

		$names = array_column($form['fields'], 'name');
		$stepFields = array_merge(...array_column($form['steps'], 'fields'));
		self::assertSame($names, $stepFields, 'Every field sits in exactly one step.');
		$rules = array_values(array_filter(array_column($form['fields'], 'visibleWhen')));
		self::assertCount(1, $rules);
		self::assertContains($rules[0]['field'], $names);
	}//end testABouwvergunningFormIsSeededWithTwoPhasesAndARule()

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

	/**
	 * The sign-in level carries exactly the values portaliq enforces, and no
	 * `anonymous` value: absence means anonymous (buildiq#935, portaliq#725).
	 *
	 * @return void
	 */
	public function testTheSignInLevelIsPortaliqsValueSet(): void {
		$property = $this->schemas()['registrationForm']['properties']['minTrust'];

		self::assertSame('string', $property['type']);
		self::assertSame(['low', 'substantial', 'high'], $property['enum']);
		self::assertNotContains('minTrust', $this->schemas()['registrationForm']['required']);
	}//end testTheSignInLevelIsPortaliqsValueSet()
}//end class
