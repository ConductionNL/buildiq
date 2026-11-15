<?php

/**
 * Contract of VirtualAppCredentialRegistrar
 *
 * The public surface of VirtualAppCredentialRegistrar that other components call, so they depend
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
 * Contract of VirtualAppCredentialRegistrar.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VirtualAppCredentialRegistrarInterface {

	/**
	 * Onboard a just-published virtual app to the credential broker when its manifest declares credentials.
	 *
	 * Never throws: any failure (manifest unresolvable, broker/Doriath absent,
	 * registration error) is logged and swallowed so the publish that triggered
	 * it is never blocked. A no-op when the manifest declares no `credentials[]`.
	 *
	 * @param string $slug The virtual app slug (its app-id becomes `openbuild-{slug}`).
	 * @param IUser|null $caller The publishing user (forwarded to the manifest resolver).
	 *
	 * @return void
	 *
	 * @spec openregister/openspec/changes/per-app-doriath-application/specs/credential-broker/spec.md#per-app-doriath-application-registration
	 *
	 * @spec openspec/specs/app-channel-application/spec.md
	 */
	public function onPublish(string $slug, ?IUser $caller): void;
}//end interface
