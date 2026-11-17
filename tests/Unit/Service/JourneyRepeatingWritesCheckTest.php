<?php

/**
 * JourneyWritesCheck on repeating writes (forms-multi-product-request).
 *
 * A write that repeats over a product list with a target per product is
 * checked against the schema of every product, with the run's own validator,
 * and a field missing on one product's schema is refused naming that
 * product; the repeat settings themselves are refused when they cannot run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-each-item-goes-to-its-products-target-req-bqmp-002
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
 * Repeating writes with a target per product.
 *
 * @covers \OCA\Buildiq\Service\JourneyWritesCheck
 * @uses   \OCA\Buildiq\Service\FormDestinationCheck
 * @uses   \OCA\Buildiq\Service\RegistrationFormTargetSchemaReader
 */
final class JourneyRepeatingWritesCheckTest extends TestCase {

	/**
	 * The properties each schema holds, by slug.
	 */
	private const PROPERTIES = [
		'parkeervergunning' => ['omschrijving', 'kenteken', 'zaaktype'],
		'afvalcontainer'    => ['omschrijving', 'zaaktype'],
		'aanvraagbundel'    => ['aanvrager'],
	];

	/**
	 * The schemas each validator call was made against, in order.
	 *
	 * @var list<string>
	 */
	private array $validated = [];

	/**
	 * A field missing on one product's schema is refused naming the product and the field.
	 *
	 * @return void
	 */
	public function testAFieldMissingOnOneTargetIsNamedWithItsProduct(): void {
		$findings = $this->check()->findings(journey: $this->journey(fields: ['toelichting' => 'omschrijving', 'kenteken' => 'kenteken']));

		self::assertSame(['parkeervergunning', 'afvalcontainer'], $this->validated, 'every target schema is checked');
		self::assertSame(
			[
				[
					'step'     => 'products',
					'write'    => 'aanvraag',
					'product'  => 'afvalcontainer',
					'field'    => 'item.kenteken',
					'property' => 'kenteken',
					'code'     => 'property-unknown',
					'message'  => 'afvalcontainer: kenteken does not exist on the target schema.',
				],
			],
			$findings
		);
	}//end testAFieldMissingOnOneTargetIsNamedWithItsProduct()

	/**
	 * Two products whose schemas both hold the mapped fields are accepted.
	 *
	 * @return void
	 */
	public function testTwoProductsWhoseSchemasFitAreAccepted(): void {
		self::assertSame([], $this->check()->findings(journey: $this->journey(fields: ['toelichting' => 'omschrijving'])));
		self::assertSame(['parkeervergunning', 'afvalcontainer'], $this->validated);
	}//end testTwoProductsWhoseSchemasFitAreAccepted()

	/**
	 * A bundle written before the repeating write may be referenced by it.
	 *
	 * @return void
	 */
	public function testARepeatingWriteMayReferenceTheBundleWrittenBeforeIt(): void {
		$journey = $this->journey(fields: ['toelichting' => 'omschrijving']);
		array_unshift(
			$journey['steps'][0]['writes'],
			['as' => 'bundel', 'bundle' => true, 'register' => 'loket', 'schema' => 'aanvraagbundel', 'mapping' => ['fields' => []]]
		);
		$journey['steps'][0]['writes'][1]['mapping']['fixed'] = ['bundel' => ['$write' => 'bundel']];

		self::assertSame([], array_column($this->check()->findings(journey: $journey), 'code'));
		self::assertSame(['aanvraagbundel', 'parkeervergunning', 'afvalcontainer'], $this->validated);
	}//end testARepeatingWriteMayReferenceTheBundleWrittenBeforeIt()

	/**
	 * Repeat settings that cannot run are refused with their own codes.
	 *
	 * @return void
	 */
	public function testRepeatSettingsThatCannotRunAreRefused(): void {
		$cases = [
			'targets without forEach' => [['forEach' => null], [JourneyWritesCheck::TARGETS_WITHOUT_FOREACH]],
			'an empty forEach'        => [['forEach' => ' '], [JourneyWritesCheck::FOREACH_INVALID, JourneyWritesCheck::TARGETS_WITHOUT_FOREACH]],
			'targets without targetBy' => [['targetBy' => ''], [JourneyWritesCheck::TARGET_BY_MISSING]],
			'a repeating bundle'      => [['bundle' => true], [JourneyWritesCheck::BUNDLE_REPEATS]],
		];

		foreach ($cases as $name => [$changes, $codes]) {
			$journey = $this->journey(fields: ['toelichting' => 'omschrijving']);
			$write   = array_merge($journey['steps'][0]['writes'][0], $changes);
			if (array_key_exists('forEach', $changes) === true && $changes['forEach'] === null) {
				unset($write['forEach']);
			}

			$journey['steps'][0]['writes'][0] = $write;

			self::assertSame($codes, array_column($this->check()->findings(journey: $journey), 'code'), $name);
		}
	}//end testRepeatSettingsThatCannotRunAreRefused()

	/**
	 * A product whose target names no schema is refused by product, the others still checked.
	 *
	 * @return void
	 */
	public function testAProductWithoutASchemaIsRefusedByName(): void {
		$journey = $this->journey(fields: ['toelichting' => 'omschrijving']);
		$journey['steps'][0]['writes'][0]['targets']['afvalcontainer'] = ['register' => 'loket', 'typeValue' => 'AC'];

		$findings = $this->check()->findings(journey: $journey);

		self::assertSame([[JourneyWritesCheck::TARGET_INVALID, 'afvalcontainer', 'afvalcontainer: the target needs a register and a schema.']], array_map(
			static fn (array $f): array => [$f['code'], $f['product'], $f['message']],
			$findings
		));
		self::assertSame(['parkeervergunning'], $this->validated);
	}//end testAProductWithoutASchemaIsRefusedByName()

	/**
	 * A journey asking a product list and repeating one write over it, to two product schemas.
	 *
	 * @param array<string, string> $fields Item field => target property.
	 *
	 * @return array<string, mixed> The journey.
	 */
	private function journey(array $fields): array {
		$mapped = [];
		foreach ($fields as $field => $property) {
			$mapped[] = ['field' => 'item.' . $field, 'property' => $property, 'type' => 'text'];
		}

		return [
			'id'    => 'j1',
			'steps' => [
				[
					'id'     => 'products',
					'type'   => 'form',
					'formId' => 'form-products',
					'writes' => [
						[
							'as'       => 'aanvraag',
							'forEach'  => 'producten',
							'targetBy' => 'product',
							'targets'  => [
								'parkeervergunning' => ['register' => 'loket', 'schema' => 'parkeervergunning', 'typeValue' => 'PV'],
								'afvalcontainer'    => ['register' => 'loket', 'schema' => 'afvalcontainer', 'typeValue' => 'AC'],
							],
							'mapping'  => ['fields' => $mapped],
						],
					],
				],
			],
		];
	}//end journey()

	/**
	 * The check over FormDestinationCheck over a validator knowing PROPERTIES per schema.
	 *
	 * @return JourneyWritesCheck The check.
	 */
	private function check(): JourneyWritesCheck {
		$this->validated = [];
		$validator = new class ($this) {
			/**
			 * @param JourneyRepeatingWritesCheckTest $test The test, which records each call.
			 */
			public function __construct(private readonly JourneyRepeatingWritesCheckTest $test) {
			}

			/**
			 * @param array<string, mixed> $mapping The mapping.
			 * @param Schema               $schema  The schema.
			 * @param array<string, mixed> $options The options.
			 *
			 * @return array<int, array<string, mixed>> The findings.
			 */
			public function validate(array $mapping, Schema $schema, array $options = []): array {
				return $this->test->validate(mapping: $mapping, slug: (string)$schema->getSlug());
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($validator);

		$slugs = array_keys(self::PROPERTIES);
		$register = $this->createMock(Register::class);
		$register->method('getSchemas')->willReturn(array_keys($slugs));
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$schemas = [];
		foreach ($slugs as $id => $slug) {
			$schemas[$id] = $this->createMock(Schema::class);
			$schemas[$id]->method('getSlug')->willReturn($slug);
		}

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(static fn ($id): Schema => $schemas[(int)$id]);

		return new JourneyWritesCheck(
			destinations: new FormDestinationCheck(
				container: $container,
				schemas: new RegistrationFormTargetSchemaReader(
					registerMapper: $registerMapper,
					schemaMapper: $schemaMapper,
					logger: $this->createMock(LoggerInterface::class),
				),
				logger: $this->createMock(LoggerInterface::class),
			)
		);
	}//end check()

	/**
	 * The validator double: a mapped property the schema lacks is a finding.
	 *
	 * @param array<string, mixed> $mapping The mapping.
	 * @param string               $slug    The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The findings.
	 */
	public function validate(array $mapping, string $slug): array {
		$this->validated[] = $slug;
		$findings = [];
		foreach (($mapping['fields'] ?? []) as $field) {
			if (in_array($field['property'], self::PROPERTIES[$slug], true) === false) {
				$findings[] = [
					'field'    => $field['field'],
					'property' => $field['property'],
					'code'     => 'property-unknown',
					'message'  => sprintf('%s does not exist on the target schema.', $field['property']),
				];
			}
		}

		return $findings;
	}//end validate()
}//end class
