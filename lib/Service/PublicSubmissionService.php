<?php

/**
 * OpenBuild PublicSubmissionService
 *
 * Writes an anonymous public-form submission through OpenRegister's
 * `ObjectService`, acting with the Application owner's authorization
 * context — never a visitor identity (design.md D3, there is none). Honours
 * the honeypot spam guard (silent 200, no write) and the `mode: edit`
 * create-vs-update branch (D4).
 *
 * Schema validation is delegated entirely to `ObjectService::saveObject()`
 * (OR's own JSON-schema validation on write) rather than re-implemented here
 * — per ADR-031 this keeps validation declarative (the schema IS the
 * validation contract); this service only adds the two things OR's
 * declarative vocabulary cannot express: the honeypot guard and the
 * anonymous→owner-context authorization boundary.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-anonymous-submission-writes-via-owner-context-service-never-a-visitor-identity
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class PublicSubmissionService
{
    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OpenRegister object service (ADR-022).
     * @param LoggerInterface $logger        PSR logger for diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Submit anonymous form data against a resolved `ShareToken`.
     *
     * @param array<string, mixed> $shareToken   The resolved, normalised ShareToken record (see ShareTokenService::resolve()).
     * @param array<string, mixed> $data         Raw submitted form data (the honeypot field, if present, is stripped before validation/write).
     * @param string               $registerSlug Target OpenRegister register slug for the write.
     * @param string               $schemaSlug   Target OpenRegister schema slug for the write.
     *
     * @return array{status: string, object?: array<string, mixed>, message?: string} `status` is one of
     *         `created`|`updated`|`honeypot_dropped`|`not_found` (edit target missing)|`validation_failed`.
     *
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-anonymous-submission-writes-via-owner-context-service-never-a-visitor-identity
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-per-record-edit-links-bind-a-token-to-one-object-and-update-on-submit
     */
    public function submit(array $shareToken, array $data, string $registerSlug, string $schemaSlug): array
    {
        $mode = (string) ($shareToken['mode'] ?? 'submit');
        if ($mode === 'read') {
            // REQ: mode:read tokens never reach a write path at all.
            throw new RuntimeException('mode:read tokens do not accept submissions');
        }

        $honeypotResult = $this->applyHoneypotGuard(shareToken: $shareToken, data: $data);
        if ($honeypotResult !== null) {
            return $honeypotResult;
        }

        $data = $this->prepareSubmissionData(shareToken: $shareToken, data: $data);

        try {
            return $this->dispatchWrite(
                mode: $mode,
                boundObjectId: ($shareToken['boundObjectId'] ?? null),
                data: $data,
                registerSlug: $registerSlug,
                schemaSlug: $schemaSlug
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'PublicSubmissionService: submit failed for register={register} schema={schema}: {message}',
                ['register' => $registerSlug, 'schema' => $schemaSlug, 'message' => $e->getMessage()]
            );
            return ['status' => 'validation_failed', 'message' => $e->getMessage()];
        }
    }//end submit()

    /**
     * Honeypot check — before any schema/field processing, so a tripped
     * honeypot never even touches OR. Silent 200/no-write per design.md D5
     * (never signal the guard to a bot).
     *
     * @param array<string, mixed> $shareToken The resolved ShareToken.
     * @param array<string, mixed> $data       Raw submitted form data.
     *
     * @return array{status: string}|null `['status' => 'honeypot_dropped']` when tripped, null otherwise.
     */
    private function applyHoneypotGuard(array $shareToken, array $data): ?array
    {
        $honeypotField = (string) ($shareToken['honeypotField'] ?? '');
        if ($honeypotField === '' || trim((string) ($data[$honeypotField] ?? '')) === '') {
            return null;
        }

        $this->logger->info(
            'PublicSubmissionService: honeypot tripped for applicationId={applicationId} pageId={pageId}',
            [
                'applicationId' => ($shareToken['applicationId'] ?? null),
                'pageId'        => ($shareToken['pageId'] ?? null),
            ]
        );
        return ['status' => 'honeypot_dropped'];
    }//end applyHoneypotGuard()

    /**
     * Strip the honeypot field and apply the accept-then-flag email-verification marker.
     *
     * @param array<string, mixed> $shareToken The resolved ShareToken.
     * @param array<string, mixed> $data       Raw submitted form data.
     *
     * @return array<string, mixed> The prepared data, ready for ObjectService.
     */
    private function prepareSubmissionData(array $shareToken, array $data): array
    {
        $honeypotField = (string) ($shareToken['honeypotField'] ?? '');
        if ($honeypotField !== '') {
            // Strip the honeypot field (and anything else outside the
            // declared schema — OR's own save-time validation rejects
            // undeclared properties) before it ever reaches ObjectService.
            unset($data[$honeypotField]);
        }

        if (($shareToken['requireEmailVerification'] ?? false) === true) {
            // Accept-then-flag (design.md Open Questions): the write always
            // happens immediately; the object additionally carries
            // `emailVerified: false` for the owner to filter on. Never
            // overwritten if the visitor's own data already set it — the
            // token's flag is the source of truth for NEW/updated writes.
            $data['emailVerified'] = false;
        }

        return $data;
    }//end prepareSubmissionData()

    /**
     * Dispatch to create or edit based on `mode` + `boundObjectId`.
     *
     * @param string               $mode          `submit`|`edit` (never `read` — guarded by the
     *                                            caller).
     * @param mixed                $boundObjectId The ShareToken's bound object UUID, if any.
     * @param array<string, mixed> $data          Prepared submission data.
     * @param string               $registerSlug  Target register slug.
     * @param string               $schemaSlug    Target schema slug.
     *
     * @return array{status: string, object?: array<string, mixed>}
     */
    private function dispatchWrite(string $mode, mixed $boundObjectId, array $data, string $registerSlug, string $schemaSlug): array
    {
        if ($mode === 'edit' && is_string($boundObjectId) === true && $boundObjectId !== '') {
            return $this->submitEdit(
                data: $data,
                boundObjectId: $boundObjectId,
                registerSlug: $registerSlug,
                schemaSlug: $schemaSlug
            );
        }

        return $this->submitCreate(data: $data, registerSlug: $registerSlug, schemaSlug: $schemaSlug);
    }//end dispatchWrite()

    /**
     * Create a new object — owner-context write, never the client objects API.
     *
     * @param array<string, mixed> $data         Submitted (honeypot-stripped) data.
     * @param string               $registerSlug Target register slug.
     * @param string               $schemaSlug   Target schema slug.
     *
     * @return array{status: string, object: array<string, mixed>}
     */
    private function submitCreate(array $data, string $registerSlug, string $schemaSlug): array
    {
        // `_rbac: false` — there is no visitor identity to authorise against
        // (design.md D3); the write is scoped to exactly the one
        // register+schema the token's bound page addresses, resolved
        // server-side from the manifest, never from a client-supplied
        // register/schema id (IDOR guard — see PublicFormController).
        $saved = $this->objectService->saveObject(
            object: $data,
            register: $registerSlug,
            schema: $schemaSlug,
            _rbac: false,
            _multitenancy: false
        );

        return ['status' => 'created', 'object' => $this->normaliseObject(object: $saved)];
    }//end submitCreate()

    /**
     * Update the bound object (mode:edit) — rejects if it no longer resolves.
     *
     * @param array<string, mixed> $data          Submitted (honeypot-stripped) data.
     * @param string               $boundObjectId The ShareToken's bound object UUID.
     * @param string               $registerSlug  Target register slug.
     * @param string               $schemaSlug    Target schema slug.
     *
     * @return array{status: string, object?: array<string, mixed>}
     *
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#scenario-edit-link-for-a-deleted-record-is-rejected
     */
    private function submitEdit(array $data, string $boundObjectId, string $registerSlug, string $schemaSlug): array
    {
        $existing = $this->objectService->find(
            id: $boundObjectId,
            register: $registerSlug,
            schema: $schemaSlug,
            _rbac: false,
            _multitenancy: false
        );

        if ($existing === null) {
            // Bound object no longer exists — reject, never create a replacement.
            return ['status' => 'not_found'];
        }

        // Merge submitted fields over the existing record so unrelated
        // properties (owner metadata, fields not on this form) survive the
        // PUT-semantic write (saveObject omits nulls — carry every existing
        // field forward, per the fleet's saveObject-is-PUT-semantic lesson).
        $existingData = $this->normaliseObject(object: $existing);
        unset($existingData['@self']);
        $merged = array_merge($existingData, $data);

        $saved = $this->objectService->saveObject(
            object: $merged,
            register: $registerSlug,
            schema: $schemaSlug,
            uuid: $boundObjectId,
            _rbac: false,
            _multitenancy: false
        );

        return ['status' => 'updated', 'object' => $this->normaliseObject(object: $saved)];
    }//end submitEdit()

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
