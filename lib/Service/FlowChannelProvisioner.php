<?php

/**
 * OpenBuild FlowChannelProvisioner
 *
 * Applies a published app repo's `flows/` channel: creates OpenRegister `Flow`
 * ENTITIES for every published flow definition the local Application does not
 * already carry, and re-binds the newly created flows onto the local
 * Application's own `flows[]` array.
 *
 * Split out of AppChannelApplier for the same reason DataRegisterProvisioner and
 * SkillChannelDelegate were: provisioning flows is a distinct responsibility
 * from orchestrating the other channels, with its own collaborator
 * (`FlowService`, the single OpenRegister-sanctioned entry point for flows,
 * ADR-022) and its own two-step shape (create, then rebind).
 *
 * WHY A FRESH LOCAL UUID, NOT THE PUBLISHED ONE
 * -----------------------------------------------
 * `FlowService::save()` — the one entry point every app uses for flows, so no
 * app grows its own flow store, resolver or trigger-index writer — mints its
 * OWN uuid on create and has no parameter to seed a caller-chosen one. That is
 * deliberate on its side: `save()` also stamps `owner`/`organisation` from the
 * acting session and rebuilds the trigger index from the nodes just written,
 * and none of that is available to a caller minting a uuid by hand without
 * reimplementing it.
 *
 * The bundler that reads a flow back out for export (`FlowAndAgentExportBundler`,
 * `openbuild-app-binds-flows-and-agents`) was written for a DIFFERENT case — the
 * openbuild-exporter's standalone scaffold, whose migration seeds a `Flow` row
 * directly at a fixed uuid because there is no live session, owner or trigger
 * index to reconcile at migration time. That is not this case: applying a v2
 * repo onto a running instance always has a real session, so going through
 * `FlowService` — the sanctioned entry point — is correct, and the published
 * uuid is carried forward as `sourceUuid` on the new binding instead, which is
 * what makes a repeat apply of the SAME repository idempotent (see below)
 * without requiring the flow's own identity to match across instances.
 *
 * SKIP-IF-EXISTS, KEYED BY sourceUuid
 * ------------------------------------
 * Because the local flow uuid is always freshly minted, "does this flow already
 * exist" cannot be answered by comparing uuids the way the connectors channel
 * does. It is answered instead by whether the local Application's OWN `flows[]`
 * array already carries a binding whose `sourceUuid` matches — entirely local
 * state, no cross-instance query, and consistent with the ADR-037 lesson: that
 * property is declared on the schema (`register.d/22-flows-and-agents.json`)
 * before this class ever writes it, so OpenRegister's `additionalProperties:
 * false` validation does not silently reject the rebind.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/app-channel-application/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates the flows a published app repo declares, and rebinds them locally.
 *
 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/app-channel-application/spec.md
 */
class FlowChannelProvisioner {

	/**
	 * The channel name used in the report.
	 *
	 * @var string
	 */
	private const CHANNEL = 'flows';

	/**
	 * The shared OpenBuild register slug the Application object lives in.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'openbuild';

	/**
	 * The Application schema slug.
	 *
	 * @var string
	 */
	private const APPLICATION_SCHEMA = 'application';

	/**
	 * The Nextcloud app id newly created flows are attributed to.
	 *
	 * @var string
	 */
	private const OWNING_APP = 'openbuild';

	/**
	 * Maximum flows applied from one repo.
	 *
	 * @var int
	 */
	private const MAX_FLOWS = 512;

	/**
	 * Constructor.
	 *
	 * @param FlowService $flowService The sanctioned single entry point for creating flows (ADR-022).
	 * @param ObjectServiceInterface $objectService Reads/writes the local Application object to rebind it.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FlowService $flowService,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply the flows channel.
	 *
	 * @param array<string,mixed> $flows The channel (published flow UUID → blob).
	 * @param string|null $applicationUuid The LOCAL Application's own uuid, whose
	 *                                     `flows[]` the newly created flows are
	 *                                     bound onto. Null when the caller has no
	 *                                     local Application context yet.
	 * @param ChannelApplyReport $report The report to write into.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/app-channel-application/spec.md#requirement-published-flows-are-created-and-rebound-onto-the-local-application
	 */
	public function apply(array $flows, ?string $applicationUuid, ChannelApplyReport $report): void {
		$report->declareChannel(channel: self::CHANNEL, declared: count($flows));

		if ($flows === []) {
			return;
		}

		$application = $this->resolveApplication(applicationUuid: $applicationUuid, report: $report);
		if ($application === null) {
			return;
		}

		$newBindings = $this->applyEach(flows: $flows, application: $application, report: $report);

		if ($newBindings !== []) {
			$this->rebindApplication(applicationUuid: (string)$applicationUuid, application: $application, newBindings: $newBindings);
		}
	}//end apply()

	/**
	 * Resolve the local Application context, recording a degradation reason
	 * on the report when it is missing or unresolvable.
	 *
	 * @param string|null $applicationUuid The LOCAL Application's own uuid.
	 * @param ChannelApplyReport $report The report to write into.
	 *
	 * @return array<string,mixed>|null The Application payload, or null when unavailable.
	 */
	private function resolveApplication(?string $applicationUuid, ChannelApplyReport $report): ?array {
		if ($applicationUuid === null || $applicationUuid === '') {
			$report->skipChannel(channel: self::CHANNEL, reason: 'no-local-application-context');
			return null;
		}

		$application = $this->loadApplication(uuid: $applicationUuid);
		if ($application === null) {
			$report->skipChannel(channel: self::CHANNEL, reason: 'application-not-found');
			return null;
		}

		return $application;
	}//end resolveApplication()

	/**
	 * Apply every declared flow: bound, skip-if-already-bound, or create.
	 *
	 * @param array<string,mixed> $flows The channel (published flow UUID → blob).
	 * @param array<string,mixed> $application The local Application payload.
	 * @param ChannelApplyReport $report The report to write into.
	 *
	 * @return array<int,array<string,mixed>> The newly created bindings.
	 */
	private function applyEach(array $flows, array $application, ChannelApplyReport $report): array {
		$existingSourceUuids = $this->existingSourceUuids(application: $application);

		$newBindings = [];
		$applied = 0;
		foreach ($flows as $sourceUuid => $blob) {
			$sourceUuid = (string)$sourceUuid;
			$item = self::CHANNEL . '/' . $sourceUuid;

			if ($applied >= self::MAX_FLOWS) {
				$this->logger->warning(
					'OpenBuild channel apply: channel "' . self::CHANNEL . '" declared ' . count($flows)
					. ' items but the bound is ' . self::MAX_FLOWS . ' — the excess was NOT applied.'
				);
				$report->recordTruncated(channel: self::CHANNEL, item: $item);
				continue;
			}

			$applied++;

			if (isset($existingSourceUuids[$sourceUuid]) === true) {
				$report->recordSkipped(channel: self::CHANNEL, item: $item, reason: ChannelApplyReport::REASON_EXISTS);
				continue;
			}

			$binding = $this->createOne(sourceUuid: $sourceUuid, blob: (array)$blob, item: $item, report: $report);
			if ($binding !== null) {
				$newBindings[] = $binding;
			}
		}//end foreach

		return $newBindings;
	}//end applyEach()

	/**
	 * Create one flow via the sanctioned entry point.
	 *
	 * @param string $sourceUuid The uuid the flow was published under.
	 * @param array<string,mixed> $blob The published flow definition.
	 * @param string $item The report item identity.
	 * @param ChannelApplyReport $report The report to write into.
	 *
	 * @return array<string,mixed>|null The new binding to add, or null on failure.
	 */
	private function createOne(string $sourceUuid, array $blob, string $item, ChannelApplyReport $report): ?array {
		try {
			$data = $blob;
			$data['app'] = self::OWNING_APP;
			// The published `uuid` travels in the blob for readability, but
			// `FlowService::save()` never reads a `uuid` key — a fresh one is
			// always minted on create (see class docblock). Left in place
			// rather than unset: passing it through is harmless and keeps this
			// call symmetric with the published shape.
			$created = $this->flowService->save(data: $data);

			$report->recordCreated(channel: self::CHANNEL, item: $item);

			return [
				'flow' => (string)$created->getUuid(),
				'sourceUuid' => $sourceUuid,
				'label' => (string)($blob['name'] ?? ''),
			];
		} catch (Throwable $e) {
			$this->logger->warning('OpenBuild channel apply: flow "' . $sourceUuid . '" could not be created: ' . $e->getMessage());
			$report->recordFailed(channel: self::CHANNEL, item: $item, reason: $e->getMessage());
			return null;
		}
	}//end createOne()

	/**
	 * Load the local Application object by uuid.
	 *
	 * @param string $uuid The Application uuid.
	 *
	 * @return array<string,mixed>|null The object payload, or null when absent.
	 */
	private function loadApplication(string $uuid): ?array {
		try {
			$found = $this->objectService->find(
				id: $uuid,
				register: self::REGISTER_SLUG,
				schema: self::APPLICATION_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('OpenBuild channel apply: local application "' . $uuid . '" could not be loaded: ' . $e->getMessage());
			return null;
		}

		if ($found === null) {
			return null;
		}

		return $found->getObject();
	}//end loadApplication()

	/**
	 * The `sourceUuid` values already bound on the Application, so a repeat
	 * apply of the same published repository is idempotent.
	 *
	 * @param array<string,mixed> $application The Application object.
	 *
	 * @return array<string,bool> A set of already-bound source uuids.
	 */
	private function existingSourceUuids(array $application): array {
		$out = [];
		foreach ((array)($application['flows'] ?? []) as $binding) {
			if (is_array($binding) === false) {
				continue;
			}

			$sourceUuid = (string)($binding['sourceUuid'] ?? '');
			if ($sourceUuid !== '') {
				$out[$sourceUuid] = true;
			}
		}

		return $out;
	}//end existingSourceUuids()

	/**
	 * Write the newly created bindings onto the local Application.
	 *
	 * `saveObject()` is PUT-semantic (see `HybridMetadataLockListener`'s own
	 * documented lesson on this exact call) — a partial payload WIPES the
	 * fields it omits rather than merging — so the FULL, freshly loaded
	 * Application payload is sent back with only `flows` mutated.
	 *
	 * @param string $applicationUuid The Application uuid.
	 * @param array<string,mixed> $application The Application payload, freshly loaded.
	 * @param array<int,array<string,mixed>> $newBindings The bindings to append.
	 *
	 * @return void
	 */
	private function rebindApplication(string $applicationUuid, array $application, array $newBindings): void {
		$application['flows'] = array_merge((array)($application['flows'] ?? []), $newBindings);

		try {
			$this->objectService->saveObject(
				object: $application,
				register: self::REGISTER_SLUG,
				schema: self::APPLICATION_SCHEMA,
				uuid: $applicationUuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			// The flows themselves are already created and already reported as
			// `created` — OpenRegister offers no cross-object transaction (see
			// AppChannelApplier's class docblock), so a failure here leaves them
			// unbound rather than costing them their own outcome. Logged loudly
			// because an unbound flow is otherwise invisible: it exists, runs
			// nothing wired to it, and nothing in the report says so.
			$this->logger->error(
				'OpenBuild channel apply: created ' . count($newBindings)
				. ' flow(s) but could not rebind them onto application "' . $applicationUuid . '": ' . $e->getMessage()
			);
		}
	}//end rebindApplication()
}//end class
