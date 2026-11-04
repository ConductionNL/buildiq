<?php

/**
 * FiredAutomationTrigger: the automation trigger an OpenRegister object event fires.
 *
 * The listeners that act on automations (`DocumentGenerationListener`,
 * `AutomationApprovalTriggerListener`) read the same four facts off a
 * dispatched event and select the stored automations the same way: enabled,
 * a `trigger` block naming the fired type and schema (and, for a lifecycle
 * transition, the transition), and at least one action of the kind that
 * listener dispatches.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\Buildiq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automation-designer/spec.md#req-autd-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Listener;

use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;

/**
 * The fired trigger type, schema slug, object uuid and transition action of one event.
 *
 * @spec openspec/specs/automation-designer/spec.md#req-autd-004
 */
final class FiredAutomationTrigger {

	/**
	 * The `findAll()` filters that select the shared register's `automation` objects.
	 *
	 * @var array<string,string>
	 */
	public const AUTOMATION_FILTERS = [
		'register' => AutomationCompilerService::REGISTER_SLUG,
		'schema' => AutomationCompilerService::AUTOMATION_SCHEMA,
	];

	/**
	 * Constructor.
	 *
	 * @param array{0:string,1:string,2:string,3:?string} $fired Trigger type, schema slug, object uuid, transition action.
	 *
	 * @return void
	 */
	private function __construct(
		private readonly array $fired,
	) {
	}//end __construct()

	/**
	 * The trigger the event fires, or null when it is not an object event an automation reacts to.
	 *
	 * @param Event $event Dispatched event.
	 *
	 * @return self|null
	 *
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-004
	 */
	public static function fromEvent(Event $event): ?self {
		if ($event instanceof ObjectCreatedEvent) {
			return self::forEntity(type: 'object-created', entity: $event->getObject());
		}

		if ($event instanceof ObjectUpdatedEvent) {
			return self::forEntity(type: 'object-updated', entity: $event->getObject());
		}

		if ($event instanceof ObjectDeletedEvent) {
			return self::forEntity(type: 'object-deleted', entity: $event->getObject());
		}

		if ($event instanceof ObjectTransitionedEvent) {
			return new self(
				fired: [
					'lifecycle-transition',
					$event->getSchema(),
					self::stringOf(entity: $event->getObject(), getter: 'getUuid'),
					$event->getAction(),
				]
			);
		}

		return null;
	}//end fromEvent()

	/**
	 * The trigger of a create, update or delete event on the entity.
	 *
	 * @param string $type   The trigger type.
	 * @param object $entity The event's `ObjectEntity`.
	 *
	 * @return self
	 */
	private static function forEntity(string $type, object $entity): self {
		return new self(fired: [$type, self::stringOf(entity: $entity, getter: 'getSchema'), self::stringOf(entity: $entity, getter: 'getUuid'), null]);
	}//end forEntity()

	/**
	 * Whether the event names both a schema and an object.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-004
	 */
	public function isAddressable(): bool {
		return $this->fired[1] !== '' && $this->fired[2] !== '';
	}//end isAddressable()

	/**
	 * The fired object's schema slug.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-004
	 */
	public function schemaSlug(): string {
		return $this->fired[1];
	}//end schemaSlug()

	/**
	 * The fired object's uuid.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-004
	 */
	public function objectUuid(): string {
		return $this->fired[2];
	}//end objectUuid()

	/**
	 * The scanned automations this trigger fires that carry an action of the given type.
	 *
	 * @param array<int|string,mixed> $rows       The scanned automation objects.
	 * @param string                  $actionType The action type the caller dispatches.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 *
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-004
	 */
	public function select(array $rows, string $actionType): array {
		$matches = [];
		foreach ($rows as $row) {
			$automation = ObjectRecord::toArray(object: $row);
			if ($this->fires(automation: $automation) === true && self::hasAction(automation: $automation, actionType: $actionType) === true) {
				$matches[] = $automation;
			}
		}

		return $matches;
	}//end select()

	/**
	 * Whether the automation is enabled and its `trigger` block matches this trigger.
	 *
	 * @param array<string,mixed> $automation The candidate automation.
	 *
	 * @return bool
	 */
	private function fires(array $automation): bool {
		$trigger = $automation['trigger'] ?? null;
		if (($automation['enabled'] ?? true) === false || is_array($trigger) === false) {
			return false;
		}

		[$triggerType, $schemaSlug, , $transitionAction] = $this->fired;
		if ((string)($trigger['type'] ?? '') !== $triggerType || (string)($trigger['schema'] ?? '') !== $schemaSlug) {
			return false;
		}

		return $triggerType !== 'lifecycle-transition' || (string)($trigger['transition'] ?? '') === (string)$transitionAction;
	}//end fires()

	/**
	 * Whether the automation carries at least one action of the given type.
	 *
	 * @param array<string,mixed> $automation The candidate automation.
	 * @param string              $actionType The action type.
	 *
	 * @return bool
	 */
	private static function hasAction(array $automation, string $actionType): bool {
		$actions = $automation['actions'] ?? [];
		if (is_array($actions) === false) {
			return false;
		}

		foreach ($actions as $action) {
			if (is_array($action) === true && ($action['type'] ?? '') === $actionType) {
				return true;
			}
		}

		return false;
	}//end hasAction()

	/**
	 * A string read off an `ObjectEntity` getter, or '' when absent or not a string.
	 *
	 * @param object $entity The `ObjectEntity` instance.
	 * @param string $getter The getter name.
	 *
	 * @return string
	 */
	private static function stringOf(object $entity, string $getter): string {
		if (method_exists($entity, $getter) === true) {
			$value = $entity->$getter();
			if (is_string($value) === true) {
				return $value;
			}
		}

		return '';
	}//end stringOf()
}//end class
