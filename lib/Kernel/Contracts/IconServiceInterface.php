<?php

/**
 * Contract of IconService
 *
 * The public surface of IconService that other components call, so they depend
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
 * Contract of IconService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IconServiceInterface {

	/**
	 * Return a stream + MIME type for the icon of a given Application slug.
	 *
	 * Light chain:  icon.ref → /img/app.svg
	 * Dark  chain:  iconDark.ref → icon.ref → /img/app-dark.svg → /img/app.svg
	 *
	 * @param string $slug The Application slug.
	 * @param bool $dark True to apply the dark-icon fallback chain.
	 *
	 * @return array{stream: resource|null, mimeType: string} The stream and MIME type.
	 *                                                        stream is null only when
	 *                                                        no filesystem fallback
	 *                                                        exists (practically never).
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-2
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-3
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function getIconStream(string $slug, bool $dark): array;
}//end interface
