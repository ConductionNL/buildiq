<?php

/**
 * Buildiq Export Bundlers
 *
 * The writers that bundle registers, flows/agents, content and the server bundle. ExportService
 * hands each part of an export to its own writer; this object carries the writers.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * The writers that bundle registers, flows/agents, content and the server bundle.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-35
 */
final class ExportBundlers {
	/**
	 * Constructor.
	 *
	 * @param DataRegisterExportBundler $dataRegisterBundler Bundles bound data registers.
	 * @param FlowAndAgentExportBundler $flowAndAgentBundler Bundles flows and agents.
	 * @param ExportAppContentBundler|null $contentBundler Writes the application's own content, when wired.
	 * @param ServerBundleWriter|null $serverBundleWriter Writes the server bundle, when wired.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly DataRegisterExportBundler $dataRegisterBundler,
		public readonly FlowAndAgentExportBundler $flowAndAgentBundler,
		public readonly ?ExportAppContentBundler $contentBundler = null,
		public readonly ?ServerBundleWriter $serverBundleWriter = null,
	) {
	}//end __construct()
}//end class
