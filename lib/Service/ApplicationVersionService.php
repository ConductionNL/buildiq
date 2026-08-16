<?php

/**
 * OpenBuild ApplicationVersionService
 *
 * Owns the imperative business logic for the versioned-app model
 * (ADR-002 / openbuild-versioning-model):
 *
 *   - Semver auto-bump on manifest content change (SHA-256 hash diff
 *     over the canonicalised manifest; ADR-031 §Exceptions(2) — stateful
 *     diff outside OR's calc vocabulary).
 *   - `promotesTo` cross-row cycle detection (ADR-031 §Exceptions(1) —
 *     traversal that OR's per-row x-openregister-validation cannot
 *     perform).
 *   - `Application.productionVersion` back-reference integrity guard
 *     (ADR-031 §Exceptions(1) — cross-row).
 *   - Version-deletion strategy branching (`delete-now`,
 *     `orphan-grace`, `keep-register`) — three branching side-effect
 *     chains conditional on a query param, outside the declarative
 *     `on_delete` vocabulary.
 *
 * All persistence flows through OpenRegister abstractions per ADR-022.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-22
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-23
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-25
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-31
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\RegisterService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Imperative business-logic surface for ApplicationVersion (ADR-002).
 *
 * Note: this service intentionally owns only the surface that ADR-031's
 * declarative-vs-imperative table classifies as imperative. Lifecycle
 * transitions, route upserts on publish, and the same-row promotesTo
 * self-loop check are declared in `lib/Settings/openbuild_register.json`.
 */
class ApplicationVersionService {
	/**
	 * Shared register that hosts both Application and ApplicationVersion.
	 */
	public const REGISTER_SLUG = 'openbuild';

	/**
	 * Schema slug of the parent Application object.
	 */
	public const APPLICATION_SCHEMA = 'application';

	/**
	 * Schema slug of the versioned-model ApplicationVersion object.
	 */
	public const APPLICATION_VERSION_SCHEMA = 'applicationVersion';

	/**
	 * Hard cap on the `promotesTo` chain walk in {@see guardNoCycle()}.
	 * Prevents runaway traversal on data corruption (spec REQ-OBV-104).
	 */
	private const CYCLE_GUARD_HOPS = 100;

	/**
	 * Initial semver assigned to a freshly-created ApplicationVersion
	 * (spec REQ-OBV-102).
	 */
	public const INITIAL_SEMVER = '0.1.0';

	/**
	 * Valid strategy values accepted by {@see deleteVersion()}.
	 *
	 * @var array<int,string>
	 */
	private const VALID_STRATEGIES = [
		self::STRATEGY_DELETE_NOW,
		self::STRATEGY_ORPHAN_GRACE,
		self::STRATEGY_KEEP_REGISTER,
	];

	/**
	 * Strategy: drop the per-version register and the ApplicationVersion row.
	 */
	public const STRATEGY_DELETE_NOW = 'delete-now';

	/**
	 * Strategy: mark the per-version register orphaned; drop only the row.
	 */
	public const STRATEGY_ORPHAN_GRACE = 'orphan-grace';

	/**
	 * Strategy: leave the register intact; drop only the row.
	 */
	public const STRATEGY_KEEP_REGISTER = 'keep-register';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR logger for diagnostics
	 * @param ObjectServiceInterface $objectService OpenRegister object service
	 * @param RegisterService $registerService OpenRegister register-level service
	 * @param RegisterMapper $registerMapper Resolves register slugs to entities
	 * @param AutomationCompilerService $automationCompiler Recompiles a cloned automation's
	 *                                                      artifacts into the new version
	 *                                                      (automation-designer design.md Decision 6).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterService $registerService,
		private readonly RegisterMapper $registerMapper,
		private readonly AutomationCompilerService $automationCompiler,
	) {
	}//end __construct()

	/**
	 * Produce a canonical JSON string for the given manifest blob.
	 *
	 * Recursively sorts associative arrays by key so the resulting
	 * string is byte-equal for any reordering of input keys. Encoded
	 * without whitespace and with `JSON_THROW_ON_ERROR` so invalid
	 * structures surface immediately. List arrays (numeric, ordered)
	 * are preserved verbatim — order is part of the manifest's
	 * semantic meaning (pages, menu, columns).
	 *
	 * @param array<string,mixed> $manifest The manifest blob
	 *
	 * @return string Canonical JSON
	 *
	 * @throws \JsonException When the structure contains non-encodable values
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-22
	 */
	public function canonicaliseManifest(array $manifest): string {
		return json_encode(
			$this->canonicaliseValue(value: $manifest),
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}//end canonicaliseManifest()

	/**
	 * Return the SHA-256 hex digest of the canonicalised manifest.
	 *
	 * @param array<string,mixed> $manifest The manifest blob
	 *
	 * @return string 64-char lowercase hexadecimal digest
	 *
	 * @throws \JsonException When the manifest contains non-encodable values
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-22
	 */
	public function hashManifest(array $manifest): string {
		return hash(algo: 'sha256', data: $this->canonicaliseManifest(manifest: $manifest));
	}//end hashManifest()

	/**
	 * Patch-bump a semver string (X.Y.Z → X.Y.(Z+1)), dropping any
	 * pre-release / build-metadata suffix on the way through.
	 *
	 * @param string $semver The current semver string
	 *
	 * @return string The patch-bumped semver
	 *
	 * @throws RuntimeException When the input is not a recognisable semver
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-22
	 */
	public function bumpPatch(string $semver): string {
		$matches = [];
		if (preg_match('/^(\d+)\.(\d+)\.(\d+)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $semver, $matches) !== 1) {
			throw new RuntimeException(message: sprintf('Invalid semver string "%s" — cannot bump.', $semver));
		}

		$major = (int)$matches[1];
		$minor = (int)$matches[2];
		$patch = (int)$matches[3];

		return sprintf('%d.%d.%d', $major, $minor, $patch + 1);
	}//end bumpPatch()

	/**
	 * Apply the semver auto-bump rule (spec REQ-OBV-103) to a pending save.
	 *
	 * Given the previously-persisted state (`$current`, may be null on
	 * create) and the candidate state (`$next` — mutated in place when a
	 * bump is required), this method:
	 *
	 *   - Computes the manifest hash of `$next`.
	 *   - Compares with `$current`'s stored `manifestHash` (mapper-internal
	 *     bookkeeping; not part of the public schema). When different,
	 *     `$next.semver` is patch-bumped and `$next.manifestHash` is set
	 *     to the new hash.
	 *   - On a brand-new row (no `$current`), `$next.semver` defaults to
	 *     `0.1.0` when absent and `$next.manifestHash` is initialised.
	 *
	 * @param array<string,mixed>|null $current The persisted state, or null on create
	 * @param array<string,mixed> $next The candidate next state (mutated in place)
	 *
	 * @return array<string,mixed> The mutated `$next` array
	 *
	 * @throws \JsonException When the manifest cannot be canonicalised
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-22
	 */
	public function onSave(?array $current, array $next): array {
		$manifest = $next['manifest'] ?? null;
		if (is_array($manifest) === false) {
			// No manifest yet — nothing to hash or bump.
			return $next;
		}

		$newHash = $this->hashManifest(manifest: $manifest);

		if ($current === null) {
			// CREATE path — default the initial semver and stamp the hash.
			if (isset($next['semver']) === false || (string)$next['semver'] === '') {
				$next['semver'] = self::INITIAL_SEMVER;
			}

			$next['manifestHash'] = $newHash;
			return $next;
		}

		$oldHash = $current['manifestHash'] ?? null;

		if ((string)$oldHash === (string)$newHash) {
			// Metadata-only edit — preserve the existing semver / hash.
			$next['manifestHash'] = $oldHash;
			if (isset($next['semver']) === false || (string)$next['semver'] === '') {
				$next['semver'] = (string)($current['semver'] ?? self::INITIAL_SEMVER);
			}

			return $next;
		}

		// Manifest content has changed — patch-bump and stamp the new hash.
		$next['semver'] = $this->bumpPatch(semver: (string)($current['semver'] ?? self::INITIAL_SEMVER));
		$next['manifestHash'] = $newHash;
		return $next;
	}//end onSave()

	/**
	 * Reject a `promotesTo` assignment that would form a cycle (spec REQ-OBV-104).
	 *
	 * Walks `promotesTo` forward from the proposed target up to
	 * {@see self::CYCLE_GUARD_HOPS} hops. Throws when the current row's
	 * UUID is encountered along the walk (cycle), when the proposed
	 * target itself equals the current row's UUID (self-loop), or when
	 * the hop cap is exceeded (chain corruption).
	 *
	 * @param string $currentUuid UUID of the row being saved
	 * @param string|null $proposedTargetUuid Proposed `promotesTo` value
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a cycle is detected or the cap is exceeded
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-23
	 */
	public function guardNoCycle(string $currentUuid, ?string $proposedTargetUuid): void {
		if ($proposedTargetUuid === null || $proposedTargetUuid === '') {
			return;
		}

		if ($proposedTargetUuid === $currentUuid) {
			throw new RuntimeException(
				message: sprintf('promotesTo cycle: ApplicationVersion %s cannot promote to itself.', $currentUuid)
			);
		}

		$cursor = $proposedTargetUuid;
		$hops = 0;
		while ($cursor !== null && $cursor !== '') {
			if ($hops >= self::CYCLE_GUARD_HOPS) {
				throw new RuntimeException(
					message: sprintf(
						'promotesTo chain exceeded %d hops starting from %s — chain corrupted, aborting cycle check.',
						self::CYCLE_GUARD_HOPS,
						$proposedTargetUuid
					)
				);
			}

			if ($cursor === $currentUuid) {
				throw new RuntimeException(
					message: sprintf(
						'promotesTo cycle: setting promotesTo on %s would loop back through %s.',
						$currentUuid,
						$proposedTargetUuid
					)
				);
			}

			$hops++;
			$cursor = $this->resolveNextPromotesTo(versionUuid: $cursor);
		}//end while
	}//end guardNoCycle()

	/**
	 * Verify that `Application.productionVersion`'s back-reference is sound (REQ-OBV-105).
	 *
	 * Reads the proposed ApplicationVersion and asserts that its
	 * `application` relation points back at the parent Application's
	 * UUID. Throws otherwise — the caller surfaces a 422 to the client.
	 *
	 * @param string $applicationUuid UUID of the parent Application being saved
	 * @param string $proposedVersionUuid UUID proposed as `productionVersion`
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the back-reference does not point at the parent
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-31
	 */
	public function guardProductionVersionOwnership(string $applicationUuid, string $proposedVersionUuid): void {
		$version = $this->objectService->find(
			id: $proposedVersionUuid,
			register: self::REGISTER_SLUG,
			schema: self::APPLICATION_VERSION_SCHEMA
		);

		if ($version === null) {
			throw new RuntimeException(
				message: sprintf(
					'productionVersion %s does not exist — cannot be assigned to Application %s.',
					$proposedVersionUuid,
					$applicationUuid
				)
			);
		}

		$data = $this->normaliseObjectArray(object: $version);
		$backReference = (string)($data['application'] ?? '');

		if ($backReference !== $applicationUuid) {
			$displayBack = $backReference;
			if ($displayBack === '') {
				$displayBack = '(unset)';
			}

			throw new RuntimeException(
				message: sprintf(
					'productionVersion %s belongs to Application %s, not %s — back-reference mismatch.',
					$proposedVersionUuid,
					$displayBack,
					$applicationUuid
				)
			);
		}
	}//end guardProductionVersionOwnership()

	/**
	 * Release a version: publish it, point production at it, archive the previous
	 * production (spec REQ-OBV-110 / design Decision 3).
	 *
	 * Cross-row imperative glue (ADR-031 §Exceptions(1)): one version's lifecycle
	 * transition + the Application's single-valued pointer + a second version's
	 * demotion, atomic in intent. Ordered pointer-move before demotion so a
	 * mid-failure leaves at most a published-but-not-production version, which a
	 * re-run converges. Never drops or mints a register.
	 *
	 * Steps:
	 *   1. guardProductionVersionOwnership — back-reference must match (else 422).
	 *   2. transition chosen version `draft → published` (saving `status` drives the
	 *      x-openregister-lifecycle transition + BuiltAppRoute upsert, REQ-OBV-106).
	 *   3. set `Application.productionVersion` = chosen version uuid.
	 *   4. archive the previous production version (if any and different) so exactly
	 *      one production version remains (single-production invariant).
	 *
	 * @param string $applicationUuid Parent Application UUID
	 * @param string $versionUuid ApplicationVersion UUID to release
	 *
	 * @return array{productionVersion: string, published: string, archived: string|null}
	 *
	 * @throws RuntimeException On back-reference mismatch or a missing object
	 *
	 * @spec openspec/changes/version-lifecycle-and-switcher/specs/application-versions/spec.md
	 */
	public function releaseVersion(string $applicationUuid, string $versionUuid): array {
		// 1. Back-reference integrity (REQ-OBV-105) — surfaced as 422 by the caller.
		$this->guardProductionVersionOwnership(
			applicationUuid: $applicationUuid,
			proposedVersionUuid: $versionUuid
		);

		$application = $this->objectService->find(
			id: $applicationUuid,
			register: self::REGISTER_SLUG,
			schema: self::APPLICATION_SCHEMA
		);
		if ($application === null) {
			throw new RuntimeException(message: sprintf('Application %s does not exist.', $applicationUuid));
		}

		$applicationData = $this->normaliseObjectArray(object: $application);
		$previousProduction = (string)($applicationData['productionVersion'] ?? '');

		// 2. Publish the chosen version (draft → published) — fires the lifecycle.
		$version = $this->objectService->find(
			id: $versionUuid,
			register: self::REGISTER_SLUG,
			schema: self::APPLICATION_VERSION_SCHEMA
		);
		if ($version === null) {
			throw new RuntimeException(message: sprintf('ApplicationVersion %s does not exist.', $versionUuid));
		}

		$versionData = $this->normaliseObjectArray(object: $version);
		unset($versionData['@self']);
		$versionData['status'] = 'published';
		$this->objectService->saveObject(
			object: $versionData,
			register: self::REGISTER_SLUG,
			schema: self::APPLICATION_VERSION_SCHEMA,
			uuid: $versionUuid
		);

		// 3. Move the single production pointer (single-production invariant).
		unset($applicationData['@self']);
		$applicationData['productionVersion'] = $versionUuid;
		$this->objectService->saveObject(
			object: $applicationData,
			register: self::REGISTER_SLUG,
			schema: self::APPLICATION_SCHEMA,
			uuid: $applicationUuid
		);

		// 4. Demote the previous production. The single-production invariant is
		// already satisfied by the pointer move (step 3); archiving is the
		// cleanup. Only a `published` version can transition to `archived`
		// (x-openregister-lifecycle has no draft→archived edge), so a draft
		// ex-production is demoted by the pointer move alone and left as a
		// draft the maintainer can keep editing or delete.
		$archived = null;
		if ($previousProduction !== '' && $previousProduction !== $versionUuid) {
			$previous = $this->objectService->find(
				id: $previousProduction,
				register: self::REGISTER_SLUG,
				schema: self::APPLICATION_VERSION_SCHEMA
			);
			if ($previous !== null) {
				$previousData = $this->normaliseObjectArray(object: $previous);
				if ((string)($previousData['status'] ?? '') === 'published') {
					unset($previousData['@self']);
					$previousData['status'] = 'archived';
					$this->objectService->saveObject(
						object: $previousData,
						register: self::REGISTER_SLUG,
						schema: self::APPLICATION_VERSION_SCHEMA,
						uuid: $previousProduction
					);
					$archived = $previousProduction;
				}
			}
		}//end if

		$previousLabel = $previousProduction;
		if ($previousProduction === '') {
			$previousLabel = '(none)';
		}

		$archivedLabel = 'unchanged';
		if ($archived !== null) {
			$archivedLabel = 'archived';
		}

		$this->logger->info(
			sprintf(
				'OpenBuild: released ApplicationVersion %s as production for Application %s (previous %s %s).',
				$versionUuid,
				$applicationUuid,
				$previousLabel,
				$archivedLabel
			)
		);

		return [
			'productionVersion' => $versionUuid,
			'published' => $versionUuid,
			'archived' => $archived,
		];
	}//end releaseVersion()

	/**
	 * Delete an ApplicationVersion using the named strategy (spec REQ-OBV-108).
	 *
	 * Branching effect chain:
	 *
	 *   - `delete-now`: drop the per-version register (and every row inside
	 *     it) via {@see RegisterService::delete()}, then delete the
	 *     ApplicationVersion row.
	 *   - `orphan-grace`: mark the per-version register orphaned by writing
	 *     a timestamped flag into its `metadata` array, then delete the
	 *     ApplicationVersion row. A background job (out of scope here)
	 *     prunes registers orphaned for more than 30 days.
	 *   - `keep-register`: leave the register untouched; delete only the
	 *     ApplicationVersion row.
	 *
	 * Rejects deletion of an ApplicationVersion currently pointed at by its
	 * parent Application's `productionVersion`.
	 *
	 * @param string $versionUuid UUID of the ApplicationVersion to delete
	 * @param string $strategy One of the STRATEGY_* constants
	 *
	 * @return void
	 *
	 * @throws RuntimeException On unknown strategy, missing version, or
	 *                          production-version refusal
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-25
	 */
	public function deleteVersion(string $versionUuid, string $strategy): void {
		$this->assertValidStrategy(strategy: $strategy);

		$version = $this->objectService->find(
			id: $versionUuid,
			register: self::REGISTER_SLUG,
			schema: self::APPLICATION_VERSION_SCHEMA
		);

		if ($version === null) {
			throw new RuntimeException(
				message: sprintf('ApplicationVersion %s does not exist — nothing to delete.', $versionUuid)
			);
		}

		$versionData = $this->normaliseObjectArray(object: $version);
		$this->assertNotProductionVersion(versionData: $versionData, versionUuid: $versionUuid);

		$registerSlug = (string)($versionData['register'] ?? '');

		// REQ-OBV-111 / design Decision 2: never drop a register shared with
		// production. When this version's register is the SAME as the parent
		// Application's production version's register (manifest-only case),
		// downgrade `delete-now` to keep-register — dropping a shared register
		// would destroy production data.
		$effectiveStrategy = $strategy;
		if ($strategy === self::STRATEGY_DELETE_NOW
			&& $this->registerSharedWithProduction(versionData: $versionData, registerSlug: $registerSlug) === true
		) {
			$this->logger->warning(
				sprintf(
					'OpenBuild: delete-now on ApplicationVersion %s downgraded to keep-register — register %s is shared with production.',
					$versionUuid,
					$registerSlug
				)
			);
			$effectiveStrategy = self::STRATEGY_KEEP_REGISTER;
		}

		switch ($effectiveStrategy) {
			case self::STRATEGY_DELETE_NOW:
				$this->dropPerVersionRegister(registerSlug: $registerSlug, versionUuid: $versionUuid);
				break;
			case self::STRATEGY_ORPHAN_GRACE:
				$this->flagRegisterOrphaned(registerSlug: $registerSlug, versionUuid: $versionUuid);
				break;
			case self::STRATEGY_KEEP_REGISTER:
				// No-op on the register — admin retains the data.
				$this->logger->info(
					sprintf(
						'OpenBuild: keep-register strategy on ApplicationVersion %s — register %s left untouched.',
						$versionUuid,
						$registerSlug
					)
				);
				break;
		}//end switch

		$this->objectService->deleteObject(uuid: $versionUuid);
	}//end deleteVersion()

	/**
	 * Reject an unknown deletion strategy.
	 *
	 * @param string $strategy Strategy value to validate
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the strategy is not recognised
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-25
	 */
	private function assertValidStrategy(string $strategy): void {
		if (in_array($strategy, self::VALID_STRATEGIES, true) === false) {
			throw new RuntimeException(
				message: sprintf(
					'Unknown deletion strategy "%s" — must be one of: %s',
					$strategy,
					implode(', ', self::VALID_STRATEGIES)
				)
			);
		}
	}//end assertValidStrategy()

	/**
	 * Reject deletion when the version is the parent's production version.
	 *
	 * @param array<string,mixed> $versionData Normalised ApplicationVersion data
	 * @param string $versionUuid The version's UUID
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the row is the parent's productionVersion
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-25
	 */
	private function assertNotProductionVersion(array $versionData, string $versionUuid): void {
		$applicationUuid = (string)($versionData['application'] ?? '');
		if ($applicationUuid === '') {
			return;
		}

		$application = $this->objectService->find(
			id: $applicationUuid,
			register: self::REGISTER_SLUG,
			schema: self::APPLICATION_SCHEMA
		);

		if ($application === null) {
			return;
		}

		$applicationData = $this->normaliseObjectArray(object: $application);
		$productionVersion = (string)($applicationData['productionVersion'] ?? '');
		if ($productionVersion === '' || $productionVersion !== $versionUuid) {
			return;
		}

		throw new RuntimeException(
			message: sprintf(
				'Cannot delete ApplicationVersion %s — it is the production version for Application %s.',
				$versionUuid,
				$applicationUuid
			)
		);
	}//end assertNotProductionVersion()

	/**
	 * Whether the given version's register is shared with production (REQ-OBV-111).
	 *
	 * Returns true when the version's `register` equals the parent Application's
	 * production version's `register` (the manifest-only / shared-register case).
	 * Handles both the UUID-string and inline-object shapes of
	 * `Application.productionVersion`.
	 *
	 * @param array<string,mixed> $versionData Normalised ApplicationVersion data
	 * @param string $registerSlug The version's register slug
	 *
	 * @return bool True when the register is shared with production
	 */
	private function registerSharedWithProduction(array $versionData, string $registerSlug): bool {
		if ($registerSlug === '') {
			return false;
		}

		$applicationUuid = (string)($versionData['application'] ?? '');
		if ($applicationUuid === '') {
			return false;
		}

		$application = $this->objectService->find(
			id: $applicationUuid,
			register: self::REGISTER_SLUG,
			schema: self::APPLICATION_SCHEMA
		);
		if ($application === null) {
			return false;
		}

		$applicationData = $this->normaliseObjectArray(object: $application);
		$productionVersion = ($applicationData['productionVersion'] ?? null);

		$productionRegister = '';
		if (is_array($productionVersion) === true) {
			$productionRegister = (string)($productionVersion['register'] ?? '');
		}

		if (is_array($productionVersion) === false) {
			$productionUuid = (string)($productionVersion ?? '');
			if ($productionUuid !== '') {
				$prod = $this->objectService->find(
					id: $productionUuid,
					register: self::REGISTER_SLUG,
					schema: self::APPLICATION_VERSION_SCHEMA
				);
				if ($prod !== null) {
					$productionRegister = (string)($this->normaliseObjectArray(object: $prod)['register'] ?? '');
				}
			}
		}

		return ($productionRegister !== '' && $productionRegister === $registerSlug);
	}//end registerSharedWithProduction()

	/**
	 * Drop a per-version register entirely (delete-now strategy).
	 *
	 * @param string $registerSlug The OR register slug to drop
	 * @param string $versionUuid The owning ApplicationVersion UUID (diagnostics)
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-25
	 */
	private function dropPerVersionRegister(string $registerSlug, string $versionUuid): void {
		if ($registerSlug === '') {
			$this->logger->warning(
				sprintf(
					'OpenBuild: ApplicationVersion %s has no register slug; nothing to drop.',
					$versionUuid
				)
			);
			return;
		}

		try {
			$register = $this->registerMapper->find($registerSlug, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf(
					'OpenBuild: register %s not found while deleting ApplicationVersion %s (%s) — continuing.',
					$registerSlug,
					$versionUuid,
					$e->getMessage()
				)
			);
			return;
		}

		$this->registerService->delete(register: $register);
		$this->logger->info(
			sprintf(
				'OpenBuild: dropped per-version register %s for ApplicationVersion %s.',
				$registerSlug,
				$versionUuid
			)
		);
	}//end dropPerVersionRegister()

	/**
	 * Mark a per-version register as orphaned (orphan-grace strategy).
	 *
	 * Writes an `orphanedAt` ISO 8601 timestamp into the Register's
	 * `metadata` JSON column via RegisterMapper::update(). A background
	 * job (out of scope for this spec) prunes registers orphaned for
	 * more than 30 days.
	 *
	 * @param string $registerSlug The OR register slug to flag
	 * @param string $versionUuid The owning ApplicationVersion UUID (diagnostics)
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-25
	 */
	private function flagRegisterOrphaned(string $registerSlug, string $versionUuid): void {
		if ($registerSlug === '') {
			$this->logger->warning(
				sprintf(
					'OpenBuild: ApplicationVersion %s has no register slug; nothing to orphan-flag.',
					$versionUuid
				)
			);
			return;
		}

		try {
			$register = $this->registerMapper->find($registerSlug, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf(
					'OpenBuild: register %s not found while orphan-flagging for ApplicationVersion %s (%s).',
					$registerSlug,
					$versionUuid,
					$e->getMessage()
				)
			);
			return;
		}

		$metadata = [];
		if (method_exists($register, 'getMetadata') === true) {
			$current = $register->getMetadata();
			if (is_array($current) === true) {
				$metadata = $current;
			}
		}

		$metadata['orphanedAt'] = gmdate(format: 'Y-m-d\TH:i:s\Z');

		if (method_exists($register, 'setMetadata') === true) {
			$register->setMetadata($metadata);
			$this->registerMapper->update($register);
			$this->logger->info(
				sprintf(
					'OpenBuild: orphan-flagged register %s for ApplicationVersion %s at %s.',
					$registerSlug,
					$versionUuid,
					$metadata['orphanedAt']
				)
			);
			return;
		}

		$this->logger->warning(
			sprintf(
				'OpenBuild: Register entity for %s has no setMetadata; falling back to PSR-logged orphan event for %s.',
				$registerSlug,
				$versionUuid
			)
		);
	}//end flagRegisterOrphaned()

	/**
	 * Read the `promotesTo` UUID of one ApplicationVersion row (helper for cycle walk).
	 *
	 * Returns null when the row does not exist or has no `promotesTo`,
	 * which terminates the walk in {@see guardNoCycle()}.
	 *
	 * @param string $versionUuid UUID of the version row to inspect
	 *
	 * @return string|null Next UUID in the chain, or null on terminal/missing
	 */
	private function resolveNextPromotesTo(string $versionUuid): ?string {
		try {
			$entity = $this->objectService->find(
				id: $versionUuid,
				register: self::REGISTER_SLUG,
				schema: self::APPLICATION_VERSION_SCHEMA
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				sprintf('OpenBuild: cycle-check lookup for %s failed (%s) — treating as terminal.', $versionUuid, $e->getMessage())
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		$data = $this->normaliseObjectArray(object: $entity);
		$next = $data['promotesTo'] ?? null;
		if (is_string($next) === true && $next !== '') {
			return $next;
		}

		return null;
	}//end resolveNextPromotesTo()

	/**
	 * Coerce an OR result entry (ObjectEntity or array) to a plain associative array.
	 *
	 * @param mixed $object The OR object/result entry
	 *
	 * @return array<string,mixed>
	 */
	private function normaliseObjectArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$inner = $object->getObject();
			if (is_array($inner) === true) {
				return $inner;
			}
		}

		return [];
	}//end normaliseObjectArray()

	/**
	 * Recursively canonicalise a value for stable JSON serialisation.
	 *
	 * Associative arrays are sorted by key; sequential (list) arrays
	 * are preserved in order; scalars pass through unchanged.
	 *
	 * @param mixed $value The value to canonicalise
	 *
	 * @return mixed The canonicalised value
	 */
	private function canonicaliseValue(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		// Detect list vs assoc-array. array_is_list() is PHP 8.1+, available per composer.json.
		if (array_is_list($value) === true) {
			return array_map(fn ($item): mixed => $this->canonicaliseValue(value: $item), $value);
		}

		ksort($value);
		$out = [];
		foreach ($value as $key => $entry) {
			$out[$key] = $this->canonicaliseValue(value: $entry);
		}

		return $out;
	}//end canonicaliseValue()

	/**
	 * Clone every `automation` object belonging to a source ApplicationVersion
	 * onto a newly-created version (automation-designer design.md Decision 6).
	 *
	 * Each clone gets a fresh object uuid and is recompiled from scratch
	 * (fresh `aut-<uuid8>` rule-set slug when it compiles to the rules
	 * backend) so `promotesTo` chain members never share a mutable compiled
	 * artifact. A per-automation failure is logged and skipped — it must not
	 * abort the rest of the clone batch or the version-creation flow itself.
	 *
	 * @param string $applicationSlug The owning Application's slug.
	 * @param string $sourceVersionUuid The ApplicationVersion being branched from.
	 * @param string $newVersionUuid The freshly-created ApplicationVersion's uuid.
	 *
	 * @return int The number of automations successfully cloned.
	 *
	 * @spec openspec/changes/automation-designer/tasks.md#2.6
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-009
	 */
	public function cloneAutomationsToVersion(string $applicationSlug, string $sourceVersionUuid, string $newVersionUuid): int {
		if ($sourceVersionUuid === '' || $newVersionUuid === '' || $sourceVersionUuid === $newVersionUuid) {
			return 0;
		}

		$automations = $this->findAutomationsForVersion(applicationSlug: $applicationSlug, versionUuid: $sourceVersionUuid);

		$cloned = 0;
		foreach ($automations as $source) {
			try {
				$this->cloneOneAutomation(source: $source, newVersionUuid: $newVersionUuid);
				$cloned++;
			} catch (Throwable $e) {
				$sourceSlug = (string)($source['slug'] ?? '');
				$this->logger->error(
					'OpenBuild: failed to clone automation "' . $sourceSlug . '" onto new version ' . $newVersionUuid . ': ' . $e->getMessage()
				);
			}
		}

		return $cloned;
	}//end cloneAutomationsToVersion()

	/**
	 * Find every `automation` object for an Application scoped to one version.
	 *
	 * @param string $applicationSlug The owning Application's slug.
	 * @param string $versionUuid The ApplicationVersion uuid to filter on.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAutomationsForVersion(string $applicationSlug, string $versionUuid): array {
		try {
			$results = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => self::REGISTER_SLUG,
						'schema' => 'automation',
						'applicationSlug' => $applicationSlug,
						'versionUuid' => $versionUuid,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('OpenBuild: cloneAutomationsToVersion lookup failed: ' . $e->getMessage());
			return [];
		}

		$normalised = [];
		foreach ($results as $row) {
			$normalised[] = $this->normaliseObjectArray(object: $row);
		}

		return $normalised;
	}//end findAutomationsForVersion()

	/**
	 * Clone one automation object onto the new version and recompile it there.
	 *
	 * @param array<string,mixed> $source The source automation object.
	 * @param string $newVersionUuid The target ApplicationVersion uuid.
	 *
	 * @return void
	 */
	private function cloneOneAutomation(array $source, string $newVersionUuid): void {
		$payload = $source;
		unset($payload['id'], $payload['uuid'], $payload['@self'], $payload['provenance']);
		$payload['versionUuid'] = $newVersionUuid;

		$created = $this->objectService->saveObject(object: $payload, register: self::REGISTER_SLUG, schema: 'automation');
		$createdArray = $this->normaliseObjectArray(object: $created);

		// Prefer the entity's own uuid (NC Entity's magic getUuid() — not
		// detectable via method_exists(), same reasoning as
		// JobOwnerImpersonator::impersonate()) since jsonSerialize()/getObject()
		// do not reliably merge the uuid into an `id` key on every OR entity shape.
		$newUuid = '';
		if (is_object($created) === true) {
			try {
				$newUuid = (string)$created->getUuid();
			} catch (Throwable $e) {
				$newUuid = '';
			}
		}

		if ($newUuid === '') {
			$newUuid = (string)($createdArray['id'] ?? $createdArray['uuid'] ?? '');
		}

		if ($newUuid === '') {
			$sourceSlug = (string)($source['slug'] ?? '');
			$this->logger->warning(
				'OpenBuild: cloned automation "' . $sourceSlug . '" did not yield a new uuid — skipping recompile.'
			);
			return;
		}

		$createdArray['id'] = $newUuid;

		$plan = $this->automationCompiler->compile(automation: $createdArray);
		$provenance = $this->automationCompiler->apply(automation: $createdArray, plan: $plan, priorProvenance: []);

		$createdArray['provenance'] = $provenance;
		$this->objectService->saveObject(object: $createdArray, register: self::REGISTER_SLUG, schema: 'automation', uuid: $newUuid);
	}//end cloneOneAutomation()

	/**
	 * Describe a Register entity for diagnostic strings.
	 *
	 * Helper used by the parent Application guard listener and tests when
	 * they need a human-readable identifier for a Register; returns an
	 * empty string for a null input so callers can concatenate safely.
	 *
	 * @param Register|null $register The register entity to introspect
	 *
	 * @return string The register's slug, or empty string when unavailable
	 *
	 * @internal Exposed only to internal callers; not part of the public API.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-23
	 */
	public function describeRegister(?Register $register): string {
		if ($register === null) {
			return '';
		}

		return (string)$register->getSlug();
	}//end describeRegister()
}//end class
