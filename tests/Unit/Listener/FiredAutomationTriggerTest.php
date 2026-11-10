<?php

/**
 * The automation listeners select the same automations for the same events
 * as before they shared FiredAutomationTrigger.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Listener;

use JsonSerializable;
use OCA\Buildiq\Listener\AutomationApprovalTriggerListener;
use OCA\Buildiq\Listener\DocumentGenerationListener;
use OCA\Buildiq\Listener\FiredAutomationTrigger;
use OCA\Buildiq\Service\DocumentGenerationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCP\EventDispatcher\Event;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * The fixture holds, per listener and event, the fired trigger tuple and the
 * slugs of the automations each listener selected, recorded before the
 * listeners shared this class.
 *
 * @covers \OCA\Buildiq\Listener\FiredAutomationTrigger
 * @covers \OCA\Buildiq\Listener\DocumentGenerationListener
 * @covers \OCA\Buildiq\Listener\AutomationApprovalTriggerListener
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
class FiredAutomationTriggerTest extends TestCase {

	/**
	 * The scan config each listener passed to `findAll()`.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $scans = [];

	/**
	 * The recorded selections.
	 *
	 * @return array<string,mixed>
	 */
	private static function golden(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/fired-automation-trigger-golden.json'), true);
	}//end golden()

	/**
	 * One case per event.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function eventNames(): array {
		$cases = [];
		foreach (array_keys(self::events()) as $name) {
			$cases[$name] = [$name];
		}

		return $cases;
	}//end eventNames()

	/**
	 * Each listener reads the fired trigger and selects the automations it recorded.
	 *
	 * @param string $name The event case.
	 *
	 * @return void
	 */
	#[DataProvider('eventNames')]
	public function testSelectsAsRecorded(string $name): void {
		$golden = self::golden()['events'][$name];
		$fired = FiredAutomationTrigger::fromEvent(event: self::events()[$name]);
		if ($golden['fired'] === null) {
			$this->assertNull(actual: $fired);
			return;
		}

		$this->assertNotNull(actual: $fired);
		$tuple = (new ReflectionProperty(FiredAutomationTrigger::class, 'fired'))->getValue($fired);
		$this->assertSame(expected: $golden['fired'], actual: $tuple);
		$this->assertSame(expected: $golden['fired'][1] !== '' && $golden['fired'][2] !== '', actual: $fired->isAddressable());
		$this->assertSame(expected: $golden['fired'][1], actual: $fired->schemaSlug());
		$this->assertSame(expected: $golden['fired'][2], actual: $fired->objectUuid());

		foreach ($this->listeners(rows: self::rows()) as $class => $listener) {
			$method = new ReflectionMethod($listener, 'findMatchingAutomations');
			$slugs = array_map(static fn (array $a): string => (string)($a['slug'] ?? ''), $method->invoke($listener, $fired));
			$this->assertSame(expected: $golden['selected'][$class], actual: $slugs, message: $class);
		}

		foreach ($this->scans as $scan) {
			$this->assertSame(expected: self::golden()['scan'], actual: $scan);
		}
	}//end testSelectsAsRecorded()

	/**
	 * A failing scan selects nothing.
	 *
	 * @return void
	 */
	public function testFailingScanSelectsNothing(): void {
		$fired = FiredAutomationTrigger::fromEvent(event: self::events()['created']);
		foreach ($this->listeners(rows: null) as $class => $listener) {
			$method = new ReflectionMethod($listener, 'findMatchingAutomations');
			$this->assertSame(expected: [], actual: $method->invoke($listener, $fired), message: $class);
		}
	}//end testFailingScanSelectsNothing()

	/**
	 * Both listeners over an object service that answers the rows, or throws when rows is null.
	 *
	 * @param array<int,mixed>|null $rows The scanned automation rows.
	 *
	 * @return array<string,object>
	 */
	private function listeners(?array $rows): array {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($rows): array {
				$this->scans[] = $config;
				if ($rows === null) {
					throw new RuntimeException('scan failed');
				}

				return $rows;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		return [
			'DocumentGenerationListener' => new DocumentGenerationListener(
				$container,
				$this->createMock(DocumentGenerationService::class),
				new NullLogger()
			),
			'AutomationApprovalTriggerListener' => new AutomationApprovalTriggerListener(
				$container,
				$this->createMock(SchemaMapper::class),
				$this->createMock(IUserSession::class),
				new NullLogger()
			),
		];
	}//end listeners()

	/**
	 * The recorded events.
	 *
	 * @return array<string,Event>
	 */
	public static function events(): array {
		return [
			'created' => new ObjectCreatedEvent(self::entity(schema: 's1', uuid: 'u1')),
			'updated' => new ObjectUpdatedEvent(self::entity(schema: 's1', uuid: 'u1'), self::entity(schema: 's1', uuid: 'u1')),
			'deleted' => new ObjectDeletedEvent(self::entity(schema: 's1', uuid: 'u1')),
			'transitionApprove' => new ObjectTransitionedEvent(object: self::entity(schema: null, uuid: 'u1'), move: new TransitionMove(action: 'approve', from: 'a', to: 'b'), scope: new RegisterSchemaRef(register: 'r', schema: 's1')),
			'transitionEmpty' => new ObjectTransitionedEvent(object: self::entity(schema: null, uuid: 'u1'), move: new TransitionMove(action: '', from: 'a', to: 'b'), scope: new RegisterSchemaRef(register: 'r', schema: 's1')),
			'transitionReject' => new ObjectTransitionedEvent(object: self::entity(schema: 's9', uuid: 'u1'), move: new TransitionMove(action: 'reject', from: 'a', to: 'b'), scope: new RegisterSchemaRef(register: 'r', schema: 's1')),
			'transitionNoUuid' => new ObjectTransitionedEvent(object: self::entity(schema: 's1', uuid: null), move: new TransitionMove(action: 'approve', from: 'a', to: 'b'), scope: new RegisterSchemaRef(register: 'r', schema: 's1')),
			'createdNoSchema' => new ObjectCreatedEvent(self::entity(schema: null, uuid: 'u1')),
			'createdNoUuid' => new ObjectCreatedEvent(self::entity(schema: 's1', uuid: null)),
			'createdOtherSchema' => new ObjectCreatedEvent(self::entity(schema: 's2', uuid: 'u2')),
			'plainEvent' => new Event(),
		];
	}//end events()

	/**
	 * An entity with the given schema and uuid.
	 *
	 * @param string|null $schema The schema slug.
	 * @param string|null $uuid   The uuid.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(?string $schema, ?string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setUuid($uuid);
		return $entity;
	}//end entity()

	/**
	 * The scanned automation rows, each named by its slug.
	 *
	 * @return array<int,mixed>
	 */
	public static function rows(): array {
		$both = [['type' => 'approval'], ['type' => 'generateDocument']];
		$created = ['type' => 'object-created', 'schema' => 's1'];
		return [
			['slug' => 'a1', 'trigger' => $created, 'actions' => [['type' => 'approval']]],
			['slug' => 'a2', 'trigger' => $created, 'actions' => [['type' => 'generateDocument']]],
			['slug' => 'a3', 'trigger' => ['type' => 'object-updated', 'schema' => 's1'], 'actions' => $both],
			['slug' => 'a4', 'enabled' => false, 'trigger' => $created, 'actions' => $both],
			['slug' => 'a5', 'enabled' => 'false', 'trigger' => $created, 'actions' => $both],
			['slug' => 'a5b', 'enabled' => true, 'trigger' => $created, 'actions' => $both],
			['slug' => 'a5c', 'enabled' => 0, 'trigger' => $created, 'actions' => $both],
			['slug' => 'a5d', 'enabled' => null, 'trigger' => $created, 'actions' => $both],
			['slug' => 'a5e', 'enabled' => '', 'trigger' => $created, 'actions' => $both],
			['slug' => 'a6', 'actions' => $both],
			['slug' => 'a7', 'trigger' => 'object-created', 'actions' => $both],
			['slug' => 'a8', 'trigger' => ['type' => 'object-created', 'schema' => 's2'], 'actions' => $both],
			['slug' => 'a9', 'trigger' => ['type' => 'lifecycle-transition', 'schema' => 's1', 'transition' => 'approve'], 'actions' => $both],
			['slug' => 'a10', 'trigger' => ['type' => 'lifecycle-transition', 'schema' => 's1'], 'actions' => $both],
			['slug' => 'a11', 'trigger' => ['type' => 'object-deleted', 'schema' => 's1'], 'actions' => 'approval'],
			['slug' => 'a12', 'trigger' => ['type' => 'object-deleted', 'schema' => 's1'], 'actions' => ['approval', ['type' => 'generateDocument']]],
			self::serialising(value: ['slug' => 'a13', 'trigger' => ['type' => 'object-deleted', 'schema' => 's1'], 'actions' => [['type' => 'approval']]]),
			self::wrapping(value: ['slug' => 'a14', 'trigger' => $created, 'actions' => [['type' => 'generateDocument']]]),
			['slug' => 'a15', 'trigger' => ['schema' => 's1'], 'actions' => $both],
			['slug' => 'a16', 'trigger' => ['type' => 'object-created'], 'actions' => $both],
			['slug' => 'a17', 'trigger' => ['type' => 'lifecycle-transition', 'schema' => 's1', 'transition' => 'reject'], 'actions' => [['type' => 'approval']]],
			['slug' => 'a18', 'trigger' => ['type' => 'object-updated', 'schema' => 's1', 'transition' => 'approve'], 'actions' => $both],
			['slug' => 'a19', 'trigger' => $created],
			['slug' => 'a20', 'trigger' => $created, 'actions' => [['kind' => 'approval'], ['type' => 'Approval']]],
			'not an automation',
		];
	}//end rows()

	/**
	 * An entity whose jsonSerialize() returns the value.
	 *
	 * @param array<string,mixed> $value The serialised value.
	 *
	 * @return JsonSerializable
	 */
	private static function serialising(array $value): JsonSerializable {
		return new class($value) implements JsonSerializable {
			/**
			 * @param array<string,mixed> $value The serialised value.
			 */
			public function __construct(private readonly array $value) {
			}

			/**
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return $this->value;
			}
		};
	}//end serialising()

	/**
	 * An object whose getObject() returns the value.
	 *
	 * @param array<string,mixed> $value The wrapped value.
	 *
	 * @return object
	 */
	private static function wrapping(array $value): object {
		return new class($value) {
			/**
			 * @param array<string,mixed> $value The wrapped value.
			 */
			public function __construct(private readonly array $value) {
			}

			/**
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return $this->value;
			}
		};
	}//end wrapping()
}//end class
