<?php

/**
 * Unit tests for RegistrationFormAuthoringService.
 *
 * Each of these asserts a refusal that was already written and, until this save
 * path existed, could never fire: the validator had no caller.
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
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004, REQ-OBRF-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Buildiq\Service\RegistrationFormAuthoringService;
use OCA\Buildiq\Service\RegistrationFormValidator;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Covers the save path and the rules it makes real.
 */
final class RegistrationFormAuthoringServiceTest extends TestCase {
	/**
	 * The in-memory store, keyed by form id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * The service under test.
	 *
	 * @var RegistrationFormAuthoringService
	 */
	private RegistrationFormAuthoringService $service;

	/**
	 * Wire the service over an in-memory store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = [];

		// onlyMethods: the double may not invent a method the contract lacks.
		$objectService = $this->getMockBuilder(ObjectServiceInterface::class)
			->disableOriginalConstructor()
			->onlyMethods(['setRegister', 'setSchema', 'findAll', 'saveObject'])
			->getMockForAbstractClass();

		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(fn (): array => array_values($this->store));
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->store[(string)($object['id'] ?? '')] = $object;

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getObject')->willReturn($object);
				$entity->method('getUuid')->willReturn((string)($object['id'] ?? ''));

				return $entity;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('buildiq');

		$this->service = new RegistrationFormAuthoringService(
			objectService: $objectService,
			appConfig: $appConfig,
			validator: new RegistrationFormValidator(),
		);
	}//end setUp()

	/**
	 * One form on the building-permit type.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The form.
	 */
	private function form(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'rf-1',
				'name' => 'Aanvraag bouwvergunning',
				'status' => 'published',
				'audience' => 'client',
				'targetApp' => 'dossiq',
				'register' => 'dossiq',
				'schema' => 'Zaak',
				'typeProperty' => 'caseType',
				'typeValue' => 'bouwvergunning',
				'isDefault' => true,
				'sections' => [
					['id' => 'aanvrager', 'label' => 'Aanvrager', 'fields' => ['naam']],
				],
			],
			$overrides
		);
	}//end form()

	/**
	 * The happy path, so the refusals below are known to be refusals and not a
	 * save path that refuses everything.
	 *
	 * @return void
	 */
	public function testAValidFormIsStored(): void {
		$saved = $this->service->save($this->form());

		$this->assertSame('rf-1', $saved['form']['id']);
		$this->assertArrayHasKey('rf-1', $this->store);
	}//end testAValidFormIsStored()

	/**
	 * A second form with the same name on the same type is refused: two forms
	 * called the same thing are two an administrator cannot tell apart.
	 *
	 * @return void
	 */
	public function testASecondFormWithTheSameNameIsRefused(): void {
		$this->service->save($this->form());

		$this->expectException(InvalidArgumentException::class);
		$this->service->save($this->form(['id' => 'rf-2', 'isDefault' => false]));
	}//end testASecondFormWithTheSameNameIsRefused()

	/**
	 * A second default on one type is refused: the intake would pick one of them
	 * and nothing on either would say why.
	 *
	 * @return void
	 */
	public function testASecondDefaultIsRefused(): void {
		$this->service->save($this->form());

		$this->expectException(InvalidArgumentException::class);
		$this->service->save($this->form(['id' => 'rf-2', 'name' => 'Snelle aanvraag']));
	}//end testASecondDefaultIsRefused()

	/**
	 * An audience the resolver does not know is refused, because the form would
	 * be published, correct-looking, and served to nobody.
	 *
	 * @return void
	 */
	public function testAnUnknownAudienceIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->save($this->form(['audience' => 'iedereen']));
	}//end testAnUnknownAudienceIsRefused()

	/**
	 * Editing a form is not a collision with itself.
	 *
	 * @return void
	 */
	public function testSavingTheSameFormAgainIsNotACollision(): void {
		$this->service->save($this->form());
		$saved = $this->service->save($this->form(['sections' => [['id' => 'aanvrager', 'label' => 'Aanvrager', 'fields' => ['naam', 'bsn']]]]));

		$this->assertSame(['naam', 'bsn'], $saved['form']['sections'][0]['fields']);
		$this->assertCount(1, $this->store);
	}//end testSavingTheSameFormAgainIsNotACollision()

	/**
	 * A form that does not say where it belongs is refused before any rule runs,
	 * because the rules are all scoped to a register and a schema.
	 *
	 * @return void
	 */
	public function testAFormWithNoScopeIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/which register and schema/');

		$this->service->save($this->form(['register' => '']));
	}//end testAFormWithNoScopeIsRefused()

	/**
	 * A form for a different schema is not in the same namespace, so the same
	 * name there is free.
	 *
	 * @return void
	 */
	public function testTheSameNameOnAnotherSchemaIsFree(): void {
		$this->service->save($this->form());
		$this->service->save($this->form(['id' => 'rf-3', 'schema' => 'Melding']));

		$this->assertCount(2, $this->store);
	}//end testTheSameNameOnAnotherSchemaIsFree()
}//end class
