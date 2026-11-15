<?php

/**
 * Buildiq ConnectorExportRun
 *
 * The progress of exporting one application's connectors: the files written, what is already exported, and the counts the descriptor reports.
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
 * @spec openspec/specs/github-app-repo-format/spec.md#requirement-connectors-are-declared-explicitly-never-inferred
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * One run of the connectors channel. Never leaves AppRepoSerializer.
 *
 * @spec openspec/specs/github-app-repo-format/spec.md#requirement-connectors-are-declared-explicitly-never-inferred
 */
final class ConnectorExportRun {

	/**
	 * The export file map, keyed `kind/name.json`.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $files = [];

	/**
	 * Dedupe index, keyed `kind/resolved-identity`.
	 *
	 * @var array<string,bool>
	 */
	public array $seen = [];

	/**
	 * Running count of stripped secrets.
	 *
	 * @var int
	 */
	public int $stripped = 0;

	/**
	 * Declared entries exported.
	 *
	 * @var int
	 */
	public int $declared = 0;

	/**
	 * Directly referenced objects exported.
	 *
	 * @var int
	 */
	public int $resolved = 0;

	/**
	 * Declared entries that did not resolve.
	 *
	 * @var int
	 */
	public int $missing = 0;

	/**
	 * What the connectors channel answers.
	 *
	 * @return array{files
	 *
	 * @spec openspec/specs/github-app-repo-format/spec.md#requirement-connectors-are-declared-explicitly-never-inferred:array<string,array<string,mixed>>,declaredCount:int,resolvedCount:int,strippedCount:int,missingCount:int}
	 */
	public function summary(): array {
		return [
			'files' => $this->files,
			'declaredCount' => $this->declared,
			'resolvedCount' => $this->resolved,
			'strippedCount' => $this->stripped,
			'missingCount' => $this->missing,
		];
	}//end summary()
}//end class
