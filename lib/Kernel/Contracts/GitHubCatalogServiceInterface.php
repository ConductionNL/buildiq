<?php

/**
 * Contract of GitHubCatalogService
 *
 * The public surface of GitHubCatalogService that other components call, so they depend
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

use OCA\Buildiq\Service\RepoSource;

/**
 * Contract of GitHubCatalogService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GitHubCatalogServiceInterface {

	/**
	 * Outcome: transport failure / non-2xx that is not a rate limit.
	 */
	public const OUTCOME_UNREACHABLE = 'github_unreachable';

	/**
	 * Fetch the repo file map (`path => contents`) AppRepoParser::parse expects:
	 * `openbuild-app.json`, `manifest.json`, and every `schemas/*.json`.
	 *
	 * @param RepoSource $source Owner, repo and ref (pattern-validated), and the optional allowed `github` credential.
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 *
	 * @return array<string,string> The file map (may be empty when the repo is unreachable).
	 *
	 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
	 */
	public function fetchRepoFiles(RepoSource $source, ?string $actingUserId): array;

	/**
	 * Search GitHub for `topic:buildiq-app` repos and build installable cards.
	 *
	 * @param string|null $query Optional free-text term appended to the topic query.
	 * @param string|null $actingUserId The session UID (broker owner-guard identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential to upgrade the call.
	 *
	 * @return array{outcome:string,cards:array<int,array<string,mixed>>,brokerUsed:bool,rateLimited:bool}
	 *
	 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
	 */
	public function search(?string $query, ?string $actingUserId, ?string $credentialId = null): array;
}//end interface
