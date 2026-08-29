<?php

/**
 * OpenBuild DocumentGenerationListener
 *
 * Trigger-fire half of the `generateDocument` automation action (design.md
 * Decision 2 of automation-document-action). `AutomationCompilerService`
 * compiles NO artifact for this action kind (Docudesk's generate route is
 * stateless — nothing to provision ahead of time); this listener is the
 * imperative companion that reads a matching `Automation` object's
 * `generateDocument` action(s) straight off the stored object and dispatches
 * {@see \OCA\OpenBuild\Service\DocumentGenerationService::generate()} once
 * per matching automation, per fired event — mirroring
 * {@see AutomationApprovalTriggerListener}'s exact shape (find matching
 * automations for the fired trigger, dispatch through the imperative
 * companion service) for the sibling `approval` action kind.
 *
 * Unlike the approval listener, there is deliberately NO idempotency guard
 * here: each firing legitimately generates a NEW document (design.md Risks/
 * Trade-offs explicitly documents unrated-limited generation on a
 * high-volume trigger, e.g. every `object-updated`, as an accepted v1
 * operational consideration — the same posture already applied to
 * notifications/webhooks).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenBuild\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-document-action/tasks.md#3.1
 * @spec openspec/specs/automation-designer/spec.md#req-autd-004
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Listener;

use OCA\OpenBuild\Service\AutomationCompilerService;
use OCA\OpenBuild\Service\DocumentGenerationService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Dispatches `DocumentGenerationService::generate()` when a `generateDocument`
 * automation's trigger fires.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/automation-document-action/tasks.md#3.1
 */
class DocumentGenerationListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's object service at USE time, to scan
	 *                                      the `automation` register — see objectService() for why a
	 *                                      listener cannot constructor-inject it.
	 * @param DocumentGenerationService $documentGenerator Calls Docudesk + writes the configured output(s).
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly DocumentGenerationService $documentGenerator,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve OpenRegister's object service, lazily.
	 *
	 * ⚠️ An event listener CANNOT constructor-inject a published interface.
	 * Nextcloud's `ServiceEventListener` builds the listener from the SERVER
	 * container ("TODO: fetch from the app containers" — its own source), which
	 * never sees this app's `registerServiceAlias()`. The constructor parameter
	 * therefore could not be built, and the exception propagated out of
	 * `dispatch()` into whichever app emitted the event.
	 *
	 * Resolves the CONCRETE class on purpose — asking the same container for
	 * `ObjectServiceInterface::class` here would fail identically. Nextcloud
	 * autowires concrete classes across apps; the declared type stays the
	 * published contract.
	 *
	 * @return ObjectServiceInterface OpenRegister's published object contract.
	 *
	 * @throws ContainerExceptionInterface When OpenRegister is absent or disabled.
	 */
	private function objectService(): ObjectServiceInterface {
		// ADR-083: establish availability before reaching. class_exists()
		// rather than SettingsService, because this listener injects no
		// settings service and adding a constructor dependency purely to ask a
		// yes/no question is the wrong trade — it answers the same question the
		// container would otherwise have answered fatally.
		//
		// Behaviour is unchanged: every caller already wraps this in a catch
		// that logs and returns null, so an instance without OpenRegister
		// degrades exactly as it does today. What changes is that the
		// dependency is now declared where a reader — and the gate — can see
		// it, instead of being implied by a catch several methods away.
		//
		// Untestable in a unit test: the stub OpenRegister classes the test
		// bootstrap declares (tests/stubs/openregister-stubs.php) make
		// class_exists() true unconditionally in this suite, so this branch
		// only ever fires on a real instance genuinely missing OpenRegister.
		// @codeCoverageIgnoreStart
		if (class_exists('OCA\OpenRegister\Service\ObjectService') === false) {
			throw new RuntimeException(
				'openbuild requires the OpenRegister app, which is not installed on this instance.'
			);
		}
		// @codeCoverageIgnoreEnd

		$service = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		// An assert(), not a `/** @var */` docblock: phpcs forbids an inline doc
		// block inside a method body, while psalm needs the narrowing or the
		// declared return type is a MixedReturnStatement. assert() satisfies
		// both, and costs nothing in production where zend.assertions is off.
		assert($service instanceof ObjectServiceInterface);

		return $service;

	}//end objectService()

	/**
	 * Handle a dispatched object event, generating a document for every
	 * matching automation's `generateDocument` action(s).
	 *
	 * @param Event $event Dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/automation-document-action/tasks.md#3.1
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-004
	 */
	public function handle(Event $event): void {
		$fired = $this->resolveFiredTrigger(event: $event);
		if ($fired === null) {
			return;
		}

		[$triggerType, $schemaSlug, $objectUuid, $transitionAction] = $fired;
		if ($schemaSlug === '' || $objectUuid === '') {
			return;
		}

		$automations = $this->findMatchingAutomations(
			triggerType: $triggerType,
			schemaSlug: $schemaSlug,
			transitionAction: $transitionAction
		);

		foreach ($automations as $automation) {
			foreach ($this->generateDocumentActions(automation: $automation) as $action) {
				try {
					$this->documentGenerator->generate(
						automation: $automation,
						action: $action,
						schemaSlug: $schemaSlug,
						objectUuid: $objectUuid
					);
				} catch (Throwable $e) {
					$this->logger->error(
						'OpenBuild: DocumentGenerationListener failed for automation "' . ($automation['slug'] ?? '')
						. '" / object "' . $objectUuid . '": ' . $e->getMessage(),
						['exception' => $e]
					);
				}
			}
		}

	}//end handle()

	/**
	 * Resolve `[triggerType, schemaSlug, objectUuid, transitionAction]` from
	 * the dispatched event, or null when the event is not one this listener
	 * acts on.
	 *
	 * @param Event $event Dispatched event.
	 *
	 * @return array{0:string,1:string,2:string,3:?string}|null
	 */
	private function resolveFiredTrigger(Event $event): ?array {
		if ($event instanceof ObjectCreatedEvent) {
			return ['object-created', $this->schemaOf(entity: $event->getObject()), $this->uuidOf(entity: $event->getObject()), null];
		}

		if ($event instanceof ObjectUpdatedEvent) {
			return ['object-updated', $this->schemaOf(entity: $event->getObject()), $this->uuidOf(entity: $event->getObject()), null];
		}

		if ($event instanceof ObjectDeletedEvent) {
			return ['object-deleted', $this->schemaOf(entity: $event->getObject()), $this->uuidOf(entity: $event->getObject()), null];
		}

		if ($event instanceof ObjectTransitionedEvent) {
			return [
				'lifecycle-transition',
				$event->getSchema(),
				$this->uuidOf(entity: $event->getObject()),
				$event->getAction(),
			];
		}

		return null;
	}//end resolveFiredTrigger()

	/**
	 * Read a schema slug off an `ObjectEntity`, defensively.
	 *
	 * @param object $entity The `ObjectEntity` instance.
	 *
	 * @return string
	 */
	private function schemaOf(object $entity): string {
		if (method_exists($entity, 'getSchema') === true) {
			$schema = $entity->getSchema();
			if (is_string($schema) === true) {
				return $schema;
			}
		}

		return '';
	}//end schemaOf()

	/**
	 * Read the uuid off an `ObjectEntity`, defensively.
	 *
	 * @param object $entity The `ObjectEntity` instance.
	 *
	 * @return string
	 */
	private function uuidOf(object $entity): string {
		if (method_exists($entity, 'getUuid') === true) {
			$uuid = $entity->getUuid();
			if (is_string($uuid) === true) {
				return $uuid;
			}
		}

		return '';
	}//end uuidOf()

	/**
	 * Scan the shared `openbuild` register's `automation` objects for every
	 * ENABLED automation whose `trigger` matches the fired event and whose
	 * `actions[]` includes at least one `generateDocument` action.
	 *
	 * @param string $triggerType The fired trigger type.
	 * @param string $schemaSlug The fired object's schema slug.
	 * @param string|null $transitionAction The matched transition action name (lifecycle-transition only).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findMatchingAutomations(string $triggerType, string $schemaSlug, ?string $transitionAction): array {
		try {
			// ADR-078 (gate-61): bounded, not unbounded — a real install's
			// configured automations number in the tens, never the thousands
			// this limit would need to matter; the bound exists to cap worst
			// case, not to reflect an expected count.
			$results = $this->objectService()->findAll(
				config: [
					'filters' => [
						'register' => AutomationCompilerService::REGISTER_SLUG,
						'schema' => AutomationCompilerService::AUTOMATION_SCHEMA,
					],
					'limit' => 1000,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('OpenBuild: DocumentGenerationListener could not scan automations: ' . $e->getMessage());
			return [];
		}

		$matches = [];
		foreach ($results as $row) {
			$automation = $this->normalise(object: $row);
			$isMatch = $this->matches(
				automation: $automation,
				triggerType: $triggerType,
				schemaSlug: $schemaSlug,
				transitionAction: $transitionAction
			);
			if ($isMatch === true) {
				$matches[] = $automation;
			}
		}

		return $matches;
	}//end findMatchingAutomations()

	/**
	 * Whether an automation's trigger matches the fired event, it is
	 * enabled, and it carries at least one `generateDocument` action.
	 *
	 * @param array<string,mixed> $automation The candidate automation.
	 * @param string $triggerType The fired trigger type.
	 * @param string $schemaSlug The fired object's schema slug.
	 * @param string|null $transitionAction The matched transition action name.
	 *
	 * @return bool
	 */
	private function matches(array $automation, string $triggerType, string $schemaSlug, ?string $transitionAction): bool {
		if (($automation['enabled'] ?? true) === false) {
			return false;
		}

		$trigger = $automation['trigger'] ?? null;
		if (is_array($trigger) === false) {
			return false;
		}

		$triggerMatches = $this->triggerMatches(
			trigger: $trigger,
			triggerType: $triggerType,
			schemaSlug: $schemaSlug,
			transitionAction: $transitionAction
		);
		if ($triggerMatches === false) {
			return false;
		}

		return $this->generateDocumentActions(automation: $automation) !== [];
	}//end matches()

	/**
	 * Whether the automation's `trigger` block matches the fired event.
	 *
	 * @param array<string,mixed> $trigger The automation's `trigger` block.
	 * @param string $triggerType The fired trigger type.
	 * @param string $schemaSlug The fired object's schema slug.
	 * @param string|null $transitionAction The matched transition action name.
	 *
	 * @return bool
	 */
	private function triggerMatches(array $trigger, string $triggerType, string $schemaSlug, ?string $transitionAction): bool {
		if ((string)($trigger['type'] ?? '') !== $triggerType) {
			return false;
		}

		if ((string)($trigger['schema'] ?? '') !== $schemaSlug) {
			return false;
		}

		if ($triggerType !== 'lifecycle-transition') {
			return true;
		}

		return (string)($trigger['transition'] ?? '') === (string)$transitionAction;
	}//end triggerMatches()

	/**
	 * Every `generateDocument` action record on the automation.
	 *
	 * @param array<string,mixed> $automation The candidate automation.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function generateDocumentActions(array $automation): array {
		$actions = $automation['actions'] ?? [];
		if (is_array($actions) === false) {
			return [];
		}

		return array_values(
			array_filter(
				$actions,
				static fn ($action): bool => is_array($action) === true && ($action['type'] ?? '') === 'generateDocument'
			)
		);

	}//end generateDocumentActions()

	/**
	 * Coerce an OR result entry to a plain associative array.
	 *
	 * @param mixed $object The OR object/result entry.
	 *
	 * @return array<string,mixed>
	 */
	private function normalise(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$inner = $object->getObject();
			if (is_array($inner) === true) {
				return $inner;
			}
		}

		return [];
	}//end normalise()
}//end class
