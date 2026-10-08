<?php

/**
 * Unit tests for RegistrationFormLeafProvider.
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
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005, REQ-OBRF-006, REQ-OBRF-008, REQ-OBRF-009)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Integration;

use OCA\Buildiq\Integration\RegistrationFormLeafProvider;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the serving shape, the filters and the draft ownership.
 */
final class RegistrationFormLeafProviderTest extends TestCase {
	/**
	 * Objects written during the test.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the provider over rows per schema.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema slug.
	 * @param string|null $uid The signed-in user, or null for no session.
	 *
	 * @return RegistrationFormLeafProvider The provider.
	 */
	private function makeProvider(array $rows, ?string $uid = 'filer'): RegistrationFormLeafProvider {
		$saved = &$this->saved;

		// onlyMethods: a double may not invent a method the real contract lacks,
		// which is how 24 green tests once covered a call that 500s in production.
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
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntityInterface {
				$saved[] = $object;

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($object);
				$entity->method('getUuid')->willReturn('draft-1');

				return $entity;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('buildiq');

		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new RegistrationFormLeafProvider(
			objectService: $objectService,
			appConfig: $appConfig,
			userSession: $session,
		);
	}//end makeProvider()

	/**
	 * A published form on the bouwvergunning case type.
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
				'fields' => [
					['name' => 'applicantRole', 'label' => 'Rol'],
					['name' => 'intakeChannel', 'label' => 'Kanaal'],
				],
				'presets' => [['field' => 'intakeChannel', 'value' => 'portal', 'hidden' => true]],
			],
			$overrides
		);
	}//end form()

	/**
	 * A hidden preset field is REMOVED from the served form, not marked hidden.
	 * A field still in the payload is still readable by anybody who opens the
	 * network tab, and "the citizen never sees it" has to mean it was never sent
	 * (REQ-OBRF-005).
	 *
	 * @return void
	 */
	public function testAHiddenPresetIsRemovedFromTheServedFields(): void {
		$provider = $this->makeProvider(['registrationForm' => [$this->form()]]);

		$served = $provider->list('dossiq', 'Zaak', 'bouwvergunning')['items'][0];

		$names = array_map(static fn (array $f): string => (string)$f['name'], $served['fields']);
		self::assertNotContains('intakeChannel', $names);
		self::assertContains('applicantRole', $names);

		// It travels beside the form instead, so the consumer still writes it.
		self::assertSame('intakeChannel', $served['presets'][0]['field']);
		self::assertSame('portal', $served['presets'][0]['value']);
	}//end testAHiddenPresetIsRemovedFromTheServedFields()

	/**
	 * A VISIBLE preset stays on the form and arrives pre-filled and editable.
	 *
	 * @return void
	 */
	public function testAVisiblePresetIsPrefilledAndStays(): void {
		$provider = $this->makeProvider(
			[
				'registrationForm' => [
					$this->form(['presets' => [['field' => 'applicantRole', 'value' => 'gemachtigde', 'hidden' => false]]]),
				],
			]
		);

		$served = $provider->list('dossiq', 'Zaak', 'bouwvergunning')['items'][0];

		$byName = [];
		foreach ($served['fields'] as $field) {
			$byName[(string)$field['name']] = $field;
		}

		self::assertArrayHasKey('applicantRole', $byName);
		self::assertSame('gemachtigde', $byName['applicantRole']['default']);
	}//end testAVisiblePresetIsPrefilledAndStays()

	/**
	 * Asking by audience returns that audience's forms, default first
	 * (REQ-OBRF-006).
	 *
	 * @return void
	 */
	public function testAskingByAudienceReturnsThatAudienceDefaultFirst(): void {
		$provider = $this->makeProvider(
			[
				'registrationForm' => [
					$this->form(['id' => 'rf-2', 'name' => 'desk-extra', 'audience' => 'internal', 'isDefault' => false]),
					$this->form(['id' => 'rf-3', 'name' => 'desk-intake', 'audience' => 'internal']),
					$this->form(),
				],
			]
		);

		$items = $provider->list('dossiq', 'Zaak', 'bouwvergunning', ['audience' => 'internal'])['items'];

		self::assertCount(2, $items);
		self::assertSame('desk-intake', $items[0]['name']);
		self::assertTrue($items[0]['isDefault']);
	}//end testAskingByAudienceReturnsThatAudienceDefaultFirst()

	/**
	 * An unknown name returns an empty list and raises nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownNameReturnsNothingAndDoesNotRaise(): void {
		$provider = $this->makeProvider(['registrationForm' => [$this->form()]]);

		self::assertSame([], $provider->list('dossiq', 'Zaak', 'bouwvergunning', ['name' => 'nope'])['items']);
	}//end testAnUnknownNameReturnsNothingAndDoesNotRaise()

	/**
	 * Asking by channel returns that channel's forms first, then the forms that
	 * declare no channel (REQ-OBRF-009).
	 *
	 * @return void
	 */
	public function testAskingByChannelPutsThatChannelFirst(): void {
		$provider = $this->makeProvider(
			[
				'registrationForm' => [
					$this->form(['id' => 'rf-any', 'name' => 'any-channel', 'isDefault' => false]),
					$this->form(['id' => 'rf-desk', 'name' => 'desk-form', 'channel' => 'desk', 'isDefault' => false]),
				],
			]
		);

		$items = $provider->list('dossiq', 'Zaak', 'bouwvergunning', ['channel' => 'desk'])['items'];

		self::assertSame(['desk-form', 'any-channel'], array_map(static fn (array $f): string => $f['name'], $items));
	}//end testAskingByChannelPutsThatChannelFirst()

	/**
	 * A channel with no form of its own falls back to the channel-less form,
	 * rather than answering nothing. Answering nothing would silently remove the
	 * form from every application arriving that way (REQ-OBRF-009).
	 *
	 * @return void
	 */
	public function testAChannelWithNoFormFallsBackToTheChannellessOne(): void {
		$provider = $this->makeProvider(['registrationForm' => [$this->form()]]);

		$items = $provider->list('dossiq', 'Zaak', 'bouwvergunning', ['channel' => 'post'])['items'];

		self::assertCount(1, $items);
		self::assertSame('client-intake', $items[0]['name']);
	}//end testAChannelWithNoFormFallsBackToTheChannellessOne()

	/**
	 * A form of ANOTHER channel is not offered when a channel was asked for.
	 *
	 * @return void
	 */
	public function testAnotherChannelsFormIsNotOffered(): void {
		$provider = $this->makeProvider(
			['registrationForm' => [$this->form(['channel' => 'desk'])]]
		);

		self::assertSame([], $provider->list('dossiq', 'Zaak', 'bouwvergunning', ['channel' => 'portal'])['items']);
	}//end testAnotherChannelsFormIsNotOffered()

	/**
	 * The fields come back in the order the administrator arranged, grouped into
	 * the declared sections, and NEVER in the target schema's property order
	 * (REQ-OBRF-008).
	 *
	 * @return void
	 */
	public function testTheFieldsComeBackInTheOrderTheAdministratorSet(): void {
		$provider = $this->makeProvider(
			[
				'registrationForm' => [
					$this->form(
						[
							'presets' => [],
							'sections' => [
								['name' => 'uw-bouwwerk', 'label' => 'Uw bouwwerk', 'order' => 2],
								['name' => 'uw-gegevens', 'label' => 'Uw gegevens', 'order' => 1],
							],
							'fields' => [
								['name' => 'oppervlakte', 'section' => 'uw-bouwwerk', 'order' => 1],
								['name' => 'achternaam', 'section' => 'uw-gegevens', 'order' => 2],
								['name' => 'voornaam', 'section' => 'uw-gegevens', 'order' => 1],
							],
						]
					),
				],
			]
		);

		$served = $provider->list('dossiq', 'Zaak', 'bouwvergunning')['items'][0];

		self::assertSame(
			['voornaam', 'achternaam', 'oppervlakte'],
			array_map(static fn (array $f): string => (string)$f['name'], $served['fields'])
		);
		self::assertSame('uw-gegevens', $served['sections'][0]['name']);
	}//end testTheFieldsComeBackInTheOrderTheAdministratorSet()

	/**
	 * A draft form is not served. Only a published one reaches a consumer, which
	 * is what lets an administrator work on the next version in the open.
	 *
	 * @return void
	 */
	public function testADraftFormIsNotServed(): void {
		$provider = $this->makeProvider(['registrationForm' => [$this->form(['status' => 'draft'])]]);

		self::assertSame([], $provider->list('dossiq', 'Zaak', 'bouwvergunning')['items']);
	}//end testADraftFormIsNotServed()

	/**
	 * A form bound to ANOTHER type is not served on this one.
	 *
	 * @return void
	 */
	public function testAFormOfAnotherTypeIsNotServed(): void {
		$provider = $this->makeProvider(['registrationForm' => [$this->form(['typeValue' => 'melding'])]]);

		self::assertSame([], $provider->list('dossiq', 'Zaak', 'bouwvergunning')['items']);
	}//end testAFormOfAnotherTypeIsNotServed()

	/**
	 * A draft is appended for the CALLING user, whatever the payload claims. The
	 * owner is the only protection a half-written form has.
	 *
	 * @return void
	 */
	public function testADraftIsOwnedByTheCallerNotByThePayload(): void {
		$provider = $this->makeProvider(['registrationForm' => [$this->form()]]);

		$draft = $provider->create(
			'dossiq',
			'Zaak',
			'bouwvergunning',
			['registrationFormId' => 'rf-1', 'userId' => 'somebody-else', 'values' => ['voornaam' => 'Jan'], 'step' => 1]
		);

		self::assertSame('filer', $draft['userId']);
		self::assertSame('draft-1', $draft['id']);
		self::assertCount(1, $this->saved);
	}//end testADraftIsOwnedByTheCallerNotByThePayload()

	/**
	 * With no session there is nobody to own the draft, so it is refused and
	 * nothing is written.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerCannotSaveADraft(): void {
		$provider = $this->makeProvider(['registrationForm' => [$this->form()]], null);

		try {
			$provider->create('dossiq', 'Zaak', 'bouwvergunning', ['registrationFormId' => 'rf-1']);
			self::fail('The leaf saved a draft with nobody to own it.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('403', $e->getMessage());
		}

		self::assertSame([], $this->saved);
	}//end testAnAnonymousCallerCannotSaveADraft()

	/**
	 * Another person's draft is never listed, even when the store answers it.
	 * The filter is re-checked here on purpose: a filter the store quietly
	 * ignored would hand one person's half-written answers to the next caller,
	 * and nothing would say so.
	 *
	 * @return void
	 */
	public function testAnotherPersonsDraftIsNeverListed(): void {
		$provider = $this->makeProvider(
			[
				'registrationForm' => [$this->form()],
				'formDraft' => [
					['id' => 'd-1', 'registrationFormId' => 'rf-1', 'userId' => 'filer'],
					['id' => 'd-2', 'registrationFormId' => 'rf-1', 'userId' => 'somebody-else'],
				],
			]
		);

		$drafts = $provider->list('dossiq', 'Zaak', 'bouwvergunning')['drafts'];

		self::assertCount(1, $drafts);
		self::assertSame('d-1', $drafts[0]['id']);
	}//end testAnotherPersonsDraftIsNeverListed()

	/**
	 * The leaf reads and appends drafts. A form is edited in buildiq, where the
	 * rules that validate it live.
	 *
	 * @return void
	 */
	public function testTheLeafRefusesToEditAForm(): void {
		$provider = $this->makeProvider(['registrationForm' => [$this->form()]]);

		$this->expectException(RuntimeException::class);

		$provider->update('dossiq', 'Zaak', 'bouwvergunning', 'rf-1', ['name' => 'renamed']);
	}//end testTheLeafRefusesToEditAForm()
}//end class
