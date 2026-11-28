<?php

/**
 * Manual Automation Run
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Starts an app's manual automation on one record for an app user
 * (logic-automation-actions-that-run D8): it lists the enabled manual
 * automations of an app for a record's schema, reads the record as the caller
 * (a record the caller cannot read is refused), and asks OpenRegister to start
 * the automation's flow on it. The caller's role on the app is checked by the
 * controller before any of this runs; the flow runs as the caller, so every
 * write inside it is checked against their rights.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Automation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-app-users-run-manual-automations-from-a-record-req-bqar-006
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use OCA\Buildiq\Kernel\Contracts\AutomationRunRecorderInterface;
use OCA\Buildiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\FlowRunOptions;
use OCA\OpenRegister\Service\Flow\FlowService;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Lists and starts the manual automations of an app on one record.
 *
 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-app-users-run-manual-automations-from-a-record-req-bqar-006
 */
class ManualAutomationRun {

	/**
	 * Buildiq's own register, where automations are stored.
	 */
	private const REGISTER_SLUG = 'buildiq';

	/**
	 * The automation schema.
	 */
	private const AUTOMATION_SCHEMA = 'automation';

	/**
	 * The most automations one listing reads.
	 */
	private const LIST_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface                  $container     Resolves OpenRegister's flow service when a run starts.
	 * @param ObjectServiceInterface              $objectService Reads the record as the caller, and the app's automations.
	 * @param AutomationRunRecorderInterface|null $runs          Writes the waiting run its flow run completes (logic-automation-run-log).
	 * @param ManualRunReconciler|null            $reconciler    Completes those waiting runs once their flow runs have ended.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ObjectServiceInterface $objectService,
		private readonly ?AutomationRunRecorderInterface $runs = null,
		private readonly ?ManualRunReconciler $reconciler = null,
	) {
	}//end __construct()

	/**
	 * Whether the automation can be run by hand: a manual trigger, enabled, and compiled to a flow.
	 *
	 * @param array<string,mixed> $automation The automation.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-app-users-run-manual-automations-from-a-record-req-bqar-006
	 */
	public function isRunnable(array $automation): bool {
		$trigger = $automation['trigger'] ?? null;
		if (is_array($trigger) === false || ($trigger['type'] ?? null) !== 'manual') {
			return false;
		}

		return ($automation['enabled'] ?? false) === true && self::flowUuidOf(automation: $automation) !== '';
	}//end isRunnable()

	/**
	 * The enabled manual automations of an app for a record schema, as `uuid` and `name`.
	 *
	 * Read in system context: the caller's role on the app is the authorisation, checked before this runs.
	 *
	 * @param string $applicationSlug The app.
	 * @param string $schemaSlug      The record's schema.
	 *
	 * @return array<int,array{uuid:string,name:string}>
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-app-users-run-manual-automations-from-a-record-req-bqar-006
	 * @SuppressWarnings(PHPMD.StaticAccess) AutomationRecords is a pure coercion with no collaborators.
	 */
	public function listFor(string $applicationSlug, string $schemaSlug): array {
		$results = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER_SLUG,
					'schema' => self::AUTOMATION_SCHEMA,
					'applicationSlug' => $applicationSlug,
				],
				'limit' => self::LIST_LIMIT,
			],
			_rbac: false
		);

		$out = [];
		foreach ($results as $result) {
			$automation = AutomationRecords::toArray(object: $result);
			if ($this->isRunnable(automation: $automation) === false || (string)($automation['trigger']['schema'] ?? '') !== $schemaSlug) {
				continue;
			}

			$out[] = [
				'uuid' => (string)($automation['id'] ?? $automation['uuid'] ?? ''),
				'name' => (string)($automation['name'] ?? $automation['slug'] ?? ''),
			];
		}

		return $out;
	}//end listFor()

	/**
	 * The run subject `{uuid, register, schema}` of a record the caller can read, or null.
	 *
	 * The record is read with the caller's rights; a record that does not
	 * exist, that the caller may not read, or that is not of the automation's
	 * schema reads as null.
	 *
	 * @param string $objectUuid The record.
	 * @param string $schemaSlug The automation's trigger schema; empty accepts any.
	 *
	 * @return array{uuid:string,register:string,schema:string}|null
	 *
	 * The record carries its schema as OpenRegister's numeric id, never the
	 * slug a trigger names, so the slug is resolved before it is compared;
	 * comparing the id refused every record of a manual automation that names
	 * its schema.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-app-users-run-manual-automations-from-a-record-req-bqar-006
	 */
	public function readableSubject(string $objectUuid, string $schemaSlug): ?array {
		if ($objectUuid === '') {
			return null;
		}

		try {
			$record = $this->objectService->find(id: $objectUuid);
		} catch (Throwable) {
			return null;
		}

		if ($record === null) {
			return null;
		}

		$schema = (string)($record->getSchema() ?? '');
		if ($schemaSlug !== '' && $this->schemaSlugOf(record: $record) !== $schemaSlug) {
			return null;
		}

		return [
			'uuid' => (string)($record->getUuid() ?? $objectUuid),
			'register' => (string)($record->getRegister() ?? ''),
			'schema' => $schema,
		];
	}//end readableSubject()

	/**
	 * The schema slug of a record: its schema as OpenRegister stores it (an id)
	 * resolved through the object schema slug resolver, or the stored value
	 * when it is a slug already or the resolver is not there.
	 *
	 * @param object $record The record as the caller reads it.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-app-users-run-manual-automations-from-a-record-req-bqar-006
	 */
	private function schemaSlugOf(object $record): string {
		try {
			$slug = $this->container->get(ObjectSchemaSlugResolverInterface::class)->schemaSlug($record);
		} catch (Throwable) {
			$slug = '';
		}

		if (is_string($slug) === true && $slug !== '') {
			return $slug;
		}

		return (string)($record->getSchema() ?? '');
	}//end schemaSlugOf()

	/**
	 * Start the automation's flow on the record; the run is queued, not awaited.
	 *
	 * The run is recorded `waiting` with the flow run's uuid; the flow run's
	 * terminal event completes it (ManualRunCompletionListener).
	 *
	 * @param array<string,mixed>                              $automation The runnable automation.
	 * @param array{uuid:string,register:string,schema:string} $subject    The record.
	 * @param string                                           $actor      The uid of the caller who ran it.
	 *
	 * @return array{flowUuid:string,run:mixed}|null The started run, or null when OpenRegister cannot start it.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-app-users-run-manual-automations-from-a-record-req-bqar-006
	 * @spec openspec/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	public function start(array $automation, array $subject, string $actor = ''): ?array {
		$flows = $this->container->get(FlowService::class);
		if (method_exists($flows, 'runForAction') === false) {
			return null;
		}

		$flowUuid = self::flowUuidOf(automation: $automation);
		$context  = ['automation' => (string)($automation['id'] ?? $automation['uuid'] ?? '')];
		$options  = new FlowRunOptions(subject: $subject, context: $context, sync: false, trigger: Flow::TRIGGER_MANUAL);
		$run      = $flows->runForAction(uuid: $flowUuid, options: $options);
		$runUuid  = $run->jsonSerialize()['uuid'] ?? null;
		$this->recordWaiting(automation: $automation, subject: $subject, flowRunUuid: (string)($runUuid ?? ''), actor: $actor);

		return ['flowUuid' => $flowUuid, 'run' => $runUuid];
	}//end start()

	/**
	 * Complete the waiting manual runs of one automation whose flow runs have
	 * ended, before its run log is read (ManualRunReconciler).
	 *
	 * @param string $automationUuid The automation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	public function reconcile(string $automationUuid): void {
		$this->reconciler?->reconcile(automationUuid: $automationUuid);
	}//end reconcile()

	/**
	 * Write the waiting run of a queued flow run. A failure to write is the
	 * recorder's to log; it never stops the run (design D2).
	 *
	 * @param array<string,mixed>                              $automation  The automation.
	 * @param array{uuid:string,register:string,schema:string} $subject     The record.
	 * @param string                                           $flowRunUuid The queued flow run.
	 * @param string                                           $actor       The uid of the caller.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	private function recordWaiting(array $automation, array $subject, string $flowRunUuid, string $actor): void {
		if ($this->runs === null || $flowRunUuid === '') {
			return;
		}

		$run = $this->runs->start(
			automation: $automation,
			trigger: 'manual',
			subject: ['schema' => $subject['schema'], 'uuid' => $subject['uuid']],
			actor: $actor
		);
		$run['flowRun'] = $flowRunUuid;
		$this->runs->finish(run: $run, outcome: 'waiting');
	}//end recordWaiting()

	/**
	 * The uuid of the flow the automation compiled to, or ''.
	 *
	 * @param array<string,mixed> $automation The automation.
	 *
	 * @return string
	 */
	private static function flowUuidOf(array $automation): string {
		$provenance = $automation['provenance'] ?? null;
		if (is_array($provenance) === false) {
			return '';
		}

		return (string)($provenance['flowUuid'] ?? '');
	}//end flowUuidOf()
}//end class
