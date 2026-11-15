<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for how AutomationCompilerService writes schedules onto an
 * ApplicationVersion manifest.
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Upserting planned schedules, dropping prior ones, keeping hand-authored ones.
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */
final class AutomationCompilerSchedulesTest extends TestCase {

	/**
	 * Object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Every saveObject() call as [object, register, schema, uuid].
	 *
	 * @var list<array{0:array<string,mixed>,1:mixed,2:mixed,3:mixed}>
	 */
	private array $saves = [];

	/**
	 * Wire the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->saves = [];

	}//end setUp()

	/**
	 * The compiler, with find() answering one ApplicationVersion.
	 *
	 * @param array<string,mixed>|null $version The version "ver-1" holds, or null for none.
	 *
	 * @return AutomationCompilerService
	 */
	private function compiler(?array $version): AutomationCompilerService {
		$versions = [];
		if ($version !== null) {
			$versions['ver-1'] = $this->entity($version);
		}

		$this->objectService->method('find')->willReturnCallback(
			static fn (string|int $id): ?ObjectEntity => ($versions[$id] ?? null)
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null
			): ObjectEntity {
				$this->saves[] = [$object, $register, $schema, $uuid];
				return $this->entity($object);
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('isEnabledForUser')->willReturn(true);

		return new AutomationCompilerService(
			$this->createMock(ContainerInterface::class),
			$this->objectService,
			$this->createMock(SchemaMapper::class),
			$appManager,
			$this->logger,
		);

	}//end compiler()

	/**
	 * An ObjectEntity serialising to the payload.
	 *
	 * @param array<string,mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = new class() extends ObjectEntity {

			/**
			 * The payload.
			 *
			 * @var array<string,mixed>
			 */
			public array $payload = [];

			/**
			 * Serialise the payload.
			 *
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return $this->payload;
			}
		};
		$entity->payload = $payload;

		return $entity;

	}//end entity()

	/**
	 * A plan carrying only schedules.
	 *
	 * @param list<array<string,mixed>> $schedules The planned schedules.
	 *
	 * @return array<string,mixed>
	 */
	private function plan(array $schedules): array {
		return [
			'notifications' => [],
			'lifecycleActions' => [],
			'schedules' => $schedules,
			'ruleSet' => null,
			'conditionActionRule' => null,
			'approvalChain' => null,
			'hash' => 'h-1',
		];

	}//end plan()

	/**
	 * A planned schedule replaces its namesake in place, a new one is appended,
	 * a prior one no longer planned is dropped and a hand-authored one is kept.
	 *
	 * @return void
	 */
	public function testPlannedSchedulesAreUpsertedAndStaleOnesDropped(): void {
		$compiler = $this->compiler(
			[
				'id' => 'ver-1',
				'manifest' => [
					'version' => '1.0.0',
					'schedules' => [
						['id' => 'aut-a', 'cron' => '0 1 * * *'],
						['id' => 'hand-made', 'cron' => '0 2 * * *'],
						'not-a-row',
						['id' => 'aut-stale', 'cron' => '0 3 * * *'],
					],
				],
			]
		);

		$provenance = $compiler->apply(
			['slug' => 'nightly', 'versionUuid' => 'ver-1'],
			$this->plan([['id' => 'aut-new', 'cron' => '0 5 * * *'], ['id' => 'aut-a', 'cron' => '0 4 * * *']]),
			['scheduleIds' => ['aut-a', 'aut-stale']]
		);

		self::assertSame(['aut-new', 'aut-a'], $provenance['scheduleIds']);
		self::assertCount(1, $this->saves);
		[$version, $register, $schema, $uuid] = $this->saves[0];
		self::assertSame(['buildiq', 'applicationVersion', 'ver-1'], [$register, $schema, $uuid]);
		self::assertSame('1.0.0', $version['manifest']['version']);
		self::assertSame(
			[
				['id' => 'aut-a', 'cron' => '0 4 * * *'],
				['id' => 'hand-made', 'cron' => '0 2 * * *'],
				'not-a-row',
				['id' => 'aut-new', 'cron' => '0 5 * * *'],
			],
			$version['manifest']['schedules']
		);

	}//end testPlannedSchedulesAreUpsertedAndStaleOnesDropped()

	/**
	 * Removing the last schedules drops the manifest's schedules key.
	 *
	 * @return void
	 */
	public function testRemovingTheLastScheduleDropsTheKey(): void {
		$compiler = $this->compiler(
			['id' => 'ver-1', 'manifest' => ['version' => '1.0.0', 'schedules' => [['id' => 'aut-a']]]]
		);

		$compiler->remove(['slug' => 'nightly', 'versionUuid' => 'ver-1'], ['scheduleIds' => ['aut-a']]);

		self::assertCount(1, $this->saves);
		self::assertSame(['version' => '1.0.0'], $this->saves[0][0]['manifest']);

	}//end testRemovingTheLastScheduleDropsTheKey()

	/**
	 * Nothing planned and nothing prior touches nothing; a missing version is
	 * logged and records no schedule ids.
	 *
	 * @return void
	 */
	public function testNothingToDoOrNoVersionWritesNothing(): void {
		$this->objectService->expects(self::never())->method('saveObject');
		$this->logger->expects(self::once())->method('warning')->with(
			'Buildiq: AutomationCompilerService could not load ApplicationVersion "ver-gone" to apply schedules.'
		);
		$compiler = $this->compiler(null);

		$none = $compiler->apply(['slug' => 'nightly', 'versionUuid' => 'ver-1'], $this->plan([]));
		self::assertSame([], $none['scheduleIds']);

		$missing = $compiler->apply(['slug' => 'nightly', 'versionUuid' => 'ver-gone'], $this->plan([['id' => 'aut-a']]));
		self::assertSame([], $missing['scheduleIds']);

	}//end testNothingToDoOrNoVersionWritesNothing()

	/**
	 * A failing save is logged and the planned ids are still recorded.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsLoggedAndStillRecordsTheIds(): void {
		$this->objectService->method('find')->willReturn($this->entity(['id' => 'ver-1', 'manifest' => []]));
		$this->objectService->method('saveObject')->willThrowException(new \RuntimeException('locked'));
		$this->logger->expects(self::once())->method('error')->with(
			'Buildiq: AutomationCompilerService failed to save schedules onto ApplicationVersion "ver-1": locked'
		);
		$appManager = $this->createMock(IAppManager::class);
		$compiler = new AutomationCompilerService(
			$this->createMock(ContainerInterface::class),
			$this->objectService,
			$this->createMock(SchemaMapper::class),
			$appManager,
			$this->logger,
		);

		$provenance = $compiler->apply(['slug' => 'nightly', 'versionUuid' => 'ver-1'], $this->plan([['id' => 'aut-a']]));

		self::assertSame(['aut-a'], $provenance['scheduleIds']);

	}//end testAFailingSaveIsLoggedAndStillRecordsTheIds()
}//end class
