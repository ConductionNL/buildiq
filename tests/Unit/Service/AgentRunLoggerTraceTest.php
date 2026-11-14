<?php

/**
 * Buildiq AgentRunLoggerTraceTest
 *
 * A run keeps the model steps its plan made, found by the trace id the plan returned.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-workspace/spec.md#requirement-a-run-keeps-its-model-steps-req-bqag-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AgentRunLogger;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers AgentRunLogger::rememberTrace() and the trace half of log().
 */
final class AgentRunLoggerTraceTest extends TestCase {

	/**
	 * The cache contents, by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $cache = [];

	/**
	 * The time-to-live each cache write asked for.
	 *
	 * @var list<int>
	 */
	private array $ttls = [];

	/**
	 * The payloads saved as runs.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The two model steps of a repaired plan.
	 *
	 * @var array<string, mixed>
	 */
	private const TRACE = [
		'modelSteps'    => [
			['attempt' => 1, 'kind' => 'plan', 'status' => 'repair-needed', 'refusal' => 'Syntax error', 'durationMs' => 1200],
			['attempt' => 2, 'kind' => 'repair', 'status' => 'successful', 'refusal' => '', 'durationMs' => 900],
		],
		'knowledgeUsed' => ['huisstijl-formulieren.md'],
		'durationMs'    => 2100,
	];

	/**
	 * A logger over an in-memory distributed cache, or none.
	 *
	 * @param bool $withCache Whether a cache is configured.
	 *
	 * @return AgentRunLogger
	 */
	private function logger(bool $withCache=true): AgentRunLogger {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($object);
				return $entity;
			}
		);

		if ($withCache === false) {
			return new AgentRunLogger(objectService: $objects, logger: $this->createMock(LoggerInterface::class));
		}

		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl=0): bool {
				$this->cache[$key] = $value;
				$this->ttls[]      = $ttl;
				return true;
			}
		);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->cache[$key] ?? null));
		$cache->method('remove')->willReturnCallback(
			function (string $key): bool {
				unset($this->cache[$key]);
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->expects($this->once())->method('createDistributed')->with('buildiq-agent-run-trace')->willReturn($cache);

		return new AgentRunLogger(objectService: $objects, logger: $this->createMock(LoggerInterface::class), cacheFactory: $factory);
	}//end logger()

	/**
	 * Write one applied run.
	 *
	 * @param AgentRunLogger $logger  The logger.
	 * @param string         $userId  The user.
	 * @param string|null    $traceId The trace id.
	 * @param string         $agentId The agent.
	 *
	 * @return array<string, mixed> The saved payload.
	 */
	private function applied(AgentRunLogger $logger, string $userId, ?string $traceId, string $agentId='agent-1'): array {
		$logger->log(
			agent: ['id' => $agentId, 'applicationSlug' => 'permits'],
			userId: $userId,
			prompt: 'Add a contact form',
			plan: ['summary' => 's', 'steps' => []],
			toolCalls: [['tool' => 'buildiq.upsertPage', 'arguments' => [], 'result' => []]],
			outcome: 'applied',
			traceId: $traceId
		);

		return end($this->saved);
	}//end applied()

	/**
	 * The plan's steps attach to the run of the same agent and user, once, and the cache keeps them an hour.
	 *
	 * @return void
	 */
	public function testThePlansStepsAttachToTheRun(): void {
		$logger  = $this->logger();
		$traceId = $logger->rememberTrace(agent: ['id' => 'agent-1'], userId: 'alice', trace: self::TRACE);

		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $traceId);
		$this->assertSame([AgentRunLogger::TRACE_TTL], $this->ttls);
		$this->assertSame(3600, AgentRunLogger::TRACE_TTL);

		$run = $this->applied(logger: $logger, userId: 'alice', traceId: $traceId);
		$this->assertSame(self::TRACE['modelSteps'], $run['modelSteps']);
		$this->assertSame(['huisstijl-formulieren.md'], $run['knowledgeUsed']);
		$this->assertSame(2100, $run['durationMs']);
		$this->assertFalse($run['traceMissing']);
		$this->assertCount(1, $run['toolCalls'], 'the tool calls are kept as before');
		$this->assertSame([], $this->cache, 'a trace is used once');
	}//end testThePlansStepsAttachToTheRun()

	/**
	 * An expired trace is recorded as lost, with the tool calls as before.
	 *
	 * @return void
	 */
	public function testAnExpiredTraceIsRecordedAsLost(): void {
		$logger  = $this->logger();
		$traceId = $logger->rememberTrace(agent: ['id' => 'agent-1'], userId: 'alice', trace: self::TRACE);
		$this->cache = [];

		$run = $this->applied(logger: $logger, userId: 'alice', traceId: $traceId);

		$this->assertSame([], $run['modelSteps']);
		$this->assertTrue($run['traceMissing']);
		$this->assertSame(0, $run['durationMs']);
		$this->assertCount(1, $run['toolCalls']);
	}//end testAnExpiredTraceIsRecordedAsLost()

	/**
	 * Another user's trace id, or one for another agent, finds nothing and stays in the cache.
	 *
	 * @return void
	 */
	public function testAnotherUsersOrAgentsTraceDoesNotAttach(): void {
		$logger  = $this->logger();
		$traceId = $logger->rememberTrace(agent: ['id' => 'agent-1'], userId: 'alice', trace: self::TRACE);

		$this->assertTrue($this->applied(logger: $logger, userId: 'bob', traceId: $traceId)['traceMissing']);
		$this->assertTrue($this->applied(logger: $logger, userId: 'alice', traceId: $traceId, agentId: 'agent-2')['traceMissing']);
		$this->assertCount(1, $this->cache, 'alice\'s trace is untouched');
		$this->assertTrue($this->applied(logger: $logger, userId: 'alice', traceId: 'not-a-trace-id')['traceMissing']);
		$this->assertFalse($this->applied(logger: $logger, userId: 'alice', traceId: $traceId)['traceMissing']);
	}//end testAnotherUsersOrAgentsTraceDoesNotAttach()

	/**
	 * Steps in hand win over a trace id, and a run without either is marked missing.
	 *
	 * @return void
	 */
	public function testStepsInHandAndNoTraceAtAll(): void {
		$logger = $this->logger();
		$logger->log(agent: ['id' => 'agent-1'], userId: 'alice', prompt: 'x', plan: [], toolCalls: [], outcome: 'plan-rejected', trace: self::TRACE);
		$this->assertSame(self::TRACE['modelSteps'], end($this->saved)['modelSteps']);
		$this->assertFalse(end($this->saved)['traceMissing']);

		$logger->log(agent: ['id' => 'agent-1'], userId: 'alice', prompt: 'x', plan: [], toolCalls: [], outcome: 'discarded');
		$this->assertTrue(end($this->saved)['traceMissing']);
	}//end testStepsInHandAndNoTraceAtAll()

	/**
	 * Without a configured cache no trace id is handed out and runs are marked missing.
	 *
	 * @return void
	 */
	public function testWithoutACacheNothingIsKept(): void {
		$logger = $this->logger(withCache: false);

		$this->assertSame('', $logger->rememberTrace(agent: ['id' => 'agent-1'], userId: 'alice', trace: self::TRACE));
		$this->assertTrue($this->applied(logger: $logger, userId: 'alice', traceId: '')['traceMissing']);
	}//end testWithoutACacheNothingIsKept()
}//end class
