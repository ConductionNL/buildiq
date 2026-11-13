<?php

/**
 * Contract of ExportAppContentBundler
 *
 * The public surface of ExportAppContentBundler that other components call, so they depend
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
 * Contract of ExportAppContentBundler.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExportAppContentBundlerInterface {

	/**
	 * Find the application and the version an export job names.
	 *
	 * @param string $applicationUuid The application UUID.
	 * @param string $semver The version semver on the job.
	 * @param string $versionSlug The version slug on the job, '' when absent.
	 *
	 * @return array{application: array<string,mixed>, version: array<string,mixed>}|null Null when the application is unknown.
	 *
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-export-targets-a-specific-application-version
	 */
	public function resolveSource(string $applicationUuid, string $semver, string $versionSlug = ''): ?array;
}//end interface
