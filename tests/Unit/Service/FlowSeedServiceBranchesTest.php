<?php

/**
 * Unit tests for the app template's FlowSeedService.
 *
 * The template is not on buildiq's autoloader (it is copied into a new app
 * under that app's namespace), so the subject is loaded from its file.
 *
 * Covers the branches FlowSeedServiceTest leaves open: a missing flow
 * directory, invalid JSON, a failing lookup or write, the fingerprint stored
 * on insert and update, adopting a never-seeded flow, and an empty node type
 * list.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

require_once __DIR__ . '/../../../lib/Resources/template/lib/Service/FlowSeedService.php';

use OCA\AppTemplate\Service\FlowSeedService;
use OCA\OpenRegister\Db\Flow;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the remaining branches of the template's FlowSeedService.
 */
#[CoversClass(FlowSeedService::class)]
final class FlowSeedServiceBranchesTest extends TestCase {
	/**
	 * Directory holding this test's shipped flow files.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Create an empty flow directory and the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->dir = sys_get_temp_dir() . '/buildiq-flow-seed-' . bin2hex(random_bytes(6));
		mkdir($this->dir);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Remove the flow directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ((glob($this->dir . '/*') ?: []) as $file) {
			unlink($file);
		}

		rmdir($this->dir);
		parent::tearDown();
	}//end tearDown()

	/**
	 * Write one shipped flow file.
	 *
	 * @param string $name    The file name.
	 * @param mixed  $content An array is written as JSON, a string as is.
	 *
	 * @return void
	 */
	private function ship(string $name, mixed $content): void {
		file_put_contents($this->dir . '/' . $name, is_string($content) === true ? $content : (string)json_encode($content));
	}//end ship()

	/**
	 * The service reading this test's flow directory.
	 *
	 * @param string|null $dir Another directory, when given.
	 *
	 * @return FlowSeedService
	 */
	private function service(?string $dir = null): FlowSeedService {
		return new FlowSeedService(appConfig: $this->appConfig, logger: $this->logger, flowDir: ($dir ?? $this->dir));
	}//end service()

	/**
	 * The fingerprint the service stores for a definition.
	 *
	 * @param array<int,mixed> $nodes The nodes.
	 * @param array<int,mixed> $edges The edges.
	 *
	 * @return string
	 */
	private static function fingerprint(array $nodes, array $edges): string {
		return hash('sha256', (string)json_encode(['nodes' => $nodes, 'edges' => $edges]));
	}//end fingerprint()

	/**
	 * A flow mapper double that records what it is asked.
	 *
	 * @param object|null      $existing What findByUuid() returns.
	 * @param RuntimeException $findFails Thrown by findByUuid() when given.
	 * @param RuntimeException $insertFails Thrown by insert() when given.
	 *
	 * @return object
	 */
	private static function mapper(?object $existing = null, ?RuntimeException $findFails = null, ?RuntimeException $insertFails = null): object {
		return new class ($existing, $findFails, $insertFails) {
			/**
			 * Inserted entities, in order.
			 *
			 * @var array<int,object>
			 */
			public array $inserted = [];

			/**
			 * Updated entities, in order.
			 *
			 * @var array<int,object>
			 */
			public array $updated = [];

			/**
			 * The uuids looked up, in order.
			 *
			 * @var array<int,string>
			 */
			public array $lookedUp = [];

			/**
			 * Build the double.
			 *
			 * @param object|null           $existing    What findByUuid() returns.
			 * @param RuntimeException|null $findFails   Thrown by findByUuid().
			 * @param RuntimeException|null $insertFails Thrown by insert().
			 */
			public function __construct(
				private readonly ?object $existing,
				private readonly ?RuntimeException $findFails,
				private readonly ?RuntimeException $insertFails,
			) {
			}

			/**
			 * Look up a flow.
			 *
			 * @param string $uuid The uuid.
			 *
			 * @return object|null
			 */
			public function findByUuid(string $uuid): ?object {
				$this->lookedUp[] = $uuid;
				if ($this->findFails !== null) {
					throw $this->findFails;
				}

				return $this->existing;
			}

			/**
			 * Insert a flow.
			 *
			 * @param object $flow The flow.
			 *
			 * @return object
			 */
			public function insert(object $flow): object {
				if ($this->insertFails !== null) {
					throw $this->insertFails;
				}

				$this->inserted[] = $flow;
				return $flow;
			}

			/**
			 * Update a flow.
			 *
			 * @param object $flow The flow.
			 *
			 * @return object
			 */
			public function update(object $flow): object {
				$this->updated[] = $flow;
				return $flow;
			}
		};
	}//end mapper()

	/**
	 * A missing flow directory seeds nothing and fails nothing.
	 *
	 * @return void
	 */
	public function testMissingDirectorySeedsNothing(): void {
		$mapper = self::mapper();

		$report = $this->service(dir: $this->dir . '/absent')->seed(flowMapper: $mapper);

		self::assertSame(expected: ['seeded' => 0, 'kept' => 0, 'unknownNodeTypes' => [], 'failed' => []], actual: $report);
		self::assertSame(expected: [], actual: $mapper->lookedUp);
	}//end testMissingDirectorySeedsNothing()

	/**
	 * Invalid JSON and a blank uuid are refused by file name, and never looked up.
	 *
	 * @return void
	 */
	public function testInvalidAndUuidLessFilesAreRefused(): void {
		$this->ship(name: 'broken.json', content: '{not json');
		$this->ship(name: 'nameless.json', content: ['uuid' => '   ', 'name' => 'No uuid']);
		$mapper = self::mapper();

		$report = $this->service()->seed(flowMapper: $mapper);

		self::assertSame(expected: ['broken.json is not valid JSON', 'nameless.json carries no uuid'], actual: $report['failed']);
		self::assertSame(expected: 0, actual: $report['seeded']);
		self::assertSame(expected: [], actual: $mapper->lookedUp);
	}//end testInvalidAndUuidLessFilesAreRefused()

	/**
	 * A new flow is inserted disabled with the shipped content, and its fingerprint is stored.
	 *
	 * @return void
	 */
	public function testNewFlowIsInsertedDisabledAndFingerprinted(): void {
		$nodes = [['id' => 'n1', 'type' => 'start']];
		$edges = [['from' => 'n1', 'to' => 'n1']];
		$this->ship(name: 'intake.json', content: ['uuid' => ' u-intake ', 'name' => 'Intake', 'description' => 'Start a case', 'nodes' => $nodes, 'edges' => $edges]);
		$mapper = self::mapper();

		$this->appConfig->expects(self::once())
			->method('setValueString')
			->with('apptemplate', 'flow-seed-u-intake', self::fingerprint(nodes: $nodes, edges: $edges));

		$report = $this->service()->seed(flowMapper: $mapper);

		self::assertSame(expected: 1, actual: $report['seeded']);
		self::assertSame(expected: ['u-intake'], actual: $mapper->lookedUp);
		self::assertCount(expectedCount: 1, haystack: $mapper->inserted);
		$flow = $mapper->inserted[0];
		self::assertInstanceOf(expected: Flow::class, actual: $flow);
		self::assertSame(expected: 'u-intake', actual: $flow->getUuid());
		self::assertFalse(condition: $flow->getEnabled());
		self::assertSame(expected: 'Intake', actual: $flow->getName());
		self::assertSame(expected: 'Start a case', actual: $flow->getDescription());
		self::assertSame(expected: $nodes, actual: $flow->getNodes());
		self::assertSame(expected: $edges, actual: $flow->getEdges());
	}//end testNewFlowIsInsertedDisabledAndFingerprinted()

	/**
	 * A failing lookup is read as "not there yet", so the flow is inserted.
	 *
	 * @return void
	 */
	public function testFailingLookupInsertsTheFlow(): void {
		$this->ship(name: 'a.json', content: ['uuid' => 'u-a']);
		$mapper = self::mapper(findFails: new RuntimeException(message: 'not found'));

		$report = $this->service()->seed(flowMapper: $mapper);

		self::assertSame(expected: 1, actual: $report['seeded']);
		self::assertCount(expectedCount: 1, haystack: $mapper->inserted);
	}//end testFailingLookupInsertsTheFlow()

	/**
	 * An existing flow unchanged since it was last seeded is overwritten with the shipped version.
	 *
	 * @return void
	 */
	public function testUnchangedExistingFlowIsUpdated(): void {
		$existing = new Flow();
		$existing->setNodes([['id' => 'old']]);
		$existing->setEdges([]);
		$shippedNodes = [['id' => 'new']];
		$this->ship(name: 'a.json', content: ['uuid' => 'u-a', 'name' => 'Updated', 'nodes' => $shippedNodes, 'edges' => []]);
		$mapper = self::mapper(existing: $existing);

		$this->appConfig->method('getValueString')
			->with('apptemplate', 'flow-seed-u-a', '')
			->willReturn(self::fingerprint(nodes: [['id' => 'old']], edges: []));
		$this->appConfig->expects(self::once())
			->method('setValueString')
			->with('apptemplate', 'flow-seed-u-a', self::fingerprint(nodes: $shippedNodes, edges: []));

		$report = $this->service()->seed(flowMapper: $mapper);

		self::assertSame(expected: ['seeded' => 1, 'kept' => 0], actual: ['seeded' => $report['seeded'], 'kept' => $report['kept']]);
		self::assertSame(expected: [$existing], actual: $mapper->updated);
		self::assertSame(expected: [], actual: $mapper->inserted);
		self::assertSame(expected: $shippedNodes, actual: $existing->getNodes());
		self::assertSame(expected: 'Updated', actual: $existing->getName());
	}//end testUnchangedExistingFlowIsUpdated()

	/**
	 * An existing flow that was never fingerprinted is adopted and updated.
	 *
	 * @return void
	 */
	public function testNeverSeededExistingFlowIsUpdated(): void {
		$existing = new Flow();
		$existing->setNodes([['id' => 'local']]);
		$existing->setEdges([]);
		$this->ship(name: 'a.json', content: ['uuid' => 'u-a', 'nodes' => [], 'edges' => []]);
		$mapper = self::mapper(existing: $existing);
		$this->appConfig->method('getValueString')->willReturn('');

		$report = $this->service()->seed(flowMapper: $mapper);

		self::assertSame(expected: 1, actual: $report['seeded']);
		self::assertSame(expected: [$existing], actual: $mapper->updated);
	}//end testNeverSeededExistingFlowIsUpdated()

	/**
	 * Without a registered node type list no node type is reported unknown.
	 *
	 * @return void
	 */
	public function testNoRegisteredListReportsNothingUnknown(): void {
		$this->ship(name: 'a.json', content: ['uuid' => 'u-a', 'nodes' => [['type' => 'ocr']]]);

		$report = $this->service()->seed(flowMapper: self::mapper());

		self::assertSame(expected: [], actual: $report['unknownNodeTypes']);
	}//end testNoRegisteredListReportsNothingUnknown()

	/**
	 * A failing write is reported against its uuid and logged, and the next file is still seeded,
	 * in file name order.
	 *
	 * @return void
	 */
	public function testFailingWriteIsReportedAndLogged(): void {
		$this->ship(name: 'b.json', content: ['uuid' => 'u-b']);
		$this->ship(name: 'a.json', content: ['uuid' => 'u-a']);
		$mapper = self::mapper(insertFails: new RuntimeException(message: 'db down'));

		$this->logger->expects(self::exactly(2))->method('warning')->with(self::stringContains(': db down'));

		$report = $this->service()->seed(flowMapper: $mapper);

		self::assertSame(expected: ['u-a: db down', 'u-b: db down'], actual: $report['failed']);
		self::assertSame(expected: ['u-a', 'u-b'], actual: $mapper->lookedUp);
		self::assertSame(expected: 0, actual: $report['seeded']);
	}//end testFailingWriteIsReportedAndLogged()
}//end class
