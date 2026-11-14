<?php

/**
 * Buildiq VersionMergeRunner
 *
 * Previews and applies the merge of one version into another
 * (lifecycle-git-branches, REQ-BQGB-003 / REQ-BQGB-004). The target must be
 * a draft and not the production version. A preview returns the merged state,
 * the conflicts and the target's manifest hash; an apply refuses a target whose
 * hash moved since, an unchosen conflict and an unconfirmed breaking schema
 * change, and then writes the schemas, the manifest (through the save hook, so
 * the semver moves as with any edit) and the target's new `branchBase`.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Branch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-git-branches/tasks.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Branch;

use InvalidArgumentException;
use OCA\Buildiq\Kernel\Contracts\ApplicationVersionServiceInterface;
use OCA\Buildiq\Service\ApplicationVersionService;
use OCA\Buildiq\Service\VersionMergeService;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Runs a version merge: preview first, write once.
 *
 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-merge-only-writes-a-draft-that-has-not-moved-req-bqgb-004
 */
class VersionMergeRunner {

	/**
	 * Constructor.
	 *
	 * @param VersionMergeService       $merger   The three-way merge.
	 * @param VersionState              $state    Reads and restores the neutral states.
	 * @param VersionSchemaWriter       $schemas  Saves the merged schemas.
	 * @param VersionBranchService      $branches Builds the target's new `branchBase`.
	 * @param ApplicationVersionService $versions Hashes manifests and stamps the semver.
	 * @param ObjectServiceInterface    $objects  Saves the target version.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly VersionMergeService $merger,
		private readonly VersionState $state,
		private readonly VersionSchemaWriter $schemas,
		private readonly VersionBranchService $branches,
		private readonly ApplicationVersionService $versions,
		private readonly ObjectServiceInterface $objects,
	) {
	}//end __construct()

	/**
	 * The merge of the request's source into its target, written nowhere.
	 *
	 * @param array<string,mixed> $application The Application (`productionVersion`).
	 * @param VersionMergeRequest $request     The two versions.
	 *
	 * @return array<string,mixed> `manifest`, `schemas`, `conflicts`, `breaking` and the target's `targetHash`.
	 *
	 * @throws VersionBranchRefused When the target cannot take a merge or the pair has no common base.
	 *
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
	 */
	public function preview(array $application, VersionMergeRequest $request): array {
		$states = $this->states(application: $application, request: $request);
		$merged = $this->merger->merge(base: $states['base'], source: $states['source'], target: $states['target']);

		return $merged + [
			'breaking' => VersionSchemaWriter::breakingChanges(current: $states['target']['schemas'], merged: $merged['schemas']),
			'targetHash' => $this->hashOf(version: $request->target),
		];
	}//end preview()

	/**
	 * Apply the merge with the request's choices and write the target.
	 *
	 * @param array<string,mixed> $application The Application (`productionVersion`).
	 * @param VersionMergeRequest $request     The versions, choices, previewed hash and confirmation.
	 *
	 * @return array<string,mixed> The saved target version.
	 *
	 * @throws VersionBranchRefused When anything would make the write wrong; nothing is written then.
	 *
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-merge-only-writes-a-draft-that-has-not-moved-req-bqgb-004
	 */
	public function apply(array $application, VersionMergeRequest $request): array {
		$states = $this->states(application: $application, request: $request);
		if ($request->targetHash !== $this->hashOf(version: $request->target)) {
			throw new VersionBranchRefused(errorCode: 'target_changed', detail: 'The target version changed after the preview. Preview the merge again.');
		}

		try {
			$merged = $this->merger->apply(base: $states['base'], source: $states['source'], target: $states['target'], choices: $request->choices);
		} catch (InvalidArgumentException $e) {
			throw new VersionBranchRefused(errorCode: 'unresolved_conflicts', detail: $e->getMessage());
		}

		$breaking = VersionSchemaWriter::breakingChanges(current: $states['target']['schemas'], merged: $merged['schemas']);
		if ($breaking !== [] && $request->acknowledgeBreaking === false) {
			throw new VersionBranchRefused(errorCode: 'breaking_change', detail: 'The merge makes a breaking schema change: ' . implode(' ', $breaking));
		}

		$this->schemas->write(version: $request->target, schemas: $merged['schemas']);

		return $this->saveTarget(request: $request, manifest: $merged['manifest'], source: $states['source']);
	}//end apply()

	/**
	 * Save the target's merged manifest and its new `branchBase`.
	 *
	 * @param VersionMergeRequest                                                $request  The merge.
	 * @param array<string,mixed>                                                $manifest The neutral merged manifest.
	 * @param array{manifest: array<string,mixed>, schemas: array<string,mixed>} $source   The source's neutral state.
	 *
	 * @return array<string,mixed>
	 */
	private function saveTarget(VersionMergeRequest $request, array $manifest, array $source): array {
		$current = $request->target;
		$next    = $current;
		unset($next['@self']);
		$next['manifest']   = $this->state->restore(manifest: $manifest, version: $current);
		$next['branchBase'] = $this->branches->baseOf(source: $request->source, snapshot: $source);
		$uuid = (string)($current['id'] ?? $current['uuid'] ?? '');

		return ObjectRecord::toArray(
			object: $this->objects->saveObject(
				object: $this->versions->onSave(current: $current, next: $next),
				register: ApplicationVersionServiceInterface::REGISTER_SLUG,
				schema: ApplicationVersionServiceInterface::APPLICATION_VERSION_SCHEMA,
				uuid: $uuid
			)
		);
	}//end saveTarget()

	/**
	 * The base, source and target states of an allowed merge.
	 *
	 * @param array<string,mixed> $application The Application.
	 * @param VersionMergeRequest $request     The two versions.
	 *
	 * @return array{base: array<string,mixed>, source: array<string,mixed>, target: array<string,mixed>}
	 *
	 * @throws VersionBranchRefused When the target is production or not a draft, or there is no common base.
	 */
	private function states(array $application, VersionMergeRequest $request): array {
		self::guardTarget(application: $application, target: $request->target);
		try {
			$base = $this->merger->baseFor(source: $request->source, target: $request->target);
		} catch (InvalidArgumentException $e) {
			throw new VersionBranchRefused(errorCode: 'no_common_base', detail: $e->getMessage(), status: 422);
		}

		return [
			'base' => $base,
			'source' => $this->state->read(version: $request->source),
			'target' => $this->state->read(version: $request->target),
		];
	}//end states()

	/**
	 * Refuse a target that is the production version or not a draft.
	 *
	 * @param array<string,mixed> $application The Application.
	 * @param array<string,mixed> $target      The target version.
	 *
	 * @return void
	 *
	 * @throws VersionBranchRefused When the target cannot take a merge.
	 */
	private static function guardTarget(array $application, array $target): void {
		$uuid = (string)($target['id'] ?? $target['uuid'] ?? '');
		if ($uuid !== '' && $uuid === (string)($application['productionVersion'] ?? '')) {
			throw new VersionBranchRefused(
				errorCode: 'target_is_production',
				detail: 'The production version changes through release and promotion only.',
				status: 422
			);
		}

		if ((string)($target['status'] ?? 'draft') !== 'draft') {
			throw new VersionBranchRefused(errorCode: 'target_not_draft', detail: 'Only a draft version takes a merge.', status: 422);
		}
	}//end guardTarget()

	/**
	 * The hash of a version's manifest as stored.
	 *
	 * @param array<string,mixed> $version The version.
	 *
	 * @return string
	 */
	private function hashOf(array $version): string {
		$manifest = [];
		if (is_array($version['manifest'] ?? null) === true) {
			$manifest = $version['manifest'];
		}

		return $this->versions->hashManifest(manifest: $manifest);
	}//end hashOf()
}//end class
