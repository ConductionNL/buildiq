<?php

/**
 * Buildiq DocumentGenerationListener
 *
 * Trigger-fire half of the `generateDocument` automation action (design.md
 * Decision 2 of automation-document-action). `AutomationCompilerService`
 * compiles NO artifact for this action kind (Docudesk's generate route is
 * stateless — nothing to provision ahead of time); this listener is the
 * imperative companion that reads a matching `Automation` object's
 * `generateDocument` action(s) straight off the stored object and dispatches
 * {@see \OCA\Buildiq\Service\DocumentGenerationService::generate()} once
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
 * @spec openspec/changes/automation-document-action/tasks.md#3.1
 * @spec openspec/specs/automation-designer/spec.md#req-autd-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Listener;

use OCA\Buildiq\Service\DocumentGenerationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
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
	 * OpenRegister's object service, resolved at call time.
	 *
	 * @var LazyObjectService
	 */
	private readonly LazyObjectService $objects;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's object service at USE time, to scan
	 *                                      the `automation` register — see LazyObjectService for why a
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
		$this->objects = new LazyObjectService(container: $container);
	}//end __construct()

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
	 * @SuppressWarnings(PHPMD.StaticAccess) FiredAutomationTrigger is a pure value read off the event.
	 */
	public function handle(Event $event): void {
		$fired = FiredAutomationTrigger::fromEvent(event: $event);
		if ($fired === null || $fired->isAddressable() === false) {
			return;
		}

		$schemaSlug = $fired->schemaSlug();
		$objectUuid = $fired->objectUuid();
		$automations = $this->findMatchingAutomations(fired: $fired);

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
						'Buildiq: DocumentGenerationListener failed for automation "' . ($automation['slug'] ?? '')
						. '" / object "' . $objectUuid . '": ' . $e->getMessage(),
						['exception' => $e]
					);
				}
			}
		}

	}//end handle()

	/**
	 * Scan the shared `buildiq` register's `automation` objects for every
	 * ENABLED automation whose `trigger` matches the fired event and whose
	 * `actions[]` includes at least one `generateDocument` action.
	 *
	 * @param FiredAutomationTrigger $fired The fired trigger.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findMatchingAutomations(FiredAutomationTrigger $fired): array {
		try {
			// ADR-078 (gate-61): bounded, not unbounded — a real install's
			// configured automations number in the tens, never the thousands
			// this limit would need to matter; the bound exists to cap worst
			// case, not to reflect an expected count.
			$results = $this->objects->get()->findAll(
				config: ['filters' => FiredAutomationTrigger::AUTOMATION_FILTERS, 'limit' => 1000]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: DocumentGenerationListener could not scan automations: ' . $e->getMessage());
			return [];
		}

		return $fired->select(rows: $results, actionType: 'generateDocument');
	}//end findMatchingAutomations()

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
}//end class
