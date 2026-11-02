<?php

/**
 * Buildiq ExportContents
 *
 * What one export run puts in the archive besides the template: the placeholder
 * context, the data register bindings, the flows and the application itself.
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
 * @spec openspec/changes/data-registers-runtime/tasks.md#task-4.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * The context, bindings and source an exported tree is filled from.
 *
 * @spec openspec/changes/data-registers-runtime/tasks.md#task-4.2
 */
final class ExportContents {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>      $context       Placeholder context: appId, appNamespace, etc.
	 * @param array<int,mixed>         $dataRegisters Source Application's `dataRegisters` bindings plus the
	 *                                                per-export includeData choice for each. Untrusted shape.
	 * @param array<int,mixed>         $flows         `Application.flows` bindings.
	 * @param array<string,mixed>|null $source        The application and version to put in the tree, from
	 *                                                ExportAppContentBundler::resolveSource(), plus
	 *                                                `includeSeedData`. Null exports the bare scaffold.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $context,
		public readonly array $dataRegisters = [],
		public readonly array $flows = [],
		public readonly ?array $source = null,
	) {
	}//end __construct()
}//end class
