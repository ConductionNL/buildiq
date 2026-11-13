<?php

/**
 * Contract of ManifestResolverService
 *
 * The public surface of ManifestResolverService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Buildiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Contracts;

use OCP\IUser;

/**
 * Contract of ManifestResolverService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ManifestResolverServiceInterface {

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
	public function filterManifestForCaller(array $manifest, array $application, ?IUser $caller): array;

	/**
	 * The Application with this slug, or null.
	 *
	 * @param string $appSlug The virtual-app slug.
	 *
	 * @return array<string, mixed>|null The Application, normalised.
	 *
	 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-a-draft-form-saves-with-its-findings
	 */
	public function findApplication(string $appSlug): ?array;

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
	public function resolve(string $appSlug, ?string $versionSlug, ?IUser $caller): ?array;

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
	public function resolveCallerPermissionsForDisplay(array $application, ?IUser $caller): array;

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
	public function resolveVersionForCaller(string $appSlug, ?string $versionSlug, ?IUser $caller): ?array;
}//end interface
