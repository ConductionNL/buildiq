<?php

/**
 * Unit tests for RegistrationFormLeafProvider's single-form read, refused
 * writes, health, and the owner check on drafts.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Integration;

use OCA\Buildiq\Integration\RegistrationFormLeafProvider;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers `get()`, the refused delete and incomplete create, health, and the
 * draft rows a store hands back that are not the caller's.
 *
 * @covers \OCA\Buildiq\Integration\RegistrationFormLeafProvider
 * @uses   \OCA\Buildiq\Integration\AppLocalLeafProvider
 * @uses   \OCA\Buildiq\Service\RegistrationFormPresenter
 */
final class RegistrationFormLeafProviderContractTest extends TestCase {
	/**
	 * Build the provider over rows keyed by schema slug.
	 *
	 * @param array<string, array<int, mixed>> $rows Rows per schema slug.
	 * @param string|null $uid The signed-in user, or null for none.
	 *
	 * @return RegistrationFormLeafProvider The provider.
	 */
	private function makeProvider(array $rows, ?string $uid = 'filer'): RegistrationFormLeafProvider {
		$objectService = $this->getMockBuilder(ObjectServiceInterface::class)
			->disableOriginalConstructor()
			->onlyMethods(['setRegister', 'setSchema', 'findAll', 'saveObject'])
			->getMockForAbstractClass();

		$schema = '';
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			static function (string $slug) use (&$schema, $objectService) {
				$schema = $slug;
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config = []) use (&$schema, $rows): array {
				return ($rows[$schema] ?? []);
			}
		);
		$objectService->expects(self::never())->method('saveObject');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('buildiq');

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new RegistrationFormLeafProvider(objectService: $objectService, appConfig: $appConfig, userSession: $session);
	}//end makeProvider()

	/**
	 * A published registration form.
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
				'status' => 'published',
				'register' => 'dossiq',
				'schema' => 'Zaak',
				'typeProperty' => 'caseType',
				'typeValue' => 'bouwvergunning',
				'fields' => [['name' => 'applicantRole', 'label' => 'Rol']],
			],
			$overrides
		);
	}//end form()

	/**
	 * `get()` serves the bound form by id.
	 *
	 * @return void
	 */
	public function testGetServesTheBoundFormById(): void {
		$provider = $this->makeProvider(['registrationForm' => [$this->form(), $this->form(['id' => 'rf-2', 'name' => 'staff-intake'])]]);

		$form = $provider->get('dossiq', 'Zaak', 'bouwvergunning', 'rf-2');

		self::assertSame('rf-2', $form['id']);
		self::assertSame('staff-intake', $form['name']);
	}//end testGetServesTheBoundFormById()

	/**
	 * `get()` refuses a form bound to another type, a draft form, and an unknown id.
	 *
	 * @return void
	 */
	public function testGetRefusesAFormThatIsNotPublishedForThisType(): void {
		$provider = $this->makeProvider(
			[
				'registrationForm' => [
					'junk',
					$this->form(['id' => 'rf-melding', 'typeValue' => 'melding']),
					$this->form(['id' => 'rf-draft', 'status' => 'draft']),
				],
			]
		);

		foreach (['rf-melding', 'rf-draft', 'rf-unknown'] as $id) {
			try {
				$provider->get('dossiq', 'Zaak', 'bouwvergunning', $id);
				self::fail("'{$id}' must not be served");
			} catch (RuntimeException $e) {
				self::assertSame('404 No published registration form with that id is bound to this type.', $e->getMessage());
			}
		}
	}//end testGetRefusesAFormThatIsNotPublishedForThisType()

	/**
	 * A draft that names no form is refused before anything is written.
	 *
	 * @return void
	 */
	public function testACreateWithoutAFormIdIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('A draft has to say which form it is an answer to.');

		$this->makeProvider([])->create('dossiq', 'Zaak', 'bouwvergunning', ['answers' => []]);
	}//end testACreateWithoutAFormIdIsRefused()

	/**
	 * Delete is refused: forms are retired in buildiq.
	 *
	 * @return void
	 */
	public function testDeleteIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('A registration form is retired in buildiq, not deleted from a consuming app.');

		$this->makeProvider([])->delete('dossiq', 'Zaak', 'bouwvergunning', 'rf-1');
	}//end testDeleteIsRefused()

	/**
	 * Health names the leaf.
	 *
	 * @return void
	 */
	public function testHealthNamesTheLeaf(): void {
		self::assertSame(['status' => 'ok', 'leaf' => 'buildiq-registration-form'], $this->makeProvider([])->health());
	}//end testHealthNamesTheLeaf()

	/**
	 * Only the caller's own drafts for a listed form come back, whatever the store returns.
	 *
	 * @return void
	 */
	public function testOnlyTheCallersDraftsForListedFormsAreReturned(): void {
		$mine = ['id' => 'd-1', 'userId' => 'filer', 'registrationFormId' => 'rf-1'];
		$provider = $this->makeProvider(
			[
				'registrationForm' => [$this->form()],
				'formDraft' => [
					'junk',
					$mine,
					['id' => 'd-2', 'userId' => 'someone-else', 'registrationFormId' => 'rf-1'],
					['id' => 'd-3', 'userId' => 'filer', 'registrationFormId' => 'rf-other'],
				],
			]
		);

		self::assertSame([$mine], $provider->list('dossiq', 'Zaak', 'bouwvergunning')['drafts']);
	}//end testOnlyTheCallersDraftsForListedFormsAreReturned()

	/**
	 * A visitor with no account gets the forms but no drafts.
	 *
	 * @return void
	 */
	public function testAVisitorGetsNoDrafts(): void {
		$provider = $this->makeProvider(
			['registrationForm' => [$this->form()], 'formDraft' => [['id' => 'd-1', 'userId' => '', 'registrationFormId' => 'rf-1']]],
			null
		);

		$listed = $provider->list('dossiq', 'Zaak', 'bouwvergunning');

		self::assertSame(1, $listed['total']);
		self::assertSame([], $listed['drafts']);
	}//end testAVisitorGetsNoDrafts()
}//end class
