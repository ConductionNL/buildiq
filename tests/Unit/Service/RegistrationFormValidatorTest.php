<?php

/**
 * Unit tests for RegistrationFormValidator.
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
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004, REQ-OBRF-005, REQ-OBRF-007, REQ-OBRF-008)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Buildiq\Service\RegistrationFormValidator;
use PHPUnit\Framework\TestCase;

/**
 * Covers the uniqueness rules, the one-default rule, the section refusal and
 * the channel refusal.
 */
final class RegistrationFormValidatorTest extends TestCase {
	/**
	 * The validator under test.
	 *
	 * @var RegistrationFormValidator
	 */
	private RegistrationFormValidator $validator;

	/**
	 * Build the validator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->validator = new RegistrationFormValidator();
	}//end setUp()

	/**
	 * A published form for the bouwvergunning case type.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The form.
	 */
	private function form(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'rf-1',
				'name' => 'client-intake',
				'audience' => 'client',
				'isDefault' => true,
				'status' => 'published',
				'targetApp' => 'dossiq',
				'register' => 'dossiq',
				'schema' => 'Zaak',
				'typeProperty' => 'caseType',
				'typeValue' => 'bouwvergunning',
				'fields' => [['name' => 'applicantRole']],
			],
			$overrides
		);
	}//end form()

	/**
	 * Three forms, one per audience, all default and all accepted: that is the
	 * whole point of an audience (REQ-OBRF-004).
	 *
	 * @return void
	 */
	public function testThreeFormsOneForEachAudienceAllStandAsDefaults(): void {
		$client = $this->form();
		$internal = $this->form(['id' => 'rf-2', 'name' => 'desk-intake', 'audience' => 'internal']);
		$supplier = $this->form(['id' => 'rf-3', 'name' => 'architect-intake', 'audience' => 'supplier']);

		$this->validator->validate($internal, [$client]);
		$this->validator->validate($supplier, [$client, $internal]);

		self::assertTrue(true);
	}//end testThreeFormsOneForEachAudienceAllStandAsDefaults()

	/**
	 * A second default for the SAME audience is refused, and the refusal names
	 * the form that already holds it (REQ-OBRF-004).
	 *
	 * @return void
	 */
	public function testASecondClientDefaultIsRefusedByName(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('client-intake');

		$this->validator->validate(
			$this->form(['id' => 'rf-9', 'name' => 'client-intake-2']),
			[$this->form()]
		);
	}//end testASecondClientDefaultIsRefusedByName()

	/**
	 * A second default on a DIFFERENT channel is fine: the portal and the desk
	 * each get their own (REQ-OBRF-007).
	 *
	 * @return void
	 */
	public function testASecondDefaultOnAnotherChannelIsAccepted(): void {
		$this->validator->validate(
			$this->form(['id' => 'rf-9', 'name' => 'desk-client-intake', 'channel' => 'desk']),
			[$this->form(['channel' => 'portal'])]
		);

		self::assertTrue(true);
	}//end testASecondDefaultOnAnotherChannelIsAccepted()

	/**
	 * A DRAFT second default does not collide: an administrator has to be able to
	 * prepare the replacement before switching over.
	 *
	 * @return void
	 */
	public function testADraftDefaultDoesNotCollide(): void {
		$this->validator->validate(
			$this->form(['id' => 'rf-9', 'name' => 'client-intake-2026', 'status' => 'draft']),
			[$this->form()]
		);

		self::assertTrue(true);
	}//end testADraftDefaultDoesNotCollide()

	/**
	 * Editing the existing default does not make it collide with itself.
	 *
	 * @return void
	 */
	public function testAFormDoesNotCollideWithItself(): void {
		$this->validator->validate($this->form(['confirmationText' => 'Dank u wel.']), [$this->form()]);

		self::assertTrue(true);
	}//end testAFormDoesNotCollideWithItself()

	/**
	 * Two forms on one type cannot share a name, because a consumer asks by name
	 * and would get whichever came back first (REQ-OBRF-004).
	 *
	 * @return void
	 */
	public function testTwoFormsOnOneTypeCannotShareAName(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('already exists');

		$this->validator->validate(
			$this->form(['id' => 'rf-9', 'audience' => 'internal', 'isDefault' => false]),
			[$this->form()]
		);
	}//end testTwoFormsOnOneTypeCannotShareAName()

	/**
	 * The same name on ANOTHER type is free: names are scoped to their type.
	 *
	 * @return void
	 */
	public function testTheSameNameOnAnotherTypeIsFree(): void {
		$this->validator->validate(
			$this->form(['id' => 'rf-9', 'typeValue' => 'melding']),
			[$this->form()]
		);

		self::assertTrue(true);
	}//end testTheSameNameOnAnotherTypeIsFree()

	/**
	 * A field in a section the form does not declare is refused, naming the
	 * sections that do exist. It would otherwise simply never be asked
	 * (REQ-OBRF-008).
	 *
	 * @return void
	 */
	public function testAFieldInAnUndeclaredSectionIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('uw-gegevens');

		$this->validator->validate(
			$this->form(
				[
					'sections' => [['name' => 'uw-gegevens', 'label' => 'Uw gegevens', 'order' => 1]],
					'fields' => [['name' => 'applicantRole', 'section' => 'uw-bouwwerk']],
				]
			)
		);
	}//end testAFieldInAnUndeclaredSectionIsRefused()

	/**
	 * A field with no section at all is fine: sections are optional.
	 *
	 * @return void
	 */
	public function testAFieldWithNoSectionIsFine(): void {
		$this->validator->validate($this->form());

		self::assertTrue(true);
	}//end testAFieldWithNoSectionIsFine()

	/**
	 * A channel the consumer does not accept is refused, and the refusal names
	 * the values it does accept (REQ-OBRF-007).
	 *
	 * @return void
	 */
	public function testAChannelTheConsumerDoesNotAcceptIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('portal, desk');

		$this->validator->validate(
			$this->form(['channel' => 'fax']),
			[],
			null,
			['portal', 'desk']
		);
	}//end testAChannelTheConsumerDoesNotAcceptIsRefused()

	/**
	 * A form with no channel is accepted whatever the consumer declares: a
	 * channel-less form serves every channel.
	 *
	 * @return void
	 */
	public function testAChannellessFormIsAlwaysAccepted(): void {
		$this->validator->validate($this->form(), [], null, ['portal', 'desk']);

		self::assertTrue(true);
	}//end testAChannellessFormIsAlwaysAccepted()

	/**
	 * A consumer that declares no channels at all cannot refuse one, so the
	 * validation stands down rather than blocking every save.
	 *
	 * @return void
	 */
	public function testAConsumerWithNoDeclaredChannelsRefusesNothing(): void {
		$this->validator->validate($this->form(['channel' => 'fax']), [], null, null);

		self::assertTrue(true);
	}//end testAConsumerWithNoDeclaredChannelsRefusesNothing()

	/**
	 * A preset naming a property the target schema does not have WARNS rather
	 * than refusing, because buildiq reads that schema across an app boundary and
	 * a schema it cannot read would otherwise make every save fail
	 * (REQ-OBRF-005).
	 *
	 * @return void
	 */
	public function testAnUnknownPresetFieldWarnsAndDoesNotRefuse(): void {
		$warnings = $this->validator->validate(
			$this->form(['presets' => [['field' => 'intakeChannnel', 'value' => 'portal', 'hidden' => true]]]),
			[],
			['applicantRole', 'intakeChannel']
		);

		self::assertCount(1, $warnings);
		self::assertStringContainsString('intakeChannnel', $warnings[0]);
	}//end testAnUnknownPresetFieldWarnsAndDoesNotRefuse()

	/**
	 * A preset naming a property that DOES exist warns about nothing.
	 *
	 * @return void
	 */
	public function testAKnownPresetFieldWarnsAboutNothing(): void {
		$warnings = $this->validator->validate(
			$this->form(['presets' => [['field' => 'intakeChannel', 'value' => 'portal', 'hidden' => true]]]),
			[],
			['applicantRole', 'intakeChannel']
		);

		self::assertSame([], $warnings);
	}//end testAKnownPresetFieldWarnsAboutNothing()

	/**
	 * An unknown audience is refused: it decides what the form asks and which
	 * default a consumer gets.
	 *
	 * @return void
	 */
	public function testAnUnknownAudienceIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('audience');

		$this->validator->validate($this->form(['audience' => 'iedereen']));
	}//end testAnUnknownAudienceIsRefused()

	/**
	 * A form with no name is refused.
	 *
	 * @return void
	 */
	public function testAFormWithNoNameIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('name');

		$this->validator->validate($this->form(['name' => '  ']));
	}//end testAFormWithNoNameIsRefused()
}//end class
