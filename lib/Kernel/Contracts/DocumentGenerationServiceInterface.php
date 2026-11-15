<?php

/**
 * Contract of DocumentGenerationService
 *
 * The public surface of DocumentGenerationService that other components call, so they depend
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
 * Contract of DocumentGenerationService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DocumentGenerationServiceInterface {

	/**
	 * Generate a document for the fired object per one `generateDocument`
	 * action, impersonating the owning Application's owner for the whole
	 * operation (the internal Docudesk call AND the attach-mode Files write
	 * both need that context).
	 *
	 * @param array<string,mixed> $automation The matching Automation object (needs `applicationSlug`).
	 * @param array<string,mixed> $action The `generateDocument` action record (`templateId`, `output`).
	 * @param string $schemaSlug The fired object's schema slug.
	 * @param string $objectUuid The fired object's uuid.
	 *
	 * @return bool Whether generation succeeded.
	 *
	 * @spec openspec/changes/archive/2026-07-24-automation-document-action/tasks.md#2.1
	 * @spec openspec/changes/archive/2026-07-24-automation-document-action/tasks.md#2.2
	 * @spec openspec/specs/automation-document-action/spec.md
	 */
	public function generate(array $automation, array $action, string $schemaSlug, string $objectUuid): bool;
}//end interface
