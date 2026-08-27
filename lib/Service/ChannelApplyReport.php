<?php

/**
 * Buildiq ChannelApplyReport
 *
 * The outcome record for applying a parsed v2 app repo's channels onto this
 * instance (app-channel-application). OpenRegister offers no cross-object
 * transaction, so applying is best-effort — which makes the report the only thing
 * standing between a partial install and a silent one.
 *
 * Its whole reason for existing is the balance identity
 * `created + skipped + failed === declared`, asserted here rather than only in
 * tests. This programme has already shipped one silent cap (94 skills submitted,
 * 64 bundled, all 94 reported as published), so a count that merely *looks* right
 * is not good enough: an item that was dropped must be arithmetically impossible
 * to represent.
 *
 * Truncated items are recorded as skips carrying `channel-bound-exceeded`, with
 * `truncated` kept alongside as a separate signal. That way exceeding a bound
 * stays inside the identity instead of quietly falling outside it.
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
 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use RuntimeException;

/**
 * Per-channel, per-item outcome record for a channel apply run.
 */
class ChannelApplyReport {

	/**
	 * Outcome for an item that was written to this instance.
	 *
	 * @var string
	 */
	public const OUTCOME_CREATED = 'created';

	/**
	 * Outcome for an item deliberately left alone.
	 *
	 * @var string
	 */
	public const OUTCOME_SKIPPED = 'skipped';

	/**
	 * Outcome for an item whose apply threw.
	 *
	 * @var string
	 */
	public const OUTCOME_FAILED = 'failed';

	/**
	 * Reason recorded when an item already exists locally and is therefore not
	 * touched — connectors are shared infrastructure (see design.md).
	 *
	 * @var string
	 */
	public const REASON_EXISTS = 'already-exists';

	/**
	 * Reason recorded for items dropped because a channel bound was hit.
	 *
	 * @var string
	 */
	public const REASON_TRUNCATED = 'channel-bound-exceeded';

	/**
	 * Per-channel state, keyed by channel name.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $channels = [];

	/**
	 * Credential references that do not resolve on this instance.
	 *
	 * @var array<string,array<int,string>>
	 */
	private array $needsCredentials = [];

	/**
	 * Structured, actionable warnings a caller should surface prominently —
	 * distinct from `needsCredentials`, which is a data-level `credentialRef`
	 * that never resolves. A warning here is about the credential USED FOR
	 * THE REQUEST ITSELF lacking a scope a delegated channel needs.
	 *
	 * @var array<int,array{code:string,channel:string,message:string}>
	 */
	private array $warnings = [];

	/**
	 * Open a channel and fix the number of items it declared.
	 *
	 * The declared count is recorded even when the channel is subsequently
	 * skipped wholesale, because reporting `declared: 0` for a channel that in
	 * fact declared 94 skills is the same lie as dropping them silently.
	 *
	 * @param string $channel The channel name.
	 * @param int $declared How many items the template declared.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-an-absent-optional-dependency-degrades-with-a-stated-reason
	 */
	public function declareChannel(string $channel, int $declared): void {
		$this->channels[$channel] = [
			'declared' => $declared,
			'created' => 0,
			'skipped' => 0,
			'failed' => 0,
			'truncated' => 0,
			'status' => 'applied',
			'reason' => null,
			'items' => [],
		];

	}//end declareChannel()

	/**
	 * Record an item that was written.
	 *
	 * @param string $channel The channel name.
	 * @param string $item The item identity (e.g. `source/<uuid>`).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-application-is-best-effort-with-a-complete-per-item-outcome-report
	 */
	public function recordCreated(string $channel, string $item): void {
		$this->record(channel: $channel, item: $item, outcome: self::OUTCOME_CREATED, reason: null);

	}//end recordCreated()

	/**
	 * Record an item that was deliberately left alone.
	 *
	 * @param string $channel The channel name.
	 * @param string $item The item identity.
	 * @param string $reason Why it was skipped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-application-is-best-effort-with-a-complete-per-item-outcome-report
	 */
	public function recordSkipped(string $channel, string $item, string $reason): void {
		$this->record(channel: $channel, item: $item, outcome: self::OUTCOME_SKIPPED, reason: $reason);

	}//end recordSkipped()

	/**
	 * Record an item whose apply threw. The run continues — one bad item must not
	 * cost the caller the other items it asked for.
	 *
	 * @param string $channel The channel name.
	 * @param string $item The item identity.
	 * @param string $reason The failure reason (never a secret).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-application-is-best-effort-with-a-complete-per-item-outcome-report
	 */
	public function recordFailed(string $channel, string $item, string $reason): void {
		$this->record(channel: $channel, item: $item, outcome: self::OUTCOME_FAILED, reason: $reason);

	}//end recordFailed()

	/**
	 * Record an item dropped because the channel bound was reached.
	 *
	 * Counted as a skip so the balance identity still holds, and additionally
	 * counted as `truncated` so that hitting a bound is visible on its own.
	 *
	 * @param string $channel The channel name.
	 * @param string $item The item identity.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-every-channel-is-bounded-and-truncation-is-reported
	 */
	public function recordTruncated(string $channel, string $item): void {
		$this->record(channel: $channel, item: $item, outcome: self::OUTCOME_SKIPPED, reason: self::REASON_TRUNCATED);
		$this->channels[$channel]['truncated']++;

	}//end recordTruncated()

	/**
	 * Mark a whole channel as not applied — typically because the app that owns
	 * it is not installed. Every declared item is recorded as skipped, so the
	 * caller can still see how much was declared and therefore what is missing.
	 *
	 * @param string $channel The channel name.
	 * @param string $reason Machine-readable reason (e.g. `hermiq-unavailable`).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-an-absent-optional-dependency-degrades-with-a-stated-reason
	 */
	public function skipChannel(string $channel, string $reason): void {
		if (isset($this->channels[$channel]) === false) {
			$this->declareChannel(channel: $channel, declared: 0);
		}

		$this->channels[$channel]['status'] = self::OUTCOME_SKIPPED;
		$this->channels[$channel]['reason'] = $reason;

		$channelState = $this->channels[$channel];
		$outstanding = ($channelState['declared'] - $channelState['created'] - $channelState['skipped'] - $channelState['failed']);

		if ($outstanding > 0) {
			$this->channels[$channel]['skipped'] += $outstanding;
		}

	}//end skipChannel()

	/**
	 * Adopt counts produced by another app (hermiq owns skill installation, so
	 * its numbers are carried through unmodified rather than recomputed here).
	 *
	 * Any shortfall between what we declared and what the source accounted for is
	 * absorbed as a skip with a named cause, rather than left to break the balance
	 * identity. A source that returns fewer outcomes than we sent it items is a
	 * real event with a real explanation — usually its own truncation — and the
	 * report should say which, not throw or quietly disagree with itself.
	 *
	 * @param string $channel The channel name.
	 * @param int $created Items the source installed.
	 * @param int $skipped Items the source skipped.
	 * @param int $failed Items the source failed.
	 * @param bool $truncated Whether the SOURCE truncated its own fetch. A flag,
	 *                        not a count: it knows truncation happened but not
	 *                        how many items it never read.
	 * @param array $sourceCounts The source's own breakdown, carried verbatim so
	 *                            "installed 0, updated 0, unchanged 94" is not
	 *                            flattened into an indistinguishable total.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-skills-are-delegated-to-hermiq-by-repository-coordinates
	 */
	public function adoptCounts(
		string $channel,
		int $created,
		int $skipped,
		int $failed,
		bool $truncated,
		array $sourceCounts = [],
	): void {
		if (isset($this->channels[$channel]) === false) {
			$this->declareChannel(channel: $channel, declared: ($created + $skipped + $failed));
		}

		$this->channels[$channel]['created'] = $created;
		$this->channels[$channel]['skipped'] = $skipped;
		$this->channels[$channel]['failed'] = $failed;

		// The source's OWN breakdown, carried verbatim. `created` here means "the
		// item is present as intended", which for an idempotent source covers
		// installed + updated + unchanged alike — collapsing them would otherwise
		// throw away the distinction between a first install and a no-op re-run.
		if ($sourceCounts !== []) {
			$this->channels[$channel]['sourceCounts'] = $sourceCounts;
		}

		if ($truncated === true) {
			$this->channels[$channel]['reason'] = 'source-bundle-truncated';
		}

		$outstanding = ($this->channels[$channel]['declared'] - $created - $skipped - $failed);
		if ($outstanding > 0) {
			$this->channels[$channel]['skipped'] += $outstanding;
			if ($this->channels[$channel]['reason'] === null) {
				$this->channels[$channel]['reason'] = 'not-accounted-for-by-source';
			}
		}

	}//end adoptCounts()

	/**
	 * Note a credential reference that does not resolve on this instance.
	 *
	 * Publishing blanks secret values but keeps `credentialRef`, so a connector
	 * can install perfectly and still be unable to run. That gap is the
	 * difference between "installed" and "installed and runnable", and it has to
	 * be visible rather than discovered later at first execution.
	 *
	 * @param string $credential The referenced credential name.
	 * @param string $connector The connector that needs it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-unresolvable-credential-references-are-reported
	 */
	public function needsCredential(string $credential, string $connector): void {
		if (isset($this->needsCredentials[$credential]) === false) {
			$this->needsCredentials[$credential] = [];
		}

		if (in_array($connector, $this->needsCredentials[$credential], true) === false) {
			$this->needsCredentials[$credential][] = $connector;
		}
	}//end needsCredential()

	/**
	 * Record a top-level, actionable warning.
	 *
	 * Unlike a per-channel `reason`, a warning is meant to be visible WITHOUT
	 * a caller having to know which channel to look inside — both
	 * `ApplicationsController::installFromTemplateArray()` and
	 * `GitHubAppSyncService::pull()` copy this list onto the top level of
	 * their own response.
	 *
	 * @param string $code Machine-readable warning code (e.g. `credential-missing-hermiq-scope`).
	 * @param string $channel The channel the warning concerns.
	 * @param string $message Human-readable, actionable text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/surface-hermiq-credential-scope-requirement/specs/app-channel-application/spec.md#requirement-application-is-best-effort-with-a-complete-per-item-outcome-report
	 */
	public function addWarning(string $code, string $channel, string $message): void {
		$this->warnings[] = ['code' => $code, 'channel' => $channel, 'message' => $message];

	}//end addWarning()

	/**
	 * Render the report, asserting the balance identity for every channel.
	 *
	 * @return array<string,mixed> The report.
	 *
	 * @throws RuntimeException When a channel does not balance — that means an
	 *                          item was dropped somewhere, which is exactly the
	 *                          class of defect this report exists to expose.
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-application-is-best-effort-with-a-complete-per-item-outcome-report
	 */
	public function toArray(): array {
		$out = [];
		foreach ($this->channels as $name => $channel) {
			$accounted = ($channel['created'] + $channel['skipped'] + $channel['failed']);
			if ($accounted !== $channel['declared']) {
				throw new RuntimeException(
					'Buildiq channel apply: report for channel "' . $name . '" does not balance — declared '
					. $channel['declared'] . ' but accounted for ' . $accounted
					. ' (created ' . $channel['created'] . ', skipped ' . $channel['skipped']
					. ', failed ' . $channel['failed'] . '). An item was dropped.'
				);
			}

			$out[$name] = $channel;
		}

		ksort($this->needsCredentials);

		return [
			'channels' => $out,
			'needsCredentials' => $this->needsCredentials,
			'warnings' => $this->warnings,
		];

	}//end toArray()

	/**
	 * Record one item outcome against a channel.
	 *
	 * @param string $channel The channel name.
	 * @param string $item The item identity.
	 * @param string $outcome One of the OUTCOME_* constants.
	 * @param string|null $reason Optional reason.
	 *
	 * @return void
	 */
	private function record(string $channel, string $item, string $outcome, ?string $reason): void {
		if (isset($this->channels[$channel]) === false) {
			$this->declareChannel(channel: $channel, declared: 0);
		}

		$this->channels[$channel][$outcome]++;
		$this->channels[$channel]['items'][] = [
			'item' => $item,
			'outcome' => $outcome,
			'reason' => $reason,
		];

	}//end record()
}//end class
