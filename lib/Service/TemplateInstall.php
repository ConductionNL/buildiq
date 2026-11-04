<?php

/**
 * Buildiq TemplateInstall
 *
 * One install of a template as a new application: the template, and the name
 * and slug the new application gets.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * The template an application is installed from, with the new application's name and slug.
 *
 * @spec openspec/specs/openbuild-remote-template-store/spec.md
 */
final class TemplateInstall {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $template The template record (local or remote payload).
	 * @param string              $name     Human-readable name for the new app.
	 * @param string              $newSlug  The new (kebab-case, pre-validated) app slug.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $template,
		public readonly string $name,
		public readonly string $newSlug,
	) {
	}//end __construct()
}//end class
