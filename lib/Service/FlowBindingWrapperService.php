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
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowVersionServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the wrapper flows of an application's `runOn` entries in step with its bindings.
 *
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
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
	 * The register the Application objects live in.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'buildiq';

	/**
	 * The Application schema.
	 *
	 * @var string
	 */
	private const APPLICATION_SCHEMA = 'built-app';

	/**
	 * The app a wrapper flow is stored under.
	 *
	 * @var string
	 */
	private const OWNING_APP = 'buildiq';

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
	 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
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
	 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
	 */
	public static function plan(string $appSlug, array $bindings): array {
		$plan = [];
		foreach ($bindings as $binding) {
			if (is_array($binding) === false || self::isWrapper(binding: $binding) === true) {
				continue;
			}

			$flow = trim((string)($binding['flow'] ?? ''));
			if ($flow === '') {
				continue;
			}

			foreach ((array)($binding['runOn'] ?? []) as $raw) {
				$entry = self::entry(raw: $raw);
				if ($entry === null) {
					continue;
				}

				$plan[self::wrapperName(appSlug: $appSlug, flow: $flow, entry: $entry)] = ['flow' => $flow, 'entry' => $entry];
			}
		}

		ksort($plan);
		return $plan;
	}//end plan()

	/**
	 * The wrapper flow's name for one entry of one bound flow.
	 *
	 * @param string                                          $appSlug The application's slug.
	 * @param string                                          $flow    The bound flow's uuid.
	 * @param array{schema:string,event?:string,mode?:string} $entry   The `runOn` entry.
	 *
	 * @return string `aut-attach-<app>-<key>`.
	 *
	 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
	 */
	public static function wrapperName(string $appSlug, string $flow, array $entry): string {
		$on = (string)($entry['event'] ?? ($entry['mode'] ?? ''));
		return self::NAME_PREFIX . $appSlug . '-' . substr(hash('sha256', $flow . '|' . $entry['schema'] . '|' . $on), 0, 12);
	}//end wrapperName()

	/**
	 * The flow definition of one wrapper.
	 *
	 * @param string                                          $name     The wrapper's name.
	 * @param string                                          $appSlug  The application's slug.
	 * @param string                                          $register The app version's register.
	 * @param string                                          $flow     The bound flow's uuid.
	 * @param array{schema:string,event?:string,mode?:string} $entry    The `runOn` entry.
	 *
	 * @return array<string,mixed> What {@see FlowService::save()} takes.
	 *
	 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
	 */
	public static function definition(string $name, string $appSlug, string $register, string $flow, array $entry): array {
		$trigger = ['id' => 'trigger', 'type' => 'openregister.trigger-manual', 'config' => []];
		$where = 'from a record of ' . $entry['schema'];
		if (isset($entry['event']) === true) {
			$trigger = [
				'id' => 'trigger',
				'type' => 'openregister.trigger-object',
				'config' => ['event' => $entry['event'], 'register' => $register, 'schema' => $entry['schema']],
			];
			$where = 'on ' . $entry['schema'] . ' ' . $entry['event'];
		}

		return [
			'name' => $name,
			'description' => 'Starts flow ' . $flow . ' ' . $where . ' for app ' . $appSlug
				. '. Kept by buildiq from the app\'s flow binding; change the binding in the app settings, not this flow.',
			'app' => self::OWNING_APP,
			'applicationSlug' => $appSlug,
			'enabled' => true,
			'nodes' => [
				$trigger,
				['id' => 'run', 'type' => 'openregister.sub-flow', 'config' => ['flowId' => $flow, 'wait' => false]],
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
	 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
	 */
	public function sync(string $applicationUuid, array $application, string $register): array {
		$appSlug = (string)($application['slug'] ?? '');
		$bindings = array_values(array_filter((array)($application['flows'] ?? []), 'is_array'));
		$plan = self::plan(appSlug: $appSlug, bindings: $bindings);

		$result = ['flows' => [], 'created' => 0, 'removed' => 0, 'failed' => []];
		$present = [];
		foreach ($bindings as $binding) {
			if (self::isWrapper(binding: $binding) === false) {
				$result['flows'][] = $binding;
				continue;
			}

			$name = (string)$binding['label'];
			if (isset($plan[$name]) === true && isset($present[$name]) === false) {
				$present[$name] = true;
				$result['flows'][] = $binding;
				continue;
			}

			$this->removeWrapper(binding: $binding, result: $result);
		}

		foreach ($plan as $name => $wanted) {
			if (isset($present[$name]) === true) {
				continue;
			}

			$this->createWrapper(name: $name, appSlug: $appSlug, register: $register, wanted: $wanted, result: $result);
		}

		if ($result['flows'] !== $bindings) {
			$application['flows'] = $result['flows'];
			$this->objectService->saveObject(
				object: $application,
				register: self::REGISTER_SLUG,
				schema: self::APPLICATION_SCHEMA,
				uuid: $applicationUuid,
				_rbac: false,
				_multitenancy: false
			);
		}

		return $result;
	}//end sync()

	/**
	 * Save, publish and bind one wrapper.
	 *
	 * @param string                                                                     $name     The wrapper's name.
	 * @param string                                                                     $appSlug  The application's slug.
	 * @param string                                                                     $register The app version's register.
	 * @param array{flow:string,entry:array{schema:string,event?:string,mode?:string}} $wanted   The bound flow and its entry.
	 * @param array<string,mixed>                                                        $result   The running result.
	 *
	 * @return void
	 */
	private function createWrapper(string $name, string $appSlug, string $register, array $wanted, array &$result): void {
		if (isset($wanted['entry']['event']) === true && $register === '') {
			$result['failed'][] = ['name' => $name, 'reason' => 'no_register'];
			return;
		}

		try {
			$flow = $this->flowService->save(
				data: self::definition(name: $name, appSlug: $appSlug, register: $register, flow: $wanted['flow'], entry: $wanted['entry'])
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: wrapper flow "' . $name . '" could not be saved: ' . $e->getMessage());
			$result['failed'][] = ['name' => $name, 'reason' => 'save_failed'];
			return;
		}

		$this->publish(flow: $flow, name: $name, result: $result);
		$result['flows'][] = ['flow' => (string)$flow->getUuid(), 'label' => $name, 'sourceUuid' => $wanted['flow']];
		$result['created']++;
	}//end createWrapper()

	/**
	 * Publish a new wrapper, so its trigger subscribes.
	 *
	 * OpenRegister subscribes the triggers of a flow's PUBLISHED version only.
	 * A wrapper left a draft is saved and bound but never fires, so a failure
	 * here is reported rather than passed over.
	 *
	 * @param \OCA\OpenRegister\Db\Flow $flow   The saved wrapper.
	 * @param string                    $name   The wrapper's name.
	 * @param array<string,mixed>       $result The running result.
	 *
	 * @return void
	 */
	private function publish(\OCA\OpenRegister\Db\Flow $flow, string $name, array &$result): void {
		if (interface_exists(FlowVersionServiceInterface::class) === false
			|| $this->container->has(FlowVersionServiceInterface::class) === false
		) {
			$result['failed'][] = ['name' => $name, 'reason' => 'not_published'];
			return;
		}

		try {
			$this->container->get(FlowVersionServiceInterface::class)->publish(flow: $flow);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: wrapper flow "' . $name . '" could not be published: ' . $e->getMessage());
			$result['failed'][] = ['name' => $name, 'reason' => 'not_published'];
		}
	}//end publish()

	/**
	 * Delete and unbind one wrapper, or keep it bound when it cannot be deleted.
	 *
	 * @param array<string,mixed> $binding The wrapper's binding.
	 * @param array<string,mixed> $result  The running result.
	 *
	 * @return void
	 */
	private function removeWrapper(array $binding, array &$result): void {
		$uuid = (string)($binding['flow'] ?? '');
		try {
			if ($uuid !== '') {
				$this->flowService->delete(uuid: $uuid);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: wrapper flow "' . (string)$binding['label'] . '" could not be removed: ' . $e->getMessage());
			$result['failed'][] = ['name' => (string)$binding['label'], 'reason' => 'remove_failed'];
			$result['flows'][] = $binding;
			return;
		}

		$result['removed']++;
	}//end removeWrapper()

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
