<?php

/**
 * Contract of PublishedApplicationProvider
 *
 * The public surface of PublishedApplicationProvider that other components call, so they depend
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
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Contracts;

/**
 * Contract of PublishedApplicationProvider.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PublishedApplicationProviderInterface {

	/**
	 * Fetch (and cache per-request) all published Applications from OpenRegister.
	 *
	 * RBAC + multitenancy are disabled here on purpose: this runs during app
	 * boot, where the user session is often NOT yet resolved (OCS navigation
	 * requests, WebDAV, cron). With the default filters the query silently
	 * returned 0 rows on those requests and no nav entries were ever
	 * registered. Per-user visibility is enforced later, by the caller, when
	 * the user IS known (AppNavigationService's entry closure, and
	 * VirtualAppWidget::isEnabled()).
	 *
	 * `productionVersion` is extended in this same query rather than fetched
	 * per application afterwards. The dashboard registrar needs the production
	 * manifest, and a second read per application would be exactly the N+1
	 * this class exists to prevent.
	 *
	 * @return array<array<string,mixed>> List of normalised Application arrays.
	 *
	 * @throws \Throwable When the OpenRegister query fails.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-published-applications-are-read-once-per-request
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function getPublishedApplications(): array;
}//end interface
