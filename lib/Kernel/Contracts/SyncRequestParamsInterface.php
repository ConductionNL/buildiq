<?php

/**
 * Contract of SyncRequestParams
 *
 * The public surface of SyncRequestParams that other components call, so they depend
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

use OCA\Buildiq\Kernel\Values\AppPushRequest;
use OCA\Buildiq\Kernel\Values\BrokeredRepo;

/**
 * Contract of SyncRequestParams.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SyncRequestParamsInterface {

	/**
	 * Safe GitHub owner/repo/org pattern.
	 */
	public const OWNER_REPO_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

	/**
	 * The optional `credentialId` param.
	 *
	 * @return string|null The credential id, or null when absent or empty.
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function credential(): ?string;

	/**
	 * What a push asks for.
	 *
	 * @param string $credentialId The validated credential.
	 * @param string|null $actingUserId The session UID (broker owner-guard identity).
	 *
	 * @return AppPushRequest
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function pushRequest(string $credentialId, ?string $actingUserId): AppPushRequest;

	/**
	 * The repository a link names, from the `owner` and `name` params, with an
	 * empty credential when the request names none.
	 *
	 * @param string|null $actingUserId The session UID (broker identity).
	 *
	 * @return BrokeredRepo
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function linkRepo(?string $actingUserId): BrokeredRepo;
}//end interface
