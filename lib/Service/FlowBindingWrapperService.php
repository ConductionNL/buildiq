<?php

/**
 * Buildiq FlowBindingWrapperService
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Makes an attached flow run where its binding says (REQ-BQSF-002). For every
 * `runOn` entry of a flow bound in `Application.flows`, buildiq keeps a small
 * wrapper flow of its own: the app's trigger (an object event on the app's
 * register and the entry's schema, or a manual trigger for `from-record`),
 * then `openregister.sub-flow` calling the bound flow without waiting. The
 * bound flow itself is never read, saved or changed.
 *
 * A wrapper is bound in `Application.flows` like any flow, with `sourceUuid`
 * the bound flow it serves and `label` its own name, `aut-attach-<app>-<key>`.
 * The key is a hash of the bound flow, the schema and the event or mode, so a
 * re-save with the same entries keeps every wrapper as it is, a removed entry
 * or binding removes its wrapper, and the export can tell a wrapper from a
 * flow the maker bound ({@see isWrapper()}).
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Kernel\Contracts\VersionPromotionServiceInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Flow\FlowService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the wrapper flows of an application's `runOn` entries in step with its bindings.
 *
 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
 */
class FlowBindingWrapperService {

	/**
	 * The name every wrapper flow starts with, before the app slug.
	 *
	 * @var string
	 */
	public const NAME_PREFIX = 'aut-attach-';

	/**
	 * The object events a `runOn` entry may name.
	 *
	 * @var array<int,string>
	 */
	public const EVENTS = ['object.created', 'object.updated', 'object.deleted'];

	/**
	 * The `mode` of an entry an app user starts from a record.
	 *
	 * @var string
	 */
	public const MODE_FROM_RECORD = 'from-record';

	/**
	 * The app a wrapper flow is stored under.
	 *
	 * @var string
	 */
	private const OWNING_APP = 'buildiq';

	/**
	 * Publishes wrappers; built on first use.
	 *
	 * @var FlowWrapperPublisher|null
	 */
	private ?FlowWrapperPublisher $publisher = null;

	/**
	 * Constructor.
	 *
	 * @param FlowService            $flowService   OpenRegister's single entry point for flows.
	 * @param ObjectServiceInterface $objectService Saves the Application with its new bindings.
	 * @param ContainerInterface     $container     Resolves OpenRegister's flow versioning, when it ships.
	 * @param LoggerInterface        $logger        Records a wrapper that could not be saved, published or removed.
	 */
	public function __construct(
		private readonly FlowService $flowService,
		private readonly ObjectServiceInterface $objectService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a binding is one of buildiq's wrappers rather than a flow the maker bound.
	 *
	 * @param mixed $binding One `Application.flows` entry.
	 *
	 * @return bool True for a wrapper.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
	 */
	public static function isWrapper(mixed $binding): bool {
		if (is_array($binding) === false) {
			return false;
		}

		return str_starts_with((string)($binding['label'] ?? ''), self::NAME_PREFIX) === true
			&& (string)($binding['sourceUuid'] ?? '') !== '';
	}//end isWrapper()
	/**
	 * The wrappers an application's bindings ask for, by wrapper name.
	 *
	 * Entries that name no schema, or neither a known event nor `from-record`,
	 * are skipped: the register fragment already refuses them, and a wrapper
	 * built from one would never fire.
	 *
	 * @param string                    $appSlug  The application's slug.
	 * @param array<int|string,mixed>   $bindings The application's `flows`.
	 *
	 * @return array<string,array{flow:string,entry:array{schema:string,event?:string,mode?:string}}> Per wrapper name, the bound flow and the entry.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
	 */
	public static function plan(string $appSlug, array $bindings): array {
		$plan = [];
		foreach ($bindings as $binding) {
			$flow = self::boundFlow(binding: $binding);
			if ($flow === '') {
				continue;
			}

			foreach ((array)($binding['runOn'] ?? []) as $raw) {
				$entry = self::entry(raw: $raw);
				if ($entry === null) {
					continue;
				}

				$wanted = ['flow' => $flow, 'entry' => $entry];
				$plan[self::wrapperName(appSlug: $appSlug, wanted: $wanted)] = $wanted;
			}
		}

		ksort($plan);
		return $plan;
	}//end plan()

	/**
	 * The wrapper flow's name for one entry of one bound flow.
	 *
	 * @param string                                                                     $appSlug The application's slug.
	 * @param array{flow:string,entry:array{schema:string,event?:string,mode?:string}} $wanted  The bound flow's uuid and its `runOn` entry.
	 *
	 * @return string `aut-attach-<app>-<key>`.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
	 */
	public static function wrapperName(string $appSlug, array $wanted): string {
		$entry = $wanted['entry'];
		$trigger = (string)($entry['event'] ?? ($entry['mode'] ?? ''));
		return self::NAME_PREFIX . $appSlug . '-' . substr(hash('sha256', $wanted['flow'] . '|' . $entry['schema'] . '|' . $trigger), 0, 12);
	}//end wrapperName()

	/**
	 * The flow definition of one wrapper.
	 *
	 * The wrapper's name is {@see self::wrapperName()} of the bound flow and its entry.
	 *
	 * @param array{slug:string,register:string}                                         $target The application's slug and its app version's register.
	 * @param array{flow:string,entry:array{schema:string,event?:string,mode?:string}} $wanted The bound flow and its `runOn` entry.
	 *
	 * @return array<string,mixed> What {@see FlowService::save()} takes.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
	 */
	public static function definition(array $target, array $wanted): array {
		$entry   = $wanted['entry'];
		$trigger = ['id' => 'trigger', 'type' => 'openregister.trigger-manual', 'config' => []];
		$where   = 'from a record of ' . $entry['schema'];
		if (isset($entry['event']) === true) {
			$trigger = [
				'id' => 'trigger',
				'type' => 'openregister.trigger-object',
				'config' => ['event' => $entry['event'], 'register' => $target['register'], 'schema' => $entry['schema']],
			];
			$where = 'on ' . $entry['schema'] . ' ' . $entry['event'];
		}

		return [
			'name' => self::wrapperName(appSlug: $target['slug'], wanted: $wanted),
			'description' => 'Starts flow ' . $wanted['flow'] . ' ' . $where . ' for app ' . $target['slug']
				. '. Kept by buildiq from the app\'s flow binding; change the binding in the app settings, not this flow.',
			'app' => self::OWNING_APP,
			'applicationSlug' => $target['slug'],
			'enabled' => true,
			'nodes' => [
				$trigger,
				// The last node: `exit` marks where a token leaves, without
				// which OpenRegister refuses to publish the wrapper.
				['id' => 'run', 'type' => 'openregister.sub-flow', 'config' => ['flowId' => $wanted['flow'], 'wait' => false], 'exit' => true],
			],
			'edges' => [['id' => 'trigger-run', 'from' => 'trigger', 'to' => 'run']],
		];
	}//end definition()

	/**
	 * Bring the application's wrapper flows in step with its bindings, and save the bindings.
	 *
	 * Wrappers whose entry still exists are kept as they are. A missing one is
	 * saved, published and bound; one whose entry or binding is gone is deleted
	 * and unbound. A wrapper that cannot be deleted stays bound, so it stays
	 * visible and the next save tries again.
	 *
	 * @param string              $applicationUuid The Application's uuid.
	 * @param array<string,mixed> $application     The Application object, freshly loaded.
	 * @param string              $register        The production version's register, for object triggers.
	 *
	 * @return array{flows:list<array<string,mixed>>,created:int,removed:int,failed:list<array{name:string,reason:string}>}
	 *         The new bindings and what changed.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
	 */
	public function sync(string $applicationUuid, array $application, string $register): array {
		$slug     = (string)($application['slug'] ?? '');
		$bindings = array_values(array_filter((array)($application['flows'] ?? []), 'is_array'));
		$target   = ['slug' => $slug, 'register' => $register];
		$plan     = self::plan(appSlug: $slug, bindings: $bindings);

		[$result, $present] = $this->keepOrRemove(target: $target + ['plan' => $plan], bindings: $bindings);
		foreach (array_diff_key($plan, $present) as $wanted) {
			$made = $this->createWrapper(target: $target, wanted: $wanted);
			array_push($result['failed'], ...$made['failed']);
			if ($made['binding'] !== null) {
				$result['flows'][] = $made['binding'];
				$result['created']++;
			}
		}

		if ($result['flows'] !== $bindings) {
			$application['flows'] = $result['flows'];
			$this->saveApplication(uuid: $applicationUuid, application: $application);
		}

		return $result;
	}//end sync()

	/**
	 * Keep the bindings that stay, and delete and unbind the wrappers that go.
	 *
	 * A maker's own binding always stays. A wrapper stays when the plan still
	 * asks for it, once per name; any other wrapper is removed, or stays bound
	 * when it cannot be.
	 *
	 * @param array{slug:string,register:string,plan:array<string,mixed>} $target   The application's slug, register and plan.
	 * @param list<array<string,mixed>>                                   $bindings The application's bindings.
	 *
	 * @return array{0:array{flows:list<array<string,mixed>>,created:int,removed:int,failed:list<array{name:string,reason:string}>},1:array<string,true>}
	 *         The running result, and the wrapper names that are already bound.
	 */
	private function keepOrRemove(array $target, array $bindings): array {
		$result  = ['flows' => [], 'created' => 0, 'removed' => 0, 'failed' => []];
		$present = [];
		foreach ($bindings as $binding) {
			if (self::isWrapper(binding: $binding) === false) {
				$result['flows'][] = $binding;
				continue;
			}

			$name = (string)$binding['label'];
			if (isset($target['plan'][$name]) === true && isset($present[$name]) === false) {
				$present[$name]    = true;
				$result['flows'][] = $binding;
				$failure = $this->publisher()->republishDraft(
					uuid: (string)($binding['flow'] ?? ''),
					name: $name,
					appSlug: $target['slug'],
					definition: self::definition(target: $target, wanted: $target['plan'][$name])
				);
				if ($failure !== null) {
					$result['failed'][] = $failure;
				}

				continue;
			}

			$reason = $this->removeWrapper(binding: $binding, appSlug: $target['slug']);
			if ($reason === null) {
				$result['removed']++;
				continue;
			}

			$result['failed'][] = ['name' => $name, 'reason' => $reason];
			$result['flows'][]  = $binding;
		}

		return [$result, $present];
	}//end keepOrRemove()

	/**
	 * Save, publish and describe one wrapper.
	 *
	 * The wrapper's name is {@see self::wrapperName()} of the bound flow and its entry.
	 *
	 * @param array{slug:string,register:string}                                         $target The application's slug and its app version's register.
	 * @param array{flow:string,entry:array{schema:string,event?:string,mode?:string}} $wanted The bound flow and its entry.
	 *
	 * @return array{binding:array<string,string>|null,failed:list<array{name:string,reason:string}>}
	 *         The new binding, null when the wrapper was not saved, and what failed.
	 */
	private function createWrapper(array $target, array $wanted): array {
		$name = self::wrapperName(appSlug: $target['slug'], wanted: $wanted);
		if (isset($wanted['entry']['event']) === true && $target['register'] === '') {
			return ['binding' => null, 'failed' => [['name' => $name, 'reason' => 'no_register']]];
		}

		try {
			$flow = $this->flowService->save(data: self::definition(target: $target, wanted: $wanted));
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: wrapper flow "' . $name . '" could not be saved: ' . $e->getMessage());
			return ['binding' => null, 'failed' => [['name' => $name, 'reason' => 'save_failed']]];
		}

		$failed = [];
		if ($this->publisher()->publish(flow: $flow, name: $name) === false) {
			$failed[] = ['name' => $name, 'reason' => 'not_published'];
		}

		return [
			'binding' => ['flow' => (string)$flow->getUuid(), 'label' => $name, 'sourceUuid' => $wanted['flow']],
			'failed' => $failed,
		];
	}//end createWrapper()

	/**
	 * Delete one wrapper's flow, or say why it stays bound.
	 *
	 * Only a flow this app keeps as a wrapper of this application is deleted: a
	 * binding's label alone does not make its flow ours. A binding naming any
	 * other flow stays bound and is reported, and that flow is left alone.
	 *
	 * @param array<string,mixed> $binding The wrapper's binding.
	 * @param string              $appSlug The application's slug.
	 *
	 * @return string|null Null when the wrapper is gone, else `not_a_wrapper` or `remove_failed`.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md
	 */
	private function removeWrapper(array $binding, string $appSlug): ?string {
		$uuid = (string)($binding['flow'] ?? '');
		if ($uuid === '') {
			return null;
		}

		try {
			if (self::ownWrapper(flow: $this->flowService->find(uuid: $uuid), appSlug: $appSlug) === false) {
				return 'not_a_wrapper';
			}

			$this->flowService->delete(uuid: $uuid);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: wrapper flow "' . (string)$binding['label'] . '" could not be removed: ' . $e->getMessage());
			return 'remove_failed';
		}

		return null;
	}//end removeWrapper()

	/**
	 * Whether a flow is a wrapper this app keeps for this application.
	 *
	 * @param \OCA\OpenRegister\Db\Flow $flow    The flow a wrapper binding names.
	 * @param string                    $appSlug The application's slug.
	 *
	 * @return bool True when the flow is ours to delete.
	 */
	public static function ownWrapper(\OCA\OpenRegister\Db\Flow $flow, string $appSlug): bool {
		return $flow->getApp() === self::OWNING_APP && $appSlug !== '' && $flow->getApplicationSlug() === $appSlug;
	}//end ownWrapper()

	/**
	 * Publishes wrappers (built on first use).
	 *
	 * @return FlowWrapperPublisher
	 */
	private function publisher(): FlowWrapperPublisher {
		$this->publisher ??= new FlowWrapperPublisher(flowService: $this->flowService, container: $this->container, logger: $this->logger);
		return $this->publisher;
	}//end publisher()

	/**
	 * Save the Application with its new bindings.
	 *
	 * @param string              $uuid        The Application's uuid.
	 * @param array<string,mixed> $application The Application with its new `flows`.
	 *
	 * @return void
	 */
	private function saveApplication(string $uuid, array $application): void {
		$this->objectService->saveObject(
			object: $application,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(
					register: VersionPromotionServiceInterface::REGISTER_SLUG,
					schema: VersionPromotionServiceInterface::APPLICATION_SCHEMA
				),
				uuid: $uuid
			),
			_rbac: false,
			_multitenancy: false
		);
	}//end saveApplication()

	/**
	 * The flow uuid a maker's binding names, or '' for a wrapper or an unusable binding.
	 *
	 * @param mixed $binding One `Application.flows` entry.
	 *
	 * @return string The bound flow's uuid.
	 */
	private static function boundFlow(mixed $binding): string {
		if (is_array($binding) === false || self::isWrapper(binding: $binding) === true) {
			return '';
		}

		return trim((string)($binding['flow'] ?? ''));
	}//end boundFlow()

	/**
	 * One usable `runOn` entry, or null.
	 *
	 * @param mixed $raw The stored entry.
	 *
	 * @return array{schema:string,event?:string,mode?:string}|null The entry.
	 */
	private static function entry(mixed $raw): ?array {
		if (is_array($raw) === false) {
			return null;
		}

		$schema = trim((string)($raw['schema'] ?? ''));
		if ($schema === '') {
			return null;
		}

		$event = (string)($raw['event'] ?? '');
		if (in_array($event, self::EVENTS, true) === true) {
			return ['schema' => $schema, 'event' => $event];
		}

		if (($raw['mode'] ?? null) === self::MODE_FROM_RECORD) {
			return ['schema' => $schema, 'mode' => self::MODE_FROM_RECORD];
		}

		return null;
	}//end entry()
}//end class
