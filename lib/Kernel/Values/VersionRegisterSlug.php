<?php

/**
 * Buildiq VersionRegisterSlug
 *
 * The per-version register slug buildiq provisions for an app version.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Values
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-copilot/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Values;

use OCA\Buildiq\Kernel\Contracts\ApplicationVersionServiceInterface;

/**
 * Builds the per-version register slug from
 * {@see ApplicationVersionServiceInterface::VERSION_REGISTER_PREFIX}, so the
 * prefix is never typed out.
 *
 * @spec openspec/specs/ai-copilot/spec.md
 */
final class VersionRegisterSlug {

	/**
	 * The per-version register slug for an app version.
	 *
	 * @param string $appSlug     The application slug.
	 * @param string $versionSlug The version slug.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public static function forVersion(string $appSlug, string $versionSlug): string {
		return ApplicationVersionServiceInterface::VERSION_REGISTER_PREFIX . $appSlug . '-' . $versionSlug;
	}//end forVersion()
}//end class
