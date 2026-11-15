<?php

/**
 * Contract of CompanionSchemaCollector
 *
 * The public surface of CompanionSchemaCollector that other components call, so they depend
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
 * Contract of CompanionSchemaCollector.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CompanionSchemaCollectorInterface {

	/**
	 * Read the app's register companion schemas, keyed by schema slug.
	 *
	 * The register is the one the published ApplicationVersion names. The
	 * creation wizard gives every version its own register,
	 * `openbuild-{slug}-{version}`, so `openbuild-{slug}` exists for no
	 * wizard-made app: deriving the name from the app slug alone found nothing
	 * and published a repository whose `schemas/` was empty, with no error to
	 * see. `openbuild-{slug}` stays as a fallback for the older records that
	 * carry no `register`.
	 *
	 * Mirrors DataRegisterExportBundler's schema resolution; a register that is
	 * absent (an app never provisioned one) yields no companions rather than an
	 * error, because serialisation is total.
	 *
	 * @param string $slug The Application slug.
	 * @param string $versionRegister The register named by the version being published.
	 *
	 * @return array<string,array<string,mixed>> Schema blobs keyed by slug.
	 *
	 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
	 */
	public function collect(string $slug, string $versionRegister = ''): array;
}//end interface
