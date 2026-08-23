<?php

/**
 * Buildiq AgentChannelProvisioner
 *
 * Applies a published app repo's `agents/` channel: writes each published
 * agent at its published uuid, with the same create-or-skip rules as
 * connectors, but ALWAYS overwriting `applicationSlug` to the LOCAL
 * application's own slug.
 *
 * Split out of `AppChannelApplier` for the same reason `DataRegisterProvisioner`
 * and `SkillChannelDelegate` were: the applier had grown past its complexity
 * threshold, and a dedicated channel deserves a name of its own.
 *
 * WHY THE PUBLISHED UUID IS PRESERVED HERE BUT NOT FOR FLOWS
 * -------------------------------------------------------------
 * An agent has no separate binding to preserve (per
 * `FlowAndAgentExportBundler`'s own docblock: "There is deliberately no
 * `Application.agents`" — `agent.applicationSlug` IS the relationship), so
 * there is nothing to rebind the way {@see FlowChannelProvisioner} rebinds
 * `Application.flows[]`. Writing at the published uuid, exactly like
 * connectors, is therefore both possible (no rebind step depends on a fresh
 * local identity) and correct (identical create-or-skip semantics to every
 * other uuid-addressed channel).
 *
 * WHY applicationSlug IS ALWAYS OVERWRITTEN
 * --------------------------------------------
 * Carrying the source instance's slug across would point the agent at an
 * application that, on this instance, is a different app (or does not exist)
 * — the same class of bug `HybridMetadataLockListener`'s docblock warns
 * against for identity fields crossing a boundary they were not authored for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
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

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Exception\ObjectExistsException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies the agents channel, tagging every agent with the local application's slug.
 *
 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/app-channel-application/spec.md
 */
class AgentChannelProvisioner {

	/**
	 * The channel name used in the report.
	 *
	 * @var string
	 */
	private const CHANNEL = 'agents';

	/**
	 * The register agents live in — the shared Buildiq register, same as the
	 * Application object itself.
	 *
	 * @var string
	 */
	private const AGENT_REGISTER = 'openbuild';

	/**
	 * The agent schema slug.
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * Maximum agents applied from one repo.
	 *
	 * @var int
	 */
	private const MAX_AGENTS = 512;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object read/write.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply the agents channel.
	 *
	 * @param array<string,mixed> $agents The channel (published agent UUID → blob).
	 * @param string|null $applicationSlug The LOCAL application's own slug.
	 * @param ChannelApplyReport $report The report to write into.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/app-channel-application/spec.md#requirement-published-agents-are-tagged-with-the-local-applications-slug
	 */
	public function apply(array $agents, ?string $applicationSlug, ChannelApplyReport $report): void {
		$report->declareChannel(channel: self::CHANNEL, declared: count($agents));

		if ($agents === []) {
			return;
		}

		if ($applicationSlug === null || $applicationSlug === '') {
			$report->skipChannel(channel: self::CHANNEL, reason: 'no-local-application-context');
			return;
		}

		$applied = 0;
		foreach ($agents as $uuid => $blob) {
			$uuid = (string)$uuid;
			$item = self::CHANNEL . '/' . $uuid;

			if ($applied >= self::MAX_AGENTS) {
				$this->logger->warning(
					'Buildiq channel apply: channel "' . self::CHANNEL . '" declared ' . count($agents)
					. ' items but the bound is ' . self::MAX_AGENTS . ' — the excess was NOT applied.'
				);
				$report->recordTruncated(channel: self::CHANNEL, item: $item);
				continue;
			}

			$applied++;
			$this->applyOne(uuid: $uuid, item: $item, blob: (array)$blob, applicationSlug: $applicationSlug, report: $report);
		}
	}//end apply()

	/**
	 * Apply a single agent at its published uuid, tagged with the local
	 * application's slug.
	 *
	 * @param string $uuid The published agent uuid.
	 * @param string $item The report item identity.
	 * @param array<string,mixed> $blob The published agent body.
	 * @param string $applicationSlug The LOCAL application's own slug.
	 * @param ChannelApplyReport $report The report to write into.
	 *
	 * @return void
	 */
	private function applyOne(string $uuid, string $item, array $blob, string $applicationSlug, ChannelApplyReport $report): void {
		$blob['applicationSlug'] = $applicationSlug;

		try {
			$this->objectService->saveObject(
				object: $blob,
				register: self::AGENT_REGISTER,
				schema: self::AGENT_SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false,
				silent: false,
				_validation: true,
				uploadedFiles: null,
				currentUser: null,
				failIfExists: true
			);

			$report->recordCreated(channel: self::CHANNEL, item: $item);
		} catch (ObjectExistsException) {
			$report->recordSkipped(channel: self::CHANNEL, item: $item, reason: ChannelApplyReport::REASON_EXISTS);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq channel apply: agent "' . $item . '" failed: ' . $e->getMessage());
			$report->recordFailed(channel: self::CHANNEL, item: $item, reason: $e->getMessage());
		}//end try
	}//end applyOne()
}//end class
