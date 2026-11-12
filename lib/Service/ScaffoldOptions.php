<?php

/**
 * Buildiq ScaffoldOptions
 *
 * What an in-memory app scaffold bundles beyond the resolved template: the
 * bound data registers, the application's flows, and whose agents to collect.
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
 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * The optional channels of {@see ExportService::buildScaffoldMap()}.
 *
 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
 */
final class ScaffoldOptions {

	/**
	 * Constructor.
	 *
	 * @param array<int,mixed> $dataRegisters   Bound data-register schemas to bundle.
	 * @param array<int,mixed> $flows           `Application.flows` bindings, bundled only when non-empty.
	 * @param string           $applicationSlug Slug of the application whose agents to collect.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $dataRegisters = [],
		public readonly array $flows = [],
		public readonly string $applicationSlug = '',
	) {
	}//end __construct()
}//end class
