<?php

/**
 * Contract of AutomationWriteService
 *
 * The public surface of AutomationWriteService that other components call, so they depend
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

use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Contract of AutomationWriteService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AutomationWriteServiceInterface {

	/**
	 * Create an automation on an Application version (REQ-AUTD-008).
	 *
	 * Authorises against the PARENT APPLICATION's `permissions` block — named
	 * by the body's `applicationSlug`/`versionUuid`, both mandatory — and only
	 * then writes, in system context.
	 *
	 * @param array<string,mixed> $payload The decoded request body.
	 * @param array<int,string> $roles Roles required on the parent Application.
	 *
	 * @return JSONResponse The created Automation, or an error envelope.
	 *
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-008
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function create(array $payload, array $roles): JSONResponse;

	/**
	 * Delete an automation and remove its compiled artifacts (REQ-AUTD-008).
	 *
	 * The artifacts are removed FIRST: a deleted definition whose compiled
	 * artifacts are still live is the one outcome that leaves the instance
	 * acting on a rule nobody can see or edit any more.
	 *
	 * The caller is authorised by `AutomationsController::withAutomation()`
	 * before this runs.
	 *
	 * @param string $uuid The Automation object uuid.
	 * @param array<string,mixed> $automation The STORED automation.
	 *
	 * @return JSONResponse Empty success envelope.
	 *
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-008
	 */
	public function destroy(string $uuid, array $automation): JSONResponse;

	/**
	 * Load the parent Application by slug.
	 *
	 * @param string $slug The Application slug.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-008
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function loadApplication(string $slug): ?array;

	/**
	 * Load an Automation object by uuid.
	 *
	 * Shared with `AutomationsController::withAutomation()` so the RBAC
	 * pipeline and the writes it guards read the object through exactly one
	 * code path — two resolvers could disagree about what "the stored
	 * automation" is, and the authorization scope is read off that object.
	 *
	 * @param string $uuid The Automation object uuid.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-008
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function loadAutomation(string $uuid): ?array;

	/**
	 * Decode the JSON request body to an associative array.
	 *
	 * `IRequest::getParams()` already merges a decoded JSON body for
	 * `Content-Type: application/json`, and carries the route placeholders
	 * with it — those are stripped so they can never be written onto the
	 * stored object as if they were properties.
	 *
	 * @param IRequest $request The current HTTP request.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-008
	 */
	public function requestBody(IRequest $request): array;

	/**
	 * Persist an automation whose artifacts were just (re)compiled.
	 *
	 * Called from `AutomationsController::recompileAndRespond()` for the
	 * compile/enable/disable routes, AFTER `withAutomation()` has authorised
	 * the caller against the parent Application. Kept here so that every
	 * `_rbac: false` write on the `automation` schema — create, update, this
	 * one, and the delete — sits in one file behind one rationale.
	 *
	 * @param array<string,mixed> $automation The automation with its fresh provenance.
	 * @param string $uuid The Automation object uuid.
	 *
	 * @return array<string,mixed> The saved object, normalised.
	 *
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-008
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function saveCompiled(array $automation, string $uuid): array;

	/**
	 * Replace an automation's definition (REQ-AUTD-008).
	 *
	 * The caller is authorised by `AutomationsController::withAutomation()`
	 * against the STORED record's own Application BEFORE this runs, and the
	 * ownership fields are pinned to those stored values here — a client may
	 * rewrite the definition, it may not re-parent the record into an
	 * application or version it was never authorised against.
	 *
	 * @param string $uuid The Automation object uuid.
	 * @param array<string,mixed> $payload The decoded request body.
	 * @param array<string,mixed> $automation The STORED automation (authorization scope).
	 *
	 * @return JSONResponse The saved Automation.
	 *
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-008
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function update(string $uuid, array $payload, array $automation): JSONResponse;
}//end interface
