<?php

/**
 * Unit tests for ManifestFormDestinations.
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
use OCA\Buildiq\Service\ManifestFormDestinations;
use OCA\Buildiq\Service\RegistrationFormTargetSchemaReader;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers which pages are checked, the field mapping, the public audience, and
 * a real type mismatch when the real validator is present.
 */
final class ManifestFormDestinationsTest extends TestCase {
	/**
	 * Only form pages with a destination are checked; each finding names its page.
	 *
	 * @return void
	 */
	public function testOnlyFormPagesWithADestinationAreCheckedAndFindingsNameTheirPage(): void {
		$answer = [['field' => 'title', 'property' => 'title', 'code' => 'type-mismatch', 'message' => 'A']];
		[$destinations, $validator] = $this->destinationsAnswering(findings: $answer);

		$findings = $destinations->findings(
			manifest: [
				'pages' => [
					['id' => 'list', 'type' => 'index', 'config' => ['register' => 'dossiq', 'schema' => 'case']],
					['id' => 'handler', 'type' => 'form', 'config' => ['submitHandler' => 'x', 'fields' => ['a']]],
					['id' => 'new', 'type' => 'form', 'config' => ['submitEndpoint' => '/apps/openregister/api/objects/dossiq/case', 'fields' => ['title']]],
				],
			]
		);

		self::assertCount(1, $validator->calls);
		self::assertSame([array_merge(['page' => 'new', 'pageIndex' => 2], $answer[0])], $findings);
	}//end testOnlyFormPagesWithADestinationAreCheckedAndFindingsNameTheirPage()

	/**
	 * Field types are translated, and the options and limits a field declares are passed on.
	 *
	 * @return void
	 */
	public function testTheMappingTranslatesTypesAndCarriesTheFieldsOwnLimits(): void {
		[$destinations] = $this->destinationsAnswering(findings: []);

		$mapping = $destinations->mappingOf(
			config: [
				'fields' => [
					['key' => 'naam', 'type' => 'string', 'validation' => ['maxLength' => 80, 'required' => true]],
					['key' => 'kanaal', 'type' => 'enum', 'enum' => ['portal', 'desk']],
					['key' => 'akkoord', 'type' => 'boolean'],
				],
			]
		);

		self::assertSame(
			[
				'fields' => [
					['field' => 'naam', 'property' => 'naam', 'type' => 'text', 'maxLength' => 80],
					['field' => 'kanaal', 'property' => 'kanaal', 'type' => 'choice', 'options' => ['portal', 'desk']],
					['field' => 'akkoord', 'property' => 'akkoord', 'type' => 'boolean'],
				],
				'fixed' => [],
			],
			$mapping
		);
	}//end testTheMappingTranslatesTypesAndCarriesTheFieldsOwnLimits()

	/**
	 * A page published for anonymous visitors is checked with audience `public`.
	 *
	 * @return void
	 */
	public function testAPagePublishedForAnonymousVisitorsIsCheckedForAPublicAudience(): void {
		[$destinations, $validator] = $this->destinationsAnswering(findings: []);

		$destinations->findings(
			manifest: [
				'pages' => [
					['id' => 'new', 'type' => 'form', 'config' => ['destination' => ['register' => 'dossiq', 'schema' => 'case'], 'fields' => ['title']]],
				],
				'runtime' => ['externalForms' => [['pageId' => 'new', 'status' => 'enabled']]],
			]
		);

		self::assertSame(['audience' => 'public'], $validator->calls[0][2]);
	}//end testAPagePublishedForAnonymousVisitorsIsCheckedForAPublicAudience()

	/**
	 * Spec scenario against the real validator: a text field into an integer is `type-mismatch`.
	 *
	 * @return void
	 */
	public function testARealTextFieldIntoAnIntegerIsATypeMismatch(): void {
		if (class_exists(FormDestinationCheck::VALIDATOR) === false) {
			self::markTestSkipped('OpenRegister without the form destination validator (openregister#4563) on the autoload path.');
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$class = FormDestinationCheck::VALIDATOR;
		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setRequired([]);
		$schema->setProperties(['aantal' => ['type' => 'integer']]);
		$destinations = new ManifestFormDestinations(
			check: $this->checkOver(validator: new $class($l10n, new \OCA\OpenRegister\Service\Form\FormFieldRules()), schema: $schema)
		);

		$findings = $destinations->findings(
			manifest: ['pages' => [['id' => 'new', 'type' => 'form', 'config' => ['register' => 'dossiq', 'schema' => 'case', 'fields' => [['key' => 'aantal', 'type' => 'string']]]]]]
		);

		self::assertSame(['type-mismatch'], array_column($findings, 'code'));
		self::assertSame('aantal', $findings[0]['field']);
	}//end testARealTextFieldIntoAnIntegerIsATypeMismatch()

	/**
	 * The service over a recording validator double.
	 *
	 * @param array<int, array<string, mixed>> $findings What the double answers.
	 *
	 * @return array{0: ManifestFormDestinations, 1: object} The service and the double.
	 */
	private function destinationsAnswering(array $findings): array {
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

		return [new ManifestFormDestinations(check: $this->checkOver(validator: $validator, schema: $schema)), $validator];
	}//end destinationsAnswering()

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
