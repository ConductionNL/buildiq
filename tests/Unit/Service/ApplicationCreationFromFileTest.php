<?php

/**
 * ApplicationCreationFromFileTest
 *
 * @category  Tests
 * @package   OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Exception\WizardCreationException;
use OCA\Buildiq\Service\ApplicationCreationService;
use OCA\Buildiq\Service\AppStartSources;
use OCA\Buildiq\Service\DatabaseManifestBuilder;
use OCA\Buildiq\Service\RegisterStore;
use OCA\Buildiq\Service\SlugValidator;
use OCA\Buildiq\Service\SpreadsheetAppSource;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ImportRequest;
use OCA\OpenRegister\Service\RegisterService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

// OpenRegister's import contract; guarded, so a real OpenRegister beside the tests wins.
require_once __DIR__ . '/../../stubs/openregister-import.stub.php';

/**
 * "Start from a spreadsheet" in the create-app wizard (REQ-BQCV-001).
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
 */
class ApplicationCreationFromFileTest extends TestCase {

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * OpenRegister's register service (deletes registers on rollback).
	 *
	 * @var RegisterService&MockObject
	 */
	private RegisterService&MockObject $registerService;

	/**
	 * The register mapper.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * The schema mapper.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * OpenRegister's importer.
	 *
	 * @var ObjectImporterInterface&MockObject
	 */
	private ObjectImporterInterface&MockObject $importer;

	/**
	 * Every object saved, in order.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Every schema created, in order.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $schemas = [];

	/**
	 * Every schema slug looked up, in order.
	 *
	 * @var list<string>
	 */
	private array $schemaLookups = [];

	/**
	 * The version register the mapper hands out.
	 *
	 * @var Register
	 */
	private Register $register;

	/**
	 * Wire OpenRegister's mappers, object service and importer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->registerService = $this->createMock(originalClassName: RegisterService::class);
		$this->registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$this->schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$this->importer = $this->createMock(originalClassName: ObjectImporterInterface::class);

		$this->register = new Register();
		$this->registerMapper->method('find')->willReturn($this->register);
		$this->registerMapper->method('createFromArray')->willReturn($this->register);

		$this->schemaMapper->method('createFromArray')->willReturnCallback(function (array $object): Schema {
			$this->schemas[] = $object;
			$schema = new Schema();
			$schema->setSlug((string)($object['slug'] ?? ''));
			return $schema;
		});
		$this->schemaMapper->method('find')->willReturnCallback(function (string|int $id): Schema {
			$this->schemaLookups[] = (string)$id;
			$schema = new Schema();
			$schema->setSlug((string)$id);
			return $schema;
		});

		$this->objectService->method('searchObjects')->willReturn([]);
		$this->objectService->method('saveObject')->willReturnCallback(function (array $object): ObjectEntity {
			$this->saved[] = $object;
			$entity = $this->createMock(originalClassName: ObjectEntity::class);
			$id = 'uuid-' . count($this->saved);
			$entity->method('jsonSerialize')->willReturn(['id' => $id, 'uuid' => $id]);
			return $entity;
		});
	}//end setUp()

	/**
	 * The service with the spreadsheet source, or without it.
	 *
	 * @param bool $withSpreadsheets Whether the instance can import spreadsheets.
	 *
	 * @return ApplicationCreationService
	 */
	private function service(bool $withSpreadsheets = true): ApplicationCreationService {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturnCallback(fn (): object => $this->importer);

		$spreadsheets = null;
		if ($withSpreadsheets === true) {
			$spreadsheets = new SpreadsheetAppSource(container: $container);
		}

		return new ApplicationCreationService(
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			store: new RegisterStore(
				objectService: $this->objectService,
				registerMapper: $this->registerMapper,
				schemaMapper: $this->schemaMapper
			),
			registerService: $this->registerService,
			userSession: $session,
			slugValidator: new SlugValidator(),
			sources: new AppStartSources(databaseManifests: new DatabaseManifestBuilder(), spreadsheets: $spreadsheets),
		);
	}//end service()

	/**
	 * The wizard payload with the 40-row permits file.
	 *
	 * @param string $preset The version chain preset.
	 *
	 * @return array<string,mixed>
	 */
	private static function payload(string $preset = 'single'): array {
		return [
			'name' => 'Vergunningen',
			'slug' => 'vergunningen',
			'preset' => $preset,
			'fromFile' => [
				'name' => 'permits.csv',
				'mime' => 'text/csv',
				'content' => base64_encode(SpreadsheetAppSourceTest::permitsCsv()),
			],
		];
	}//end payload()

	/**
	 * Scenario "A maker starts an app from a spreadsheet": one schema with name, address and status,
	 * 40 records in the first version's register, and a list page first with a detail page.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function aMakerStartsAnAppFromASpreadsheet(): void {
		$imported = [];
		$this->importer->expects(self::once())->method('importFromCsv')->willReturnCallback(
			static function (ImportRequest $request) use (&$imported): array {
				$rows = array_map('str_getcsv', array_filter(explode("\n", (string)file_get_contents($request->filePath))));
				$imported = ['rows' => (count($rows) - 1), 'header' => $rows[0], 'schema' => $request->scope->schema->getSlug()];
				return ['permits' => ['created' => 40, 'errors' => []], 'importJobId' => 'job-1'];
			}
		);
		$this->objectService->expects(self::never())->method('deleteObject');

		$uuid = $this->service()->createApplication(payload: self::payload());

		self::assertSame(expected: 'uuid-1', actual: $uuid);

		$fileSchemas = array_values(array_filter($this->schemas, static fn (array $schema): bool => str_ends_with((string)$schema['slug'], '-permits')));
		self::assertCount(expectedCount: 1, haystack: $fileSchemas);
		self::assertSame(expected: 'vergunningen-production-permits', actual: $fileSchemas[0]['slug']);
		self::assertSame(expected: ['name', 'address', 'status'], actual: array_keys($fileSchemas[0]['properties']));

		self::assertSame(
			expected: ['rows' => 40, 'header' => ['name', 'address', 'status'], 'schema' => 'vergunningen-production-permits'],
			actual: $imported
		);

		$versions = array_values(array_filter($this->saved, static fn (array $object): bool => isset($object['manifest']) === true));
		$pages = $versions[0]['manifest']['pages'];
		self::assertSame(expected: ['PermitsIndex', 'PermitsDetail'], actual: array_column($pages, 'id'));
		self::assertSame(expected: 'index', actual: $pages[0]['type']);
		self::assertSame(expected: 'detail', actual: $pages[1]['type']);
		foreach ($pages as $page) {
			self::assertSame(expected: 'openbuild-vergunningen-production', actual: $page['config']['register']);
			self::assertSame(expected: 'vergunningen-production-permits', actual: $page['config']['schema']);
		}

		self::assertSame(expected: 'PermitsIndex', actual: $versions[0]['manifest']['menu'][0]['route']);
		self::assertSame(expected: 'permits', actual: $versions[0]['manifest']['menu'][0]['label']);
	}//end aMakerStartsAnAppFromASpreadsheet()

	/**
	 * With several versions every version gets the schema and pages, and only the first gets the records.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function onlyTheFirstVersionGetsTheRecords(): void {
		$this->importer->expects(self::once())->method('importFromCsv')->willReturn(['created' => 40, 'errors' => []]);

		$this->service()->createApplication(payload: self::payload(preset: 'dev-prod'));

		$slugs = array_column($this->schemas, 'slug');
		self::assertContains(needle: 'vergunningen-development-permits', haystack: $slugs);
		self::assertContains(needle: 'vergunningen-production-permits', haystack: $slugs);
		self::assertContains(needle: 'vergunningen-development-permits', haystack: $this->schemaLookups);
		self::assertNotContains(needle: 'vergunningen-production-permits', haystack: $this->schemaLookups);
	}//end onlyTheFirstVersionGetsTheRecords()

	/**
	 * Scenario "A file that cannot be read creates nothing": OpenRegister refuses the rows, the wizard
	 * shows the import error, and the import, schema, register, version and app are all removed.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function aFileOpenRegisterCannotImportCreatesNothing(): void {
		$this->importer->method('importFromCsv')->willReturn([
			'permits' => ['created' => 3, 'errors' => [['row' => 4, 'error' => 'Row 4 has 5 cells, the header has 3.']]],
			'importJobId' => 'job-9',
		]);
		$this->importer->expects(self::once())->method('softDeleteByImportJobId')->with('job-9')->willReturn([]);
		$this->schemaMapper->expects(self::once())->method('delete');
		$this->registerService->expects(self::once())->method('delete');
		$this->objectService->expects(self::exactly(count: 2))->method('deleteObject');

		try {
			$this->service()->createApplication(payload: self::payload());
			self::fail(message: 'Expected a WizardCreationException.');
		} catch (WizardCreationException $e) {
			self::assertSame(expected: 'import-file', actual: $e->getFailedAtStep());
			self::assertSame(expected: 'complete', actual: $e->getRollbackStatus());
			self::assertSame(
				expected: 'The file could not be imported: 1 row(s) were refused. Row 4 has 5 cells, the header has 3.',
				actual: $e->getMessage()
			);
		}
	}//end aFileOpenRegisterCannotImportCreatesNothing()

	/**
	 * An import that throws rolls back the same way, with nothing to undo.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function anImportThatFailsRollsEverythingBack(): void {
		$this->importer->method('importFromCsv')->willThrowException(new \RuntimeException('The file is not a valid CSV file.'));
		$this->importer->expects(self::never())->method('softDeleteByImportJobId');
		$this->schemaMapper->expects(self::once())->method('delete');
		$this->registerService->expects(self::once())->method('delete');

		try {
			$this->service()->createApplication(payload: self::payload());
			self::fail(message: 'Expected a WizardCreationException.');
		} catch (WizardCreationException $e) {
			self::assertSame(expected: 'import-file', actual: $e->getFailedAtStep());
			self::assertSame(expected: 'The file could not be imported: The file is not a valid CSV file.', actual: $e->getMessage());
		}
	}//end anImportThatFailsRollsEverythingBack()

	/**
	 * A file that cannot be read, or an instance without the importer:
	 * refused at validation, before anything is saved.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function unusableFilesAreRefusedBeforeAnythingIsSaved(): void {
		$this->objectService->expects(self::never())->method('saveObject');
		$this->schemaMapper->expects(self::never())->method('createFromArray');

		$unreadable = self::payload();
		$unreadable['fromFile']['content'] = base64_encode("\x00\x01\xFF");

		$messages = [];
		foreach ([[$this->service(), $unreadable], [$this->service(withSpreadsheets: false), self::payload()]] as [$service, $payload]) {
			try {
				$service->createApplication(payload: $payload);
			} catch (WizardCreationException $e) {
				self::assertSame(expected: 'validate', actual: $e->getFailedAtStep());
				$messages[] = $e->getMessage();
			}
		}

		self::assertSame(expected: ['The file is not a readable CSV file.', 'Spreadsheets cannot be imported on this instance.'], actual: $messages);
		self::assertSame(expected: [], actual: $this->saved);
	}//end unusableFilesAreRefusedBeforeAnythingIsSaved()
}//end class
