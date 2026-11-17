<?php

/**
 * Automation Flow Backend
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The I/O half of an automation that compiles to an OpenRegister flow
 * (logic-automation-actions-that-run D2): it reads the register and schemas of
 * the automation's app version, saves the flow document the builder returns
 * through `FlowService::save()` with `app` buildiq, publishes it so its trigger
 * is subscribed, binds it in `Application.flows`, switches it on and off with
 * the automation, and deletes and unbinds it again.
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
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-declarative-primitives-or-one-openregister-flow-req-bqar-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\FlowVersionServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Saves, publishes, binds, toggles and removes the flow of an automation.
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-declarative-primitives-or-one-openregister-flow-req-bqar-001
 */
class AutomationFlowBackend {

	/**
	 * The shared buildiq register.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'buildiq';

	/**
	 * The Application schema slug.
	 *
	 * @var string
	 */
	private const APPLICATION_SCHEMA = 'built-app';

	/**
	 * The app the flows are attributed to.
	 *
	 * @var string
	 */
	private const OWNING_APP = 'buildiq';

	/**
	 * The head status a flow's definition may be changed in.
	 *
	 * @var string
	 */
	private const DRAFT = 'draft';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface     $container      Resolves OpenRegister's flow services at use time.
	 * @param ObjectServiceInterface $objectService  Reads the version and reads and writes the Application.
	 * @param RegisterMapper         $registerMapper Reads the version's register.
	 * @param SchemaMapper           $schemaMapper   Reads the slugs of its schemas.
	 * @param LoggerInterface        $logger         PSR logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The register slug and schema slugs of an app version (D4).
	 *
	 * @param string $versionUuid The ApplicationVersion uuid.
	 *
	 * @return array{register: string, schemas: list<string>}
	 *
	 * @throws UnsupportedAutomationCombinationException When the version or its register cannot be read.
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-record-step-writes-a-record-of-the-app-req-bqar-002
	 */
	public function scope(string $versionUuid): array {
		$version = $this->loadVersion(uuid: $versionUuid);
		$register = (string)($version['register'] ?? '');
		if ($register === '') {
			throw new UnsupportedAutomationCombinationException(
				message: 'The app version of this automation has no register, so its steps cannot be compiled.'
			);
		}

		try {
			$schemaIds = ($this->registerMapper->find($register, _rbac: false, _multitenancy: false)->getSchemas() ?? []);
		} catch (Throwable $e) {
			throw new UnsupportedAutomationCombinationException(
				message: 'The register "' . $register . '" of this automation\'s app version could not be read.',
				previous: $e
			);
		}

		$schemas = [];
		foreach ((array)$schemaIds as $schemaId) {
			try {
				$slug = (string)$this->schemaMapper->find($schemaId, _rbac: false, _multitenancy: false)->getSlug();
			} catch (Throwable $e) {
				$this->logger->warning('Buildiq: AutomationFlowBackend skipped schema ' . (string)$schemaId . ': ' . $e->getMessage());
				continue;
			}

			if ($slug !== '') {
				$schemas[] = $slug;
			}
		}

		sort($schemas);

		return ['register' => $register, 'schemas' => $schemas];
	}//end scope()

	/**
	 * Save the flow, publish a changed definition, set its enabled state and
	 * bind it on the app (D2). Returns the flow's uuid.
	 *
	 * An unchanged definition is not saved as a new version: only the enabled
	 * state is written, which OpenRegister allows on a published flow.
	 *
	 * @param array<string, mixed> $flow        The flow document `{name, nodes, edges}`.
	 * @param string|null          $priorUuid   The flow this automation had, if any.
	 * @param bool                 $enabled     Whether the automation is enabled.
	 * @param string               $versionUuid The automation's ApplicationVersion uuid.
	 *
	 * @return string The flow uuid.
	 *
	 * @throws Throwable When OpenRegister refuses the flow.
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-declarative-primitives-or-one-openregister-flow-req-bqar-001
	 */
	public function apply(array $flow, ?string $priorUuid, bool $enabled, string $versionUuid): string {
		$existing = $this->findFlow(uuid: $priorUuid);
		if ($existing === null) {
			$stored = $this->flowService()->save(data: $this->payload(flow: $flow, enabled: $enabled));
			$this->versions()->publish(flow: $stored);
			$uuid = (string)$stored->getUuid();
			$this->bind(versionUuid: $versionUuid, flowUuid: $uuid, label: (string)($flow['name'] ?? ''));
			return $uuid;
		}

		$uuid = (string)$existing->getUuid();
		if (self::sameGraph(stored: $existing, flow: $flow) === true) {
			if ((bool)$existing->getEnabled() !== $enabled) {
				$this->flowService()->save(data: ['enabled' => $enabled], uuid: $uuid);
			}

			$this->bind(versionUuid: $versionUuid, flowUuid: $uuid, label: (string)($flow['name'] ?? ''));
			return $uuid;
		}

		if ((string)$existing->getLifecycleStatus() !== self::DRAFT) {
			$this->versions()->createDraft(flow: $existing);
		}

		$stored = $this->flowService()->save(data: $this->payload(flow: $flow, enabled: $enabled), uuid: $uuid);
		$this->versions()->publish(flow: $stored);
		$this->bind(versionUuid: $versionUuid, flowUuid: $uuid, label: (string)($flow['name'] ?? ''));

		return $uuid;
	}//end apply()

	/**
	 * Delete the flow and unbind it from the app (D2). A flow that is already
	 * gone is only unbound.
	 *
	 * @param string $flowUuid    The flow uuid.
	 * @param string $versionUuid The automation's ApplicationVersion uuid.
	 *
	 * @return void
	 *
	 * @throws Throwable When OpenRegister refuses the delete (a run has not finished).
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-declarative-primitives-or-one-openregister-flow-req-bqar-001
	 */
	public function remove(string $flowUuid, string $versionUuid): void {
		if ($flowUuid === '') {
			return;
		}

		if ($this->findFlow(uuid: $flowUuid) !== null) {
			$this->flowService()->delete(uuid: $flowUuid);
		}

		$this->unbind(versionUuid: $versionUuid, flowUuid: $flowUuid);
	}//end remove()

	/**
	 * The live document of a flow, in the shape the builder returns, or null
	 * when it is gone (drift, REQ-AUTD-005).
	 *
	 * @param string $flowUuid The flow uuid.
	 *
	 * @return array{name: string, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}|null
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-declarative-primitives-or-one-openregister-flow-req-bqar-001
	 */
	public function live(string $flowUuid): ?array {
		$flow = $this->findFlow(uuid: $flowUuid);
		if ($flow === null) {
			return null;
		}

		return [
			'name' => (string)$flow->getName(),
			'nodes' => array_values((array)($flow->getNodes() ?? [])),
			'edges' => array_values((array)($flow->getEdges() ?? [])),
		];
	}//end live()

	/**
	 * The save payload of a flow document.
	 *
	 * @param array<string, mixed> $flow    The flow document.
	 * @param bool                 $enabled The enabled state.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(array $flow, bool $enabled): array {
		return [
			'name' => (string)($flow['name'] ?? ''),
			'nodes' => array_values((array)($flow['nodes'] ?? [])),
			'edges' => array_values((array)($flow['edges'] ?? [])),
			'app' => self::OWNING_APP,
			'enabled' => $enabled,
		];
	}//end payload()

	/**
	 * Whether the stored flow already holds this document's graph.
	 *
	 * @param Flow                 $stored The stored flow.
	 * @param array<string, mixed> $flow   The flow document.
	 *
	 * @return bool
	 */
	private static function sameGraph(Flow $stored, array $flow): bool {
		return json_encode(array_values((array)($stored->getNodes() ?? []))) === json_encode(array_values((array)($flow['nodes'] ?? [])))
			&& json_encode(array_values((array)($stored->getEdges() ?? []))) === json_encode(array_values((array)($flow['edges'] ?? [])));
	}//end sameGraph()

	/**
	 * The stored flow, or null when there is none.
	 *
	 * @param string|null $uuid The flow uuid.
	 *
	 * @return Flow|null
	 */
	private function findFlow(?string $uuid): ?Flow {
		if ($uuid === null || $uuid === '') {
			return null;
		}

		try {
			return $this->flowService()->find(uuid: $uuid);
		} catch (Throwable $e) {
			return null;
		}
	}//end findFlow()

	/**
	 * Add the flow to the app's `flows` when it is not bound yet.
	 *
	 * @param string $versionUuid The ApplicationVersion uuid.
	 * @param string $flowUuid    The flow uuid.
	 * @param string $label       The binding label.
	 *
	 * @return void
	 */
	private function bind(string $versionUuid, string $flowUuid, string $label): void {
		[$applicationUuid, $application] = $this->application(versionUuid: $versionUuid);
		if ($application === null) {
			return;
		}

		$bindings = array_values((array)($application['flows'] ?? []));
		foreach ($bindings as $binding) {
			if (is_array($binding) === true && (string)($binding['flow'] ?? '') === $flowUuid) {
				return;
			}
		}

		$bindings[] = ['label' => $label, 'flow' => $flowUuid];
		$application['flows'] = $bindings;
		$this->saveApplication(applicationUuid: $applicationUuid, application: $application);
	}//end bind()

	/**
	 * Remove the flow from the app's `flows`.
	 *
	 * @param string $versionUuid The ApplicationVersion uuid.
	 * @param string $flowUuid    The flow uuid.
	 *
	 * @return void
	 */
	private function unbind(string $versionUuid, string $flowUuid): void {
		[$applicationUuid, $application] = $this->application(versionUuid: $versionUuid);
		if ($application === null) {
			return;
		}

		$bindings = array_values((array)($application['flows'] ?? []));
		$kept = array_values(
			array_filter(
				$bindings,
				static fn ($binding): bool => is_array($binding) === false || (string)($binding['flow'] ?? '') !== $flowUuid
			)
		);
		if (count($kept) === count($bindings)) {
			return;
		}

		$application['flows'] = $kept;
		$this->saveApplication(applicationUuid: $applicationUuid, application: $application);
	}//end unbind()

	/**
	 * The Application of a version: its uuid and payload, or a null payload.
	 *
	 * @param string $versionUuid The ApplicationVersion uuid.
	 *
	 * @return array{0: string, 1: array<string, mixed>|null}
	 */
	private function application(string $versionUuid): array {
		try {
			$version = $this->loadVersion(uuid: $versionUuid);
		} catch (UnsupportedAutomationCombinationException $e) {
			return ['', null];
		}

		$applicationUuid = self::relationUuid(value: $version['application'] ?? null);
		if ($applicationUuid === '') {
			$this->logger->warning('Buildiq: AutomationFlowBackend found no application on version "' . $versionUuid . '"; the flow is not bound.');
			return ['', null];
		}

		try {
			$found = $this->objectService->find(
				id: $applicationUuid,
				options: new ObjectFindOptions(register: self::REGISTER_SLUG, schema: self::APPLICATION_SCHEMA),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: AutomationFlowBackend could not load application "' . $applicationUuid . '": ' . $e->getMessage());
			return [$applicationUuid, null];
		}

		if ($found === null) {
			return [$applicationUuid, null];
		}

		return [$applicationUuid, (array)$found->getObject()];
	}//end application()

	/**
	 * Write the full Application back (saveObject replaces, it does not merge).
	 *
	 * @param string               $applicationUuid The Application uuid.
	 * @param array<string, mixed> $application     The full payload.
	 *
	 * @return void
	 */
	private function saveApplication(string $applicationUuid, array $application): void {
		$this->objectService->saveObject(
			object: $application,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::REGISTER_SLUG, schema: self::APPLICATION_SCHEMA),
				uuid: $applicationUuid
			),
			_rbac: false,
			_multitenancy: false
		);
	}//end saveApplication()

	/**
	 * Load an ApplicationVersion.
	 *
	 * @param string $uuid The version uuid.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws UnsupportedAutomationCombinationException When it cannot be read.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	private function loadVersion(string $uuid): array {
		if ($uuid === '') {
			throw new UnsupportedAutomationCombinationException(message: 'This automation belongs to no app version, so its steps cannot be compiled.');
		}

		try {
			$found = $this->objectService->find(
				id: $uuid,
				options: new ObjectFindOptions(register: self::REGISTER_SLUG, schema: 'applicationVersion'),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$found = null;
			$this->logger->warning('Buildiq: AutomationFlowBackend could not load version "' . $uuid . '": ' . $e->getMessage());
		}

		if ($found === null) {
			throw new UnsupportedAutomationCombinationException(message: 'The app version of this automation could not be read.');
		}

		return ObjectRecord::toArray(object: $found);
	}//end loadVersion()

	/**
	 * The uuid of a relation, stored as a uuid string or as an object with one.
	 *
	 * @param mixed $value The relation value.
	 *
	 * @return string
	 */
	private static function relationUuid(mixed $value): string {
		if (is_array($value) === true) {
			return (string)($value['uuid'] ?? ($value['id'] ?? ''));
		}

		if (is_string($value) === true) {
			return $value;
		}

		return '';
	}//end relationUuid()

	/**
	 * OpenRegister's flow service.
	 *
	 * @return FlowService
	 */
	private function flowService(): FlowService {
		return $this->container->get(FlowService::class);
	}//end flowService()

	/**
	 * OpenRegister's flow version service.
	 *
	 * @return FlowVersionServiceInterface
	 */
	private function versions(): FlowVersionServiceInterface {
		return $this->container->get(FlowVersionServiceInterface::class);
	}//end versions()
}//end class
