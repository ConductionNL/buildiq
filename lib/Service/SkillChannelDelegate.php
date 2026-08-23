<?php

/**
 * Buildiq SkillChannelDelegate
 *
 * Applies a published app repo's `skills/` channel by delegating to hermiq, which
 * owns skill installation and fetches the bundle itself from the repo coordinates.
 *
 * Buildiq deliberately parses no skill frontmatter and places no aux files:
 * byte-fidelity and the ADR-068 §3 rule that `learning-candidates.md` never leaves
 * the instance have to keep living in exactly one implementation.
 *
 * Split out of AppChannelApplier because talking to another app across an optional
 * dependency boundary is a different responsibility from applying OpenRegister
 * channels — and because the applier had grown past the complexity threshold,
 * which is the tooling saying the same thing.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-skills-are-delegated-to-hermiq-by-repository-coordinates
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delegates the skills channel to hermiq.
 */
class SkillChannelDelegate {

	/**
	 * The channel name used in the report.
	 *
	 * @var string
	 */
	private const CHANNEL = 'skills';

	/**
	 * Reason recorded when hermiq is not available.
	 *
	 * @var string
	 */
	private const REASON_NO_HERMIQ = 'hermiq-unavailable';

	/**
	 * The hermiq service that installs a published skill bundle by repo
	 * coordinates. Resolved from the container only when hermiq is enabled, so
	 * hermiq stays an optional dependency.
	 *
	 * @var string
	 */
	private const HERMIQ_INSTALLER = '\OCA\Hermiq\Service\SkillBundleInstaller';

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Optional-dependency detection.
	 * @param ContainerLocator $locator Lazy cross-app service resolution.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerLocator $locator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply the skills channel.
	 *
	 * @param array<string,mixed> $skills The channel (name → path → contents).
	 * @param string $owner Repo owner.
	 * @param string $repo Repo name.
	 * @param string|null $ref Optional git ref.
	 * @param string|null $actingUserId The session UID.
	 * @param string|null $credentialId Optional broker credential UUID.
	 * @param ChannelApplyReport $report The report to write into.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-skills-are-delegated-to-hermiq-by-repository-coordinates
	 */
	public function apply(
		array $skills,
		string $owner,
		string $repo,
		?string $ref,
		?string $actingUserId,
		?string $credentialId,
		ChannelApplyReport $report,
	): void {
		$report->declareChannel(channel: self::CHANNEL, declared: count($skills));

		if ($skills === []) {
			return;
		}

		if ($owner === '' || $repo === '') {
			// Hermiq fetches the bundle itself, so without coordinates there is
			// nothing to delegate. Reported rather than treated as "no skills".
			$report->skipChannel(channel: self::CHANNEL, reason: 'no-repo-coordinates');
			return;
		}

		$installer = $this->installer();
		if ($installer === null) {
			$this->logger->info(
				'Buildiq channel apply: hermiq is not available — skipping ' . count($skills) . ' declared skills.'
			);
			$report->skipChannel(channel: self::CHANNEL, reason: self::REASON_NO_HERMIQ);
			return;
		}

		try {
			$result = $installer->installFromRepo(
				owner: $owner,
				repo: $repo,
				ref: $ref,
				actingUserId: $actingUserId,
				credentialId: $credentialId
			);

			$this->adoptCounts(result: $result, report: $report);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq channel apply: hermiq skill install failed: ' . $e->getMessage());
			$report->skipChannel(channel: self::CHANNEL, reason: 'hermiq-install-failed');
		}//end try

	}//end apply()

	/**
	 * Resolve hermiq's bundle installer, or null when hermiq is not available.
	 *
	 * @return object|null The installer.
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-an-absent-optional-dependency-degrades-with-a-stated-reason
	 */
	private function installer(): ?object {
		if ($this->appManager->isEnabledForUser('hermiq') === false) {
			return null;
		}

		return $this->locator->get(className: self::HERMIQ_INSTALLER);
	}//end installer()

	/**
	 * Carry hermiq's counts into the report.
	 *
	 * "Present as intended" is installed + updated + unchanged. hermiq's installer
	 * is idempotent, so a re-install of a bundle already present reports
	 * `installed: 0, updated: 0, unchanged: 94`. Reading only `installed` would
	 * account for 0 of 94 declared items and report the whole channel as
	 * "not accounted for by source" — a loud failure on a perfectly good run.
	 *
	 * The source's own breakdown is carried through unflattened, so a first
	 * install and a no-op re-run stay distinguishable.
	 *
	 * @param array<string,mixed> $result The installer's response.
	 * @param ChannelApplyReport $report The report to write into.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-skills-are-delegated-to-hermiq-by-repository-coordinates
	 */
	private function adoptCounts(array $result, ChannelApplyReport $report): void {
		$installed = (int)($result['installed'] ?? 0);
		$updated = (int)($result['updated'] ?? 0);
		$unchanged = (int)($result['unchanged'] ?? 0);

		$report->adoptCounts(
			channel: self::CHANNEL,
			created: ($installed + $updated + $unchanged),
			skipped: (int)($result['skipped'] ?? 0),
			failed: (int)($result['failed'] ?? 0),
			truncated: (bool)($result['truncated'] ?? false),
			sourceCounts: [
				'installed' => $installed,
				'updated' => $updated,
				'unchanged' => $unchanged,
			]
		);

	}//end adoptCounts()
}//end class
