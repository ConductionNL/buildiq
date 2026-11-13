<?php

/**
 * Contract of AppRepoParser
 *
 * The public surface of AppRepoParser that other components call, so they depend
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

use OCA\Buildiq\Exception\AppRepoParseException;

/**
 * Contract of AppRepoParser.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AppRepoParserInterface {

	/**
	 * Parse a repo file map into the clone-seam template array.
	 *
	 * @param array<string,string> $files A `path => contents` map (bytes/string).
	 * @param array{owner:string,name:string}|null $repo Optional repo identity for `templateOrigin`.
	 *
	 * @return array<string,mixed> The `ApplicationTemplate`-shaped payload.
	 *
	 * @throws AppRepoParseException On any conforming-repo violation (all-or-nothing).
	 *
	 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
	 */
	public function parse(array $files, ?array $repo = null): array;
}//end interface
