<?php

/**
 * Buildiq DocumentRun
 *
 * One generateDocument action carried out for one fired object: the template,
 * the output modes, the object, and what the attach and download-link modes
 * produced.
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
 * @spec openspec/changes/automation-document-action/specs/automation-document-action/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * The state of one document generation. Never leaves DocumentGenerationService.
 *
 * @spec openspec/changes/automation-document-action/specs/automation-document-action/spec.md
 */
final class DocumentRun {

	/**
	 * The `attach`-mode file id, once written.
	 *
	 * @var string|null
	 */
	public ?string $fileRef = null;

	/**
	 * The `download-link`-mode signed URL, once written.
	 *
	 * @var string|null
	 */
	public ?string $downloadLink = null;

	/**
	 * Constructor.
	 *
	 * @param string            $templateId  Docudesk template UUID.
	 * @param array<int,string> $outputModes Normalised output modes (may be empty when the action is invalid).
	 * @param string            $schemaSlug  The fired object's schema slug.
	 * @param string            $objectUuid  The fired object's uuid.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $templateId,
		public readonly array $outputModes,
		public readonly string $schemaSlug,
		public readonly string $objectUuid,
	) {
	}//end __construct()

	/**
	 * The requested download filename.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/automation-document-action/specs/automation-document-action/spec.md
	 */
	public function filename(): string {
		return 'document-' . $this->objectUuid . '.pdf';
	}//end filename()
}//end class
