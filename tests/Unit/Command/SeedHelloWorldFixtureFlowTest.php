<?php

/**
 * Unit tests for the write sequence of buildiq:seed-hello-world-fixture: what
 * a fresh seed writes and in which order, what a re-run on a fully seeded
 * instance writes (nothing), when the virtual app counts as present, and how
 * a failing write ends the command.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Command;

use OCA\Buildiq\Command\SeedHelloWorldFixture;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The write sequence of SeedHelloWorldFixture.
 *
 * @covers \OCA\Buildiq\Command\SeedHelloWorldFixture
 * @uses   \OCA\Buildiq\Support\UuidV4
 */
final class SeedHelloWorldFixtureFlowTest extends TestCase {

	/**
	 * Every saveObject() call, in order: schema, data and uuid.
	 *
	 * @var array<int,array{schema:mixed,data:mixed,uuid:?string}>
	 */
	private array $saved = [];

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Build the command against an instance with the given objects present.
	 *
	 * @param bool                   $appPresent    Whether the hello-world Application exists.
	 * @param bool                   $routePresent  Whether its built-app route exists.
	 * @param bool                   $hybridPresent Whether the hybrid example exists.
	 * @param RuntimeException|null  $failOnSave    Thrown by the first saveObject() call.
	 *
	 * @return SeedHelloWorldFixture The command.
	 */
	private function command(
		bool $appPresent,
		bool $routePresent,
		bool $hybridPresent,
		?RuntimeException $failOnSave = null
	): SeedHelloWorldFixture {
		$this->saved         = [];
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$register = $this->createMock(Register::class);
		$register->method('getId')->willReturn(1);
		$register->method('getSchemas')->willReturn([7]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$registerMapper->expects($this->never())->method('createFromArray');
		$registerMapper->expects($this->never())->method('update');

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (string|int $slug): Schema {
				$schema = $this->createMock(Schema::class);
				$schema->method('getId')->willReturn(['built-app' => 3, 'built-app-route' => 5, 'hello-message' => 7][$slug] ?? 9);
				return $schema;
			}
		);

		$this->objectService->method('searchObjects')->willReturnCallback(
			static function (array $query) use ($appPresent, $routePresent, $hybridPresent): array {
				if (isset($query['appType']) === true) {
					return ($hybridPresent === true) ? [['uuid' => 'hybrid']] : [];
				}

				if ($query['@self']['schema'] === 5) {
					return ($routePresent === true) ? [['uuid' => 'route']] : [];
				}

				return ($appPresent === true) ? [['uuid' => 'app']] : [];
			}
		);

		$this->objectService->method('saveObject')->willReturnCallback(
			function (
				array|ObjectEntity $object,
				?array $extend = [],
				Register|string|int|null $register = null,
				Schema|string|int|null $schema = null,
				?string $uuid = null,
			) use ($failOnSave): ObjectEntity {
				if ($failOnSave !== null) {
					throw $failOnSave;
				}

				$this->saved[] = ['schema' => $schema, 'data' => $object, 'uuid' => $uuid];
				$entity        = $this->createMock(ObjectEntity::class);
				$entity->method('getUuid')->willReturn($uuid ?? 'minted-'.count($this->saved));

				return $entity;
			}
		);

		return new SeedHelloWorldFixture($this->objectService, $registerMapper, $schemaMapper);
	}//end command()

	/**
	 * A fresh seed writes the app, its version, the app again with its production
	 * pointer, the route, three messages, then the hybrid example's version and app.
	 *
	 * @return void
	 */
	public function testAFreshSeedWritesEverythingInOrder(): void {
		$tester = new CommandTester($this->command(appPresent: false, routePresent: false, hybridPresent: false));

		self::assertSame(Command::SUCCESS, $tester->execute([]));
		self::assertSame(
			[
				'built-app',
				'applicationVersion',
				'built-app',
				'built-app-route',
				'hello-message',
				'hello-message',
				'hello-message',
				'applicationVersion',
				'built-app',
			],
			array_column($this->saved, 'schema')
		);

		self::assertNull($this->saved[0]['uuid']);
		self::assertSame('minted-1', $this->saved[1]['data']['application']);
		self::assertSame('openbuild-hello-world', $this->saved[1]['data']['register']);
		self::assertSame('minted-1', $this->saved[2]['uuid']);
		self::assertSame('minted-2', $this->saved[2]['data']['productionVersion']);
		self::assertSame(['slug' => 'hello-world', 'applicationUuid' => 'minted-1'], $this->saved[3]['data']);
		self::assertSame(
			['Welcome to Buildiq', 'Edit me', 'Built from a manifest'],
			array_map(static fn (array $call): string => $call['data']['title'], array_slice($this->saved, 4, 3))
		);
		self::assertStringContainsString('Seeded hello-world fixture (application minted-1).', $tester->getDisplay());
	}//end testAFreshSeedWritesEverythingInOrder()

	/**
	 * The hybrid app is written under the uuid its version points at, with a production pointer to that version.
	 *
	 * @return void
	 */
	public function testTheHybridAppPointsAtItsVersion(): void {
		$tester = new CommandTester($this->command(appPresent: true, routePresent: true, hybridPresent: false));

		self::assertSame(Command::SUCCESS, $tester->execute([]));
		self::assertCount(2, $this->saved);
		[$version, $app] = $this->saved;
		self::assertSame('applicationVersion', $version['schema']);
		self::assertSame('openbuild-opencatalogi', $version['data']['register']);
		self::assertSame(['kind' => 'fleet-app', 'id' => 'opencatalogi'], $version['data']['baseRef']);
		self::assertSame($version['data']['application'], $app['uuid']);
		self::assertSame('minted-1', $app['data']['productionVersion']);
		self::assertSame('hybrid', $app['data']['appType']);
		self::assertStringContainsString('already present — skipping the virtual app', $tester->getDisplay());
	}//end testTheHybridAppPointsAtItsVersion()

	/**
	 * A fully seeded instance gets no writes at all.
	 *
	 * @return void
	 */
	public function testAFullySeededInstanceIsNotWritten(): void {
		$tester = new CommandTester($this->command(appPresent: true, routePresent: true, hybridPresent: true));

		self::assertSame(Command::SUCCESS, $tester->execute([]));
		self::assertSame([], $this->saved);
		self::assertStringContainsString('hybrid example already present — nothing to do.', $tester->getDisplay());
	}//end testAFullySeededInstanceIsNotWritten()

	/**
	 * A route alone is enough for the virtual app to count as present.
	 *
	 * @return void
	 */
	public function testAnExistingRouteAloneSkipsTheVirtualApp(): void {
		$tester = new CommandTester($this->command(appPresent: false, routePresent: true, hybridPresent: true));

		self::assertSame(Command::SUCCESS, $tester->execute([]));
		self::assertSame([], $this->saved);
		self::assertStringContainsString('skipping the virtual app', $tester->getDisplay());
	}//end testAnExistingRouteAloneSkipsTheVirtualApp()

	/**
	 * A failing write ends the command with FAILURE and the reason.
	 *
	 * @return void
	 */
	public function testAFailingWriteFailsTheCommand(): void {
		$tester = new CommandTester(
			$this->command(
				appPresent: false,
				routePresent: false,
				hybridPresent: false,
				failOnSave: new RuntimeException('register is read-only')
			)
		);

		self::assertSame(Command::FAILURE, $tester->execute([]));
		self::assertStringContainsString(
			'Failed to seed hello-world fixture: register is read-only',
			$tester->getDisplay()
		);
	}//end testAFailingWriteFailsTheCommand()
}//end class
