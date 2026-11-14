<?php

/**
 * Unit tests for UpsertSchemaHandler's defensive guards.
 *
 * Two paths the existing spec never takes, each guarding a write:
 *
 *  - `required` comes straight off the MCP tool arguments, so a client can
 *    send a scalar where a list belongs. It is normalised to `[]`; without
 *    that, a string would be written into the stored schema's `required`.
 *  - `findBySlug()` is allowed to THROW for an absent slug (OR's mappers
 *    raise rather than return empty), and the handler has to read that as
 *    "no such schema yet" and create one. If the throw escaped, every
 *    first-time upsert against a throwing mapper would fail.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Mcp\Handler
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @covers \OCA\Buildiq\Mcp\Handler\UpsertSchemaHandler
 *
 * @uses \OCA\Buildiq\Mcp\Handler\AbstractToolHandler
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Mcp\Handler;

use OCA\Buildiq\Mcp\Handler\UpsertSchemaHandler;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the normalisation and not-found guards.
 */
class UpsertSchemaHandlerGuardsTest extends TestCase {
	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock container that hands out the mapper fakes.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Handler under test.
	 *
	 * @var UpsertSchemaHandler
	 */
	private UpsertSchemaHandler $handler;

	/**
	 * Wire an admin session and the handler.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);

		$this->handler = new UpsertSchemaHandler(
			userSession: $session,
			container: $this->container,
			logger: $this->logger,
			groupManager: $groupManager,
			objectService: $this->createMock(originalClassName: ObjectServiceInterface::class)
		);
	}//end setUp()

	/**
	 * Valid tool arguments, with `required` overridable.
	 *
	 * @param mixed $required The `required` argument to send.
	 *
	 * @return array<string, mixed>
	 */
	private function args(mixed $required = ['name']): array {
		return [
			'appSlug' => 'demo',
			'slug' => 'person',
			'title' => 'Person',
			'description' => 'A person',
			'properties' => ['name' => ['type' => 'string']],
			'required' => $required,
		];
	}//end args()

	/**
	 * Hand the given mapper fakes out of the container.
	 *
	 * @param object $schemaMapper The SchemaMapper fake.
	 * @param object $registerMapper The RegisterMapper fake.
	 *
	 * @return void
	 */
	private function wire(object $schemaMapper, object $registerMapper): void {
		$this->container->method('get')->willReturnMap(
			[
				['OCA\OpenRegister\Db\SchemaMapper', $schemaMapper],
				['OCA\OpenRegister\Db\RegisterMapper', $registerMapper],
			]
		);
	}//end wire()

	/**
	 * A SchemaMapper fake whose findBySlug either answers or throws.
	 *
	 * @param array<int, Schema> $existing What findBySlug returns.
	 * @param bool $throwOnLookup Whether findBySlug throws instead.
	 *
	 * @return object
	 */
	private static function schemaMapper(array $existing, bool $throwOnLookup = false): object {
		return new class($existing, $throwOnLookup) {
			/**
			 * Recorded write calls.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $writes = [];

			/**
			 * Store the fixture.
			 *
			 * @param array<int, Schema> $existing What findBySlug returns.
			 * @param bool $throwOnLookup Whether findBySlug throws.
			 */
			public function __construct(private array $existing, private bool $throwOnLookup) {
			}

			/**
			 * Answer the lookup, or raise the way OR's mapper does for an
			 * absent slug.
			 *
			 * @param string $slug The looked-up slug.
			 *
			 * @return array<int, Schema>
			 */
			public function findBySlug(string $slug): array {
				if ($this->throwOnLookup === true) {
					throw new RuntimeException('Schema not found');
				}

				return $this->existing;
			}

			/**
			 * Record an update.
			 *
			 * @param int $id The schema id.
			 * @param array<string, mixed> $blob The schema body.
			 *
			 * @return Schema
			 */
			public function updateFromArray(int $id, array $blob): Schema {
				$this->writes[] = ['op' => 'update', 'id' => $id, 'blob' => $blob];
				$schema = new Schema();
				$schema->setId($id);

				return $schema;
			}

			/**
			 * Record a create.
			 *
			 * @param array<string, mixed> $blob The schema body.
			 *
			 * @return Schema
			 */
			public function createFromArray(array $blob): Schema {
				$this->writes[] = ['op' => 'create', 'blob' => $blob];
				$schema = new Schema();
				$schema->setId(77);

				return $schema;
			}
		};
	}//end schemaMapper()

	/**
	 * A RegisterMapper fake returning the given register.
	 *
	 * @param Register $register The register `find` returns.
	 *
	 * @return object
	 */
	private static function registerMapper(Register $register): object {
		return new class($register) {
			/**
			 * Registers passed to `update`.
			 *
			 * @var array<int, Register>
			 */
			public array $updated = [];

			/**
			 * Store the fixture.
			 *
			 * @param Register $register The register to return.
			 */
			public function __construct(private Register $register) {
			}

			/**
			 * Return the register.
			 *
			 * @param string $slug The register slug.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return Register
			 */
			public function find(string $slug, bool $_multitenancy = true): Register {
				return $this->register;
			}

			/**
			 * Record the update.
			 *
			 * @param Register $register The register to persist.
			 *
			 * @return Register
			 */
			public function update(Register $register): Register {
				$this->updated[] = $register;

				return $register;
			}
		};
	}//end registerMapper()

	/**
	 * Non-list `required` values an MCP client could send.
	 *
	 * @return array<string, array{0: mixed}> The cases.
	 */
	public static function nonListRequired(): array {
		return [
			'a string' => ['name'],
			'a number' => [1],
			'a boolean' => [true],
		];
	}

	/**
	 * A non-list `required` is stored as an empty list, never as the scalar.
	 *
	 * @param mixed $required The malformed required value.
	 *
	 * @dataProvider nonListRequired
	 *
	 * @return void
	 */
	public function testANonListRequiredIsStoredAsAnEmptyList(mixed $required): void {
		$schemas = self::schemaMapper(existing: []);
		$register = new Register();
		$register->setSchemas([5]);
		$this->wire(schemaMapper: $schemas, registerMapper: self::registerMapper(register: $register));

		$result = $this->handler->handle(args: $this->args(required: $required));

		self::assertTrue(condition: $result['success']);
		self::assertSame(expected: [], actual: $schemas->writes[0]['blob']['required']);
	}//end testANonListRequiredIsStoredAsAnEmptyList()

	/**
	 * A well-formed `required` list still reaches the stored schema, with the
	 * non-string entries the canonical normaliser drops.
	 *
	 * @return void
	 */
	public function testAWellFormedRequiredListIsKept(): void {
		$schemas = self::schemaMapper(existing: []);
		$register = new Register();
		$register->setSchemas([5]);
		$this->wire(schemaMapper: $schemas, registerMapper: self::registerMapper(register: $register));

		$this->handler->handle(args: $this->args(required: ['name', 42]));

		self::assertSame(expected: ['name'], actual: $schemas->writes[0]['blob']['required']);
	}//end testAWellFormedRequiredListIsKept()

	/**
	 * A mapper that RAISES for an absent slug means "no such schema yet", so
	 * the handler creates one instead of letting the throw escape.
	 *
	 * @return void
	 */
	public function testAThrowingLookupIsReadAsAbsentAndCreates(): void {
		$schemas = self::schemaMapper(existing: [], throwOnLookup: true);
		$register = new Register();
		$register->setSchemas([5]);
		$this->wire(schemaMapper: $schemas, registerMapper: self::registerMapper(register: $register));

		$result = $this->handler->handle(args: $this->args());

		self::assertTrue(condition: $result['success']);
		self::assertSame(expected: 'created', actual: $result['action']);
		self::assertSame(expected: 'create', actual: $schemas->writes[0]['op']);
	}//end testAThrowingLookupIsReadAsAbsentAndCreates()

}//end class
