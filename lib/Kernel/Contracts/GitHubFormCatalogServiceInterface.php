<?php

/**
 * Contract of GitHubFormCatalogService
 *
 * The public surface of GitHubFormCatalogService that other components call, so they depend
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

/**
 * Contract of GitHubFormCatalogService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GitHubFormCatalogServiceInterface {

	/**
	 * Search GitHub for shared forms: repositories with the `buildiq-form`
	 * topic and a `form.json` at their root holding a form export envelope
	 * (REQ-BQGL-005). Cached like the app search, under its own key.
	 *
	 * A repository whose form.json is missing or is not a form export is
	 * listed as not installable rather than hidden, so a publisher can see
	 * why their form does not install.
	 *
	 * @param string|null $query Free-text search, added to the topic.
	 * @param string|null $actingUserId The signed-in user.
	 * @param string|null $credentialId An OpenRegister broker credential, if any.
	 *
	 * @return array{outcome: string, cards: array<int, array<string, mixed>>, brokerUsed: bool, rateLimited: bool}
	 *
	 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
	 */
	public function searchForms(?string $query, ?string $actingUserId, ?string $credentialId = null): array;
}//end interface
