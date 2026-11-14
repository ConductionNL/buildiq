<?php

/**
 * Contract of AppCatalogue
 *
 * The public surface of AppCatalogue that other components call, so they depend
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
 * Contract of AppCatalogue.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AppCatalogueInterface {

	/**
	 * The app with the given slug, or null.
	 *
	 * @param string $slug The app slug.
	 *
	 * @return array<string,mixed>|null The app.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-delete-an-app-from-the-command-line-req-bqic-007
	 */
	public function app(string $slug): ?array;

	/**
	 * Every app, or the one with the given slug, sorted by slug.
	 *
	 * @param string|null $slug Only this app, when given.
	 *
	 * @return array<int,array<string,mixed>> The apps.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-list-apps-from-the-command-line-req-bqic-004
	 */
	public function apps(?string $slug = null): array;

	/**
	 * The versions of an app, each with its slug, semver and status.
	 *
	 * @param string $appUuid The app's uuid.
	 *
	 * @return array<int,array{slug:string,semver:string,status:string}> The versions, sorted by slug.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md#requirement-list-apps-from-the-command-line-req-bqic-004
	 */
	public function versions(string $appUuid): array;
}//end interface
