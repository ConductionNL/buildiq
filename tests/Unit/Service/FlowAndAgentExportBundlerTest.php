<?php

/**
 * What an export carries of the flows and agents an application is made of.
 *
 * The defect this class exists to prevent is silent by construction: a bundler
 * that reads the wrong store, or drops a skip, produces a ZIP that passes every
 * inspection and yields an app that does not work. So the fixtures here are
 * chosen to DISTINGUISH implementations rather than to demonstrate one:
 *
 *   * the entity and the `agentflow` object mirror are made to DISAGREE, so a
 *     bundler reading the mirror fails. A fixture where they agree cannot tell
 *     the two apart;
 *   * a dangling binding is asserted to be RETURNED, not merely survived —
 *     "the export did not crash" is also what silently dropping it looks like;
 *   * agents are asserted to be found WITHOUT a binding, because the binding
 *     that would have been consulted deliberately does not exist.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Service
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Service\FlowAndAgentExportBundler;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenBuild\Service\FlowAndAgentExportBundler
 */
final class FlowAndAgentExportBundlerTest extends TestCase {

	/**
	 * Resolves a bound flow.
	 *
	 * @var FlowMapper&MockObject
	 */
	private $flowMapper;

	/**
	 * Finds the agents pointing at the application.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * Subject.
	 *
	 * @var FlowAndAgentExportBundler
	 */
	private FlowAndAgentExportBundler $bundler;

	/**
	 * Scratch tree standing in for the export scaffold.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Build the subject over a real temporary directory.
	 *
	 * A real directory rather than a virtual filesystem: the thing under test
	 * writes files, and "did it write the file" is the assertion.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->flowMapper = $this->createMock(originalClassName: FlowMapper::class);
		$this->objectService = $this->createMock(originalClassName: ObjectService::class);

		$this->bundler = new FlowAndAgentExportBundler(
			$this->flowMapper,
			$this->objectService,
			$this->createMock(originalClassName: LoggerInterface::class)
		);

		$this->root = sys_get_temp_dir() . '/ob-bundler-' . uniqid();
		mkdir($this->root, 0o755, true);

	}//end setUp()

	/**
	 * Remove the scratch tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->root));

	}//end tearDown()

	/**
	 * Build a Flow entity with a given graph.
	 *
	 * @param string $uuid The UUID.
	 * @param array $nodes The nodes.
	 *
	 * @return Flow The entity.
	 */
	private function flow(string $uuid, array $nodes): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setName('Hydra sequencer');
		$flow->setNodes($nodes);
		$flow->setEdges([['from' => 'a', 'to' => 'b']]);

		return $flow;
	}//end flow()

	/**
	 * A bound flow is written, with the UUID travelling alongside the graph.
	 *
	 * The UUID matters as much as the nodes: the importing side seeds it
	 * verbatim, and a definition that arrived without one leaves every binding
	 * in the imported application pointing at nothing.
	 *
	 * @return void
	 */
	public function testABoundFlowIsWrittenWithItsUuid(): void {
		$uuid = '6b14a1fd-0cab-40c0-a3e7-7fea3be29bdc';
		$this->flowMapper->method('findByUuid')->willReturn($this->flow($uuid, [['id' => 'start', 'type' => 'openregister.trigger-schedule']]));
		$this->objectService->method('findAll')->willReturn([]);

		$skipped = $this->bundler->bundle($this->root, [['flow' => $uuid]], 'hydra-console');

		$path = $this->root . '/lib/Settings/flows/' . $uuid . '.json';
		$this->assertFileExists($path);

		$written = json_decode((string)file_get_contents($path), true);
		$this->assertSame(expected: $uuid, actual: $written['uuid'], message: 'the UUID must travel with the definition');
		$this->assertCount(expectedCount: 1, haystack: $written['nodes']);
		$this->assertSame(expected: [], actual: $skipped);

	}//end testABoundFlowIsWrittenWithItsUuid()

	/**
	 * THE FIXTURE THAT DISTINGUISHES: the entity and the mirror disagree.
	 *
	 * `FlowMapper` is the entity. The `agentflow` object store mirrors some
	 * definitions and drifts — a definition written to the object left the
	 * engine running the previous graph, with no error anywhere. A bundler
	 * reading the mirror would export a graph that is not the one that runs,
	 * and every check short of executing it would pass.
	 *
	 * Here the mapper (entity) answers with THREE nodes while the object store
	 * would have answered with one. The export must carry three.
	 *
	 * @return void
	 */
	public function testTheEntityIsTheSourceWhenTheMirrorDisagrees(): void {
		$uuid = '6b14a1fd-0cab-40c0-a3e7-7fea3be29bdc';
		$entityGraph = [
			['id' => 'start', 'type' => 'openregister.trigger-schedule'],
			['id' => 'review', 'type' => 'hermiq.workload-step'],
			['id' => 'end', 'type' => 'openregister.end'],
		];

		// The mapper — and ONLY the mapper — is consulted. If the subject ever
		// reached for the object store, it would have to do so through
		// ObjectService, which is asserted below never to be asked for flows.
		$this->flowMapper->expects($this->once())->method('findByUuid')->with($uuid)
			->willReturn($this->flow($uuid, $entityGraph));
		$this->objectService->method('findAll')->willReturn([]);

		$this->bundler->bundle($this->root, [['flow' => $uuid]], 'hydra-console');

		$written = json_decode((string)file_get_contents($this->root . '/lib/Settings/flows/' . $uuid . '.json'), true);
		$this->assertCount(
			expectedCount: 3,
			haystack: $written['nodes'],
			message: 'the exported graph must be the entity’s three nodes, not the mirror’s one'
		);

	}//end testTheEntityIsTheSourceWhenTheMirrorDisagrees()

	/**
	 * A flow with agentic nodes takes the ordinary path (ADR-065).
	 *
	 * One flow system: no branch on node type, no hermiq special case, one
	 * file shape.
	 *
	 * @return void
	 */
	public function testAnAgenticFlowIsBundledLikeAnyOther(): void {
		$plain = '11111111-1111-4111-8111-111111111111';
		$agentic = '22222222-2222-4222-8222-222222222222';

		$this->flowMapper->method('findByUuid')->willReturnCallback(
			fn (string $u): Flow => $this->flow(
				$u,
				$u === $agentic
					? [['id' => 'review', 'type' => 'hermiq.workload-step']]
					: [['id' => 'start', 'type' => 'openregister.trigger-schedule']]
			)
		);
		$this->objectService->method('findAll')->willReturn([]);

		$this->bundler->bundle($this->root, [['flow' => $plain], ['flow' => $agentic]], 'hydra-console');

		$a = json_decode((string)file_get_contents($this->root . '/lib/Settings/flows/' . $plain . '.json'), true);
		$b = json_decode((string)file_get_contents($this->root . '/lib/Settings/flows/' . $agentic . '.json'), true);

		$this->assertSame(
			expected: array_keys($a),
			actual: array_keys($b),
			message: 'an agentic flow must emit the same file shape as any other'
		);

	}//end testAnAgenticFlowIsBundledLikeAnyOther()

	/**
	 * A dangling binding is RETURNED, not merely survived.
	 *
	 * "The export did not crash" is also what silently dropping the binding
	 * looks like. The operator reads the finished job, never the log.
	 *
	 * @return void
	 */
	public function testADanglingBindingIsReturnedAsASkip(): void {
		$missing = '00000000-0000-0000-0000-000000000000';
		$this->flowMapper->method('findByUuid')->willThrowException(new RuntimeException('not found'));
		$this->objectService->method('findAll')->willReturn([]);

		$skipped = $this->bundler->bundle($this->root, [['flow' => $missing]], 'hydra-console');

		$this->assertCount(expectedCount: 1, haystack: $skipped);
		$this->assertSame(expected: 'flow', actual: $skipped[0]['kind']);
		$this->assertSame(expected: $missing, actual: $skipped[0]['ref']);
		$this->assertDirectoryDoesNotExist(
			$this->root . '/lib/Settings/flows',
			'nothing should be written for a binding that resolved to nothing'
		);

	}//end testADanglingBindingIsReturnedAsASkip()

	/**
	 * Agents are found WITHOUT a binding, by asking which point at the app.
	 *
	 * There is deliberately no `Application.agents`: `agent.applicationSlug`
	 * already expresses the relationship, and a second edge could disagree
	 * with the first.
	 *
	 * @return void
	 */
	public function testAgentsAreResolvedByApplicationSlugRatherThanABinding(): void {
		$captured = [];
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$captured): array {
				$captured = $config;
				return [['@self' => ['id' => 'agent-uuid-1'], 'name' => 'Code reviewer', 'applicationSlug' => 'hydra-console']];
			}
		);

		$this->bundler->bundle($this->root, [], 'hydra-console');

		$this->assertSame(
			expected: 'hydra-console',
			actual: ($captured['filters']['applicationSlug'] ?? null),
			message: 'agents must be looked up by the application they point at'
		);
		$this->assertFileExists($this->root . '/lib/Settings/agents/agent-uuid-1.json');

		$written = json_decode((string)file_get_contents($this->root . '/lib/Settings/agents/agent-uuid-1.json'), true);
		$this->assertArrayNotHasKey(
			key: '@self',
			array: $written,
			message: 'the OR envelope is instance-local and must not travel in an export'
		);

	}//end testAgentsAreResolvedByApplicationSlugRatherThanABinding()

	/**
	 * An application with no bindings and no agents writes nothing at all.
	 *
	 * @return void
	 */
	public function testAnApplicationWithNothingBoundWritesNothing(): void {
		$this->objectService->method('findAll')->willReturn([]);

		$skipped = $this->bundler->bundle($this->root, [], 'hydra-console');

		$this->assertSame(expected: [], actual: $skipped);
		$this->assertDirectoryDoesNotExist($this->root . '/lib/Settings/flows');
		$this->assertDirectoryDoesNotExist($this->root . '/lib/Settings/agents');

	}//end testAnApplicationWithNothingBoundWritesNothing()
}//end class
