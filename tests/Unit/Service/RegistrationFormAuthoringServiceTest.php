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
use OCA\Buildiq\Exception\FormDestinationRefusedException;
use OCA\Buildiq\Service\FormDestinationCheck;
use OCA\Buildiq\Service\RegistrationFormTargetSchemaReader;
use OCA\Buildiq\Service\RegistrationFormTargetWarnings;
use OCA\Buildiq\Service\RegistrationFormValidator;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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

		$this->service = new RegistrationFormAuthoringService(
			objectService: $this->objectServiceOverStore(),
			appConfig: $this->appConfigStub(),
			validator: new RegistrationFormValidator(),
			targetSchema: $this->readerOver(
				[
					'caseType' => ['type' => 'string'],
					'naam' => ['type' => 'string'],
					'intakeChannel' => ['type' => 'string', 'enum' => ['portal', 'desk']],
				]
			),
			destination: $this->destinationAnswering(findings: []),
		);
	}//end setUp()

	/**
	 * The destination check over a validator double answering these findings.
	 *
	 * @param array<int, array<string, mixed>> $findings What OpenRegister's validator answers.
	 * @param RegistrationFormTargetSchemaReader|null $reader The reader, or one over `Zaak`.
	 *
	 * @return RegistrationFormTargetWarnings The destination check.
	 */
	private function destinationAnswering(array $findings, ?RegistrationFormTargetSchemaReader $reader = null): RegistrationFormTargetWarnings {
		$validator = new class($findings) {
			/**
			 * @param array<int, array<string, mixed>> $findings What to answer.
			 */
			public function __construct(private readonly array $findings) {
			}

			/**
			 * @param array<string, mixed> $mapping The mapping.
			 * @param Schema $schema The schema.
			 * @param array<string, mixed> $options The options.
			 *
			 * @return array<int, array<string, mixed>> The findings.
			 */
			public function validate(array $mapping, Schema $schema, array $options = []): array {
				return $this->findings;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($validator);

		return new RegistrationFormTargetWarnings(
			check: new FormDestinationCheck(
				container: $container,
				schemas: $reader ?? $this->readerOver(['caseType' => ['type' => 'string']]),
				logger: $this->createMock(LoggerInterface::class),
			),
		);
	}//end destinationAnswering()

	/**
	 * An object service over the in-memory store.
	 *
	 * @return ObjectServiceInterface The double.
	 */
	private function objectServiceOverStore(): ObjectServiceInterface {
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

		return $objectService;
	}//end objectServiceOverStore()

	/**
	 * App config answering buildiq's own register slug.
	 *
	 * @return IAppConfig The double.
	 */
	private function appConfigStub(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('buildiq');

		return $appConfig;
	}//end appConfigStub()

	/**
	 * A reader over one register holding the `Zaak` schema.
	 *
	 * The real reader over doubled mappers, not a double of the reader: the
	 * shape it returns is the contract the save path depends on, and a double
	 * would let that shape drift without a red test.
	 *
	 * @param array<string, mixed> $properties What `Zaak` declares.
	 *
	 * @return RegistrationFormTargetSchemaReader The reader.
	 */
	private function readerOver(array $properties): RegistrationFormTargetSchemaReader {
		$schema = $this->createMock(Schema::class);
		$schema->method('getSlug')->willReturn('Zaak');
		$schema->method('getProperties')->willReturn($properties);

		$register = $this->createMock(Register::class);
		$register->method('getSchemas')->willReturn([1]);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		return new RegistrationFormTargetSchemaReader(
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end readerOver()

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
		// A draft: the destination double only knows `Zaak`, and this test is
		// about the name rule, not the destination check.
		$this->service->save($this->form(['id' => 'rf-3', 'schema' => 'Melding', 'status' => 'draft', 'isDefault' => false]));

		$this->assertCount(2, $this->store);
	}//end testTheSameNameOnAnotherSchemaIsFree()

	/**
	 * A channel the consumer never declared is refused, and the refusal names
	 * the ones it did. This rule was written, unit-tested and enforced on
	 * nothing until the save path could read the consuming schema.
	 *
	 * @return void
	 */
	public function testAChannelTheConsumerDoesNotDeclareIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/portal, desk/');

		$this->service->save(
			$this->form(['channelProperty' => 'intakeChannel', 'channel' => 'fax'])
		);
	}//end testAChannelTheConsumerDoesNotDeclareIsRefused()

	/**
	 * A channel the consumer does declare goes through.
	 *
	 * @return void
	 */
	public function testADeclaredChannelIsAccepted(): void {
		$saved = $this->service->save(
			$this->form(['channelProperty' => 'intakeChannel', 'channel' => 'portal'])
		);

		$this->assertSame('portal', $saved['form']['channel']);
		$this->assertSame([], $saved['warnings']);
	}//end testADeclaredChannelIsAccepted()

	/**
	 * Decision 189: a draft with findings is stored, and the findings come back
	 * for the designer to mark on each field.
	 *
	 * @return void
	 */
	public function testADraftWithFindingsIsSavedAndReturnsThem(): void {
		$finding = ['property' => 'caseType', 'code' => 'required-unmapped', 'message' => '"caseType" is required.'];
		$service = $this->serviceWith(destination: $this->destinationAnswering(findings: [$finding]));

		$saved = $service->save($this->form(['status' => 'draft']));

		$this->assertArrayHasKey('rf-1', $this->store);
		$this->assertSame([$finding], $saved['findings']);
	}//end testADraftWithFindingsIsSavedAndReturnsThem()

	/**
	 * A published form with any finding is refused and nothing is stored: no
	 * report-only mode (decision 181).
	 *
	 * @return void
	 */
	public function testAPublishedFormWithFindingsIsRefusedAndNotStored(): void {
		$findings = [
			['field' => 'naam', 'property' => 'naam', 'code' => 'type-mismatch', 'message' => 'A'],
			['property' => 'intakeChannel', 'code' => 'fixed-value-invalid', 'message' => 'B'],
		];
		$service = $this->serviceWith(destination: $this->destinationAnswering(findings: $findings));

		try {
			$service->save($this->form(['status' => 'published']));
			$this->fail('A published form with findings was saved.');
		} catch (FormDestinationRefusedException $e) {
			$this->assertSame($findings, $e->getFindings());
		}

		$this->assertSame([], $this->store);
	}//end testAPublishedFormWithFindingsIsRefusedAndNotStored()

	/**
	 * A target schema nothing answers to is a finding: the check did not run,
	 * so a published form is refused. Silence would read as a clean pass.
	 *
	 * @return void
	 */
	public function testASchemaThatCannotBeReadRefusesPublishing(): void {
		$service = $this->serviceWith(
			destination: $this->destinationAnswering(findings: [], reader: $this->readerOverNothing()),
			reader: $this->readerOverNothing()
		);

		$this->expectException(FormDestinationRefusedException::class);
		$service->save($this->form());
	}//end testASchemaThatCannotBeReadRefusesPublishing()

	/**
	 * The service over the in-memory store with this destination check.
	 *
	 * @param RegistrationFormTargetWarnings $destination The destination check.
	 * @param RegistrationFormTargetSchemaReader|null $reader The reader.
	 *
	 * @return RegistrationFormAuthoringService The service.
	 */
	private function serviceWith(RegistrationFormTargetWarnings $destination, ?RegistrationFormTargetSchemaReader $reader = null): RegistrationFormAuthoringService {
		return new RegistrationFormAuthoringService(
			objectService: $this->objectServiceOverStore(),
			appConfig: $this->appConfigStub(),
			validator: new RegistrationFormValidator(),
			targetSchema: $reader ?? $this->readerOver(['caseType' => ['type' => 'string']]),
			destination: $destination,
		);
	}//end serviceWith()

	/**
	 * A reader whose register holds no schema at all.
	 *
	 * @return RegistrationFormTargetSchemaReader The reader.
	 */
	private function readerOverNothing(): RegistrationFormTargetSchemaReader {
		$register = $this->createMock(Register::class);
		$register->method('getSchemas')->willReturn([]);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		return new RegistrationFormTargetSchemaReader(
			registerMapper: $registerMapper,
			schemaMapper: $this->createMock(SchemaMapper::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end readerOverNothing()
}//end class
