<?php

/**
 * Contract of GitHubShopSearch
 *
 * The public surface of GitHubShopSearch that other components call, so they depend
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

use Throwable;

/**
 * Contract of GitHubShopSearch.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GitHubShopSearchInterface {

	/**
	 * The response body for a search the catalogue answered.
	 *
	 * Reports the search's outcome first; the report never changes the body.
	 *
	 * @param array<string,mixed> $result GitHubCatalogService::search()'s answer.
	 *
	 * @return array<string,mixed> `{outcome, cards, brokerCredentialAvailable, brokerUsed, rateLimited}`.
	 *
	 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
	 */
	public function answered(array $result): array;

	/**
	 * The response body for a search the catalogue could not answer.
	 *
	 * Logs the failure and reports the search as unreachable.
	 *
	 * @param Throwable $error What the catalogue threw.
	 *
	 * @return array<string,mixed> `{outcome, cards, brokerCredentialAvailable, rateLimited}`.
	 *
	 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
	 */
	public function failed(Throwable $error): array;
}//end interface
