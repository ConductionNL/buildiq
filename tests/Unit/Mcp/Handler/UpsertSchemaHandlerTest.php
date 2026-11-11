<?php

/**
 * Unit tests for the upsertSchema MCP handler past its argument and admin guards.
 *
 * Covers the create path (schema created and attached to its per-version
 * register), the update path behind the register-ownership guard, and the
 * failure paths: a schema owned by another register, a missing register, a
 * failing attach and a failing mapper call.
 *
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
 * Tests for UpsertSchemaHandler::handle.
 */
class UpsertSchemaHandlerTest extends TestCase {

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
	 * Valid tool arguments for app `demo`, version `development`, schema `person`.
	 *
	 * @return array<string, mixed>
	 */
	private function args(): array {
		return [
			'appSlug' => 'demo',
			'slug' => 'person',
			'title' => 'Person',
			'description' => 'A person',
			'properties' => ['name' => ['type' => 'string']],
			'required' => ['name', 42],
		];
	}//end args()

	/**
	 * Build a stub Schema with an id.
	 *
	 * @param int $id The schema id.
	 *
	 * @return Schema
	 */
	private static function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);

		return $schema;
	}//end schema()

	/**
	 * Build a stub Register holding the given schema ids.
	 *
	 * @param array<int, int> $schemaIds The register's schema ids.
	 *
	 * @return Register
	 */
	private static function register(array $schemaIds): Register {
		$register = new Register();
		$register->setSchemas($schemaIds);

		return $register;
	}//end register()

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
	 * A SchemaMapper fake that records what it was asked to write.
	 *
	 * @param array<int, Schema> $existing What findBySlug returns.
	 * @param bool $throwOnWrite Whether create and update throw.
	 *
	 * @return object
	 */
	private static function schemaMapper(array $existing, bool $throwOnWrite = false): object {
		return new class($existing, $throwOnWrite) {
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
			 * @param bool $throwOnWrite Whether writes throw.
			 */
			public function __construct(private array $existing, private bool $throwOnWrite) {
			}

			/**
			 * Return the configured matches.
			 *
			 * @param string $slug The looked-up slug.
			 *
			 * @return array<int, Schema>
			 */
			public function findBySlug(string $slug): array {
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
				if ($this->throwOnWrite === true) {
					throw new RuntimeException('write failed');
				}

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
				if ($this->throwOnWrite === true) {
					throw new RuntimeException('write failed');
				}

				$this->writes[] = ['op' => 'create', 'blob' => $blob];
				$schema = new Schema();
				$schema->setId(77);
				return $schema;
			}
		};
	}//end schemaMapper()

	/**
	 * A RegisterMapper fake: returns a register, or throws when there is none.
	 *
	 * @param Register|null $register The register `find` returns; null throws.
	 *
	 * @return object
	 */
	private static function registerMapper(?Register $register): object {
		return new class($register) {
			/**
			 * Slugs `find` was asked for.
			 *
			 * @var array<int, string>
			 */
			public array $found = [];

			/**
			 * Registers passed to `update`.
			 *
			 * @var array<int, Register>
			 */
			public array $updated = [];

			/**
			 * Store the fixture.
			 *
			 * @param Register|null $register The register to return.
			 */
			public function __construct(private ?Register $register) {
			}

			/**
			 * Return the register or throw.
			 *
			 * @param string $slug The register slug.
			 * @param bool $_multitenancy Multitenancy flag.
			 *
			 * @return Register
			 */
			public function find(string $slug, bool $_multitenancy = true): Register {
				$this->found[] = $slug;
				if ($this->register === null) {
					throw new RuntimeException('no register');
				}

				return $this->register;
			}

			/**
			 * Record an update.
			 *
			 * @param Register $register The updated register.
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
	 * A new schema is created under the namespaced slug and attached to its version register.
	 *
	 * @return void
	 */
	public function testCreatesAndAttachesANewSchema(): void {
		$schemas = self::schemaMapper(existing: []);
		$registers = self::registerMapper(register: self::register(schemaIds: [5]));
		$this->wire(schemaMapper: $schemas, registerMapper: $registers);

		$result = $this->handler->handle(args: $this->args());

		self::assertTrue(condition: $result['success']);
		self::assertSame(expected: 'created', actual: $result['action']);
		self::assertSame(expected: 77, actual: $result['schema']['id']);
		self::assertSame(expected: 'demo-development-person', actual: $result['schema']['slug']);
		self::assertSame(expected: 'person', actual: $result['schema']['shortSlug']);
		self::assertSame(expected: 'openbuild-demo-development', actual: $result['schema']['register']);

		self::assertCount(expectedCount: 1, haystack: $schemas->writes);
		self::assertSame(expected: ['name'], actual: $schemas->writes[0]['blob']['required']);
		self::assertSame(expected: 'object', actual: $schemas->writes[0]['blob']['type']);

		self::assertSame(expected: ['openbuild-demo-development'], actual: $registers->found);
		self::assertSame(expected: [5, 77], actual: $registers->updated[0]->getSchemas());
	}//end testCreatesAndAttachesANewSchema()

	/**
	 * A failing attach is logged and does not undo the created schema.
	 *
	 * @return void
	 */
	public function testCreateSurvivesAMissingRegisterOnAttach(): void {
		$this->wire(schemaMapper: self::schemaMapper(existing: []), registerMapper: self::registerMapper(register: null));
		$this->logger->expects(self::once())->method('warning')
			->with('Buildiq MCP: upsertSchema attach-to-register failed', self::anything());

		$result = $this->handler->handle(args: $this->args());

		self::assertTrue(condition: $result['success']);
		self::assertSame(expected: 'created', actual: $result['action']);
	}//end testCreateSurvivesAMissingRegisterOnAttach()

	/**
	 * An existing schema owned by the version register is updated in place.
	 *
	 * @return void
	 */
	public function testUpdatesASchemaOwnedByTheVersionRegister(): void {
		$schemas = self::schemaMapper(existing: [self::schema(id: 12)]);
		$this->wire(schemaMapper: $schemas, registerMapper: self::registerMapper(register: self::register(schemaIds: [12])));

		$result = $this->handler->handle(args: $this->args());

		self::assertTrue(condition: $result['success']);
		self::assertSame(expected: 'updated', actual: $result['action']);
		self::assertSame(expected: 12, actual: $result['schema']['id']);
		self::assertSame(expected: 'update', actual: $schemas->writes[0]['op']);
		self::assertSame(expected: 12, actual: $schemas->writes[0]['id']);
	}//end testUpdatesASchemaOwnedByTheVersionRegister()

	/**
	 * A slug collision with a schema of another register is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testRefusesToUpdateASchemaOfAnotherRegister(): void {
		$schemas = self::schemaMapper(existing: [self::schema(id: 12)]);
		$this->wire(schemaMapper: $schemas, registerMapper: self::registerMapper(register: self::register(schemaIds: [3, 4])));
		$this->logger->expects(self::once())->method('warning')
			->with('Buildiq MCP: upsertSchema ownership check failed', self::anything());

		$result = $this->handler->handle(args: $this->args());

		self::assertTrue(condition: $result['isError']);
		self::assertSame(expected: 'forbidden', actual: $result['error']);
		self::assertStringContainsString(needle: "register 'openbuild-demo-development'", haystack: $result['message']);
		self::assertSame(expected: [], actual: $schemas->writes);
	}//end testRefusesToUpdateASchemaOfAnotherRegister()

	/**
	 * An update is refused when the version register does not exist.
	 *
	 * @return void
	 */
	public function testRefusesToUpdateWhenTheRegisterIsMissing(): void {
		$schemas = self::schemaMapper(existing: [self::schema(id: 12)]);
		$this->wire(schemaMapper: $schemas, registerMapper: self::registerMapper(register: null));

		$result = $this->handler->handle(args: $this->args());

		self::assertSame(expected: 'forbidden', actual: $result['error']);
		self::assertStringContainsString(needle: 'not found. Update denied.', haystack: $result['message']);
		self::assertSame(expected: [], actual: $schemas->writes);
	}//end testRefusesToUpdateWhenTheRegisterIsMissing()

	/**
	 * A failing mapper write is logged and answered with a generic error.
	 *
	 * @return void
	 */
	public function testAFailingWriteReturnsAGenericError(): void {
		$this->wire(
			schemaMapper: self::schemaMapper(existing: [], throwOnWrite: true),
			registerMapper: self::registerMapper(register: self::register(schemaIds: []))
		);
		$this->logger->expects(self::once())->method('error')
			->with('Buildiq MCP: upsertSchema failed', self::anything());

		$result = $this->handler->handle(args: $this->args());

		self::assertSame(expected: 'upsert_failed', actual: $result['error']);
		self::assertStringNotContainsString(needle: 'write failed', haystack: $result['message']);
	}//end testAFailingWriteReturnsAGenericError()

	/**
	 * Invalid version slugs and a missing title are rejected before any lookup.
	 *
	 * @return void
	 */
	public function testRejectsAnInvalidVersionSlugOrMissingTitle(): void {
		$this->container->expects(self::never())->method('get');

		$badVersion = $this->handler->handle(args: ['versionSlug' => 'Bad Slug'] + $this->args());
		$badSchema = $this->handler->handle(args: ['slug' => '-x'] + $this->args());
		$noTitle = $this->handler->handle(args: ['title' => ''] + $this->args());

		self::assertSame(expected: "Invalid versionSlug 'Bad Slug'.", actual: $badVersion['message']);
		self::assertSame(expected: "Invalid schema slug '-x'.", actual: $badSchema['message']);
		self::assertSame(expected: 'title is required.', actual: $noTitle['message']);
	}//end testRejectsAnInvalidVersionSlugOrMissingTitle()
}//end class
