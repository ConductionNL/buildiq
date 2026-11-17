<?php

/**
 * The demo dataset's forms for one case type.
 *
 * The seed carries three forms for `dossiq/case` `caseType = bouwvergunning`, one per
 * audience: the citizen's portal form (public, a confirmation text, the intake channel
 * preset and hidden), the desk form (internal, its own channel) and the architect's
 * form (supplier, the applicant role preset and visible). Each passes the same rules a
 * save runs, and each is served the way the spec says.
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
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004, REQ-OBRF-005, REQ-OBRF-007, REQ-OBRF-009)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\RegistrationFormPresenter;
use OCA\Buildiq\Service\RegistrationFormValidator;
use PHPUnit\Framework\TestCase;

/**
 * The seeded bouwvergunning forms are valid and served as the spec describes.
 *
 * @covers \OCA\Buildiq\Service\RegistrationFormValidator
 * @covers \OCA\Buildiq\Service\RegistrationFormPresenter
 * @uses   \OCA\Buildiq\Service\RegistrationFormSignInLevel
 */
final class RegistrationFormSeedTest extends TestCase {

	/**
	 * The seeded registration forms, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function seededForms(): array {
		$data = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/buildiq_mock_register.json'), true);
		$forms = [];
		foreach ($data['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? '') === 'registrationForm') {
				$forms[(string)$object['name']] = $object;
			}
		}

		return $forms;

	}//end seededForms()

	/**
	 * Three forms for one type, one per audience, each the default of its audience.
	 *
	 * @return void
	 */
	public function testOneTypeCarriesAFormPerAudience(): void {
		$forms = $this->seededForms();

		self::assertSame(['client-intake', 'desk-intake', 'architect-intake'], array_keys($forms));
		$validator = new RegistrationFormValidator();
		$keys = array_unique(array_map(static fn (array $form): string => $validator->tupleKey(form: $form), $forms));
		self::assertCount(1, $keys, 'all three forms are bound to the same type');
		self::assertSame(
			['client-intake' => 'client', 'desk-intake' => 'internal', 'architect-intake' => 'supplier'],
			array_map(static fn (array $form): string => $form['audience'], $forms)
		);
		foreach ($forms as $form) {
			self::assertTrue($form['isDefault']);
			self::assertSame('published', $form['status']);
		}

	}//end testOneTypeCarriesAFormPerAudience()

	/**
	 * Each seeded form passes the rules a save runs, against the ones seeded before it.
	 *
	 * @return void
	 */
	public function testEverySeededFormPassesTheSaveRules(): void {
		$validator = new RegistrationFormValidator();
		$existing = [];
		foreach ($this->seededForms() as $form) {
			$validator->validate(form: $form, existing: $existing, targetChannels: ['portal', 'desk', 'post', 'email', 'phone']);
			$existing[] = $form;
		}

		self::assertCount(3, $existing);

	}//end testEverySeededFormPassesTheSaveRules()

	/**
	 * The citizen's portal form is public, says what happens next, and never shows the channel field.
	 *
	 * @return void
	 */
	public function testTheCitizenNeverSeesTheChannelField(): void {
		$served = (new RegistrationFormPresenter())->serve(form: $this->seededForms()['client-intake']);

		self::assertSame('portal', $served['channel']);
		self::assertTrue($served['isPublic']);
		self::assertNull($served['minTrust']);
		self::assertNotSame('', $served['confirmationText']);
		self::assertNotContains('intakeChannel', array_column($served['fields'], 'name'));
		self::assertContains(['field' => 'intakeChannel', 'value' => 'portal', 'hidden' => true], $served['presets']);
		self::assertSame(['uw-gegevens', 'het-bouwplan'], array_column($served['sections'], 'name'));

	}//end testTheCitizenNeverSeesTheChannelField()

	/**
	 * The desk form has its own channel, and the architect's form pre-fills the role and leaves it editable.
	 *
	 * @return void
	 */
	public function testTheDeskAndArchitectFormsDiffer(): void {
		$presenter = new RegistrationFormPresenter();
		$forms = $this->seededForms();

		$desk = $presenter->serve(form: $forms['desk-intake']);
		self::assertSame('desk', $desk['channel']);
		self::assertFalse($desk['isPublic']);
		self::assertNotContains('intakeChannel', array_column($desk['fields'], 'name'));

		$architect = $presenter->serve(form: $forms['architect-intake']);
		self::assertSame('', $architect['channel'], 'a form without a channel serves every channel');
		self::assertSame('substantial', $architect['minTrust']);
		$role = array_values(array_filter($architect['fields'], static fn (array $field): bool => $field['name'] === 'applicantRole'));
		self::assertCount(1, $role);
		self::assertSame('gemachtigde', $role[0]['default']);

	}//end testTheDeskAndArchitectFormsDiffer()
}//end class
