<?php

/**
 * Buildiq AppContentBundleRun
 *
 * One run of ExportAppContentBundler: the tree it writes into, the exported app
 * id and version, and the application, manifest and content it has read.
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
 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-companion-schemas-migrate-into-the-exported-app-s-own-namespace
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * The state of writing one application's content into an exported tree.
 * Never leaves ExportAppContentBundler.
 *
 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-companion-schemas-migrate-into-the-exported-app-s-own-namespace
 */
final class AppContentBundleRun {

	/**
	 * The application record.
	 *
	 * @var array<string,mixed>
	 */
	public array $application = [];

	/**
	 * The rewritten, publishable manifest.
	 *
	 * @var array<string,mixed>
	 */
	public array $manifest = [];

	/**
	 * The definitions, and the records when asked, keyed by exported slug.
	 *
	 * @var array{definitions: array<string,array<mixed>>, records: array<string,array<int,array{key: string, data: array<string,mixed>}>>}
	 */
	public array $content = ['definitions' => [], 'records' => []];

	/**
	 * Constructor.
	 *
	 * @param string $rootDir The exported tree root (placeholders already resolved).
	 * @param string $appId   The exported app id.
	 * @param string $semver  The exported version.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $rootDir,
		public readonly string $appId,
		public readonly string $semver,
	) {
	}//end __construct()
}//end class
