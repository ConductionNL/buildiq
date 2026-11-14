<?php

/**
 * Contract of GitHubPushService
 *
 * The public surface of GitHubPushService that other components call, so they depend
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

use OCA\Buildiq\Kernel\Values\BrokerAccess;
use OCA\Buildiq\Kernel\Values\PushTarget;
use RuntimeException;

/**
 * Contract of GitHubPushService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GitHubPushServiceInterface {

	/**
	 * Push the generated tree to a new GitHub repo + open a placeholder PR.
	 *
	 * @param string $jobUuid Export job UUID — correlation key in audit logs.
	 * @param string $treeDir Absolute path to the generated tree on disk.
	 * @param BrokerAccess $access The broker credential and its owner. Not a token:
	 *                             this service cannot read the secret behind it.
	 * @param PushTarget $target The organisation, repository and visibility.
	 *
	 * @return array{repoUrl:string,pullRequestUrl:string,commitSha:string} URLs of repo + PR.
	 *
	 * @throws RuntimeException On any GitHub API failure (auth, repo-exists, …).
	 *
	 * @spec openspec/changes/archive/2026-06-14-openbuild-exporter/tasks.md#task-6.2
	 */
	public function push(string $jobUuid, string $treeDir, BrokerAccess $access, PushTarget $target): array;
}//end interface
