<?php

/**
 * Unit tests for DataRegisterProvisioner.
 *
 * Covers the dataRegisters channel of an app install: an existing register is
 * left alone, a missing one is created with its schemas (reusing schemas that
 * already exist), a schema that cannot be created is dropped from the
 * register, a failing register is recorded as failed, and the channel bound
 * truncates the excess. Every outcome must balance in the report.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ChannelApplyReport;
use OCA\Buildiq\Service\DataRegisterProvisioner;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for DataRegisterProvisioner::apply.
 */
class DataRegisterProvisionerTest extends TestCase {

	/**
	 * Mock register mapper.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * Mock schema mapper.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Provisioner under test.
	 *
	 * @var DataRegisterProvisioner
	 */
	private DataRegisterProvisioner $provisioner;

	/**
	 * Wire the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$this->schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->provisioner = new DataRegisterProvisioner(
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * A stub Schema with an id.
	 *
	 * @param int $id The schema id.
	 *
	 * @return Schema
	 */
	private static function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);

		return $schema;
	}//end schema()

	/**
	 * The dataRegisters channel of the report as an array.
	 *
	 * @param ChannelApplyReport $report The report.
	 *
	 * @return array<string, mixed>
	 */
	private static function channel(ChannelApplyReport $report): array {
		return $report->toArray()['channels']['dataRegisters'];
	}//end channel()

	/**
	 * An existing register is skipped and nothing is created.
	 *
	 * @return void
	 */
	public function testSkipsAnExistingRegister(): void {
		$this->registerMapper->method('find')->willReturn(new Register());
		$this->registerMapper->expects(self::never())->method('createFromArray');
		$this->schemaMapper->expects(self::never())->method('createFromArray');

		$report = new ChannelApplyReport();
		$this->provisioner->apply(registers: ['cases' => ['schemas' => ['case' => []]]], report: $report);

		$channel = self::channel(report: $report);
		self::assertSame(expected: 1, actual: $channel['skipped']);
		self::assertSame(expected: ChannelApplyReport::REASON_EXISTS, actual: $channel['items'][0]['reason']);
	}//end testSkipsAnExistingRegister()

	/**
	 * A missing register is created with its existing and new schemas; a failing schema is left out.
	 *
	 * @return void
	 */
	public function testCreatesAMissingRegisterWithItsSchemas(): void {
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('absent'));
		$this->schemaMapper->method('find')->willReturnCallback(
			static function (string $slug): Schema {
				if ($slug === 'person') {
					return self::schema(id: 4);
				}

				throw new RuntimeException('absent');
			}
		);

		$created = [];
		$this->schemaMapper->method('createFromArray')->willReturnCallback(
			static function (array $blob) use (&$created): Schema {
				if ($blob['slug'] === 'broken') {
					throw new RuntimeException('invalid schema');
				}

				$created[] = $blob;
				return self::schema(id: 9);
			}
		);

		$this->registerMapper->expects(self::once())->method('createFromArray')
			->with(
				self::callback(
					callback: static fn (array $blob): bool => $blob['slug'] === 'cases'
						&& $blob['title'] === 'Cases'
						&& $blob['schemas'] === [4, 9]
				)
			)
			->willReturn(new Register());
		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains(string: 'schema "broken" could not be created: invalid schema'));

		$report = new ChannelApplyReport();
		$this->provisioner->apply(
			registers: [
				'cases' => [
					'title' => 'Cases',
					'schemas' => [
						'person' => [],
						'case' => ['title' => 'Case', 'required' => ['title' => 'title'], 'properties' => ['title' => ['type' => 'string']]],
						'broken' => [],
						'' => [],
					],
				],
			],
			report: $report
		);

		self::assertSame(expected: 1, actual: self::channel(report: $report)['created']);
		self::assertCount(expectedCount: 1, haystack: $created);
		self::assertSame(expected: 'Case', actual: $created[0]['title']);
		self::assertSame(expected: ['title'], actual: $created[0]['required']);
		self::assertSame(expected: '0.1.0', actual: $created[0]['version']);
	}//end testCreatesAMissingRegisterWithItsSchemas()

	/**
	 * A register whose creation fails is recorded as failed with the reason.
	 *
	 * @return void
	 */
	public function testRecordsAFailingRegister(): void {
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('absent'));
		$this->registerMapper->method('createFromArray')->willThrowException(new RuntimeException('duplicate slug'));
		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains(string: 'data register "cases" failed: duplicate slug'));

		$report = new ChannelApplyReport();
		$this->provisioner->apply(registers: ['cases' => []], report: $report);

		$channel = self::channel(report: $report);
		self::assertSame(expected: 1, actual: $channel['failed']);
		self::assertSame(expected: 'duplicate slug', actual: $channel['items'][0]['reason']);
	}//end testRecordsAFailingRegister()

	/**
	 * Registers past the channel bound are truncated, logged and still accounted for.
	 *
	 * @return void
	 */
	public function testTruncatesRegistersPastTheBound(): void {
		$this->registerMapper->method('find')->willReturn(new Register());

		$registers = [];
		for ($i = 0; $i < 66; $i++) {
			$registers['r' . $i] = [];
		}

		$this->logger->expects(self::exactly(count: 2))->method('warning')
			->with(self::stringContains(string: 'the excess was NOT applied'));

		$report = new ChannelApplyReport();
		$this->provisioner->apply(registers: $registers, report: $report);

		$channel = self::channel(report: $report);
		self::assertSame(expected: 66, actual: $channel['declared']);
		self::assertSame(expected: 66, actual: $channel['skipped']);
		self::assertSame(expected: 2, actual: $channel['truncated']);
		self::assertSame(expected: 'r65', actual: $channel['items'][65]['item']);
		self::assertSame(expected: ChannelApplyReport::REASON_TRUNCATED, actual: $channel['items'][65]['reason']);
	}//end testTruncatesRegistersPastTheBound()
}//end class
