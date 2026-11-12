<?php

/**
 * Buildiq Broker Access
 *
 * The credential a GitHub call goes out under: the broker credential UUID and the user
 * who owns it. Buildiq never holds the token behind the credential; OpenRegister's
 * credential broker checks the owner and injects the token server-side.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\GitHub
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\GitHub;

/**
 * A broker credential and its owner.
 *
 * @spec openspec/changes/archive/2026-06-14-openbuild-exporter/tasks.md#task-6.2
 */
final class BrokerAccess {
	/**
	 * Constructor.
	 *
	 * @param string $credentialId Broker credential UUID for a `github` provider
	 *                             credential. Not a token: nothing in Buildiq can
	 *                             read the secret behind it.
	 * @param string|null $actingUserId Credential owner. Required on the background-job
	 *                                  path, where there is no user session for the
	 *                                  broker's ownership guard to read.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $credentialId,
		public readonly ?string $actingUserId = null,
	) {
	}//end __construct()
}//end class
