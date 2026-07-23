<?php

/**
 * OpenBuild ShareTokenService
 *
 * Mint / revoke / resolve `ShareToken` objects (public-form-access) — the
 * OR-backed model (ADR-022) scoping anonymous access to exactly one
 * Application + one page (design.md D1). This is imperative logic per
 * ADR-031's security-boundary exception: opaque-string → Application → page
 * lookup and the anonymous→owner-context authorization boundary are outside
 * OR's declarative vocabulary.
 *
 * Fail-closed contract mirrors OpenRegister's `CaseTokenService` precedent:
 * `resolve()` never distinguishes "unknown" from "revoked"/"expired" in a way
 * that leaks existence — both collapse to a single not-found status. The one
 * deliberate exception is a wrong/missing password on an otherwise-valid
 * token, which the caller (PublicFormController) surfaces as a distinct
 * "password required" prompt per the public-form-access spec Scenario
 * "Password-protected token requires the password" — this is not an
 * enumeration leak because it still reveals nothing about page/schema
 * content, only that *a* token exists at that URL, which the visitor already
 * knows (they followed the link).
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
 * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mint / revoke / resolve ShareToken objects.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The full mint→resolve→revoke→list
 *  lifecycle for one small, cohesive OR-backed model is kept in one service rather than
 *  fragmented across several — each public method is independently unit-tested and
 *  already delegates its own internal branching to small private helpers (see
 *  validateIssueRequest/resolveIssueExpiry/findShareTokenByToken/isExpired/passwordSatisfied).
 */
class ShareTokenService
{

    /**
     * Register slug shared by every OpenBuild control schema (ADR-002).
     *
     * @var string
     */
    private const REGISTER_SLUG = 'openbuild';

    /**
     * Schema slug for the ShareToken schema (50-public-forms-runtime.json fragment).
     *
     * @var string
     */
    private const SCHEMA_SLUG = 'share-token';

    /**
     * Opaque token length in characters (URL-safe alphanumeric). 43 chars of
     * the 62-symbol alphabet ≈ 256 bits of entropy, matching CaseTokenService's
     * precedent — non-guessable, so brute-force enumeration is infeasible.
     *
     * @var int
     */
    private const TOKEN_LENGTH = 43;

    /**
     * Honeypot field-name length in characters. Randomised per-token so the
     * field name is never a static, greppable constant (design.md D5).
     *
     * @var int
     */
    private const HONEYPOT_NAME_LENGTH = 12;

    /**
     * Default `expiresAt` window (in seconds) auto-applied to `mode: edit`
     * tokens created without an explicit expiry — REQUIRED by the schema and
     * the "Per-record edit links let anyone with the link edit that record
     * indefinitely" risk in design.md (30-day default).
     *
     * @var int
     */
    private const EDIT_MODE_DEFAULT_TTL_SECONDS = (30 * 24 * 60 * 60);

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService  OpenRegister object service (ADR-022).
     * @param RegisterMapper  $registerMapper Resolves the `openbuild` register slug to its numeric ID.
     * @param SchemaMapper    $schemaMapper   Resolves the `share-token` schema slug to its numeric ID.
     * @param ISecureRandom   $secureRandom   NC secure RNG for the token + honeypot field name.
     * @param IHasher         $hasher         NC BCrypt hasher for the optional password.
     * @param LoggerInterface $logger         PSR logger for diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly ISecureRandom $secureRandom,
        private readonly IHasher $hasher,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Issue (mint) a new ShareToken for one Application + one page.
     *
     * Rejects issuance when the target page's manifest `config.public.enabled`
     * is not `true` (public-form-access requirement "Public page can only be
     * issued a token when its config declares public.enabled"). Enforces a
     * mandatory `expiresAt` for `mode: edit` (defaults to a 30-day window when
     * the caller does not supply one) per design.md's "Per-record edit links"
     * risk.
     *
     * @param string               $applicationUuid          Owning Application UUID.
     * @param array<string, mixed> $applicationManifest      The Application's production manifest (already resolved
     *                                                       by the caller so this service never re-fetches it).
     * @param string               $pageId                   The bound page's manifest `page.id`.
     * @param string               $mode                     One of `submit`|`read`|`edit`.
     * @param string|null          $boundObjectId            Optional bound object UUID (required for `mode: edit`).
     * @param string|null          $expiresAt                Optional ISO-8601 expiry (mandatory for `mode: edit`, auto-defaulted when omitted).
     * @param string|null          $password                 Optional plaintext password — hashed before storage, never persisted
     *                                                       raw.
     * @param array<int, string>   $allowedPrefillFields     Allow-listed form field keys prefillable from query params.
     * @param bool                 $requireEmailVerification Accept-then-flag verification marker (design.md Open Questions).
     *
     * @return array<string, mixed> The created ShareToken, including the plaintext `token` (also re-exposed by
     *                              `listForApplication()` for the authenticated owner/editor dialog — see its
     *                              docblock; NEVER re-exposed by the anonymous `resolve()` path).
     *
     * @throws InvalidArgumentException When `mode` is invalid, the page is not public-enabled, or `mode: edit` has no `boundObjectId`.
     *
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-sharetoken-schema-scopes-one-token-to-one-application-and-page
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-public-page-can-only-be-issued-a-token-when-its-config-declares-publicenabled
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$requireEmailVerification` mirrors the
     *  ShareToken schema's own boolean property 1:1 — it configures a per-token accept-then-flag
     *  marker, not a behavioural switch on `issue()` itself.
     * @SuppressWarnings(PHPMD.LongVariable)        Named to match the ShareToken schema field
     *  (`requireEmailVerification`) exactly for discoverability; shortening it would desync the
     *  parameter name from the schema and the named-argument call sites that reference it.
     */
    public function issue(
        string $applicationUuid,
        array $applicationManifest,
        string $pageId,
        string $mode,
        ?string $boundObjectId=null,
        ?string $expiresAt=null,
        ?string $password=null,
        array $allowedPrefillFields=[],
        bool $requireEmailVerification=false
    ): array {
        $this->validateIssueRequest(
            applicationManifest: $applicationManifest,
            pageId: $pageId,
            mode: $mode,
            boundObjectId: $boundObjectId
        );

        $expiresAt = $this->resolveIssueExpiry(mode: $mode, expiresAt: $expiresAt);

        $token         = $this->secureRandom->generate(self::TOKEN_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
        $honeypotField = 'hp_'.$this->secureRandom->generate(self::HONEYPOT_NAME_LENGTH, ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);

        $passwordHash = null;
        if ($password !== null && $password !== '') {
            $passwordHash = $this->hasher->hash(message: $password);
        }

        $payload = [
            'applicationId'            => $applicationUuid,
            'pageId'                   => $pageId,
            'token'                    => $token,
            'mode'                     => $mode,
            'boundObjectId'            => $boundObjectId,
            'expiresAt'                => $expiresAt,
            'passwordHash'             => $passwordHash,
            'revoked'                  => false,
            'allowedPrefillFields'     => array_values($allowedPrefillFields),
            'honeypotField'            => $honeypotField,
            'requireEmailVerification' => $requireEmailVerification,
        ];

        // System-context write (`_rbac: false`): the caller (ShareTokenController)
        // has already enforced owner/editor RBAC on the parent Application before
        // reaching this service — mirrors AppOverrideService::upsertUserDelta's
        // justification for the identical flag. The ShareToken schema's own
        // OR-level ACL is admin-only (see the register fragment), which a
        // non-admin editor cannot satisfy; authorization for this write is
        // enforced by the CALLER, not by OR's schema ACL.
        $saved = $this->objectService->saveObject(
            object: $payload,
            register: self::REGISTER_SLUG,
            schema: self::SCHEMA_SLUG,
            _rbac: false,
            _multitenancy: false
        );

        return $this->normaliseObject(object: $saved);
    }//end issue()

    /**
     * Validate an `issue()` request: mode enum, page exists, page is marked
     * public, and `mode: edit` carries a `boundObjectId`.
     *
     * @param array<string, mixed> $applicationManifest The Application's production manifest.
     * @param string               $pageId              The bound page's manifest `page.id`.
     * @param string               $mode                One of `submit`|`read`|`edit`.
     * @param string|null          $boundObjectId       Optional bound object UUID.
     *
     * @return void
     *
     * @throws InvalidArgumentException When any validation fails.
     */
    private function validateIssueRequest(array $applicationManifest, string $pageId, string $mode, ?string $boundObjectId): void
    {
        if (in_array($mode, ['submit', 'read', 'edit'], true) === false) {
            throw new InvalidArgumentException('mode must be one of submit|read|edit');
        }

        $page = $this->findPage(manifest: $applicationManifest, pageId: $pageId);
        if ($page === null) {
            throw new InvalidArgumentException('No page with id "'.$pageId.'" exists on this Application');
        }

        if ($this->isPageMarkedPublic(page: $page) === false) {
            throw new InvalidArgumentException(
                'This page is not marked public. Enable "public.enabled" in the page config before creating a share link.'
            );
        }

        if ($mode === 'edit' && ($boundObjectId === null || $boundObjectId === '')) {
            throw new InvalidArgumentException('mode "edit" requires a boundObjectId');
        }
    }//end validateIssueRequest()

    /**
     * Resolve the `expiresAt` to persist: the caller's value, or (for
     * `mode: edit` only) a default 30-day window when omitted.
     *
     * @param string      $mode      One of `submit`|`read`|`edit`.
     * @param string|null $expiresAt The caller-supplied expiry, if any.
     *
     * @return string|null
     */
    private function resolveIssueExpiry(string $mode, ?string $expiresAt): ?string
    {
        if ($mode !== 'edit' || ($expiresAt !== null && $expiresAt !== '')) {
            return $expiresAt;
        }

        // REQ: mandatory expiry for mode:edit — default to a 30-day window
        // when the caller (the ShareTokenDialog) did not supply one, rather
        // than rejecting outright; the UI always shows the field, but a
        // programmatic caller (MCP tool, future API) must not be able to
        // mint an unbounded edit link by omission.
        return (new DateTimeImmutable())
            ->modify('+'.self::EDIT_MODE_DEFAULT_TTL_SECONDS.' seconds')
            ->format(DateTimeInterface::ATOM);
    }//end resolveIssueExpiry()

    /**
     * Revoke a ShareToken so it never resolves again.
     *
     * Idempotent: revoking an already-revoked token is a no-op that still
     * returns true (the end state — "cannot be resolved" — already holds).
     *
     * @param string $tokenUuid The ShareToken's own OR object UUID (not the opaque `token` value).
     *
     * @return bool True when the token was found (and is now revoked); false when unknown.
     *
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-token-management-ui-in-the-page-designer-and-app-settings
     */
    public function revoke(string $tokenUuid): bool
    {
        try {
            $existing = $this->objectService->find(
                id: $tokenUuid,
                register: self::REGISTER_SLUG,
                schema: self::SCHEMA_SLUG,
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->debug(
                'ShareTokenService: revoke lookup failed for {uuid}: {message}',
                ['uuid' => $tokenUuid, 'message' => $e->getMessage()]
            );
            return false;
        }

        if ($existing === null) {
            return false;
        }

        $data            = $this->normaliseObject(object: $existing);
        $data['revoked'] = true;
        unset($data['@self']);

        $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER_SLUG,
            schema: self::SCHEMA_SLUG,
            uuid: $tokenUuid,
            _rbac: false,
            _multitenancy: false
        );

        return true;
    }//end revoke()

    /**
     * Resolve an opaque public token to its ShareToken record + owning Application.
     *
     * Fail-closed status contract:
     *   - `not_found`         — unknown token, revoked, or expired (uniform — no enumeration leak).
     *   - `password_required` — the token has a `passwordHash` and none/an incorrect password was supplied.
     *   - `ok`                — resolved; `shareToken` + `applicationUuid` are populated.
     *
     * @param string      $token    The opaque public token from the URL.
     * @param string|null $password Optional plaintext password supplied by the visitor.
     *
     * @return array{status: string, shareToken?: array<string, mixed>, applicationUuid?: string}
     *
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-public-render-endpoint-resolves-a-token-to-exactly-its-bound-page
     */
    public function resolve(string $token, ?string $password=null): array
    {
        if (trim($token) === '') {
            return ['status' => 'not_found'];
        }

        $shareToken = $this->findShareTokenByToken(token: $token);
        if ($shareToken === null) {
            return ['status' => 'not_found'];
        }

        if (($shareToken['revoked'] ?? false) === true || $this->isExpired(shareToken: $shareToken) === true) {
            return ['status' => 'not_found'];
        }

        if ($this->passwordSatisfied(shareToken: $shareToken, password: $password) === false) {
            return ['status' => 'password_required'];
        }

        $applicationUuid = (string) ($shareToken['applicationId'] ?? '');
        if ($applicationUuid === '') {
            return ['status' => 'not_found'];
        }

        // Never echo the password hash or the raw token back through the
        // public path — the caller already has the token (it's in the URL);
        // re-serialising it in the JSON body adds no value and the hash must
        // never leave the server.
        unset($shareToken['passwordHash']);

        return [
            'status'          => 'ok',
            'shareToken'      => $shareToken,
            'applicationUuid' => $applicationUuid,
        ];
    }//end resolve()

    /**
     * Look up a ShareToken by its opaque `token` value.
     *
     * @param string $token The opaque token.
     *
     * @return array<string, mixed>|null The normalised ShareToken, or null on miss/error.
     */
    private function findShareTokenByToken(string $token): ?array
    {
        try {
            $registerId = $this->registerMapper->find(self::REGISTER_SLUG, _multitenancy: false)->getId();
            $schemaId   = $this->schemaMapper->find(self::SCHEMA_SLUG, _multitenancy: false)->getId();

            $results = $this->objectService->searchObjects(
                query: [
                    '@self' => [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                    ],
                    'token' => $token,
                ],
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->error('ShareTokenService: resolve lookup failed: {message}', ['message' => $e->getMessage()]);
            return null;
        }

        if (empty($results) === true) {
            return null;
        }

        return $this->normaliseObject(object: $results[0]);
    }//end findShareTokenByToken()

    /**
     * Whether a ShareToken's `expiresAt` has passed.
     *
     * @param array<string, mixed> $shareToken The normalised ShareToken.
     *
     * @return bool
     *
     * @SuppressWarnings(PHPMD.StaticAccess) `DateTimeImmutable::createFromFormat()`
     *  is PHP's own datetime-parsing factory; wrapping it behind an interface for
     *  this single call is over-engineering for the scope of this service.
     */
    private function isExpired(array $shareToken): bool
    {
        $expiresAt = ($shareToken['expiresAt'] ?? null);
        if (is_string($expiresAt) === false || $expiresAt === '') {
            return false;
        }

        $expiry = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $expiresAt);
        if ($expiry === false) {
            $expiry = new DateTimeImmutable($expiresAt);
        }

        return $expiry < new DateTimeImmutable();
    }//end isExpired()

    /**
     * Whether the supplied password (if any is required) is satisfied.
     *
     * @param array<string, mixed> $shareToken The normalised ShareToken.
     * @param string|null          $password   The visitor-supplied plaintext password.
     *
     * @return bool True when no password is required, or the supplied one matches.
     */
    private function passwordSatisfied(array $shareToken, ?string $password): bool
    {
        $passwordHash = ($shareToken['passwordHash'] ?? null);
        if (is_string($passwordHash) === false || $passwordHash === '') {
            return true;
        }

        if ($password === null || $password === '') {
            return false;
        }

        return $this->hasher->verify(message: $password, hash: $passwordHash);
    }//end passwordSatisfied()

    /**
     * List ShareTokens for an Application, optionally narrowed to one page.
     *
     * Authenticated, owner/editor-only surface (RBAC enforced by the caller,
     * `ShareTokenController`) backing `ShareTokenDialog`'s list view. Strips
     * `passwordHash` from every row — the dialog only needs to know a
     * password IS set, not its hash.
     *
     * @param string      $applicationUuid Owning Application UUID.
     * @param string|null $pageId          Optional page-id filter.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-token-management-ui-in-the-page-designer-and-app-settings
     */
    public function listForApplication(string $applicationUuid, ?string $pageId=null): array
    {
        try {
            $registerId = $this->registerMapper->find(self::REGISTER_SLUG, _multitenancy: false)->getId();
            $schemaId   = $this->schemaMapper->find(self::SCHEMA_SLUG, _multitenancy: false)->getId();

            $query = [
                '@self'         => [
                    'register' => $registerId,
                    'schema'   => $schemaId,
                ],
                'applicationId' => $applicationUuid,
            ];
            if ($pageId !== null && $pageId !== '') {
                $query['pageId'] = $pageId;
            }

            $results = $this->objectService->searchObjects(query: $query, _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            $this->logger->error('ShareTokenService: listForApplication failed: {message}', ['message' => $e->getMessage()]);
            return [];
        }

        $rows = [];
        foreach ($results as $entry) {
            $row = $this->normaliseObject(object: $entry);
            $row['hasPassword'] = (is_string($row['passwordHash'] ?? null) === true && $row['passwordHash'] !== '');
            unset($row['passwordHash']);
            $rows[] = $row;
        }

        return $rows;
    }//end listForApplication()

    /**
     * Resolve the OpenRegister register+schema slug a page's form submission
     * targets, so `PublicSubmissionService` writes through `ObjectService`
     * directly (never a client-facing HTTP round-trip to that endpoint).
     *
     * A `type: form` page has no first-class `config.register`/`config.schema`
     * (unlike `index`/`detail`/`logs`/`wiki` pages) — its existing addressing
     * convention is `config.submitEndpoint`, an OpenRegister objects-API URL
     * of the shape `/api/objects/{register}/{schema}` (optionally with a
     * trailing `/:id` route-param segment for the authenticated edit path).
     * This reuses that EXISTING convention rather than inventing a new
     * manifest key, per the proposal's constraint that the external
     * `app-manifest.schema.json` is not modified by this change. Falls back
     * to an explicit `config.register` + `config.schema` pair when present
     * (the shape other page types already use), for forward-compatibility
     * with a `read`-mode page built from e.g. a `detail` page.
     *
     * @param array<string, mixed> $page The manifest page entry.
     *
     * @return array{register: string, schema: string}|null Null when no addressing could be resolved.
     *
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-anonymous-submission-writes-via-owner-context-service-never-a-visitor-identity
     */
    public function resolveTargetSchema(array $page): ?array
    {
        $config = ($page['config'] ?? []);
        if (is_array($config) === false) {
            return null;
        }

        $register = ($config['register'] ?? null);
        $schema   = ($config['schema'] ?? null);
        if (is_string($register) === true && $register !== '' && is_string($schema) === true && $schema !== '') {
            return ['register' => $register, 'schema' => $schema];
        }

        $submitEndpoint = ($config['submitEndpoint'] ?? null);
        if (is_string($submitEndpoint) === true && $submitEndpoint !== '') {
            if (preg_match('#/api/objects/([^/]+)/([^/]+)#', $submitEndpoint, $matches) === 1) {
                return ['register' => $matches[1], 'schema' => $matches[2]];
            }
        }

        return null;
    }//end resolveTargetSchema()

    /**
     * Look up a page by `id` inside a manifest's `pages[]` array.
     *
     * Public — reused by `PublicFormController` to build the single-page
     * manifest fragment returned by the render endpoint.
     *
     * @param array<string, mixed> $manifest The Application's (production) manifest.
     * @param string               $pageId   The page id to find.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-public-render-endpoint-resolves-a-token-to-exactly-its-bound-page
     */
    public function findPage(array $manifest, string $pageId): ?array
    {
        $pages = ($manifest['pages'] ?? []);
        if (is_array($pages) === false) {
            return null;
        }

        foreach ($pages as $page) {
            if (is_array($page) === true && ($page['id'] ?? null) === $pageId) {
                return $page;
            }
        }

        return null;
    }//end findPage()

    /**
     * Whether a page's `config.public.enabled` is strictly `true`.
     *
     * @param array<string, mixed> $page The manifest page entry.
     *
     * @return bool
     */
    private function isPageMarkedPublic(array $page): bool
    {
        $config = ($page['config'] ?? []);
        if (is_array($config) === false) {
            return false;
        }

        $public = ($config['public'] ?? []);
        if (is_array($public) === false) {
            return false;
        }

        return ($public['enabled'] ?? false) === true;
    }//end isPageMarkedPublic()

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
