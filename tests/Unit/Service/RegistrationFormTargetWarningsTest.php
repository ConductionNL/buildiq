<?php

/**
 * Unit tests for RegistrationFormTargetWarnings, the registration form's destination check.
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
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-a-registration-forms-presets-are-checked-against-its-destination
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\FormDestinationCheck;
use OCA\Buildiq\Service\RegistrationFormTargetSchemaReader;
use OCA\Buildiq\Service\RegistrationFormTargetWarnings;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers the mapping (fields, presets as fixed values, audience), the two
 * findings a registration form field cannot earn, and, against the real
 * validator when present, the spec scenarios.
 */
final class RegistrationFormTargetWarningsTest extends TestCase {
	/**
	 * Fields write the property they are named after; every preset is a fixed value.
	 *
	 * @return void
	 */
	public function testTheMappingCarriesFieldsAndPresetsAsFixedValues(): void {
		$warnings = new RegistrationFormTargetWarnings(check: $this->checkAnswering(findings: [])[0]);

		$mapping = $warnings->mappingOf(
			form: [
				'fields' => [['name' => 'naam', 'type' => 'text', 'label' => 'Naam'], ['label' => 'no name']],
				'presets' => [
					['field' => 'caseType', 'value' => 'omgevingsvergunning', 'hidden' => true],
					['field' => 'intakeChannel', 'value' => 'portal', 'hidden' => false],
				],
			]
		);

		self::assertSame(
			[
				'fields' => [['field' => 'naam', 'property' => 'naam', 'type' => 'text']],
				'fixed' => ['caseType' => 'omgevingsvergunning', 'intakeChannel' => 'portal'],
			],
			$mapping
		);
	}//end testTheMappingCarriesFieldsAndPresetsAsFixedValues()

	/**
	 * A public form is checked with audience `public`; any other form names none.
	 *
	 * @return void
	 */
	public function testOnlyAPublicFormIsCheckedForAPublicAudience(): void {
		[$check, $validator] = $this->checkAnswering(findings: []);
		$warnings = new RegistrationFormTargetWarnings(check: $check);

		$warnings->findings(form: $this->form(['isPublic' => true]));
		$warnings->findings(form: $this->form(['audience' => 'client']));

		self::assertSame(['audience' => 'public'], $validator->calls[0][2]);
		self::assertSame([], $validator->calls[1][2]);
	}//end testOnlyAPublicFormIsCheckedForAPublicAudience()

	/**
	 * A field with no options of its own renders its property's enum, so
	 * `enum-unconstrained` does not apply; a field with its own options keeps it,
	 * and every other code stands.
	 *
	 * @return void
	 */
	public function testAFieldRenderedFromItsPropertyDoesNotEarnTheConstraintFindings(): void {
		$answer = [
			['field' => 'kanaal', 'property' => 'kanaal', 'code' => 'enum-unconstrained', 'message' => 'A'],
			['field' => 'eigen', 'property' => 'eigen', 'code' => 'enum-unconstrained', 'message' => 'B'],
			['field' => 'kanaal', 'property' => 'kanaal', 'code' => 'type-mismatch', 'message' => 'C'],
			['property' => 'caseType', 'code' => 'required-unmapped', 'message' => 'D'],
		];
		$warnings = new RegistrationFormTargetWarnings(check: $this->checkAnswering(findings: $answer)[0]);

		$found = $warnings->findings(
			form: $this->form(['fields' => [['name' => 'kanaal'], ['name' => 'eigen', 'options' => ['a']]]])
		);

		self::assertSame(['B', 'C', 'D'], array_column($found, 'message'));
	}//end testAFieldRenderedFromItsPropertyDoesNotEarnTheConstraintFindings()

	/**
	 * Spec scenarios against OpenRegister's real validator: a preset outside the
	 * enum is `fixed-value-invalid`, a missing `caseType` is
	 * `required-unmapped`, and a preset filling `caseType` clears it.
	 *
	 * @return void
	 */
	public function testTheSpecScenariosAgainstTheRealValidator(): void {
		if (class_exists(FormDestinationCheck::VALIDATOR) === false) {
			self::markTestSkipped('OpenRegister without the form destination validator (openregister#4563) on the autoload path.');
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$class = FormDestinationCheck::VALIDATOR;
		$validator = new $class($l10n, new \OCA\OpenRegister\Service\Form\FormFieldRules());
		$warnings = new RegistrationFormTargetWarnings(check: $this->checkOver(validator: $validator, schema: FormDestinationCheckTest::caseSchema()));
		$fields = [['name' => 'title', 'type' => 'text']];

		$noCaseType = $warnings->findings(form: $this->form(['fields' => $fields]));
		$preset = $warnings->findings(
			form: $this->form(['fields' => $fields, 'presets' => [['field' => 'caseType', 'value' => 'omgevingsvergunning', 'hidden' => true]]])
		);
		$fax = $warnings->findings(
			form: $this->form(
				[
					'fields' => $fields,
					'presets' => [
						['field' => 'caseType', 'value' => 'omgevingsvergunning', 'hidden' => true],
						['field' => 'intakeChannel', 'value' => 'fax', 'hidden' => true],
					],
				]
			)
		);

		self::assertSame([['caseType', 'required-unmapped']], FormDestinationCheckTest::pairs(findings: $noCaseType));
		self::assertSame([], $preset);
		self::assertSame([['intakeChannel', 'fixed-value-invalid']], FormDestinationCheckTest::pairs(findings: $fax));
	}//end testTheSpecScenariosAgainstTheRealValidator()

	/**
	 * One registration form into dossiq `case`.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The form.
	 */
	private function form(array $overrides = []): array {
		return array_merge(['id' => 'rf-1', 'register' => 'dossiq', 'schema' => 'case', 'audience' => 'client', 'status' => 'published'], $overrides);
	}//end form()

	/**
	 * A check over a recording validator double.
	 *
	 * @param array<int, array<string, mixed>> $findings What the double answers.
	 *
	 * @return array{0: FormDestinationCheck, 1: object} The check and the double.
	 */
	private function checkAnswering(array $findings): array {
		$validator = new class($findings) {
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

		$schema = $this->createMock(Schema::class);
		$schema->method('getSlug')->willReturn('case');

		return [$this->checkOver(validator: $validator, schema: $schema), $validator];
	}//end checkAnswering()

	/**
	 * A check over this validator and a register holding this schema.
	 *
	 * @param object $validator The validator.
	 * @param Schema $schema The schema.
	 *
	 * @return FormDestinationCheck The check.
	 */
	private function checkOver(object $validator, Schema $schema): FormDestinationCheck {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($validator);

		$register = $this->createMock(Register::class);
		$register->method('getSchemas')->willReturn([7]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		return new FormDestinationCheck(
			container: $container,
			schemas: new RegistrationFormTargetSchemaReader(
				registerMapper: $registerMapper,
				schemaMapper: $schemaMapper,
				logger: $this->createMock(LoggerInterface::class),
			),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end checkOver()
}//end class
