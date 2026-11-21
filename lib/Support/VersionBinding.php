<?php

/**
 * VersionBinding — the application version a manifest's data bindings point at.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\Buildiq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-an-approved-plan-executes-atomically-through-the-mcp-handler-layer
 */

declare(strict_types=1);

namespace OCA\Buildiq\Support;

/**
 * The application version, and the data registers it binds, that
 * {@see ManifestDataBinding} points a config block at.
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-an-approved-plan-executes-atomically-through-the-mcp-handler-layer
 */
final class VersionBinding {

	/**
	 * Constructor.
	 *
	 * @param string             $appSlug       The application slug.
	 * @param string             $versionSlug   The version slug.
	 * @param array<int, string> $dataRegisters Register slugs the Application binds in `dataRegisters`.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $appSlug,
		public readonly string $versionSlug,
		public readonly array $dataRegisters = [],
	) {
	}//end __construct()

	/**
	 * Whether both the application and the version are named; without both
	 * there is no version to bind to.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/ai-copilot/spec.md#requirement-an-approved-plan-executes-atomically-through-the-mcp-handler-layer
	 */
	public function isComplete(): bool {
		return ($this->appSlug !== '' && $this->versionSlug !== '');
	}//end isComplete()
}//end class
