<?php

/**
 * OpenBuild PublicFormController
 *
 * The runtime's first `#[PublicPage]` surface (public-forms-runtime). Two
 * endpoints, both resolving authorization SOLELY through a `ShareToken` —
 * never the session/organisation posture `ApplicationsController::getManifest`
 * uses (openbuild-runtime spec, "Public manifest resolution never uses
 * session/organisation authorization"). Deliberately its own controller (not
 * a branch inside `ApplicationsController`) per design.md Decision D2, so a
 * future refactor of the authenticated manifest path can never accidentally
 * widen the public surface.
 *
 * `render()` returns a manifest fragment containing ONLY the token's bound
 * page — no other page, schema, or Application data (fail-closed, mirrors
 * OpenRegister's `CaseTokenController` uniform-404 precedent for
 * unknown/revoked/expired tokens; a wrong/missing password is the one
 * deliberately distinguishable state, surfaced as 401 so the public
 * bootstrap can show a password prompt instead of a dead end).
 *
 * `submit()` never touches the OR client-facing objects API and never writes
 * as a visitor identity (design.md D3) — it delegates to
 * `PublicSubmissionService`, which writes via `ObjectService` directly,
 * scoped to the register+schema resolved server-side from the token's bound
 * page (never a client-supplied register/schema id — IDOR guard).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenBuild\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md
 * @spec openspec/changes/public-forms-runtime/specs/openbuild-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Controller;

use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenBuild\Service\ManifestResolverService;
use OCA\OpenBuild\Service\PublicSubmissionService;
use OCA\OpenBuild\Service\ShareTokenService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Public (anonymous) render + submit controller for shared form/read pages.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) `render()` and `submit()` share one
 *  resolve path (`resolvePageContext()`) by design (D2 — a single controller, never a
 *  branch inside `ApplicationsController`); the per-concern logic (prefill, edit-mode
 *  initial value, submit-target rewrite, honeypot injection) is already split into small,
 *  independently-readable private helpers rather than inlined.
 */
class PublicFormController extends Controller
{

    /**
     * Request keys that are never form data — stripped before a submission
     * is handed to `PublicSubmissionService`.
     *
     * @var array<int, string>
     */
    private const RESERVED_PARAMS = ['token', 'password'];

    /**
     * Constructor.
     *
     * @param IRequest                $request           Current HTTP request.
     * @param ShareTokenService       $shareTokenService Token issue/revoke/resolve.
     * @param ManifestResolverService $manifestResolver  Production-manifest resolution.
     * @param ObjectService           $objectService     OpenRegister object service (ADR-022).
     * @param PublicSubmissionService $submissionService Owner-context anonymous writer.
     * @param IURLGenerator           $urlGenerator      Builds the webroot-correct public submit URL.
     * @param LoggerInterface         $logger            PSR logger for diagnostics.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly ShareTokenService $shareTokenService,
        private readonly ManifestResolverService $manifestResolver,
        private readonly ObjectService $objectService,
        private readonly PublicSubmissionService $submissionService,
        private readonly IURLGenerator $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * GET /api/public/forms/{token} — resolve a token to its bound page only.
     *
     * `#[NoCSRFRequired]` is justified: an anonymous visitor has no Nextcloud
     * session and therefore no CSRF token to carry — CSRF protection relies
     * on a session-bound token that does not exist here (design.md
     * Constraints). Read-only, so no state-changing risk either way.
     *
     * @param string      $token    The opaque public token.
     * @param string|null $password Optional plaintext password (`?password=`) for password-protected tokens.
     *
     * @return JSONResponse `{ manifest, honeypotField, mode }` on 200; `{ error }` on 401/404.
     *
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-public-render-endpoint-resolves-a-token-to-exactly-its-bound-page
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-prefill-from-url-maps-allow-listed-query-params-to-form-fields
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-per-record-edit-links-bind-a-token-to-one-object-and-update-on-submit
     * @spec openspec/changes/public-forms-runtime/specs/openbuild-runtime/spec.md#requirement-public-manifest-resolution-never-uses-sessionorganisation-authorization
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 30, period: 60)]
    public function render(string $token, ?string $password=null): JSONResponse
    {
        $context = $this->resolvePageContext(token: $token, password: $password);
        if ($context instanceof JSONResponse) {
            return $context;
        }

        ['shareToken' => $shareToken, 'page' => $page, 'manifest' => $manifest] = $context;

        $page = $this->applyPrefill(page: $page, shareToken: $shareToken);
        $page = $this->applyEditModeInitialValue(page: $page, shareToken: $shareToken);
        $page = $this->rewriteSubmitTarget(page: $page, token: $token, shareToken: $shareToken);

        $fragment = [
            'version' => ($manifest['version'] ?? '1.0.0'),
            'pages'   => [$page],
            'menu'    => [],
        ];

        return new JSONResponse(
            data: [
                'manifest'      => $fragment,
                'honeypotField' => ($shareToken['honeypotField'] ?? ''),
                'mode'          => ($shareToken['mode'] ?? 'submit'),
            ],
            statusCode: Http::STATUS_OK
        );
    }//end render()

    /**
     * POST /api/public/forms/{token}/submit — anonymous write.
     *
     * `#[NoCSRFRequired]` is justified identically to {@see render()}: no NC
     * session exists to carry a CSRF token. In its place: `#[AnonRateLimit]`
     * + the token's own honeypot guard (`PublicSubmissionService`) are the
     * anti-abuse controls (design.md Constraints).
     *
     * @param string      $token    The opaque public token.
     * @param string|null $password Optional plaintext password for password-protected tokens.
     *
     * @return JSONResponse 201 on create, 200 on update/honeypot-drop, 400/404/401 on failure.
     *
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-anonymous-submission-writes-via-owner-context-service-never-a-visitor-identity
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 10, period: 60)]
    public function submit(string $token, ?string $password=null): JSONResponse
    {
        $context = $this->resolvePageContext(token: $token, password: $password);
        if ($context instanceof JSONResponse) {
            return $context;
        }

        ['shareToken' => $shareToken, 'page' => $page] = $context;

        $mode = (string) ($shareToken['mode'] ?? 'submit');
        if ($mode === 'read') {
            return new JSONResponse(
                data: ['error' => 'read_only', 'message' => 'This share link is read-only'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $target = $this->shareTokenService->resolveTargetSchema(page: $page);
        if ($target === null) {
            $this->logger->error(
                'PublicFormController: page {pageId} has no resolvable register/schema for public submission',
                ['pageId' => ($shareToken['pageId'] ?? null)]
            );
            return new JSONResponse(
                data: ['error' => 'misconfigured', 'message' => 'This page is not configured to accept submissions'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        $data = $this->request->getParams();
        foreach (self::RESERVED_PARAMS as $reserved) {
            unset($data[$reserved]);
        }

        try {
            $result = $this->submissionService->submit(
                shareToken: $shareToken,
                data: $data,
                registerSlug: $target['register'],
                schemaSlug: $target['schema']
            );
        } catch (Throwable $e) {
            $this->logger->error('PublicFormController: submit failed: {message}', ['message' => $e->getMessage()]);
            return new JSONResponse(
                data: ['error' => 'internal_error', 'message' => 'Failed to submit'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return $this->responseForSubmissionResult(result: $result);
    }//end submit()

    /**
     * Map a `PublicSubmissionService::submit()` result to an HTTP response.
     *
     * @param array{status: string, object?: array<string, mixed>, message?: string} $result The submission result.
     *
     * @return JSONResponse
     */
    private function responseForSubmissionResult(array $result): JSONResponse
    {
        return match ($result['status']) {
            'created' => new JSONResponse(data: ['status' => 'created'], statusCode: Http::STATUS_CREATED),
            'updated' => new JSONResponse(data: ['status' => 'updated'], statusCode: Http::STATUS_OK),
            // Honeypot: deliberately 200 with no indication a write was skipped
            // (design.md D5 — never signal the guard to a bot).
            'honeypot_dropped' => new JSONResponse(data: ['status' => 'ok'], statusCode: Http::STATUS_OK),
            'not_found' => new JSONResponse(
                data: ['error' => 'not_found', 'message' => 'The record this link edits no longer exists'],
                statusCode: Http::STATUS_NOT_FOUND
            ),
            default => new JSONResponse(
                data: ['error' => 'validation_failed', 'message' => ($result['message'] ?? 'Invalid submission')],
                statusCode: Http::STATUS_BAD_REQUEST
            ),
        };
    }//end responseForSubmissionResult()

    /**
     * Shared resolve path for both `render()` and `submit()`: token →
     * ShareToken → Application → production manifest → bound page.
     *
     * @param string      $token    The opaque public token.
     * @param string|null $password Optional plaintext password.
     *
     * @return JSONResponse|array<string, mixed> A JSONResponse (401/404) on any
     *         failure, or `{shareToken, application, page, manifest}` on success.
     */
    private function resolvePageContext(string $token, ?string $password): JSONResponse|array
    {
        $resolved = $this->shareTokenService->resolve(token: $token, password: $password);
        $status   = $resolved['status'];

        if ($status === 'password_required') {
            return new JSONResponse(
                data: ['error' => 'password_required', 'message' => 'This link is password-protected'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        if ($status !== 'ok') {
            // Uniform 404 for unknown/revoked/expired — no enumeration oracle.
            return new JSONResponse(
                data: ['error' => 'not_found', 'message' => 'This link is no longer valid'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        $shareToken      = $resolved['shareToken'];
        $applicationUuid = $resolved['applicationUuid'];

        try {
            $application = $this->objectService->find(
                id: $applicationUuid,
                register: 'openbuild',
                schema: 'application',
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->error('PublicFormController: Application lookup failed: {message}', ['message' => $e->getMessage()]);
            $application = null;
        }

        if ($application === null) {
            return new JSONResponse(
                data: ['error' => 'not_found', 'message' => 'This link is no longer valid'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        $applicationArray = $this->normaliseObject(object: $application);

        $manifest = $this->manifestResolver->resolveProductionManifestForApplication(
            application: $applicationArray,
            appSlug: (string) ($applicationArray['slug'] ?? '')
        );

        if ($manifest === null) {
            return new JSONResponse(
                data: ['error' => 'not_found', 'message' => 'This link is no longer valid'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        $pageId = (string) ($shareToken['pageId'] ?? '');
        $page   = $this->shareTokenService->findPage(manifest: $manifest, pageId: $pageId);

        if ($page === null) {
            // The page was removed/renamed since the token was minted.
            return new JSONResponse(
                data: ['error' => 'not_found', 'message' => 'This link is no longer valid'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        return [
            'shareToken'  => $shareToken,
            'application' => $applicationArray,
            'page'        => $page,
            'manifest'    => $manifest,
        ];
    }//end resolvePageContext()

    /**
     * Map allow-listed query params onto the page's `config.initialValue`.
     *
     * Only for `mode: submit`. Query parameters not present in the token's
     * `allowedPrefillFields` are ignored and never reflected into the form.
     *
     * @param array<string, mixed> $page       The bound page entry.
     * @param array<string, mixed> $shareToken The resolved ShareToken.
     *
     * @return array<string, mixed> The page, with `config.initialValue` merged.
     *
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-prefill-from-url-maps-allow-listed-query-params-to-form-fields
     */
    private function applyPrefill(array $page, array $shareToken): array
    {
        if (($shareToken['mode'] ?? 'submit') !== 'submit') {
            return $page;
        }

        $allowed = ($shareToken['allowedPrefillFields'] ?? []);
        if (is_array($allowed) === false || $allowed === []) {
            return $page;
        }

        $initialValue = $this->collectAllowedQueryParams(allowed: $allowed);
        if ($initialValue === []) {
            return $page;
        }

        return $this->mergeIntoInitialValue(page: $page, values: $initialValue);
    }//end applyPrefill()

    /**
     * Collect the request's query params that are present AND allow-listed.
     *
     * @param array<int, mixed> $allowed The token's `allowedPrefillFields`.
     *
     * @return array<string, mixed> Field key → query-param value.
     */
    private function collectAllowedQueryParams(array $allowed): array
    {
        $initialValue = [];
        foreach ($allowed as $field) {
            if (is_string($field) === false || $field === '') {
                continue;
            }

            $value = $this->request->getParam($field);
            if ($value !== null && $value !== '') {
                $initialValue[$field] = $value;
            }
        }

        return $initialValue;
    }//end collectAllowedQueryParams()

    /**
     * Merge `$values` into `$page['config']['initialValue']`, creating either as needed.
     *
     * @param array<string, mixed> $page   The page entry.
     * @param array<string, mixed> $values Values to merge in.
     *
     * @return array<string, mixed> The page, with `config.initialValue` merged.
     */
    private function mergeIntoInitialValue(array $page, array $values): array
    {
        if (is_array(($page['config'] ?? null)) === false) {
            $page['config'] = [];
        }

        $existing = ($page['config']['initialValue'] ?? []);
        if (is_array($existing) === false) {
            $existing = [];
        }

        $page['config']['initialValue'] = array_merge($existing, $values);

        return $page;
    }//end mergeIntoInitialValue()

    /**
     * Point the returned page's submission at the PUBLIC submit endpoint,
     * never the authenticated one copied from the stored manifest.
     *
     * The manifest's own `config.submitEndpoint` (when the page is a
     * `type: form` page) addresses OpenRegister's authenticated objects API
     * (`/api/objects/{register}/{schema}`, see `ShareTokenService::
     * resolveTargetSchema()`'s docblock) — an anonymous visitor has no
     * session and cannot call it (design.md D3: never the OR client-facing
     * objects API from an unauthenticated frontend). This rewrites the
     * COPY of the page returned to the browser only; the server always
     * re-derives the real register/schema from the STORED manifest on
     * submit (`resolvePageContext()` → `ShareTokenService::findPage()`),
     * never from anything the client sends back — so this rewrite is purely
     * for the browser's benefit and cannot be used to redirect a write.
     *
     * @param array<string, mixed> $page       The bound page entry (already prefill/initial-value adjusted).
     * @param string               $token      The opaque public token (identifies the submit URL).
     * @param array<string, mixed> $shareToken The resolved ShareToken.
     *
     * @return array<string, mixed> The page, with `config.submitEndpoint`/`submitMethod`/`submitHandler`
     *         rewritten and the honeypot field appended (mode:submit/edit), or left untouched (mode:read).
     *
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-anonymous-submission-writes-via-owner-context-service-never-a-visitor-identity
     */
    private function rewriteSubmitTarget(array $page, string $token, array $shareToken): array
    {
        if (($shareToken['mode'] ?? 'submit') === 'read') {
            return $page;
        }

        if (is_array(($page['config'] ?? null)) === false) {
            $page['config'] = [];
        }

        unset($page['config']['submitHandler']);
        $page['config']['submitEndpoint'] = $this->urlGenerator->linkToRoute(
            'openbuild.publicForm.submit',
            ['token' => $token]
        );
        $page['config']['submitMethod']   = 'POST';

        // Append the honeypot as an ordinary form field (satisfies the
        // `formField` $def's `additionalProperties: false` — no bespoke
        // "hidden" marker key exists in the external manifest schema, so a
        // visually-hidden marker cannot travel on the field object itself).
        // The public bootstrap entry (public-form.js) hides it in the DOM
        // post-render by matching this `key`, mirroring builder.js's
        // existing DOM-patching pattern for top-bar branding.
        $honeypotField = (string) ($shareToken['honeypotField'] ?? '');
        if ($honeypotField !== '') {
            $fields = ($page['config']['fields'] ?? []);
            if (is_array($fields) === false) {
                $fields = [];
            }

            $alreadyPresent = false;
            foreach ($fields as $field) {
                if (is_array($field) === true && ($field['key'] ?? null) === $honeypotField) {
                    $alreadyPresent = true;
                    break;
                }
            }

            if ($alreadyPresent === false) {
                $fields[] = [
                    'key'      => $honeypotField,
                    'label'    => '',
                    'type'     => 'string',
                    'required' => false,
                ];
            }

            $page['config']['fields'] = $fields;
        }//end if

        return $page;
    }//end rewriteSubmitTarget()

    /**
     * For `mode: edit` tokens, pre-fill the form from the bound object's
     * current values.
     *
     * @param array<string, mixed> $page       The bound page entry.
     * @param array<string, mixed> $shareToken The resolved ShareToken.
     *
     * @return array<string, mixed> The page, with `config.initialValue` set from the bound object.
     *
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-per-record-edit-links-bind-a-token-to-one-object-and-update-on-submit
     */
    private function applyEditModeInitialValue(array $page, array $shareToken): array
    {
        if (($shareToken['mode'] ?? '') !== 'edit') {
            return $page;
        }

        $boundObjectId = ($shareToken['boundObjectId'] ?? null);
        if (is_string($boundObjectId) === false || $boundObjectId === '') {
            return $page;
        }

        $target = $this->shareTokenService->resolveTargetSchema(page: $page);
        if ($target === null) {
            return $page;
        }

        try {
            $object = $this->objectService->find(
                id: $boundObjectId,
                register: $target['register'],
                schema: $target['schema'],
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->debug('PublicFormController: bound object lookup failed: {message}', ['message' => $e->getMessage()]);
            return $page;
        }

        if ($object === null) {
            return $page;
        }

        $objectData = $this->normaliseObject(object: $object);
        unset($objectData['@self']);

        if (is_array(($page['config'] ?? null)) === false) {
            $page['config'] = [];
        }

        $page['config']['initialValue'] = $objectData;

        return $page;
    }//end applyEditModeInitialValue()

    /**
     * Coerce an OR result entry (ObjectEntity or array) to a plain associative array.
     *
     * @param mixed $object The OR object/result entry.
     *
     * @return array<string, mixed>
     */
    private function normaliseObject(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialised = $object->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $inner = $object->getObject();
            if (is_array($inner) === true) {
                return $inner;
            }
        }

        return [];
    }//end normaliseObject()
}//end class
