<?php

/**
 * The registration-form scenarios of REQ-BQCV-019: a form can require sign-in at a trust level.
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
 * @spec openspec/changes/capability-requirements-backfill/specs/external-form-provisioning/spec.md#requirement-a-form-can-require-sign-in-at-a-trust-level-req-bqcv-019
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Buildiq\Service\FormDestinationCheck;
use OCA\Buildiq\Service\RegistrationFormPresenter;
use OCA\Buildiq\Service\RegistrationFormTargetWarnings;
use OCA\Buildiq\Service\RegistrationFormValidator;
use PHPUnit\Framework\TestCase;

/**
 * Buildiq records the level, serves it to the portal (which asks the visitor
 * to sign in, portaliq#725), asks for no anonymous create, and refuses a
 * level on a public form or a level the portal does not know.
 */
final class RegistrationFormSignInScenariosTest extends TestCase {

	/**
	 * A registration form for the `request` type with the given extra keys.
	 *
	 * @param array<string, mixed> $extra Keys to set on the form.
	 *
	 * @return array<string, mixed>
	 */
	private function form(array $extra): array {
		return array_merge(
			[
				'name' => 'Request intake',
				'audience' => 'client',
				'targetApp' => 'caseiq',
				'register' => 'cases',
				'schema' => 'request',
				'typeProperty' => 'caseType',
				'typeValue' => 'request',
			],
			$extra
		);
	}//end form()

	/**
	 * A substantial form is saved, served to the portal with its level and not
	 * as public, and its destination is not asked to let an anonymous visitor create.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/external-form-provisioning/spec.md#requirement-a-form-can-require-sign-in-at-a-trust-level-req-bqcv-019
	 */
	public function testAFormThatNeedsASubstantialSignIn(): void {
		$form = $this->form(extra: ['minTrust' => 'substantial']);

		(new RegistrationFormValidator())->validate(form: $form);

		$served = (new RegistrationFormPresenter())->serve(form: $form);
		self::assertSame(expected: 'substantial', actual: $served['minTrust']);
		self::assertFalse(condition: $served['isPublic']);

		$check = $this->createMock(originalClassName: FormDestinationCheck::class);
		$check->expects(self::once())
			->method('check')
			->with(
				self::identicalTo(value: 'cases'),
				self::identicalTo(value: 'request'),
				self::anything(),
				self::identicalTo(value: [])
			)
			->willReturn([]);

		$findings = (new RegistrationFormTargetWarnings(check: $check))->findings(form: $form);
		self::assertSame(expected: [], actual: $findings);
	}//end testAFormThatNeedsASubstantialSignIn()

	/**
	 * A public form with level high is refused on save, saying a public form cannot require sign-in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/external-form-provisioning/spec.md#requirement-a-form-can-require-sign-in-at-a-trust-level-req-bqcv-019
	 */
	public function testAPublicFormCannotCarryALevel(): void {
		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(
			message: 'A form open to anyone without signing in cannot also ask for a sign-in level; choose one of the two.'
		);

		(new RegistrationFormValidator())->validate(form: $this->form(extra: ['isPublic' => true, 'minTrust' => 'high']));
	}//end testAPublicFormCannotCarryALevel()

	/**
	 * A level the portal does not know is refused on save.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/external-form-provisioning/spec.md#requirement-a-form-can-require-sign-in-at-a-trust-level-req-bqcv-019
	 */
	public function testAnUnknownLevelIsRefused(): void {
		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: "Unknown sign-in level 'medium'");

		(new RegistrationFormValidator())->validate(form: $this->form(extra: ['minTrust' => 'medium']));
	}//end testAnUnknownLevelIsRefused()

	/**
	 * Each of the three levels is accepted, and no level leaves the form anonymous.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/external-form-provisioning/spec.md#requirement-a-form-can-require-sign-in-at-a-trust-level-req-bqcv-019
	 */
	public function testTheThreeLevelsAndNoneAreAccepted(): void {
		$validator = new RegistrationFormValidator();
		$presenter = new RegistrationFormPresenter();

		foreach (['low', 'substantial', 'high'] as $level) {
			$form = $this->form(extra: ['minTrust' => $level]);
			$validator->validate(form: $form);
			self::assertSame(expected: $level, actual: $presenter->serve(form: $form)['minTrust']);
		}

		$anonymous = $this->form(extra: ['isPublic' => true]);
		$validator->validate(form: $anonymous);
		self::assertNull(actual: $presenter->serve(form: $anonymous)['minTrust']);
	}//end testTheThreeLevelsAndNoneAreAccepted()
}//end class
