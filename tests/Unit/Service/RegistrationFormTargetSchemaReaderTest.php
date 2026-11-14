<?php

/**
 * Unit tests for RegistrationFormTargetSchemaReader.
 *
 * The reader is what turns two rules that were enforced on nothing into rules
 * that run: the preset warning and the channel refusal both take their truth
 * from the consuming schema, and until this class existed both were handed
 * null.
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
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005, REQ-OBRF-007)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\RegistrationFormTargetSchemaReader;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers what the reader can and cannot say about a schema it does not own.
 */
final class RegistrationFormTargetSchemaReaderTest extends TestCase {
	/**
	 * Build a reader over one register holding one schema.
	 *
	 * @param string $schemaSlug The slug that schema carries.
	 * @param array<string, mixed> $properties The properties it declares.
	 *
	 * @return RegistrationFormTargetSchemaReader The reader.
	 */
	private function readerOver(string $schemaSlug, array $properties): RegistrationFormTargetSchemaReader {
		$schema = $this->createMock(Schema::class);
		$schema->method('getSlug')->willReturn($schemaSlug);
		$schema->method('getProperties')->willReturn($properties);

		$register = $this->createMock(Register::class);
		$register->method('getSchemas')->willReturn([7]);

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
	 * The properties of the target schema come back as plain names, which is
	 * what the preset and field warnings compare against.
	 *
	 * @return void
	 */
	public function testItReadsTheTargetSchemasPropertyNames(): void {
		$reader = $this->readerOver(
			'zaak',
			['caseType' => ['type' => 'string'], 'intakeChannel' => ['type' => 'string']]
		);

		$target = $reader->read(registerSlug: 'dossiq', schemaSlug: 'Zaak');

		$this->assertSame(['caseType', 'intakeChannel'], $target['properties']);
		$this->assertNull($target['note']);
	}//end testItReadsTheTargetSchemasPropertyNames()

	/**
	 * The channel list is the named property's enum, and nothing else.
	 *
	 * @return void
	 */
	public function testItReadsTheNamedChannelPropertysEnum(): void {
		$reader = $this->readerOver(
			'zaak',
			['intakeChannel' => ['type' => 'string', 'enum' => ['portal', 'desk', 'post']]]
		);

		$target = $reader->read(registerSlug: 'dossiq', schemaSlug: 'Zaak', channelProperty: 'intakeChannel');

		$this->assertSame(['portal', 'desk', 'post'], $target['channels']);
	}//end testItReadsTheNamedChannelPropertysEnum()

	/**
	 * A form that names no channel property is not channel-checked. Guessing
	 * which property is the channel would refuse a save on a name that merely
	 * looks channel-ish.
	 *
	 * @return void
	 */
	public function testItNeverGuessesWhichPropertyIsTheChannel(): void {
		$reader = $this->readerOver(
			'zaak',
			['intakeChannel' => ['type' => 'string', 'enum' => ['portal', 'desk']]]
		);

		$target = $reader->read(registerSlug: 'dossiq', schemaSlug: 'Zaak');

		$this->assertNull($target['channels']);
	}//end testItNeverGuessesWhichPropertyIsTheChannel()

	/**
	 * A channel property with no enum accepts whatever the consumer writes, so
	 * there is nothing to refuse against.
	 *
	 * @return void
	 */
	public function testAChannelPropertyWithoutAnEnumDeclaresNoChannels(): void {
		$reader = $this->readerOver('zaak', ['intakeChannel' => ['type' => 'string']]);

		$target = $reader->read(registerSlug: 'dossiq', schemaSlug: 'Zaak', channelProperty: 'intakeChannel');

		$this->assertNull($target['channels']);
	}//end testAChannelPropertyWithoutAnEnumDeclaresNoChannels()

	/**
	 * A register that holds no schema by that slug is reported, not silently
	 * treated as a schema with no properties: the second would warn about every
	 * field on the form.
	 *
	 * @return void
	 */
	public function testAMissingSchemaIsReportedRatherThanReadAsEmpty(): void {
		$reader = $this->readerOver('iets-anders', ['caseType' => ['type' => 'string']]);

		$target = $reader->read(registerSlug: 'dossiq', schemaSlug: 'Zaak');

		$this->assertNull($target['properties']);
		$this->assertNull($target['channels']);
		$this->assertStringContainsString('did not run', (string)$target['note']);
	}//end testAMissingSchemaIsReportedRatherThanReadAsEmpty()

	/**
	 * A read that throws leaves the save working and says the checks did not
	 * run. A builder that refuses every save because openregister is briefly
	 * unreachable is a harder failure than a form that is merely unchecked.
	 *
	 * @return void
	 */
	public function testAFailedReadIsSaidOutLoudRatherThanSwallowed(): void {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willThrowException(new DoesNotExistException('no such register'));

		$reader = new RegistrationFormTargetSchemaReader(
			registerMapper: $registerMapper,
			schemaMapper: $this->createMock(SchemaMapper::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$target = $reader->read(registerSlug: 'dossiq', schemaSlug: 'Zaak');

		$this->assertNull($target['properties']);
		$this->assertStringContainsString('could not be read', (string)$target['note']);
	}//end testAFailedReadIsSaidOutLoudRatherThanSwallowed()

	/**
	 * Without a register and a schema there is nothing to read and nothing to
	 * complain about.
	 *
	 * @return void
	 */
	public function testAnEmptyScopeReadsNothingAndSaysNothing(): void {
		$reader = $this->readerOver('zaak', []);

		$target = $reader->read(registerSlug: '', schemaSlug: '');

		$this->assertNull($target['properties']);
		$this->assertNull($target['note']);
	}//end testAnEmptyScopeReadsNothingAndSaysNothing()
}//end class
