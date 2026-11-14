<?php

/**
 * Unit tests for JourneyWritesCheck.
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
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-write-mappings-must-be-validated-against-the-target-schema-at-author-time
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\FormDestinationCheck;
use OCA\Buildiq\Service\JourneyWritesCheck;
use OCA\Buildiq\Service\RegistrationFormTargetSchemaReader;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every write of a journey reaches OpenRegister's validator through
 * FormDestinationCheck, with its step and write named on each finding.
 *
 * @covers \OCA\Buildiq\Service\JourneyWritesCheck
 * @uses   \OCA\Buildiq\Service\FormDestinationCheck
 * @uses   \OCA\Buildiq\Service\RegistrationFormTargetSchemaReader
 */
final class JourneyWritesCheckTest extends TestCase {

	/**
	 * The validator double of the check built last.
	 *
	 * @var object
	 */
	private object $validator;

	/**
	 * An unknown target property is refused, naming the property, the step and the write.
	 *
	 * @return void
	 */
	public function testAnUnknownTargetPropertyIsRefusedByName(): void {
		$findings = $this->check()->findings(journey: $this->journey(property: 'naam'));

		self::assertSame(
			[
				[
					'step'     => 'applicant',
					'write'    => 'org',
					'field'    => 'name',
					'property' => 'naam',
					'code'     => 'property-unknown',
					'message'  => 'The schema "organisation" has no property "naam".',
				],
			],
			$findings
		);
	}//end testAnUnknownTargetPropertyIsRefusedByName()

	/**
	 * The designer and the submit path reject one invalid mapping identically: both ask the same validator.
	 *
	 * @return void
	 */
	public function testAuthorTimeAndSubmitTimeRejectAMappingIdentically(): void {
		$journey  = $this->journey(property: 'naam');
		$write    = $journey['steps'][0]['writes'][0];
		$designer = $this->check()->findings(journey: $journey);

		$runPath = $this->destinationCheck()->check(register: $write['register'], schema: $write['schema'], mapping: $write['mapping']);

		self::assertNotSame([], $runPath);
		self::assertSame($runPath, array_map(static function (array $finding): array {
			unset($finding['step'], $finding['write']);
			return $finding;
		}, $designer));
	}//end testAuthorTimeAndSubmitTimeRejectAMappingIdentically()

	/**
	 * A contact referencing the organisation written before it validates, with the submit path's placeholder id.
	 *
	 * @return void
	 */
	public function testADependentWriteValidates(): void {
		$journey = $this->journey(property: 'name');
		$journey['audience'] = 'public';
		$journey['steps'][0]['writes'][] = [
			'as'       => 'contact',
			'register' => 'crm',
			'schema'   => 'contact',
			'mapping'  => ['fields' => [], 'fixed' => ['organisation' => ['$write' => 'org'], 'role' => 'applicant']],
		];

		$findings = $this->check()->findings(journey: $journey);

		self::assertSame([], $findings);
		self::assertCount(2, $this->validator->calls);
		self::assertSame(
			['fields' => [], 'fixed' => ['organisation' => '00000000-0000-4000-8000-000000000000', 'role' => 'applicant']],
			$this->validator->calls[1][0]
		);
		self::assertSame(['audience' => 'public'], $this->validator->calls[1][2]);
	}//end testADependentWriteValidates()

	/**
	 * A reference to a write made later is refused with OpenRegister's code.
	 *
	 * @return void
	 */
	public function testAReferenceToALaterWriteIsRefused(): void {
		$journey = [
			'steps' => [
				['id' => 'one', 'writes' => [['as' => 'contact', 'register' => 'crm', 'schema' => 'contact', 'mapping' => ['fixed' => ['organisation' => ['$write' => 'org']]]]]],
				['id' => 'two', 'writes' => [['as' => 'org', 'register' => 'crm', 'schema' => 'organisation', 'mapping' => []]]],
			],
		];

		$findings = $this->check()->findings(journey: $journey);

		self::assertSame(
			[
				[
					'step'     => 'one',
					'write'    => 'contact',
					'property' => 'organisation',
					'code'     => 'write-unknown',
					'message'  => 'Write "org" is referenced before it is made.',
				],
			],
			$findings
		);
		self::assertSame([], $this->validator->calls[0][2], 'an internal journey names no audience');
	}//end testAReferenceToALaterWriteIsRefused()

	/**
	 * A write without a destination and a step whose writes are not a list are both refused.
	 *
	 * @return void
	 */
	public function testMalformedWritesAreRefused(): void {
		$journey = [
			'steps' => [
				['id' => 'a', 'writes' => [['mapping' => []]]],
				['id' => 'b', 'writes' => ['as' => 'x']],
				['id' => 'c'],
				'not a step',
			],
		];

		$findings = $this->check()->findings(journey: $journey);

		self::assertSame(
			[['a', 'write0', FormDestinationCheck::DESTINATION_MISSING], ['b', '', JourneyWritesCheck::WRITES_INVALID]],
			array_map(static fn (array $f): array => [$f['step'], $f['write'], $f['code']], $findings)
		);
		self::assertSame([], $this->validator->calls);
	}//end testMalformedWritesAreRefused()

	/**
	 * A journey with one write mapping `name` onto the property given.
	 *
	 * @param string $property The target property.
	 *
	 * @return array<string, mixed> The journey.
	 */
	private function journey(string $property): array {
		return [
			'id'    => 'j1',
			'steps' => [
				[
					'id'     => 'applicant',
					'type'   => 'form',
					'formId' => 'form-applicant',
					'writes' => [
						[
							'as'       => 'org',
							'register' => 'crm',
							'schema'   => 'organisation',
							'mapping'  => ['fields' => [['field' => 'name', 'property' => $property, 'type' => 'text']]],
						],
					],
				],
				['id' => 'review', 'type' => 'review'],
			],
		];
	}//end journey()

	/**
	 * The check over the destination check over a validator that knows the property `name` only.
	 *
	 * @return JourneyWritesCheck The check.
	 */
	private function check(): JourneyWritesCheck {
		return new JourneyWritesCheck(destinations: $this->destinationCheck());
	}//end check()

	/**
	 * FormDestinationCheck over a register holding one schema and a validator that knows the property `name` only.
	 *
	 * @return FormDestinationCheck The destination check.
	 */
	private function destinationCheck(): FormDestinationCheck {
		$this->validator = new class {
			/**
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * @param array<string, mixed> $mapping The mapping.
			 * @param Schema               $schema  The schema.
			 * @param array<string, mixed> $options The options.
			 *
			 * @return array<int, array<string, mixed>> The findings.
			 */
			public function validate(array $mapping, Schema $schema, array $options = []): array {
				$this->calls[] = [$mapping, $schema, $options];
				$findings = [];
				foreach (($mapping['fields'] ?? []) as $field) {
					if (($field['property'] ?? '') !== 'name') {
						$findings[] = [
							'field'    => $field['field'],
							'property' => $field['property'],
							'code'     => 'property-unknown',
							'message'  => sprintf('The schema "organisation" has no property "%s".', $field['property']),
						];
					}
				}

				return $findings;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($this->validator);

		$register = $this->createMock(Register::class);
		$register->method('getSchemas')->willReturn([7, 8]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$schemas = [];
		foreach ([7 => 'organisation', 8 => 'contact'] as $id => $slug) {
			$schemas[$id] = $this->createMock(Schema::class);
			$schemas[$id]->method('getSlug')->willReturn($slug);
		}

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(static fn ($id): Schema => $schemas[(int)$id]);

		return new FormDestinationCheck(
			container: $container,
			schemas: new RegistrationFormTargetSchemaReader(
				registerMapper: $registerMapper,
				schemaMapper: $schemaMapper,
				logger: $this->createMock(LoggerInterface::class),
			),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end destinationCheck()
}//end class
