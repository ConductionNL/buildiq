<?php

/**
 * Contract of the template install seam
 *
 * The install path that GitHub installs, templates and archive imports share,
 * so the Service component depends on this contract instead of the controller
 * that implements it.
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

use OCA\Buildiq\Kernel\Values\RepoSource;

/**
 * Contract of the template install seam.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TemplateInstallSeamInterface {
	/**
	 * Clone a template array into a new local Application.
	 *
	 * @param array<string,mixed> $template The template record (local or remote payload).
	 * @param string              $name     Human-readable name for the new app.
	 * @param string              $newSlug  The new (kebab-case, pre-validated) app slug.
	 * @param string              $ownerUid The owner UID (becomes the app owner).
	 * @param RepoSource|null     $source   GitHub repo the template was fetched from and the broker credential
	 *                                      used for that fetch, when applicable.
	 *
	 * @return array{status:int,data:array<string,mixed>} 201 + app on success; 409/500/503 on failure.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on ApplicationsController::installFromTemplateArray()
	 */
	public function installFromTemplateArray(
		array $template,
		string $name,
		string $newSlug,
		string $ownerUid,
		?RepoSource $source = null,
	): array;
}//end interface
