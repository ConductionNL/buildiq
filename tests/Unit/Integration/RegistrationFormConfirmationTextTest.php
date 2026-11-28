<?php

/**
 * The registration-form scenario of REQ-BQCV-012: the form served to a portal carries its confirmation text.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-form-shows-its-confirmation-text-after-a-submit-req-bqcv-012
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Integration;

use OCA\Buildiq\Integration\RegistrationFormLeafProvider;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * A portal reads a registration form through the leaf provider; the confirmation text travels with it.
 *
 * @covers \OCA\Buildiq\Integration\RegistrationFormLeafProvider
 * @uses   \OCA\Buildiq\Integration\AppLocalLeafProvider
 * @uses   \OCA\Buildiq\Service\RegistrationFormPresenter
 * @uses   \OCA\Buildiq\Service\HelperFactory
 * @uses   \OCA\Buildiq\Service\RegistrationFormFee
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
final class RegistrationFormConfirmationTextTest extends TestCase {

	/**
	 * The provider over one stored registration form, read by an anonymous portal visitor.
	 *
	 * @param array<string, mixed> $form The stored form.
	 *
	 * @return RegistrationFormLeafProvider The provider.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-form-shows-its-confirmation-text-after-a-submit-req-bqcv-012
	 */
	private function providerOver(array $form): RegistrationFormLeafProvider {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn(value: [$form]);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn(value: 'buildiq');

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(value: null);

		return new RegistrationFormLeafProvider(objectService: $objectService, appConfig: $appConfig, userSession: $session);
	}//end providerOver()

	/**
	 * A published application form for permits with the given confirmation text.
	 *
	 * @param string|null $confirmation The confirmation text, or null to store none.
	 *
	 * @return array<string, mixed> The stored form.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-form-shows-its-confirmation-text-after-a-submit-req-bqcv-012
	 */
	private function form(?string $confirmation): array {
		$form = [
			'id'         => 'rf-1',
			'name'       => 'permit-application',
			'audience'   => 'client',
			'status'     => 'published',
			'register'   => 'vergunningen',
			'schema'     => 'Zaak',
			'typeValue'  => 'bouwvergunning',
			'isPublic'   => true,
			'fields'     => [['name' => 'address', 'label' => 'Adres']],
		];
		if ($confirmation !== null) {
			$form['confirmationText'] = $confirmation;
		}

		return $form;
	}//end form()

	/**
	 * The one form a portal asks for by id carries the confirmation text the maker wrote.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-form-shows-its-confirmation-text-after-a-submit-req-bqcv-012
	 */
	public function testTheFormServedByIdCarriesItsConfirmationText(): void {
		$served = $this->providerOver(form: $this->form(confirmation: 'Your application is in.'))
			->get(register: 'vergunningen', schema: 'Zaak', objectId: 'bouwvergunning', entityId: 'rf-1');

		self::assertSame(expected: 'Your application is in.', actual: $served['confirmationText']);
	}//end testTheFormServedByIdCarriesItsConfirmationText()

	/**
	 * Every form in the list a portal reads carries its confirmation text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-form-shows-its-confirmation-text-after-a-submit-req-bqcv-012
	 */
	public function testTheListedFormCarriesItsConfirmationText(): void {
		$listed = $this->providerOver(form: $this->form(confirmation: 'Your application is in.'))
			->list(register: 'vergunningen', schema: 'Zaak', objectId: 'bouwvergunning');

		self::assertCount(expectedCount: 1, haystack: $listed['items']);
		self::assertSame(expected: 'Your application is in.', actual: $listed['items'][0]['confirmationText']);
	}//end testTheListedFormCarriesItsConfirmationText()

	/**
	 * A form without a confirmation text is served with an empty one, never a made-up sentence.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-form-shows-its-confirmation-text-after-a-submit-req-bqcv-012
	 */
	public function testAFormWithoutConfirmationTextIsServedWithAnEmptyOne(): void {
		$served = $this->providerOver(form: $this->form(confirmation: null))
			->get(register: 'vergunningen', schema: 'Zaak', objectId: 'bouwvergunning', entityId: 'rf-1');

		self::assertSame(expected: '', actual: $served['confirmationText']);
	}//end testAFormWithoutConfirmationTextIsServedWithAnEmptyOne()
}//end class
