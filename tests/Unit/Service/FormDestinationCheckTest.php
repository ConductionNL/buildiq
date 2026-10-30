<?php

/**
 * Unit tests for FormDestinationCheck.
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
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\FormDestinationCheck;
use OCA\Buildiq\Service\RegistrationFormTargetSchemaReader;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the refusals buildiq adds (no destination, unreadable schema, no
 * validator), the pass-through to OpenRegister's validator, and, when the
 * real validator is on the autoload path, the spec scenarios against it.
 */
final class FormDestinationCheckTest extends TestCase {
	/**
	 * A form with no register or schema has nowhere to save into.
	 *
	 * @return void
	 */
	public function testAFormWithoutADestinationIsRefused(): void {
		$check = new FormDestinationCheck(
			container: $this->containerWith(validator: null),
			schemas: $this->readerOver(schema: null),
			logger: $this->createMock(LoggerInterface::class),
		);

		$findings = $check->check(register: '', schema: 'case', mapping: []);

		self::assertSame([FormDestinationCheck::DESTINATION_MISSING], array_column($findings, 'code'));
	}//end testAFormWithoutADestinationIsRefused()

	/**
	 * A schema that cannot be read refuses: a check that did not run is not a pass.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaRefuses(): void {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willThrowException(new RuntimeException('register gone'));

		$check = new FormDestinationCheck(
			container: $this->containerWith(validator: $this->validatorAnswering(findings: [])),
			schemas: new RegistrationFormTargetSchemaReader(
				registerMapper: $registerMapper,
				schemaMapper: $this->createMock(SchemaMapper::class),
				logger: $this->createMock(LoggerInterface::class),
			),
			logger: $this->createMock(LoggerInterface::class),
		);

		$findings = $check->check(register: 'dossiq', schema: 'case', mapping: []);

		self::assertSame([FormDestinationCheck::DESTINATION_UNCHECKED], array_column($findings, 'code'));
		self::assertStringContainsString('could not be read', $findings[0]['message']);
	}//end testAnUnreadableSchemaRefuses()

	/**
	 * A register that holds no such schema refuses.
	 *
	 * @return void
	 */
	public function testARegisterWithoutTheSchemaRefuses(): void {
		$check = new FormDestinationCheck(
			container: $this->containerWith(validator: $this->validatorAnswering(findings: [])),
			schemas: $this->readerOver(schema: null),
			logger: $this->createMock(LoggerInterface::class),
		);

		$findings = $check->check(register: 'dossiq', schema: 'case', mapping: []);

		self::assertSame([FormDestinationCheck::DESTINATION_UNCHECKED], array_column($findings, 'code'));
		self::assertStringContainsString('holds no schema', $findings[0]['message']);
	}//end testARegisterWithoutTheSchemaRefuses()

	/**
	 * An OpenRegister without the validator refuses rather than passing silently.
	 *
	 * @return void
	 */
	public function testAnOpenRegisterWithoutTheValidatorRefuses(): void {
		$check = new FormDestinationCheck(
			container: $this->containerWith(validator: null),
			schemas: $this->readerOver(schema: $this->schemaDouble()),
			logger: $this->createMock(LoggerInterface::class),
		);

		$findings = $check->check(register: 'dossiq', schema: 'case', mapping: []);

		self::assertSame([FormDestinationCheck::DESTINATION_UNCHECKED], array_column($findings, 'code'));
		self::assertStringContainsString('cannot check forms', $findings[0]['message']);
	}//end testAnOpenRegisterWithoutTheValidatorRefuses()

	/**
	 * The control: an accepting validator gives no findings, and the mapping,
	 * the resolved schema and the options reach it unchanged.
	 *
	 * @return void
	 */
	public function testAnAcceptedMappingHasNoFindingsAndReachesTheValidatorWhole(): void {
		$schema = $this->schemaDouble();
		$validator = $this->validatorAnswering(findings: []);
		$check = new FormDestinationCheck(
			container: $this->containerWith(validator: $validator),
			schemas: $this->readerOver(schema: $schema),
			logger: $this->createMock(LoggerInterface::class),
		);
		$mapping = ['fields' => [['field' => 'onderwerp', 'property' => 'title', 'type' => 'text']], 'fixed' => ['caseType' => 'x']];

		$findings = $check->check(register: 'dossiq', schema: 'case', mapping: $mapping, options: ['audience' => 'public']);

		self::assertSame([], $findings);
		self::assertSame([$mapping, $schema, ['audience' => 'public']], $validator->calls[0]);
	}//end testAnAcceptedMappingHasNoFindingsAndReachesTheValidatorWhole()

	/**
	 * Every finding the validator returns comes back, in its order.
	 *
	 * @return void
	 */
	public function testEveryValidatorFindingIsReturned(): void {
		$answer = [
			['field' => 'a', 'property' => 'a', 'code' => 'type-mismatch', 'message' => 'A'],
			['property' => 'caseType', 'code' => 'required-unmapped', 'message' => 'B'],
		];
		$check = new FormDestinationCheck(
			container: $this->containerWith(validator: $this->validatorAnswering(findings: $answer)),
			schemas: $this->readerOver(schema: $this->schemaDouble()),
			logger: $this->createMock(LoggerInterface::class),
		);

		self::assertSame($answer, $check->check(register: 'dossiq', schema: 'case', mapping: []));
	}//end testEveryValidatorFindingIsReturned()

	/**
	 * Spec scenario, against OpenRegister's real validator: a form into a
	 * case that sets no `caseType` is refused with `required-unmapped`, and a
	 * preset filling `caseType` clears it.
	 *
	 * @return void
	 */
	public function testTheRealValidatorFindsAnUnmappedRequiredPropertyAndAPresetFillsIt(): void {
		$check = $this->checkOverTheRealValidator();

		$without = $check->check(
			register: 'dossiq',
			schema: 'case',
			mapping: ['fields' => [['field' => 'title', 'property' => 'title', 'type' => 'text']], 'fixed' => []]
		);
		$with = $check->check(
			register: 'dossiq',
			schema: 'case',
			mapping: ['fields' => [['field' => 'title', 'property' => 'title', 'type' => 'text']], 'fixed' => ['caseType' => 'omgevingsvergunning']]
		);

		self::assertSame([['caseType', 'required-unmapped']], self::pairs(findings: $without));
		self::assertSame([], $with);
	}//end testTheRealValidatorFindsAnUnmappedRequiredPropertyAndAPresetFillsIt()

	/**
	 * A check over OpenRegister's real validator and a real `case` schema, or a skip.
	 *
	 * @return FormDestinationCheck The check.
	 */
	private function checkOverTheRealValidator(): FormDestinationCheck {
		if (class_exists(FormDestinationCheck::VALIDATOR) === false) {
			self::markTestSkipped('OpenRegister without the form destination validator (openregister#4563) on the autoload path.');
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$class = FormDestinationCheck::VALIDATOR;
		$validator = new $class($l10n, new \OCA\OpenRegister\Service\Form\FormFieldRules());

		return new FormDestinationCheck(
			container: $this->containerWith(validator: $validator),
			schemas: $this->readerOver(schema: self::caseSchema()),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end checkOverTheRealValidator()

	/**
	 * A real `case` schema: `title` and `caseType` required, a channel enum.
	 *
	 * @return Schema The schema.
	 */
	public static function caseSchema(): Schema {
		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setRequired(['title', 'caseType']);
		$schema->setProperties(
			[
				'title' => ['type' => 'string'],
				'caseType' => ['type' => 'string'],
				'intakeChannel' => ['type' => 'string', 'enum' => ['portal', 'phone', 'desk']],
				'startDate' => ['type' => 'string', 'format' => 'date'],
			]
		);

		return $schema;
	}//end caseSchema()

	/**
	 * Findings as `[property, code]` pairs, for readable assertions.
	 *
	 * @param array<int, array<string, mixed>> $findings The findings.
	 *
	 * @return array<int, array{0: string, 1: string}> The pairs.
	 */
	public static function pairs(array $findings): array {
		return array_map(static fn (array $f): array => [(string)$f['property'], (string)$f['code']], $findings);
	}//end pairs()

	/**
	 * A reader whose register holds this schema, or none.
	 *
	 * The real reader over doubled mappers, not a double of the reader.
	 *
	 * @param Schema|null $schema The schema, or null for an empty register.
	 *
	 * @return RegistrationFormTargetSchemaReader The reader.
	 */
	private function readerOver(?Schema $schema): RegistrationFormTargetSchemaReader {
		$register = $this->createMock(Register::class);
		$register->method('getSchemas')->willReturn($schema === null ? [] : [7]);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		if ($schema !== null) {
			$schemaMapper->method('find')->willReturn($schema);
		}

		return new RegistrationFormTargetSchemaReader(
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end readerOver()

	/**
	 * A schema double answering the slug `case`.
	 *
	 * @return Schema The double.
	 */
	private function schemaDouble(): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getSlug')->willReturn('case');

		return $schema;
	}//end schemaDouble()

	/**
	 * A container that holds this validator, or none.
	 *
	 * @param object|null $validator The validator.
	 *
	 * @return ContainerInterface The container.
	 */
	private function containerWith(?object $validator): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn($validator !== null);
		$container->method('get')->willReturn($validator);

		return $container;
	}//end containerWith()

	/**
	 * A validator double with OpenRegister's `validate` signature, recording its calls.
	 *
	 * @param array<int, array<string, mixed>> $findings What it answers.
	 *
	 * @return object The double.
	 */
	private function validatorAnswering(array $findings): object {
		return new class($findings) {
			/**
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

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
				$this->calls[] = [$mapping, $schema, $options];
				return $this->findings;
			}
		};
	}//end validatorAnswering()
}//end class
