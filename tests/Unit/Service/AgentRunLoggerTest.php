<?php

/**
 * Unit tests for AgentRunLogger (spec `agent-workspace`).
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
 *
 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/agent-workspace/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AgentRunLogger;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for AgentRunLogger.
 */
class AgentRunLoggerTest extends TestCase {

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up shared mocks + the SUT.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * log() persists an `AgentRun` via `ObjectService::saveObject()` in the shared
	 * `buildiq` register / `agentRun` schema, carrying every field.
	 *
	 * @return void
	 */
	public function testLogPersistsAgentRunWithAllFields(): void {
		$agent = ['id' => 'agent-1', 'applicationSlug' => 'tool-library'];
		$plan = ['summary' => 'x', 'steps' => [['tool' => 'buildiq.upsertPage', 'arguments' => []]]];
		$calls = [['tool' => 'buildiq.upsertPage', 'arguments' => [], 'result' => ['success' => true]]];

		// ObjectService::saveObject()'s real signature interleaves several
		// optional params (extend/register/schema/uuid/...) — with() matches
		// positionally against the fully-resolved invocation, so position 1
		// (`extend`, defaulted to []) must be constrained explicitly too.
		$this->objectService->expects(self::once())->method('saveObject')
			->with(
				self::callback(
					static function (array $payload) use ($agent, $plan, $calls): bool {
						return $payload['agentId'] === 'agent-1'
						&& $payload['applicationSlug'] === 'tool-library'
						&& $payload['prompt'] === 'Add a page'
						&& $payload['plan'] === $plan
						&& $payload['toolCalls'] === $calls
						&& $payload['outcome'] === 'applied'
						&& is_string($payload['createdAt']) === true
						&& $payload['createdAt'] !== '';
					}
				),
				self::anything(),
				'buildiq',
				'agentRun'
			)
			->willReturn($this->objectEntity(['id' => 'run-1', 'outcome' => 'applied']));

		$logger = new AgentRunLogger(objectService: $this->objectService, logger: $this->logger);
		$result = $logger->log(agent: $agent, userId: 'alice', prompt: 'Add a page', plan: $plan, toolCalls: $calls, outcome: 'applied');

		self::assertSame('run-1', $result['id']);
		self::assertSame('applied', $result['outcome']);
	}//end testLogPersistsAgentRunWithAllFields()

	/**
	 * Build a mocked `ObjectEntity` whose `jsonSerialize()` returns the given payload
	 * (mirrors `PrincipalMatcherTest`'s pattern for a strictly-typed OR return value).
	 *
	 * @param array<string, mixed> $payload The payload `jsonSerialize()` should return.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function objectEntity(array $payload): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($payload);
		return $entity;
	}//end objectEntity()

	/**
	 * log() resolves the agent id from either `id` or `uuid`, and defaults
	 * `applicationSlug` when absent.
	 *
	 * @return void
	 */
	public function testLogResolvesAgentIdFromUuidFallback(): void {
		$agent = ['uuid' => 'agent-2'];

		$this->objectService->expects(self::once())->method('saveObject')
			->with(
				self::callback(static fn (array $payload): bool => $payload['agentId'] === 'agent-2' && $payload['applicationSlug'] === ''),
				self::anything(),
				'buildiq',
				'agentRun'
			)
			->willReturn($this->objectEntity([]));

		$logger = new AgentRunLogger(objectService: $this->objectService, logger: $this->logger);
		$logger->log(agent: $agent, userId: 'alice', prompt: 'x', plan: [], toolCalls: [], outcome: 'discarded');
	}//end testLogResolvesAgentIdFromUuidFallback()

	/**
	 * log() swallows a persistence failure (logs it, does not throw) — a broken
	 * audit write must never turn a completed plan/execute/discard action into
	 * a user-facing 500.
	 *
	 * @return void
	 */
	public function testLogSwallowsPersistenceFailure(): void {
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects(self::once())->method('error');

		$logger = new AgentRunLogger(objectService: $this->objectService, logger: $this->logger);
		$result = $logger->log(agent: ['id' => 'agent-1'], userId: 'alice', prompt: 'x', plan: [], toolCalls: [], outcome: 'plan-rejected');

		self::assertSame([], $result);
	}//end testLogSwallowsPersistenceFailure()

	/**
	 * log() coerces the persisted `ObjectEntity`'s `jsonSerialize()` payload
	 * into the returned plain array.
	 *
	 * @return void
	 */
	public function testLogCoercesPersistedEntityToArray(): void {
		$this->objectService->method('saveObject')->willReturn($this->objectEntity(['id' => 'run-9', 'outcome' => 'rolled-back']));

		$logger = new AgentRunLogger(objectService: $this->objectService, logger: $this->logger);
		$result = $logger->log(agent: ['id' => 'agent-1'], userId: 'alice', prompt: 'x', plan: [], toolCalls: [], outcome: 'rolled-back');

		self::assertSame('run-9', $result['id']);
		self::assertSame('rolled-back', $result['outcome']);
	}//end testLogCoercesPersistedEntityToArray()
}//end class
