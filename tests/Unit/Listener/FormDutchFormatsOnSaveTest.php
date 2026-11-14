<?php

/**
 * Buildiq's own forms check their Dutch formats on save
 * (form-dutch-field-formats T04, REQ-BQDF-002): saving an app version
 * records which form fields carry a format; saving an object such a form
 * writes refuses a value that fails its format with that format's message,
 * and stores a valid licence plate and postcode normalised.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-buildiqs-own-forms-check-the-dutch-formats-on-save-req-bqdf-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Listener;

use OCA\Buildiq\Listener\FormLiveValuesListener;
use OCA\Buildiq\Service\DutchFormatValidator;
use OCA\Buildiq\Service\FormLiveBindingIndex;
use OCA\Buildiq\Service\FormLiveValuesRecomputer;
use OCA\Buildiq\Service\ObjectSchemaSlugResolver;
use OCA\Buildiq\Service\RuleEngineService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Buildiq\Listener\FormLiveValuesListener
 * @covers \OCA\Buildiq\Service\FormLiveBindingIndex
 * @uses   \OCA\Buildiq\Service\DutchFormatValidator
 * @uses   \OCA\Buildiq\Service\FormLiveValuesRecomputer
 * @uses   \OCA\Buildiq\Service\ObjectSchemaSlugResolver
 */
final class FormDutchFormatsOnSaveTest extends TestCase {

	/**
	 * App config as a plain map.
	 *
	 * @var array<string,string>
	 */
	private array $config = [];

	private FormLiveBindingIndex $index;

	private FormLiveValuesListener $listener;

	private RuleEngineService $engine;

	protected function setUp(): void {
		parent::setUp();
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$app . '.' . $key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$app . '.' . $key] = $value;
				return true;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$logger = $this->createMock(LoggerInterface::class);
		$this->engine = $this->createMock(RuleEngineService::class);
		$this->index = new FormLiveBindingIndex(appConfig: $appConfig, logger: $logger);
		$this->listener = new FormLiveValuesListener(
			slugs: new ObjectSchemaSlugResolver(container: $this->createMock(ContainerInterface::class), logger: $logger),
			index: $this->index,
			recomputer: new FormLiveValuesRecomputer(engine: $this->engine, logger: $logger),
			logger: $logger,
			dutchFormats: new DutchFormatValidator(l10n: $l10n),
		);

		// An app version with a form whose fields carry Dutch formats and nothing else.
		$this->listener->handle(new ObjectUpdatingEvent($this->entity('buildiq', 'applicationVersion', [
			'slug' => 'production',
			'register' => 'openbuild-grants-production',
			'manifest' => ['pages' => [[
				'id' => 'apply',
				'type' => 'form',
				'config' => [
					'submitEndpoint' => '/apps/openregister/api/objects/{registerSlug}/grant',
					'fields' => [
						['key' => 'rekeningnummer', 'label' => 'Rekening', 'type' => 'text', 'format' => 'iban'],
						['key' => 'kenteken', 'label' => 'Kenteken', 'type' => 'text', 'format' => 'nl-licence-plate'],
						['key' => 'postcode', 'label' => 'Postcode', 'type' => 'text', 'format' => 'postcode'],
						['key' => 'mail', 'label' => 'E-mail', 'type' => 'text', 'format' => 'email'],
						['key' => 'naam', 'label' => 'Naam', 'type' => 'text'],
					],
				],
			]]],
		])));
	}//end setUp()

	/**
	 * An object entity.
	 *
	 * @param string $register The register.
	 * @param string $schema The schema.
	 * @param array<string,mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $register, string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister($register);
		$entity->setSchema($schema);
		$entity->setUuid('11111111-2222-3333-4444-555555555555');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Save a grant through the listener.
	 *
	 * @param array<string,mixed> $data The grant.
	 *
	 * @return ObjectCreatingEvent The handled event.
	 */
	private function save(array $data): ObjectCreatingEvent {
		$event = new ObjectCreatingEvent($this->entity('openbuild-grants-production', 'grant', $data));
		$this->listener->handle($event);
		return $event;
	}//end save()

	public function testTheIndexRecordsTheFormatOfEachField(): void {
		$bindings = $this->index->bindingsFor(registerKeys: ['openbuild-grants-production'], schemaKeys: ['grant']);

		self::assertCount(1, $bindings);
		self::assertSame(
			['rekeningnummer' => 'iban', 'kenteken' => 'nl-licence-plate', 'postcode' => 'postcode', 'mail' => 'email'],
			$bindings[0]['formats']
		);
		self::assertSame([], $bindings[0]['calculate']);
	}

	public function testAWrongIbanIsRefusedWithItsMessage(): void {
		$event = $this->save(['rekeningnummer' => 'NL91ABNA0417164301']);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(
			['status' => 422, 'code' => 'invalid_format', 'message' => 'This IBAN is not valid. Check the digits.', 'field' => 'rekeningnummer'],
			$event->getErrors()
		);
	}

	public function testALicencePlateAndAPostcodeAreStoredNormalised(): void {
		$event = $this->save(['rekeningnummer' => 'NL91ABNA0417164300', 'kenteken' => 'gz-482-k', 'postcode' => '1234 ab']);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(['kenteken' => 'GZ482K', 'postcode' => '1234AB'], $event->getModifiedData());
	}

	public function testEmptyValuesAndOtherFormatsAreNotChecked(): void {
		$event = $this->save(['rekeningnummer' => '', 'mail' => 'not-an-email', 'naam' => 'x']);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData());
		$this->engine->expects(self::never())->method(self::anything());
	}
}//end class
