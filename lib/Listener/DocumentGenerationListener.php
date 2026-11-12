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
 * @spec openspec/changes/archive/2026-07-24-automation-document-action/tasks.md#3.1
 * @spec openspec/specs/automation-designer/spec.md#req-autd-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Listener;

use OCA\Buildiq\Service\DocumentGenerationService;
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
 * @spec openspec/changes/archive/2026-07-24-automation-document-action/tasks.md#3.1
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
				'buildiq requires the OpenRegister app, which is not installed on this instance.'
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
	 * @spec openspec/changes/archive/2026-07-24-automation-document-action/tasks.md#3.1
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
			$results = $this->objectService()->findAll(
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
