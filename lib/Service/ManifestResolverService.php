<?php

/**
 * Buildiq ManifestResolverService
 *
 * Encapsulates the two-step slug resolution (Application by slug →
 * ApplicationVersion by application + slug) and the RBAC gate for
 * version-aware manifest endpoint access (spec `buildiq-version-routing`
 * REQ-OBVR-002 / REQ-OBVR-003).
 *
 * Design decisions:
 *   - Default (no versionSlug): returns productionVersion's manifest —
 *     accessible to every authenticated caller, no RBAC check (Decision 2).
 *   - Non-production version: caller MUST appear in permissions.owners or
 *     permissions.editors on the Application. NC admins are NOT auto-granted
 *     (Decision 7 + REQ-OBVR-003).
 *   - Unknown slug OR unauthorised caller: both return null with the same
 *     404-mapped null to prevent version-slug enumeration (Decision 8).
 *
 * Per ADR-031 §Exceptions: two-step cross-object lookup + RBAC branch +
 * security-shaped 404-for-auth are all imperative — see design.md
 * Declarative-vs-Imperative table.
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
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-70
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-71
 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-menu-items-and-pages-must-be-filterable-by-permission
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Kernel\Contracts\ManifestResolverServiceInterface;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves application slug + optional version slug to a manifest payload,
 * enforcing RBAC for non-production versions.
 *
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-70
 */
class ManifestResolverService implements ManifestResolverServiceInterface {
	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service (ADR-022)
	 * @param RegisterMapper $registerMapper Register slug-to-ID resolver
	 * @param SchemaMapper $schemaMapper Schema slug-to-ID resolver
	 * @param LoggerInterface $logger PSR logger for diagnostics
	 * @param PermissionResolver $permissionResolver Shared principal-grammar resolver (H1/H2 fix)
	 * @param ManifestWidgetRoleFilter $widgetRoleFilter Removes page widgets by `roles`
	 * @param ManifestDeltaMerger $deltaMerger The keyed merge of a delta over its base (app-delta-override)
	 * @param ManifestBaseResolver|null $baseResolver Reads template and fleet-app bases; null reads none
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
		private readonly PermissionResolver $permissionResolver,
		private readonly ManifestWidgetRoleFilter $widgetRoleFilter,
		private readonly ManifestDeltaMerger $deltaMerger=new ManifestDeltaMerger(),
		private readonly ?ManifestBaseResolver $baseResolver=null,
	) {
	}//end __construct()

	/**
	 * How many built apps a base chain may pass through before resolution stops.
	 *
	 * @var int
	 */
	public const MAX_BASE_DEPTH = 5;

	/**
	 * The built-app slugs whose base is being resolved, outermost first (cycle guard).
	 *
	 * @var list<string>
	 */
	private array $resolvingBases = [];

	/**
	 * What the last delta resolution noticed: orphaned delta paths, an unresolved base, a cycle.
	 *
	 * @var array{orphanedDeltaPaths: list<string>, unresolvedBase: array<string,mixed>|null, cycle: bool}
	 */
	private array $diagnostics = ['orphanedDeltaPaths' => [], 'unresolvedBase' => null, 'cycle' => false];

	/**
	 * Resolve an application slug (+ optional version slug) to the manifest payload.
	 *
	 * Step 1  : look up the Application by slug via ObjectService.
	 * Step 2a : (no versionSlug) return productionVersion's manifest — no RBAC check.
	 * Step 2b : (with versionSlug) look up the ApplicationVersion whose `application`
	 *           relation matches + slug matches. Return null if not found.
	 * Step 3  : RBAC gate for non-production versions. Production version skips gate.
	 *           Unknown or unauthorised both return null (same response — no existence leak).
	 * Step 4  : return the resolved ApplicationVersion's `manifest` payload.
	 *
	 * NOTE on `_version` parameter name: the underscore-prefix form (`_version`) is
	 * Buildiq's system-reserved namespace marker for query parameters. This prevents
	 * collision with user-defined `?version=` params that citizen developers may add
	 * to their virtual apps' routes. Callers of this service pass the string value
	 * of `_version`; the underscore prefix is stripped at the HTTP layer.
	 *
	 * @param string $appSlug The virtual-app slug from the URL.
	 * @param string|null $versionSlug Optional version slug (`?_version=<value>` from the request).
	 * @param IUser|null $caller The authenticated user, or null for unauthenticated.
	 *
	 * @return array<string, mixed>|null The manifest payload, or null → caller maps to 404.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-70
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-71
	 */
	public function resolve(string $appSlug, ?string $versionSlug, ?IUser $caller): ?array {
		// Step 1 — resolve Application by slug.
		$application = $this->findApplicationBySlug(appSlug: $appSlug);
		if ($application === null) {
			$this->logger->debug(
				'ManifestResolverService: Application not found for slug={slug}',
				['slug' => $appSlug]
			);
			return null;
		}

		// Step 2a — no versionSlug: return production manifest (accessible to all).
		if ($versionSlug === null) {
			return $this->resolveProductionManifest(application: $application, appSlug: $appSlug, caller: $caller);
		}

		if ($versionSlug === '') {
			return $this->resolveProductionManifest(application: $application, appSlug: $appSlug, caller: $caller);
		}

		// Step 2b — named versionSlug: look up the ApplicationVersion.
		$version = $this->findVersionBySlug(application: $application, versionSlug: $versionSlug);
		if ($version === null) {
			// No existence leak — return null (caller maps to 404).
			return null;
		}

		// Step 3 — RBAC gate for non-production access (delegates to checkNonProductionAccess).
		$denied = $this->checkNonProductionAccess(
			application: $application,
			version: $version,
			caller: $caller,
			appSlug: $appSlug,
			versionSlug: $versionSlug
		);
		if ($denied === true) {
			return null;
		}

		// Step 4 — return the resolved ApplicationVersion's manifest payload:
		// the blob itself, or its base with its delta merged over it (app-delta-override).
		$manifest = $this->resolveVersionManifest(version: $version, application: $application, caller: $caller);
		if (is_array($manifest) === false) {
			$this->logger->warning(
				'ManifestResolverService: ApplicationVersion has no manifest for app={appSlug} version={versionSlug}',
				['appSlug' => $appSlug, 'versionSlug' => $versionSlug]
			);
			return null;
		}

		return $manifest;
	}//end resolve()

	/**
	 * Apply the RBAC gate for non-production version access.
	 *
	 * Returns true when access is denied (caller maps to 404), false when access
	 * is allowed (either it is the production version, or the caller is authorised).
	 *
	 * NOTE: Nextcloud admins are NOT auto-granted — this is intentional.
	 * See design.md Decision 7 / REQ-OBVR-003.
	 *
	 * @param array<string, mixed> $application The normalised Application data.
	 * @param array<string, mixed> $version The normalised ApplicationVersion data.
	 * @param IUser|null $caller The authenticated user.
	 * @param string $appSlug The app slug (for logging).
	 * @param string $versionSlug The version slug (for logging).
	 *
	 * @return bool True when access is denied; false when access is allowed.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-71
	 */
	private function checkNonProductionAccess(
		array $application,
		array $version,
		?IUser $caller,
		string $appSlug,
		string $versionSlug,
	): bool {
		$prodUuid = $this->extractProductionVersionUuid(application: $application);
		$resolvedUuid = (string)($version['uuid'] ?? $version['id'] ?? '');
		$isProdVersion = $prodUuid !== '' && $resolvedUuid === $prodUuid;

		if ($isProdVersion === true) {
			return false;
		}

		$allowed = $this->isCallerAuthorised(application: $application, caller: $caller);
		if ($allowed === true) {
			return false;
		}

		$callerUid = 'unauthenticated';
		if ($caller !== null) {
			$callerUid = $caller->getUID();
		}

		// Server-side debug log only — MUST NOT be exposed in the HTTP response
		// (security-shaped 404 per Decision 8 / REQ-OBVR-003).
		$this->logger->debug(
			'ManifestResolverService: version_access_denied for caller={callerUid} on app={appSlug} version={versionSlug}',
			[
				'event' => 'version_access_denied',
				'callerUid' => $callerUid,
				'appSlug' => $appSlug,
				'versionSlug' => $versionSlug,
			]
		);
		return true;
	}//end checkNonProductionAccess()

	/**
	 * The Application with this slug, or null.
	 *
	 * @param string $appSlug The virtual-app slug.
	 *
	 * @return array<string, mixed>|null The Application, normalised.
	 *
	 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-a-draft-form-saves-with-its-findings
	 */
	public function findApplication(string $appSlug): ?array {
		return $this->findApplicationBySlug(appSlug: $appSlug);
	}//end findApplication()

	/**
	 * The application and version a signed-in caller runs, by the manifest endpoint's rule.
	 *
	 * No version slug means the production version, which every caller of the
	 * runtime may open; a named version needs the caller to be authorised for it.
	 *
	 * @param string $appSlug The application slug.
	 * @param string|null $versionSlug The version slug, null or empty for production.
	 * @param IUser|null $caller The caller; null is refused.
	 *
	 * @return array{applicationUuid: string, versionUuid: string}|null Null for an unknown app or version, or a caller
	 *                                                                  who may not open it.
	 *
	 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-server-scopes-and-bounds-samples-req-bqdm-005
	 */
	public function resolveVersionForCaller(string $appSlug, ?string $versionSlug, ?IUser $caller): ?array {
		if ($caller === null) {
			return null;
		}

		$application = $this->findApplicationBySlug(appSlug: $appSlug);
		if ($application === null) {
			return null;
		}

		$applicationUuid = (string)($application['uuid'] ?? $application['id'] ?? '');
		if ($versionSlug === null || $versionSlug === '') {
			$versionUuid = $this->extractProductionVersionUuid(application: $application);
			if ($applicationUuid === '' || $versionUuid === '') {
				return null;
			}

			return ['applicationUuid' => $applicationUuid, 'versionUuid' => $versionUuid];
		}

		$version = $this->findVersionBySlug(application: $application, versionSlug: $versionSlug);
		if ($version === null) {
			return null;
		}

		$denied = $this->checkNonProductionAccess(
			application: $application,
			version: $version,
			caller: $caller,
			appSlug: $appSlug,
			versionSlug: $versionSlug
		);
		if ($denied === true) {
			return null;
		}

		return ['applicationUuid' => $applicationUuid, 'versionUuid' => (string)($version['uuid'] ?? $version['id'] ?? '')];
	}//end resolveVersionForCaller()

	/**
	 * Look up the Application object by slug via OR's ObjectService.
	 *
	 * Uses searchObjects with register=openbuild, schema=application, slug={appSlug}.
	 * Returns the first normalised result or null on miss.
	 *
	 * @param string $appSlug The application slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-70
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	private function findApplicationBySlug(string $appSlug): ?array {
		try {
			// Spec decision (L2): _multitenancy: false is used throughout
			// ManifestResolverService so that all register/schema/object lookups
			// operate in the global (non-tenant-isolated) scope.  Buildiq ships
			// virtual apps as a shared-instance service — each Application is owned
			// and accessed by specific NC users, but the Application objects themselves
			// are not partitioned by tenant/organisation.  Cross-tenant slug
			// uniqueness depends on this flag being consistently false across every
			// lookup in this service.  Changing it to true would silo Applications
			// per org and break the shared-registry model.
			$registerId = $this->registerMapper->find(ApplicationVersionService::REGISTER_SLUG, _multitenancy: false)->getId();
			$schemaId = $this->schemaMapper->find('built-app', _multitenancy: false)->getId();

			$results = $this->objectService->searchObjects(
				query: [
					'@self' => [
						'register' => $registerId,
						'schema' => $schemaId,
					],
					'slug' => $appSlug,
				]
			);

			if (empty($results) === true) {
				return null;
			}

			return ObjectRecord::toArray(object: $results[0]);
		} catch (Throwable $e) {
			$this->logger->error(
				'ManifestResolverService: findApplicationBySlug failed for slug={slug}: {message}',
				['slug' => $appSlug, 'message' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end findApplicationBySlug()

	/**
	 * Resolve the production version manifest from Application.productionVersion.
	 *
	 * When productionVersion is a UUID string, fetch the ApplicationVersion by UUID.
	 * When productionVersion is an inline embedded object, use it directly.
	 * Falls back to Application.manifest when no productionVersion is set
	 * (backwards-compat for apps created before spec C landed).
	 *
	 * @param array<string, mixed> $application The normalised Application data.
	 * @param string $appSlug The app slug (for logging).
	 * @param IUser|null $caller The caller, for a built-app base's own version gate.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-70
	 */
	private function resolveProductionManifest(array $application, string $appSlug, ?IUser $caller=null): ?array {
		$productionVersion = ($application['productionVersion'] ?? null);

		// ProductionVersion is a UUID or embedded object.
		$version = null;
		if (is_string($productionVersion) === true && $productionVersion !== '') {
			// UUID reference — fetch the ApplicationVersion.
			$version = $this->findVersionByUuid(uuid: $productionVersion);
		} elseif (is_array($productionVersion) === true) {
			// Inline embedded object — use manifest directly.
			$version = $productionVersion;
		}

		if ($version !== null) {
			$manifest = $this->resolveVersionManifest(version: $version, application: $application, caller: $caller);
			if (is_array($manifest) === true) {
				return $manifest;
			}
		}

		// Backwards-compat fallback: application-level manifest field.
		$manifest = ($application['manifest'] ?? null);
		if (is_array($manifest) === true) {
			$this->logger->debug(
				'ManifestResolverService: falling back to Application.manifest for slug={slug} (no productionVersion)',
				['slug' => $appSlug]
			);
			return $manifest;
		}

		$this->logger->warning(
			'ManifestResolverService: no manifest found for application slug={slug}',
			['slug' => $appSlug]
		);
		return null;
	}//end resolveProductionManifest()

	/**
	 * The manifest an ApplicationVersion serves: its blob, or its base with its delta merged over it.
	 *
	 * A version without a `baseRef` serves its `manifest` blob unchanged (the
	 * legacy branch, byte for byte). A hybrid app's version keeps its existing
	 * contract too: its delta is merged in the browser over the installed fleet
	 * app's manifest, so it is served as stored. Any other version with a
	 * `baseRef` is resolved to its base (a template, another built app, or a
	 * fleet app's bundled manifest) with `manifestDelta` merged over it by
	 * ManifestDeltaMerger. A base that cannot be resolved falls back to the
	 * stored blob as the base, so a derived app stays renderable; what was
	 * skipped is kept in diagnostics(), never in the manifest.
	 *
	 * @param array<string, mixed> $version The ApplicationVersion.
	 * @param array<string, mixed> $application The Application it belongs to.
	 * @param IUser|null $caller The caller; a built-app base pinned to a version passes that app's own gate.
	 *
	 * @return array<string, mixed>|null The manifest, or null when the version has none.
	 *
	 * @throws ManifestResolutionException When the merged manifest is not a renderable manifest.
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-4.1
	 */
	public function resolveVersionManifest(array $version, array $application, ?IUser $caller): ?array {
		$blob = $version['manifest'] ?? null;
		$baseRef = $version['baseRef'] ?? null;
		if (is_array($baseRef) === false || trim((string)($baseRef['kind'] ?? '')) === '' || ($application['appType'] ?? '') === 'hybrid') {
			if (is_array($blob) === true) {
				return $blob;
			}

			return null;
		}

		$this->diagnostics = ['orphanedDeltaPaths' => [], 'unresolvedBase' => null, 'cycle' => false];
		$base = $this->resolveBase(baseRef: $baseRef, caller: $caller);
		if ($base === null) {
			$this->diagnostics['unresolvedBase'] = $baseRef;
			$this->logger->warning(
				'ManifestResolverService: the base {kind}:{id} of a delta-mode version could not be resolved',
				['kind' => (string)($baseRef['kind'] ?? ''), 'id' => (string)($baseRef['id'] ?? '')]
			);
			$base = [];
			if (is_array($blob) === true) {
				$base = $blob;
			}
		}

		$delta = $version['manifestDelta'] ?? [];
		if (is_array($delta) === false) {
			$delta = [];
		}

		$merged = $this->deltaMerger->merge(base: $base, delta: $delta);
		$this->diagnostics['orphanedDeltaPaths'] = $merged['orphanedDeltaPaths'];

		return self::servedManifest(merged: $merged['manifest']);
	}//end resolveVersionManifest()

	/**
	 * What the last delta resolution noticed, for the editor and admin surfaces; never served.
	 *
	 * @return array{orphanedDeltaPaths: list<string>, unresolvedBase: array<string,mixed>|null, cycle: bool}
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-4.2
	 */
	public function diagnostics(): array {
		return $this->diagnostics;
	}//end diagnostics()

	/**
	 * The base manifest a `baseRef` names, or null when it cannot be resolved.
	 *
	 * @param array<string, mixed> $baseRef `{kind: template|buildiq-app|fleet-app, id, version?}`.
	 * @param IUser|null $caller The caller.
	 *
	 * @return array<string, mixed>|null The base manifest.
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
	 */
	private function resolveBase(array $baseRef, ?IUser $caller): ?array {
		$id = trim((string)($baseRef['id'] ?? ''));
		return match ((string)($baseRef['kind'] ?? '')) {
			'template' => $this->baseResolver?->template(slug: $id),
			'fleet-app' => $this->baseResolver?->fleetApp(appId: $id),
			'buildiq-app' => $this->builtAppBase(slug: $id, versionSlug: self::pinnedVersion(baseRef: $baseRef), caller: $caller),
			default => null,
		};
	}//end resolveBase()

	/**
	 * Another built app's resolved manifest as a base, with a depth cap and a cycle guard.
	 *
	 * The base is resolved through resolve(), so a pinned non-production
	 * version passes the same gate as a direct request for it. A chain that
	 * returns to an app already being resolved, or passes MAX_BASE_DEPTH
	 * apps, stops there: that base is unresolved and the chain keeps the
	 * deepest base that did resolve.
	 *
	 * @param string $slug The base app's slug.
	 * @param string|null $versionSlug The pinned version, or null for its production version.
	 * @param IUser|null $caller The caller.
	 *
	 * @return array<string, mixed>|null The base manifest.
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.2
	 */
	private function builtAppBase(string $slug, ?string $versionSlug, ?IUser $caller): ?array {
		if ($slug === '' || in_array($slug, $this->resolvingBases, true) === true || count($this->resolvingBases) >= self::MAX_BASE_DEPTH) {
			$this->diagnostics['cycle'] = true;
			$this->logger->warning(
				'ManifestResolverService: the base chain stops at {slug} (cycle or deeper than {max})',
				['slug' => $slug, 'max' => self::MAX_BASE_DEPTH]
			);
			return null;
		}

		$outer = $this->diagnostics;
		$this->resolvingBases[] = $slug;
		try {
			$base = $this->resolve(appSlug: $slug, versionSlug: $versionSlug, caller: $caller);
		} catch (ManifestResolutionException $e) {
			// An unrenderable base is an unresolved base, not a broken derived app.
			$base = null;
		} finally {
			array_pop($this->resolvingBases);
			// The outer version's diagnostics are the ones reported; an inner cycle still counts.
			$outer['cycle'] = ($outer['cycle'] === true || $this->diagnostics['cycle'] === true);
			$this->diagnostics = $outer;
		}

		return $base;
	}//end builtAppBase()

	/**
	 * The pinned base version of a `baseRef`, or null to follow the live base.
	 *
	 * @param array<string, mixed> $baseRef The base reference.
	 *
	 * @return string|null The version slug.
	 */
	private static function pinnedVersion(array $baseRef): ?string {
		$version = trim((string)($baseRef['version'] ?? ''));
		if ($version === '') {
			return null;
		}

		return $version;
	}//end pinnedVersion()

	/**
	 * The merged manifest as served: never a raw delta key, and renderable.
	 *
	 * @param mixed $merged The merge result.
	 *
	 * @return array<string, mixed> The manifest.
	 *
	 * @throws ManifestResolutionException When it lacks a string `version` or a `pages` array.
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-4.3
	 */
	private static function servedManifest(mixed $merged): array {
		if (is_array($merged) === false || is_string($merged['version'] ?? null) === false || is_array($merged['pages'] ?? null) === false) {
			throw new ManifestResolutionException('The merged manifest is not renderable: it needs a string `version` and a `pages` array.');
		}

		unset($merged['baseRef'], $merged['manifestDelta'], $merged['orphanedDeltaPaths']);

		return $merged;
	}//end servedManifest()

	/**
	 * Look up an ApplicationVersion whose application relation matches the given
	 * Application AND whose slug matches versionSlug.
	 *
	 * Two-step lookup: uses the Application's UUID to filter versions by the
	 * `application` relation + the `slug` field.
	 *
	 * @param array<string, mixed> $application The normalised Application data.
	 * @param string $versionSlug The requested version slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-70
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	private function findVersionBySlug(array $application, string $versionSlug): ?array {
		try {
			$applicationUuid = (string)($application['uuid'] ?? $application['id'] ?? '');
			if ($applicationUuid === '') {
				return null;
			}

			$registerId = $this->registerMapper->find(ApplicationVersionService::REGISTER_SLUG, _multitenancy: false)->getId();
			$schemaId = $this->schemaMapper->find(ApplicationVersionService::APPLICATION_VERSION_SCHEMA, _multitenancy: false)->getId();

			// Two-step: filter ApplicationVersions by parent application UUID + slug.
			// OR compound-filter note: OR's searchObjects supports direct property
			// equality. For the application relation we filter by UUID equality on
			// the `application` field. For installations where OR stores the relation
			// as a nested object, we fall back to PHP-side filtering after fetchAll.
			$results = $this->objectService->searchObjects(
				query: [
					'@self' => [
						'register' => $registerId,
						'schema' => $schemaId,
					],
					'slug' => $versionSlug,
					'application' => $applicationUuid,
				]
			);

			if (empty($results) === false) {
				$version = ObjectRecord::toArray(object: $results[0]);
				// Verify application relation ownership (IDOR guard).
				if ($this->versionBelongsToApplication(version: $version, applicationUuid: $applicationUuid) === true) {
					return $version;
				}
			}

			// Fallback: fetch all versions for this app and match slug in PHP.
			// Required when OR's compound-filter on a relation UUID is not yet
			// stable on this installation (apply-notes workaround).
			return $this->findVersionBySlugFallback(applicationUuid: $applicationUuid, versionSlug: $versionSlug);
		} catch (Throwable $e) {
			$this->logger->error(
				'ManifestResolverService: findVersionBySlug failed: {message}',
				['message' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end findVersionBySlug()

	/**
	 * PHP-side fallback for version-by-slug lookup when OR compound filters
	 * on a relation UUID are not reliable on this installation.
	 *
	 * Fetches all ApplicationVersions for the given application UUID and
	 * returns the first one whose `slug` matches.
	 *
	 * @param string $applicationUuid Parent Application UUID.
	 * @param string $versionSlug The requested version slug.
	 *
	 * @return array<string, mixed>|null
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	private function findVersionBySlugFallback(string $applicationUuid, string $versionSlug): ?array {
		try {
			$registerId = $this->registerMapper->find(ApplicationVersionService::REGISTER_SLUG, _multitenancy: false)->getId();
			$schemaId = $this->schemaMapper->find(ApplicationVersionService::APPLICATION_VERSION_SCHEMA, _multitenancy: false)->getId();

			$allVersions = $this->objectService->searchObjects(
				query: [
					'@self' => [
						'register' => $registerId,
						'schema' => $schemaId,
					],
					'application' => $applicationUuid,
				]
			);

			foreach ($allVersions as $entry) {
				$version = ObjectRecord::toArray(object: $entry);
				if (($version['slug'] ?? '') === $versionSlug
					&& $this->versionBelongsToApplication(version: $version, applicationUuid: $applicationUuid) === true
				) {
					return $version;
				}
			}

			return null;
		} catch (Throwable $e) {
			$this->logger->error(
				'ManifestResolverService: findVersionBySlugFallback failed: {message}',
				['message' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end findVersionBySlugFallback()

	/**
	 * Fetch an ApplicationVersion by its UUID.
	 *
	 * @param string $uuid The ApplicationVersion UUID.
	 *
	 * @return array<string, mixed>|null
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	private function findVersionByUuid(string $uuid): ?array {
		try {
			$version = $this->objectService->find(
				id: $uuid,
				register: 'buildiq',
				schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA
			);

			if ($version === null) {
				return null;
			}

			return ObjectRecord::toArray(object: $version);
		} catch (Throwable $e) {
			$this->logger->debug(
				'ManifestResolverService: findVersionByUuid failed for uuid={uuid}: {message}',
				['uuid' => $uuid, 'message' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end findVersionByUuid()

	/**
	 * Verify that an ApplicationVersion's `application` relation points back at
	 * the expected Application UUID (IDOR guard).
	 *
	 * @param array<string, mixed> $version The normalised ApplicationVersion data.
	 * @param string $applicationUuid The expected parent Application UUID.
	 *
	 * @return bool
	 */
	private function versionBelongsToApplication(array $version, string $applicationUuid): bool {
		$appRelation = ($version['application'] ?? null);

		if (is_string($appRelation) === true) {
			return $appRelation === $applicationUuid;
		}

		if (is_array($appRelation) === true) {
			$relUuid = (string)($appRelation['uuid'] ?? $appRelation['id'] ?? '');
			return $relUuid === $applicationUuid;
		}

		return false;
	}//end versionBelongsToApplication()

	/**
	 * Extract the productionVersion UUID from an Application record.
	 *
	 * Handles both string UUID and inline embedded object shapes.
	 *
	 * @param array<string, mixed> $application The normalised Application data.
	 *
	 * @return string The UUID, or empty string if not determinable.
	 */
	private function extractProductionVersionUuid(array $application): string {
		$productionVersion = ($application['productionVersion'] ?? null);

		if (is_string($productionVersion) === true) {
			return $productionVersion;
		}

		if (is_array($productionVersion) === true) {
			return (string)($productionVersion['uuid'] ?? $productionVersion['id'] ?? '');
		}

		return '';
	}//end extractProductionVersionUuid()

	/**
	 * Check whether the caller is listed in permissions.owners or permissions.editors
	 * on the Application.
	 *
	 * NOTE: Nextcloud admins are NOT auto-granted (Decision 7 / REQ-OBVR-003).
	 * The IGroupManager::isAdmin() check deliberately does NOT exist in this method.
	 *
	 * @param array<string, mixed> $application The normalised Application data.
	 * @param IUser|null $caller The authenticated user.
	 *
	 * @return bool True when the caller is an editor or owner; false otherwise.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-71
	 */
	private function isCallerAuthorised(array $application, ?IUser $caller): bool {
		if ($caller === null) {
			return false;
		}

		return $this->hasWriteRole(application: $application, caller: $caller);
	}//end isCallerAuthorised()

	/**
	 * Filter a resolved manifest's `menu[]` / `pages[]` entries by the caller's
	 * group-scoped `permission` (spec `runtime-group-scoped-access`
	 * REQ-runtime-group-scoped-access-2).
	 *
	 * This is the SERVER-SIDE enforcement point: an out-of-group caller never
	 * receives the gated menu item / page in the manifest payload at all, so
	 * the standalone runtime's vue-router (built entirely from `manifest.pages`
	 * in `src/builder.js::routesFromManifest()`) never registers a route for
	 * it — "not routable" is achieved by the data simply not being present,
	 * not by a client-side route guard that a determined client could bypass.
	 * The frontend ALSO mirrors this filtering via `CnAppNav`'s own
	 * `permissions` prop (defense in depth per design.md Decision 4), but this
	 * method is the authoritative gate.
	 *
	 * Bypass: Nextcloud admins and any caller holding an owner or editor role
	 * on the Application (the existing `permissions.owners` /
	 * `permissions.editors` buckets — the "write role" grouping used
	 * throughout this codebase, e.g. {@see isCallerAuthorised()} and
	 * `ApplicationsController::filterApplicationsByRole()`) see the manifest
	 * completely unfiltered. The spec text names only "admins and application
	 * owners"; this extends the bypass to editors too because an editor who
	 * cannot see a group-gated menu item/page cannot edit it either — a real
	 * functional regression the spec did not anticipate. Documented deviation,
	 * see the PR description.
	 *
	 * Fail-closed: an unauthenticated caller (`null`) is treated as holding NO
	 * permissions — every gated entry is stripped.
	 *
	 * Page widgets that declare `roles` the caller does not match are removed
	 * too, with the same bypass ({@see ManifestWidgetRoleFilter}).
	 *
	 * @param array<string, mixed> $manifest The resolved manifest payload.
	 * @param array<string, mixed> $application The normalised Application data
	 *                                          (source of `permissions`, for the
	 *                                          write-role bypass check).
	 * @param IUser|null $caller The authenticated caller, or null.
	 *
	 * @return array<string, mixed> The manifest, with gated `menu[]` / `pages[]`
	 *                              entries stripped for callers who lack the
	 *                              declared `permission`. A group-scoped
	 *                              dashboard the caller satisfies is promoted to
	 *                              the landing position (first in `pages[]`).
	 *                              Page widgets whose `roles` the caller does
	 *                              not match are removed.
	 *
	 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-menu-items-and-pages-must-be-filterable-by-permission
	 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-a-group-scoped-dashboard-may-be-the-landing-page-for-its-group
	 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-page-widgets-must-be-filterable-by-roles
	 */
	public function filterManifestForCaller(array $manifest, array $application, ?IUser $caller): array {
		if ($caller !== null && $this->permissionResolver->isAdmin($caller) === true) {
			return $manifest;
		}

		if ($caller !== null && $this->hasWriteRole(application: $application, caller: $caller) === true) {
			return $manifest;
		}

		$callerPermissions = [];
		if ($caller !== null) {
			$callerPermissions = $this->buildCallerPermissionSet(caller: $caller);
		}

		if (isset($manifest['menu']) === true && is_array($manifest['menu']) === true) {
			$manifest['menu'] = $this->filterMenuEntries(entries: $manifest['menu'], callerPermissions: $callerPermissions);
		}

		if (isset($manifest['pages']) === true && is_array($manifest['pages']) === true) {
			$pages = $this->filterEntriesByPermission(entries: $manifest['pages'], callerPermissions: $callerPermissions);
			$manifest['pages'] = $this->promoteLandingDashboard(pages: $pages);
		}

		return $this->widgetRoleFilter->filterManifest(manifest: $manifest, caller: $caller);
	}//end filterManifestForCaller()

	/**
	 * Resolve the permission-string array the CLIENT should pass to
	 * `CnAppRoot`'s `permissions` prop (forwarded to `CnAppNav` /
	 * `CnPageRenderer`) so client-side rendering mirrors the server's own
	 * filtering decision (design.md Decision 4, defense in depth — the
	 * server-side {@see filterManifestForCaller()} filter remains the
	 * authoritative gate).
	 *
	 * Returns an EMPTY array for admins and owner/editor write-role holders —
	 * `CnAppNav.passesPermission()` already treats an empty/absent
	 * `permissions` prop as "show everything" (see the component's own
	 * default), which is exactly correct here: {@see filterManifestForCaller()}
	 * sent them the FULL unfiltered manifest, so nothing should be hidden
	 * client-side either. Everyone else gets their real `group:<gid>` set, so
	 * `CnAppNav`'s own filter reaches the identical verdict the server already
	 * enforced.
	 *
	 * @param array<string, mixed> $application The normalised Application data.
	 * @param IUser|null $caller The authenticated caller, or null.
	 *
	 * @return array<int, string> Permission strings for the `permissions` prop.
	 *
	 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-the-runtime-must-inject-the-current-user-s-group-context
	 */
	public function resolveCallerPermissionsForDisplay(array $application, ?IUser $caller): array {
		if ($caller === null) {
			return [];
		}

		if ($this->permissionResolver->isAdmin($caller) === true) {
			return [];
		}

		if ($this->hasWriteRole(application: $application, caller: $caller) === true) {
			return [];
		}

		return $this->buildCallerPermissionSet(caller: $caller);
	}//end resolveCallerPermissionsForDisplay()

	/**
	 * Whether the caller holds an owner or editor role on the Application —
	 * the "write role" bucket used throughout this codebase to mean "can
	 * manage this app" (see {@see isCallerAuthorised()},
	 * `ApplicationsController::filterApplicationsByRole()`).
	 *
	 * NC admin bypass is deliberately NOT checked here — callers pass through
	 * {@see filterManifestForCaller()}'s own admin check first.
	 *
	 * @param array<string, mixed> $application The normalised Application data.
	 * @param IUser $caller The authenticated caller.
	 *
	 * @return bool True when the caller is an owner or editor.
	 *
	 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-menu-items-and-pages-must-be-filterable-by-permission
	 */
	private function hasWriteRole(array $application, IUser $caller): bool {
		$permissions = ($application['permissions'] ?? []);
		if (is_array($permissions) === false) {
			return false;
		}

		// Use PermissionResolver for consistent grammar (H1/H2 fix). Group
		// membership is resolved via IGroupManager, so `group:` entries count for
		// non-production version access too, matching the HTTP endpoint.
		$userGroups = $this->permissionResolver->resolveUserGroups($caller);

		return $this->permissionResolver->matchesCaller(
			permissions: $permissions,
			caller: $caller,
			userGroups: $userGroups,
			allowAdminBypass: false,
			roles: ['owners', 'editors']
		);
	}//end hasWriteRole()

	/**
	 * Build the caller's permission-string set for `permission`-tag matching:
	 * `group:<gid>` for every Nextcloud group the caller belongs to.
	 *
	 * Deliberately does NOT include an `admin` or `owner` marker — those
	 * callers never reach this method ({@see filterManifestForCaller()}
	 * returns the unfiltered manifest for them before this is called).
	 *
	 * @param IUser $caller The authenticated caller.
	 *
	 * @return array<int, string> Permission strings the caller holds.
	 *
	 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-the-runtime-must-inject-the-current-user-s-group-context
	 */
	private function buildCallerPermissionSet(IUser $caller): array {
		$groups = $this->permissionResolver->resolveUserGroups($caller);

		$permissions = [];
		foreach ($groups as $gid) {
			$permissions[] = 'group:' . $gid;
		}

		return $permissions;
	}//end buildCallerPermissionSet()

	/**
	 * Filter a `menu[]` array by `permission`, recursing one level into each
	 * entry's `children[]` (mirrors `CnAppNav`'s own one-level nesting
	 * support).
	 *
	 * @param array<int, mixed> $entries The manifest `menu[]` array.
	 * @param array<int, string> $callerPermissions The caller's permission strings.
	 *
	 * @return array<int, array<string, mixed>> The filtered menu array.
	 *
	 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-menu-items-and-pages-must-be-filterable-by-permission
	 */
	private function filterMenuEntries(array $entries, array $callerPermissions): array {
		$filtered = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			if ($this->entryPasses(entry: $entry, callerPermissions: $callerPermissions) === false) {
				continue;
			}

			if (isset($entry['children']) === true && is_array($entry['children']) === true) {
				$entry['children'] = $this->filterEntriesByPermission(entries: $entry['children'], callerPermissions: $callerPermissions);
			}

			$filtered[] = $entry;
		}//end foreach

		return $filtered;
	}//end filterMenuEntries()

	/**
	 * Filter an array of entries (menu children or pages) by `permission`,
	 * without recursing further.
	 *
	 * @param array<int, mixed> $entries The entries to filter.
	 * @param array<int, string> $callerPermissions The caller's permission strings.
	 *
	 * @return array<int, array<string, mixed>> The filtered entries, reindexed.
	 *
	 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-menu-items-and-pages-must-be-filterable-by-permission
	 */
	private function filterEntriesByPermission(array $entries, array $callerPermissions): array {
		$filtered = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			if ($this->entryPasses(entry: $entry, callerPermissions: $callerPermissions) === true) {
				$filtered[] = $entry;
			}
		}

		// `$filtered` is only ever appended to with `[]=`, so it is already a
		// list — the array_values() this replaces was a no-op.
		return $filtered;
	}//end filterEntriesByPermission()

	/**
	 * Whether a single menu/page entry's `permission` (string, list, or
	 * absent) is satisfied by the caller's permission set. Multiple
	 * permissions on an item = visible if the caller holds ANY of them (OR
	 * semantics), matching `CnAppNav.passesPermission`. An absent/empty
	 * `permission` always passes (back-compat default-open).
	 *
	 * @param array<string, mixed> $entry The menu item or page entry.
	 * @param array<int, string> $callerPermissions The caller's permission strings.
	 *
	 * @return bool True when the entry is visible to the caller.
	 *
	 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-menu-items-and-pages-must-be-filterable-by-permission
	 */
	private function entryPasses(array $entry, array $callerPermissions): bool {
		$required = ($entry['permission'] ?? null);
		if ($required === null || $required === '' || $required === []) {
			return true;
		}

		$requiredList = $required;
		if (is_array($requiredList) === false) {
			$requiredList = [$requiredList];
		}

		return array_intersect($requiredList, $callerPermissions) !== [];
	}//end entryPasses()

	/**
	 * Promote the landing dashboard: the FIRST already-filtered `pages[]`
	 * entry with `type === 'dashboard'` AND a non-empty `permission` (i.e. a
	 * group-scoped dashboard the caller satisfies — it would not have
	 * survived {@see filterEntriesByPermission()} otherwise) is moved to
	 * index 0, ahead of the default dashboard. `src/builder.js`'s
	 * `routesFromManifest()` treats `pages[0]` as the app's home route, so
	 * this is what actually lands the caller on their group dashboard.
	 *
	 * A caller who does not satisfy any group-scoped dashboard's permission
	 * never has one in the filtered array, so this is a no-op for them — they
	 * keep the manifest author's original page order (default dashboard
	 * first, per REQ "falling back to the default dashboard when none
	 * match").
	 *
	 * @param array<int, array<string, mixed>> $pages The already permission-filtered pages array.
	 *
	 * @return array<int, array<string, mixed>> The pages array, reordered if needed.
	 *
	 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-a-group-scoped-dashboard-may-be-the-landing-page-for-its-group
	 */
	private function promoteLandingDashboard(array $pages): array {
		$landingIndex = null;
		foreach ($pages as $index => $page) {
			$isDashboard = (($page['type'] ?? null) === 'dashboard');
			$permission = ($page['permission'] ?? null);
			$isScoped = ($permission !== null && $permission !== '' && $permission !== []);
			if ($isDashboard === true && $isScoped === true) {
				$landingIndex = $index;
				break;
			}
		}

		if ($landingIndex === null || $landingIndex === 0) {
			return $pages;
		}

		$landing = $pages[$landingIndex];
		unset($pages[$landingIndex]);
		array_unshift($pages, $landing);

		return array_values($pages);
	}//end promoteLandingDashboard()
}//end class
