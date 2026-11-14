<?php

/**
 * Golden tests for how AutomationCompilerService reads and writes schema configuration.
 *
 * Six steps of the compiler load a target schema by slug and read its
 * configuration: the notification and lifecycle-action upserts, their live
 * reads for drift detection, and the two approval-chain reads. These pin what
 * each answers for a present schema, an empty slug, a schema that cannot be
 * loaded, and which writes the upserts make.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins the schema-config reads and writes of the compiler.
 */
class AutomationCompilerSchemaConfigTest extends TestCase {

	/**
	 * Slugs the schema mapper was asked for, in order.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * Configurations written back, per slug, in order.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $written = [];

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The service under test.
	 *
	 * @var AutomationCompilerService
	 */
	private AutomationCompilerService $compiler;

	/**
	 * Wire a schema mapper over `permit` (id 5) and `gone` (missing).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$schemas = ['permit' => $this->schema(slug: 'permit', id: 5)];
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturnCallback(function (string $slug) use ($schemas): Schema {
			$this->asked[] = $slug;
			return $schemas[$slug] ?? throw new DoesNotExistException('no schema ' . $slug);
		});
		$mapper->method('update')->willReturnCallback(function (Schema $schema): Schema {
			$this->written[] = [$schema->slugForTest, $schema->getConfiguration()];
			return $schema;
		});
		$appManager = $this->createMock(IAppManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->compiler = new AutomationCompilerService(
			$this->createMock(ContainerInterface::class),
			$this->createMock(ObjectServiceInterface::class),
			$mapper,
			$appManager,
			$this->logger,
		);
	}//end setUp()

	/**
	 * Live notifications: empty keys and slugs are skipped unread, a missing schema is skipped.
	 *
	 * @return void
	 */
	public function testFetchLiveNotifications(): void {
		$keys = [
			['schema' => 'permit', 'key' => 'aut-a-1'],
			['schema' => '', 'key' => 'x'],
			['schema' => 'permit', 'key' => ''],
			['schema' => 'gone', 'key' => 'aut-a-2'],
			['schema' => 'permit', 'key' => 'absent'],
		];

		$live = $this->call(method: 'fetchLiveNotifications', args: ['keys' => $keys]);

		$expected = [
			['schema' => 'permit', 'key' => 'aut-a-1', 'entry' => ['trigger' => 'created']],
			['schema' => 'permit', 'key' => 'absent', 'entry' => null],
		];
		self::assertSame($expected, $live);
		self::assertSame(['permit', 'gone', 'permit'], $this->asked);
	}//end testFetchLiveNotifications()

	/**
	 * Live lifecycle actions: only this automation's marker, on the named transition.
	 *
	 * @return void
	 */
	public function testFetchLiveLifecycleActions(): void {
		$marked = [
			['schema' => 'permit', 'transition' => 'approve'],
			['schema' => '', 'transition' => 'approve'],
			['schema' => 'gone', 'transition' => 'approve'],
			['schema' => 'permit', 'transition' => ''],
		];

		$live = $this->call(method: 'fetchLiveLifecycleActions', args: ['slug' => 'a', 'marked' => $marked]);

		$action = ['marker' => 'aut-a', 'type' => 'notify'];
		self::assertSame([['schema' => 'permit', 'transition' => 'approve', 'marker' => 'aut-a', 'action' => $action]], $live);
		self::assertSame(['permit', 'gone'], $this->asked);
	}//end testFetchLiveLifecycleActions()

	/**
	 * Approval-chain reads, per schema and chain name.
	 *
	 * @return void
	 */
	public function testApprovalChainReads(): void {
		$live = [
			$this->call(method: 'fetchLiveApprovalChain', args: ['schemaSlug' => 'permit', 'name' => 'chain-a']),
			$this->call(method: 'fetchLiveApprovalChain', args: ['schemaSlug' => 'permit', 'name' => 'nope']),
			$this->call(method: 'fetchLiveApprovalChain', args: ['schemaSlug' => 'gone', 'name' => 'chain-a']),
			$this->call(method: 'fetchLiveApprovalChain', args: ['schemaSlug' => 'permit', 'name' => '']),
			$this->call(method: 'fetchLiveApprovalChain', args: ['schemaSlug' => 'permit', 'name' => 7]),
		];
		$state = [
			$this->call(method: 'resolveApprovalChainForState', args: ['schemaSlug' => 'permit', 'chainName' => 'chain-a']),
			$this->call(method: 'resolveApprovalChainForState', args: ['schemaSlug' => 'permit', 'chainName' => 'flat']),
			$this->call(method: 'resolveApprovalChainForState', args: ['schemaSlug' => '', 'chainName' => 'chain-a']),
		];

		$chain = ['name' => 'chain-a', 'steps' => [['order' => 1, 'role' => 'lead'], ['order' => 2, 'role' => 'head']], 'enabled' => false];
		self::assertSame([$chain, null, null, null, null], $live);
		self::assertSame([['name' => 'chain-a', 'schemaId' => 5], null, null], $state);
		self::assertSame(['permit', 'permit', 'gone', 'permit', 'permit', ''], $this->asked);
	}//end testApprovalChainReads()

	/**
	 * The notification upsert drops stale prior keys, adds planned ones and writes each touched schema once.
	 *
	 * @return void
	 */
	public function testApplyNotifications(): void {
		$planned = [['schema' => 'permit', 'key' => 'aut-a-2', 'entry' => ['trigger' => 'updated']]];
		$prior = [['schema' => 'permit', 'key' => 'aut-a-1'], ['schema' => 'gone', 'key' => 'aut-a-1'], ['schema' => '', 'key' => 'x']];

		$result = $this->call(method: 'applyNotifications', args: ['planned' => $planned, 'priorKeys' => $prior]);

		self::assertSame([['schema' => 'permit', 'key' => 'aut-a-2']], $result);
		self::assertSame(['permit', 'gone'], $this->asked);
		self::assertSame([['permit', ['x-openregister-notifications' => ['hand' => ['trigger' => 'x'], 'aut-a-2' => ['trigger' => 'updated']]] + $this->baseConfig(without: 'x-openregister-notifications')]], $this->written);
	}//end testApplyNotifications()

	/**
	 * The lifecycle upsert strips this automation's actions and re-adds the planned ones.
	 *
	 * @return void
	 */
	public function testApplyLifecycleActions(): void {
		$planned = [['schema' => 'permit', 'transition' => 'approve', 'marker' => 'aut-a', 'action' => ['marker' => 'aut-a', 'type' => 'webhook']]];
		$prior = [['schema' => 'gone', 'transition' => 'approve'], ['schema' => '', 'transition' => 'x']];

		$result = $this->call(method: 'applyLifecycleActions', args: ['slug' => 'a', 'planned' => $planned, 'priorActions' => $prior]);

		self::assertSame([['schema' => 'permit', 'transition' => 'approve', 'marker' => 'aut-a']], $result);
		self::assertSame(['permit', 'gone'], $this->asked);
		$actions = $this->written[0][1]['x-openregister-lifecycle']['transitions']['approve']['actions'];
		self::assertSame([['type' => 'hand-made'], ['marker' => 'aut-a', 'type' => 'webhook']], $actions);
		self::assertCount(1, $this->written);
	}//end testApplyLifecycleActions()

	/**
	 * Invoke a private compiler step.
	 *
	 * @param string               $method The method.
	 * @param array<string, mixed> $args   Its named arguments.
	 *
	 * @return mixed
	 */
	private function call(string $method, array $args): mixed {
		return (new ReflectionMethod($this->compiler, $method))->invokeArgs($this->compiler, $args);
	}//end call()

	/**
	 * The configuration every schema starts with.
	 *
	 * @param string $without A key to leave out.
	 *
	 * @return array<string, mixed>
	 */
	private function baseConfig(string $without = ''): array {
		$config = [
			'x-openregister-notifications' => ['aut-a-1' => ['trigger' => 'created'], 'hand' => ['trigger' => 'x']],
			'x-openregister-lifecycle' => ['transitions' => ['approve' => ['actions' => [['marker' => 'aut-a', 'type' => 'notify'], ['type' => 'hand-made']]]]],
			'x-openregister-approval-chains' => ['chain-a' => ['approvers' => [['role' => 'lead'], ['role' => ' '], ['role' => 'head']], 'enabled' => false], 'flat' => 'not an array'],
		];
		unset($config[$without]);
		return $config;
	}//end baseConfig()

	/**
	 * A schema double carrying the base configuration.
	 *
	 * @param string   $slug The slug, kept for the write log.
	 * @param int|null $id   The id, or null for none.
	 *
	 * @return Schema
	 */
	private function schema(string $slug, ?int $id): Schema {
		$schema = new class() extends Schema {

			/**
			 * The slug the double answers for.
			 *
			 * @var string
			 */
			public string $slugForTest = '';
		};
		$schema->slugForTest = $slug;
		if ($id !== null) {
			$schema->setId($id);
		}

		$schema->setConfiguration($this->baseConfig());
		return $schema;
	}//end schema()
}//end class
