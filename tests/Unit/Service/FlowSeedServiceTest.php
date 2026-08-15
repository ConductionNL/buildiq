<?php

/**
 * The importing half — the one that fails silently.
 *
 * Every wrong version of this seeder produces files that look right and flows
 * that never run: seeding the `agentflow` object mirror instead of the entity,
 * minting a fresh UUID, running install-only, or overwriting an operator's
 * edit. None of those surfaces an error, and only a flow RUN distinguishes
 * them — which is why the e2e round trip asserts on a run and why these unit
 * tests assert on the specific decisions rather than on "it did not throw".
 *
 * ⚠️ The subject lives under `lib/Resources/template/`, which is excluded from
 * the classmap on purpose: it is scaffold shipped INTO generated apps, not code
 * openbuild runs. It is therefore required explicitly here — testing shipped
 * scaffold is exactly as necessary as testing the code that ships it, because
 * a broken seeder breaks every app ever exported.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Service
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../../../lib/Resources/template/lib/Service/FlowSeedService.php';

/**
 * @coversNothing Template scaffold, required explicitly; excluded from the classmap by design.
 */
final class FlowSeedServiceTest extends TestCase {

	/**
	 * Where the fake app's shipped flows live.
	 *
	 * @var string
	 */
	private string $flowDir;

	/**
	 * Remembers what was last seeded, per flow.
	 *
	 * @var IAppConfig&MockObject
	 */
	private $appConfig;

	/**
	 * In-memory stand-in for the stored fingerprints.
	 *
	 * @var array<string,string>
	 */
	private array $stored = [];

	/**
	 * Subject.
	 *
	 * @var object
	 */
	private object $seeder;

	/**
	 * Build the subject over a real flows directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		// A TEMP directory, injected. The first version of this test wrote
		// fixtures into the template tree itself and passed as the file owner
		// while failing as www-data, because a test that writes into the source
		// tree needs write access to the app under test. It also left artefacts
		// in the repository. The service takes the directory as a parameter for
		// exactly this reason.
		$this->flowDir = sys_get_temp_dir() . '/ob-flow-seed-' . uniqid();
		mkdir($this->flowDir, 0o755, true);

		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);

		$class = '\OCA\AppTemplate\Service\FlowSeedService';
		$this->seeder = new $class(
			$this->appConfig,
			$this->createMock(originalClassName: LoggerInterface::class),
			$this->flowDir
		);

	}//end setUp()

	/**
	 * Leave no fixtures behind in the template tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		array_map('unlink', (array)glob($this->flowDir . '/*.json'));
		@rmdir($this->flowDir);
		// Nothing to clean in the repository: the fixtures never went there.

	}//end tearDown()

	/**
	 * Write a shipped flow definition.
	 *
	 * @param string $uuid The UUID.
	 * @param array $nodes The nodes.
	 *
	 * @return void
	 */
	private function ship(string $uuid, array $nodes): void {
		file_put_contents(
			$this->flowDir . '/' . $uuid . '.json',
			(string)json_encode(['uuid' => $uuid, 'name' => 'Shipped flow', 'nodes' => $nodes, 'edges' => []])
		);

	}//end ship()

	/**
	 * A mapper that has nothing, and records what it was asked to insert.
	 *
	 * @param array $captured Reference collecting inserted entities.
	 *
	 * @return FlowMapper&MockObject The mapper.
	 */
	private function emptyMapper(array &$captured): FlowMapper {
		$mapper = $this->createMock(originalClassName: FlowMapper::class);
		$mapper->method('findByUuid')->willThrowException(new RuntimeException('not found'));
		$mapper->method('insert')->willReturnCallback(
			function (Flow $flow) use (&$captured): Flow {
				$captured[] = $flow;
				return $flow;
			}
		);

		return $mapper;
	}//end emptyMapper()

	/**
	 * THE UUID IS PRESERVED, never minted.
	 *
	 * An application binds its flows by UUID. A seeder that generates a fresh
	 * one leaves every binding in the imported app pointing at nothing, while
	 * every file in the ZIP still looks correct — the failure that passes
	 * every check short of running a flow.
	 *
	 * @return void
	 */
	public function testTheShippedUuidIsPreservedRatherThanMinted(): void {
		$uuid = '6b14a1fd-0cab-40c0-a3e7-7fea3be29bdc';
		$this->ship($uuid, [['id' => 'start', 'type' => 'openregister.trigger-schedule']]);

		$captured = [];
		$report = $this->seeder->seed($this->emptyMapper($captured));

		$this->assertSame(expected: 1, actual: $report['seeded']);
		$this->assertCount(expectedCount: 1, haystack: $captured);
		$this->assertSame(
			expected: $uuid,
			actual: $captured[0]->getUuid(),
			message: 'a minted UUID orphans every binding in the imported application'
		);

	}//end testTheShippedUuidIsPreservedRatherThanMinted()

	/**
	 * A seeded flow arrives DISABLED.
	 *
	 * An app that installs itself and immediately starts running flows against
	 * an operator's data is a surprise, not a feature.
	 *
	 * @return void
	 */
	public function testASeededFlowArrivesDisabled(): void {
		$this->ship('11111111-1111-4111-8111-111111111111', []);

		$captured = [];
		$this->seeder->seed($this->emptyMapper($captured));

		$this->assertFalse(
			condition: (bool)$captured[0]->getEnabled(),
			message: 'seeding must not switch on automation over data the operator has not reviewed'
		);

	}//end testASeededFlowArrivesDisabled()

	/**
	 * Seeding twice yields ONE flow, not two.
	 *
	 * The step runs on install and on every upgrade, so it runs many times.
	 *
	 * @return void
	 */
	public function testSeedingTwiceDoesNotDuplicate(): void {
		$uuid = '22222222-2222-4222-8222-222222222222';
		$this->ship($uuid, [['id' => 'a', 'type' => 'openregister.end']]);

		$existing = new Flow();
		$existing->setUuid($uuid);
		$existing->setNodes([['id' => 'a', 'type' => 'openregister.end']]);
		$existing->setEdges([]);

		$mapper = $this->createMock(originalClassName: FlowMapper::class);
		$mapper->method('findByUuid')->willReturn($existing);
		$mapper->expects($this->never())->method('insert');
		$mapper->expects($this->once())->method('update');

		$report = $this->seeder->seed($mapper);

		$this->assertSame(expected: 1, actual: $report['seeded']);

	}//end testSeedingTwiceDoesNotDuplicate()

	/**
	 * A LOCALLY EDITED flow is kept, not overwritten.
	 *
	 * Last-writer-wins silently deletes the customisation that made the app
	 * useful to that organisation.
	 *
	 * @return void
	 */
	public function testALocallyEditedFlowIsNotOverwritten(): void {
		$uuid = '33333333-3333-4333-8333-333333333333';
		$shippedNodes = [['id' => 'a', 'type' => 'openregister.end']];
		$this->ship($uuid, $shippedNodes);

		// What we last seeded…
		$this->stored['flow-seed-' . $uuid] = hash(
			'sha256',
			(string)json_encode(['nodes' => $shippedNodes, 'edges' => []])
		);

		// …and what is on the instance now: the operator added a node.
		$edited = new Flow();
		$edited->setUuid($uuid);
		$edited->setNodes([['id' => 'a', 'type' => 'openregister.end'], ['id' => 'mine', 'type' => 'openregister.end']]);
		$edited->setEdges([]);

		$mapper = $this->createMock(originalClassName: FlowMapper::class);
		$mapper->method('findByUuid')->willReturn($edited);
		$mapper->expects($this->never())->method('update');

		$report = $this->seeder->seed($mapper);

		$this->assertSame(expected: 1, actual: $report['kept'], message: 'the local edit must survive the upgrade');
		$this->assertSame(expected: 0, actual: $report['seeded']);

	}//end testALocallyEditedFlowIsNotOverwritten()

	/**
	 * A node type this instance does not have is SURFACED, not swallowed.
	 *
	 * An agentic flow on an instance without hermiq is the ordinary case. It
	 * is not refused — the app may ship a flow for a capability installed
	 * later — but discovering it when somebody triggers the flow, months on,
	 * is the expensive path.
	 *
	 * @return void
	 */
	public function testAnUnregisteredNodeTypeIsSurfaced(): void {
		$this->ship('44444444-4444-4444-8444-444444444444', [['id' => 'r', 'type' => 'hermiq.workload-step']]);

		$captured = [];
		$report = $this->seeder->seed(
			$this->emptyMapper($captured),
			['openregister.trigger-schedule' => true, 'openregister.end' => true]
		);

		$this->assertContains(needle: 'hermiq.workload-step', haystack: $report['unknownNodeTypes']);
		$this->assertSame(
			expected: 1,
			actual: $report['seeded'],
			message: 'it is reported, not refused — the capability may be installed later'
		);

	}//end testAnUnregisteredNodeTypeIsSurfaced()

	/**
	 * A definition with no UUID is refused loudly rather than seeded uselessly.
	 *
	 * @return void
	 */
	public function testADefinitionWithoutAUuidIsRefused(): void {
		file_put_contents(
			$this->flowDir . '/broken.json',
			(string)json_encode(['name' => 'No uuid', 'nodes' => [], 'edges' => []])
		);

		$captured = [];
		$report = $this->seeder->seed($this->emptyMapper($captured));

		$this->assertSame(expected: 0, actual: $report['seeded']);
		$this->assertNotEmpty(actual: $report['failed']);

	}//end testADefinitionWithoutAUuidIsRefused()

	/**
	 * With OpenRegister absent, seeding is a no-op rather than a crash.
	 *
	 * @return void
	 */
	public function testNoOpWhenOpenRegisterIsAbsent(): void {
		$this->ship('55555555-5555-4555-8555-555555555555', []);

		$report = $this->seeder->seed(null);

		$this->assertSame(expected: 0, actual: $report['seeded']);
		$this->assertSame(expected: [], actual: $report['failed']);

	}//end testNoOpWhenOpenRegisterIsAbsent()
}//end class
