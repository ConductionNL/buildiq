<?php

/**
 * Contract of AppChannelApplier
 *
 * The public surface of AppChannelApplier that other components call, so they depend
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

use OCA\Buildiq\Kernel\Values\LocalApplicationRef;
use OCA\Buildiq\Kernel\Values\RepoSource;

/**
 * Contract of AppChannelApplier.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AppChannelApplierInterface {

	/**
	 * Apply every channel a parsed template declares.
	 *
	 * Best-effort by design: a channel that cannot be applied is reported, and the
	 * remaining channels are still applied.
	 *
	 * Repo coordinates are derived from the template's own `templateOrigin.repo`
	 * when the caller does not supply them, so that every install path can call
	 * this with what it already has. A path that had to thread extra arguments
	 * through is a path that eventually gets added without them.
	 *
	 * @param array<string,mixed> $template The parsed repo template.
	 * @param RepoSource|null $source Repo coordinates, ref and broker credential for the skills delegation.
	 *                                An empty owner or repo falls back to the template's origin.
	 * @param string|null $actingUserId The session UID.
	 * @param LocalApplicationRef|null $local The LOCAL Application: its uuid lets the flows channel rebind newly
	 *                                        created flows, its slug lets the agents channel tag newly created
	 *                                        agents with THIS instance's slug, never the source instance's. A
	 *                                        missing uuid degrades the flows channel with a stated reason.
	 *
	 * @return array<string,mixed> The channel report.
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-every-install-path-applies-the-v2-channels
	 * @spec openspec/specs/app-channel-application/spec.md#requirement-published-flows-are-created-and-rebound-onto-the-local-application
	 */
	public function apply(
		array $template,
		?RepoSource $source = null,
		?string $actingUserId = null,
		?LocalApplicationRef $local = null,
	): array;
}//end interface
