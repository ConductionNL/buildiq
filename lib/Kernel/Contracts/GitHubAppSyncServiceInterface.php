<?php

/**
 * Contract of GitHubAppSyncService
 *
 * The public surface of GitHubAppSyncService that other components call, so they depend
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

use OCA\Buildiq\Exception\AppRepoParseException;
use OCA\Buildiq\Kernel\Values\AppPushRequest;
use OCA\Buildiq\Kernel\Values\BrokeredRepo;

/**
 * Contract of GitHubAppSyncService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GitHubAppSyncServiceInterface {

	/**
	 * Outcome: the operation succeeded.
	 */
	public const OUTCOME_OK = 'ok';

	/**
	 * Outcome: the credential broker is absent — publish is unavailable.
	 */
	public const OUTCOME_BROKER_UNAVAILABLE = 'broker_unavailable';

	/**
	 * Outcome: the broker denied the call (rules missing / credential not allowed).
	 */
	public const OUTCOME_BROKER_DENIED = 'broker_denied';

	/**
	 * Outcome: the app is not linked to a GitHub repository.
	 */
	public const OUTCOME_NOT_LINKED = 'not_linked';

	/**
	 * Outcome: the remote head moved — the owner must pull first (never force).
	 */
	public const OUTCOME_PUSH_CONFLICT = 'push_conflict';

	/**
	 * Outcome: GitHub / transport failure.
	 */
	public const OUTCOME_UNREACHABLE = 'github_unreachable';

	/**
	 * Outcome: GitHub refused the call on permissions grounds (HTTP 403).
	 *
	 * Distinct from a transport failure. Publishing a generated app needs a
	 * credential carrying the `workflow` permission, because the scaffold always
	 * emits `.github/workflows/*` and GitHub rejects a tree containing those
	 * without it.
	 */
	public const OUTCOME_FORBIDDEN = 'github_forbidden';

	/**
	 * Whether the OpenRegister credential broker is present on this instance.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function isBrokerAvailable(): bool;

	/**
	 * Store the app's GitHub linkage (owner/name + resolved default branch).
	 *
	 * @param array<string,mixed> $application The owner-gated Application object.
	 * @param BrokeredRepo $repo The repo (owner pattern-validated by the controller) and the
	 *                           access that resolves its default branch (empty credential = none).
	 *
	 * @return array{githubRepo:array{owner:string,name:string},githubDefaultBranch:string}
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function link(array $application, BrokeredRepo $repo): array;

	/**
	 * Load an Application object by slug (org-wide; RBAC gating is the caller's job).
	 *
	 * @param string $slug The Application slug.
	 *
	 * @return array<string,mixed>|null The Application object, or null when absent.
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function loadApplicationBySlug(string $slug): ?array;

	/**
	 * Serialize a seeded template and publish it to GitHub as an `buildiq-app`
	 * repo (never-throw). The repo is created (or reused) via the broker, tagged
	 * with the discovery topic, then the template file map is tree-pushed — the
	 * SAME create-repo + topic + Git-Data mechanics `push()` uses, so the result
	 * is installable by AppRepoParser (round-trip).
	 *
	 * @param array<string,mixed> $template The seeded application-template object.
	 * @param BrokeredRepo $repo The org (empty = the credential user's account), repo name
	 *                           (empty = `openbuild-{template-slug}`), visibility and access.
	 *
	 * @return array<string,mixed> `{outcome, ...}` — outcome `ok` carries `repoUrl`/`commitSha`/`branch`.
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function publishTemplate(array $template, BrokeredRepo $repo): array;

	/**
	 * Fetch + strictly parse the linked repo and create a NEW draft version.
	 *
	 * @param array<string,mixed> $application The owner-gated Application object.
	 * @param string $ref The git ref to pull.
	 * @param string|null $credentialId Optional credential (broker path for private repos).
	 * @param string|null $actingUserId The session UID (broker identity).
	 *
	 * @return array<string,mixed> `{outcome, ...}` — outcome `ok` carries the new draft version.
	 *
	 * @throws AppRepoParseException On a strict-parse failure (caller surfaces the code + file).
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function pull(
		array $application,
		string $ref,
		?string $credentialId,
		?string $actingUserId,
	): array;

	/**
	 * Serialize the chosen version and push it through the broker (non-destructive).
	 *
	 * @param array<string,mixed> $application The owner-gated Application object.
	 * @param AppPushRequest $request The version, the broker access and an optional repo to create.
	 *
	 * @return array<string,mixed> `{outcome, ...}` — outcome `ok` carries `commitSha`/`branch`/`repoUrl`.
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function push(array $application, AppPushRequest $request): array;
}//end interface
