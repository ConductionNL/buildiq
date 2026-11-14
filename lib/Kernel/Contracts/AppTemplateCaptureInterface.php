<?php

/**
 * Contract of AppTemplateCapture
 *
 * The public surface of AppTemplateCapture that other components call, so they depend
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
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Contracts;

use InvalidArgumentException;

/**
 * Contract of AppTemplateCapture.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AppTemplateCaptureInterface {

	/**
	 * Capture an app into a template array: companion schemas and manifest,
	 * de-namespaced. Definitions only; no record is ever read or copied.
	 *
	 * @param array<string,mixed> $application The source Application (needs `slug`)
	 * @param array<int,array<string,mixed>> $schemas The app's companion schema blobs
	 * @param array<string,mixed> $manifest The app's current manifest
	 *
	 * @return array{description:string,manifest:mixed,companionSchemas:list<array<string,mixed>>}
	 *
	 * @throws InvalidArgumentException When two schemas de-namespace to one slug.
	 *
	 * @spec openspec/changes/apps-copy-app-and-page/specs/copy-app-page-and-form/spec.md#requirement-a-maker-copies-an-app-req-bqcp-001
	 */
	public function capture(array $application, array $schemas, array $manifest): array;
}//end interface
