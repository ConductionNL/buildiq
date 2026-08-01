<?php

/**
 * OpenBuild DocumentGenerationService
 *
 * Imperative half of the `generateDocument` automation action (design.md
 * Decision 1/2/3 of automation-document-action). On trigger fire, calls
 * Docudesk's EXISTING, already-integration-tested public route
 * `POST /apps/docudesk/api/correspondence/generate` — the SAME pinned route
 * `docudesk-document-templates` REQ-DDT-006 already closes the integration
 * surface to for interactive, browser-driven generation — and nothing else.
 * This class never imports an `OCA\DocuDesk\*` class and never reads a
 * Docudesk table (REQ-DDT-006, modified by this change to name this second
 * caller shape).
 *
 * **The one real design problem this change solves (design.md Decision 1):**
 * an automation trigger fires from a background/event-listener context with
 * no interactive NC session — there is no "caller" in REQ-DDT-006's
 * "the caller's Nextcloud session" sense. The resolution is to impersonate
 * the owning `Application`'s NC owner via the existing
 * {@see JobOwnerImpersonator} (the fleet-wide owner-impersonation utility,
 * reused rather than reinvented) for the duration of exactly one internal
 * HTTP call, mirroring `automation-approval-steps` D3's and
 * `public-forms-runtime` D3's identical owner-context authorization story
 * for their own server-driven actions.
 *
 * **Transport detail extending design.md D1 (documented, not silent):**
 * design.md's literal wording — `IUserSession::setUser()` "make[s] that user
 * the active session for the duration of one internal HTTP call" — describes
 * the AUTHORIZATION story correctly but not, by itself, a working HTTP
 * transport: `IUserSession::setUser()` changes only the CURRENT PHP
 * process's session; `OCP\Http\Client\IClientService` performs a genuine
 * network request that Nextcloud's auth middleware evaluates independently
 * on arrival, with no cookie/session to inherit from a background-listener
 * context. This class realizes the SAME "one call, as the impersonated
 * owner" authorization intent by minting a short-lived Nextcloud login
 * token for the impersonated owner (resolved through NC's core, non-Docudesk
 * `OC\Authentication\Token\IProvider`, the exact mechanism behind
 * Nextcloud's own "app password" feature — explicitly invalidated again
 * immediately after the one call, for effective single-use semantics; see
 * {@see mintOneTimeToken()}'s docblock for why `TEMPORARY_TOKEN` is used
 * over `ONETIME_TOKEN`) and presenting it as HTTP Basic Auth on the one
 * internal call — the same defensive container-lookup idiom
 * {@see JobOwnerImpersonator} already uses for an optional collaborator, so
 * an instance where that internal provider is unavailable fails the call
 * closed (logged, no generation) rather than fatally.
 *
 * Output handling (design.md Decision 3): `attach` writes the returned bytes
 * to the triggering object's owner's Nextcloud Files via `OCP\Files\IRootFolder`
 * and sets `{ "ref": "<fileId>" }` on the object's `generatedDocument` field
 * (ADR-001 `{ref}` shape, the same one `logoRef`/`ShareToken` already use);
 * `download-link` writes the bytes to OpenBuild's OWN app-private storage
 * (`OCP\Files\IAppData` — never the user-visible Files tree, satisfying "no
 * file is written to Nextcloud Files") behind a random, single-use-scoped
 * token with a ~24h TTL, served by {@see \OCA\OpenBuild\Controller\GeneratedDocumentController};
 * `notify` dispatches a notification through the existing
 * {@see RuleActionDispatcher} (reuse, not a second notification path) and
 * MUST be paired with `attach` and/or `download-link`.
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
 * @spec openspec/changes/automation-document-action/tasks.md#2.1
 * @spec openspec/changes/automation-document-action/tasks.md#2.2
 * @spec openspec/changes/automation-document-action/tasks.md#2.3
 * @spec openspec/changes/automation-document-action/tasks.md#2.4
 * @spec openspec/changes/automation-document-action/tasks.md#2.5
 * @spec openspec/changes/automation-document-action/specs/automation-document-action/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Authentication\Token\IToken;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Owner-impersonated internal caller of Docudesk's pinned generate route.
 *
 * @spec openspec/changes/automation-document-action/tasks.md#2.1
 */
class DocumentGenerationService
{
    /**
     * Shared OpenBuild register slug (matches {@see AutomationCompilerService::REGISTER_SLUG}).
     */
    public const REGISTER_SLUG = AutomationCompilerService::REGISTER_SLUG;

    /**
     * Schema slug of the `application` object.
     */
    private const APPLICATION_SCHEMA = 'application';

    /**
     * NC route name for Docudesk's pinned generate route
     * (`OCA\DocuDesk\Controller\CorrespondenceController::generate()`,
     * `appinfo/routes.php` entry `correspondence#generate`).
     */
    private const GENERATE_ROUTE = 'docudesk.correspondence.generate';

    /**
     * Field the `attach` output mode writes `{ "ref": "<fileId>" }" to on
     * the triggering object.
     */
    public const ATTACHMENT_FIELD = 'generatedDocument';

    /**
     * `attach` output mode's subfolder name in the impersonated owner's
     * Nextcloud Files.
     */
    private const ATTACH_SUBFOLDER = 'OpenBuild generated documents';

    /**
     * `download-link` mode's signed-URL lifetime (design.md Open Questions —
     * "lean: short-lived (~24h)", matching `public-forms-runtime`'s edit-link
     * expiry posture).
     */
    public const DOWNLOAD_LINK_TTL_SECONDS = 86400;

    /**
     * Appdata folder name (`OCP\Files\IAppData`) holding pending download-link
     * artifacts, keyed by their random token.
     */
    private const APPDATA_FOLDER = 'generated-documents';

    /**
     * Valid `output` values (register.d/40-automations.json `output` enum).
     *
     * @var array<int,string>
     */
    public const OUTPUT_MODES = ['attach', 'download-link', 'notify'];

    /**
     * FQCN of Nextcloud core's internal login-token provider (not part of
     * `nextcloud/ocp`'s public stub package, so referenced as a lazily
     * resolved string rather than a hard `use` import — mirrors
     * {@see JobOwnerImpersonator}'s defensive container-lookup pattern for an
     * optional collaborator).
     */
    private const TOKEN_PROVIDER_CLASS = 'OC\\Authentication\\Token\\IProvider';

    /**
     * Constructor.
     *
     * @param ObjectService        $objectService        OpenRegister object service (ADR-022 boundary)
     *                                                   — resolves the owning Application by slug
     *                                                   and writes the `attach`-mode object file
     *                                                   reference.
     * @param RegisterMapper       $registerMapper       Resolves the shared `openbuild` register id.
     * @param SchemaMapper         $schemaMapper         Resolves the `application` schema id.
     * @param JobOwnerImpersonator $ownerImpersonator    Impersonates the Application owner (design.md Decision 1).
     * @param RuleActionDispatcher $ruleActionDispatcher Dispatches the `notify` output mode's notification
     *                                                   (reuse of the existing send-notification path).
     * @param IUserSession         $userSession          Reads the impersonated user for the token mint.
     * @param IURLGenerator        $urlGenerator         Builds the absolute Docudesk generate-route URL.
     * @param IClientService       $httpClientService    NC HTTP client factory (the one internal call).
     * @param IRootFolder          $rootFolder           `attach` mode — writes to the owner's
     *                                                   Nextcloud Files.
     * @param IAppDataFactory      $appDataFactory       `download-link` mode — writes to
     *                                                   OpenBuild's own app-private storage (never
     *                                                   the user's Files tree).
     * @param ContainerInterface   $container            PSR container — lazily resolves the
     *                                                   optional NC-core token provider (see {@see
     *                                                   self::TOKEN_PROVIDER_CLASS}).
     * @param LoggerInterface      $logger               PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly JobOwnerImpersonator $ownerImpersonator,
        private readonly RuleActionDispatcher $ruleActionDispatcher,
        private readonly IUserSession $userSession,
        private readonly IURLGenerator $urlGenerator,
        private readonly IClientService $httpClientService,
        private readonly IRootFolder $rootFolder,
        private readonly IAppDataFactory $appDataFactory,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Generate a document for the fired object per one `generateDocument`
     * action, impersonating the owning Application's owner for the whole
     * operation (the internal Docudesk call AND the attach-mode Files write
     * both need that context).
     *
     * @param array<string,mixed> $automation The matching Automation object (needs `applicationSlug`).
     * @param array<string,mixed> $action     The `generateDocument` action record (`templateId`, `output`).
     * @param string              $schemaSlug The fired object's schema slug.
     * @param string              $objectUuid The fired object's uuid.
     *
     * @return bool Whether generation succeeded.
     *
     * @spec openspec/changes/automation-document-action/tasks.md#2.1
     * @spec openspec/changes/automation-document-action/tasks.md#2.2
     * @spec openspec/changes/automation-document-action/specs/automation-document-action/spec.md
     */
    public function generate(array $automation, array $action, string $schemaSlug, string $objectUuid): bool
    {
        $templateId  = (string) ($action['templateId'] ?? '');
        $outputModes = $this->normaliseOutputModes(raw: $action['output'] ?? null);
        if ($templateId === '' || $outputModes === []) {
            $this->logger->warning('OpenBuild: DocumentGenerationService skipped — invalid generateDocument action config.');
            return false;
        }

        $applicationSlug = (string) ($automation['applicationSlug'] ?? '');
        $applicationUuid = $this->resolveApplicationUuid(slug: $applicationSlug);
        if ($applicationUuid === null) {
            $this->logger->warning(
                'OpenBuild: DocumentGenerationService could not resolve Application "'.$applicationSlug.'" — generation skipped.'
            );
            return false;
        }

        try {
            $result = $this->ownerImpersonator->runAsOwner(
                objectId: $applicationUuid,
                work: function () use ($templateId, $outputModes, $schemaSlug, $objectUuid): bool {
                    return $this->generateImpersonated(
                        templateId: $templateId,
                        outputModes: $outputModes,
                        schemaSlug: $schemaSlug,
                        objectUuid: $objectUuid
                    );
                }
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: DocumentGenerationService failed for object "'.$objectUuid.'": '.$e->getMessage(),
                ['exception' => $e]
            );
            return false;
        }

        return (bool) $result;

    }//end generate()

    /**
     * The impersonated unit of work: call Docudesk, then dispatch every
     * configured output mode.
     *
     * @param string            $templateId  Docudesk template UUID.
     * @param array<int,string> $outputModes Validated, non-empty output modes.
     * @param string            $schemaSlug  The fired object's schema slug.
     * @param string            $objectUuid  The fired object's uuid.
     *
     * @return bool
     */
    private function generateImpersonated(string $templateId, array $outputModes, string $schemaSlug, string $objectUuid): bool
    {
        $dataRef  = ['register' => self::REGISTER_SLUG, 'schema' => $schemaSlug, 'id' => $objectUuid];
        $filename = 'document-'.$objectUuid.'.pdf';

        $response = $this->callGenerate(templateId: $templateId, dataRefs: [$dataRef], filename: $filename);
        if ($response === null) {
            return false;
        }

        $fileRef      = null;
        $downloadLink = null;

        if (in_array('attach', $outputModes, true) === true) {
            $fileRef = $this->attachToFiles(filename: $filename, body: (string) $response['body']);
            if ($fileRef !== null) {
                $this->writeAttachmentReference(schemaSlug: $schemaSlug, objectUuid: $objectUuid, fileId: $fileRef);
            }
        }

        if (in_array('download-link', $outputModes, true) === true) {
            $downloadLink = $this->writeDownloadLink(
                filename: $filename,
                body: (string) $response['body'],
                contentType: (string) ($response['contentType'] ?? 'application/octet-stream')
            );
        }

        if (in_array('notify', $outputModes, true) === true) {
            $this->dispatchNotification(objectUuid: $objectUuid, fileRef: $fileRef, downloadLink: $downloadLink);
        }

        return $fileRef !== null || $downloadLink !== null || in_array('notify', $outputModes, true) === true;

    }//end generateImpersonated()

    /**
     * Validate + normalise the action's `output` field to a non-empty list
     * of known modes; `notify` alone (with neither `attach` nor
     * `download-link`) is rejected as incomplete (design.md Decision 3).
     *
     * @param mixed $raw The action's raw `output` value.
     *
     * @return array<int,string> Normalised modes, or `[]` when invalid.
     */
    private function normaliseOutputModes(mixed $raw): array
    {
        $modes = [];
        if (is_array($raw) === true) {
            $modes = $raw;
        } else if (is_string($raw) === true && $raw !== '') {
            // Tolerate the single-string shorthand shown in design.md's seed
            // data example.
            $modes = [$raw];
        }

        $modes = array_values(
            array_unique(
                array_filter(
                    $modes,
                    static fn ($m): bool => is_string($m) === true && in_array($m, self::OUTPUT_MODES, true) === true
                )
            )
        );

        if ($modes === []) {
            return [];
        }

        $hasNotify       = in_array('notify', $modes, true);
        $hasDeliveryMode = in_array('attach', $modes, true) === true || in_array('download-link', $modes, true) === true;
        if ($hasNotify === true && $hasDeliveryMode === false) {
            return [];
        }

        return $modes;

    }//end normaliseOutputModes()

    /**
     * Resolve the owning Application's uuid by slug (established scattered
     * pattern in this codebase — see e.g.
     * {@see \OCA\OpenBuild\Service\ManifestResolverService::findApplicationBySlug()}).
     *
     * @param string $slug The Application slug.
     *
     * @return string|null
     */
    private function resolveApplicationUuid(string $slug): ?string
    {
        if ($slug === '') {
            return null;
        }

        try {
            $registerId = $this->registerMapper->find(self::REGISTER_SLUG, _multitenancy: false)->getId();
            $schemaId   = $this->schemaMapper->find(self::APPLICATION_SCHEMA, _multitenancy: false)->getId();

            $results = $this->objectService->searchObjects(
                query: [
                    '@self' => ['register' => $registerId, 'schema' => $schemaId],
                    'slug'  => $slug,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning('OpenBuild: DocumentGenerationService findApplicationBySlug failed for "'.$slug.'": '.$e->getMessage());
            return null;
        }

        if (empty($results) === true) {
            return null;
        }

        $application = $this->normalise(object: $results[0]);
        $uuid        = (string) ($application['id'] ?? $application['uuid'] ?? '');

        if ($uuid === '') {
            return null;
        }

        return $uuid;

    }//end resolveApplicationUuid()

    /**
     * The one internal HTTP call to Docudesk's pinned generate route, HTTP
     * Basic-authenticated with a single-use token minted for the currently
     * impersonated user (see class docblock "Transport detail").
     *
     * @param string                          $templateId Docudesk template UUID.
     * @param array<int,array<string,string>> $dataRefs   Exactly one `{register,schema,id}` entry
     *                                                    (REQ: "Object data maps to template variables
     *                                                    exclusively via dataRefs" — no flattening here).
     * @param string                          $filename   Requested download filename.
     *
     * @return array{status:int,body:string,contentType:?string}|null Null on any failure.
     */
    private function callGenerate(string $templateId, array $dataRefs, string $filename): ?array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            $this->logger->warning('OpenBuild: DocumentGenerationService has no impersonated user active — Docudesk call skipped.');
            return null;
        }

        $uid    = $user->getUID();
        $minted = $this->mintOneTimeToken(uid: $uid);
        if ($minted === null) {
            $this->logger->error(
                'OpenBuild: DocumentGenerationService could not mint an internal auth token for "'.$uid.'" — Docudesk call skipped.'
            );
            return null;
        }

        [$token, $provider] = $minted;
        $url = $this->urlGenerator->linkToRouteAbsolute(self::GENERATE_ROUTE);

        try {
            try {
                $response = $this->httpClientService->newClient()->post(
                    $url,
                    [
                        'auth'    => [$uid, $token],
                        'headers' => ['OCS-APIRequest' => 'true'],
                        'json'    => [
                            'templateId' => $templateId,
                            'dataRefs'   => $dataRefs,
                            'options'    => ['format' => 'pdf'],
                            'filename'   => $filename,
                        ],
                        'timeout' => 30,
                    ]
                );
            } catch (Throwable $e) {
                $this->logger->error('OpenBuild: DocumentGenerationService Docudesk call failed: '.$e->getMessage(), ['exception' => $e]);
                return null;
            }
        } finally {
            // ALWAYS invalidate — the token is scoped to exactly this one
            // call (class docblock "Transport detail"); a TEMPORARY_TOKEN
            // (the only lifetime constant guaranteed present across the
            // pinned `nextcloud/ocp` versions this app supports) would
            // otherwise remain valid until its normal session-expiry.
            $this->invalidateToken(provider: $provider, token: $token);
        }//end try

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->logger->warning('OpenBuild: DocumentGenerationService Docudesk call returned status '.$status.'.');
            return null;
        }

        return [
            'status'      => $status,
            'body'        => $response->getBody(),
            'contentType' => $response->getHeader('Content-Type'),
        ];

    }//end callGenerate()

    /**
     * Mint a short-lived Nextcloud login token for `$uid`, presented as HTTP
     * Basic Auth on the one internal call (class docblock "Transport
     * detail"), explicitly invalidated by the caller immediately after that
     * one call for true single-use semantics. Best-effort/soft-optional —
     * mirrors {@see JobOwnerImpersonator::impersonate()}'s defensive
     * container-lookup shape for an NC-core collaborator that is not part
     * of the public `nextcloud/ocp` stub surface.
     *
     * `IToken::TEMPORARY_TOKEN` (not `ONETIME_TOKEN`) is used deliberately —
     * `ONETIME_TOKEN` is not present on every `nextcloud/ocp` stub version
     * this app's `composer.json` range resolves to; `TEMPORARY_TOKEN` has
     * been stable since NC 28. The explicit {@see invalidateToken()} call
     * right after use gives the same effective one-shot lifetime.
     *
     * @param string $uid The impersonated user's uid.
     *
     * @return array{0:string,1:object}|null Tuple of `[tokenSecret, provider]`, or null on any failure.
     */
    private function mintOneTimeToken(string $uid): ?array
    {
        try {
            if ($this->container->has(self::TOKEN_PROVIDER_CLASS) === false) {
                return null;
            }

            $provider = $this->container->get(self::TOKEN_PROVIDER_CLASS);
            if (method_exists($provider, 'generateToken') === false) {
                return null;
            }

            $secret = bin2hex(random_bytes(36));
            $provider->generateToken(
                $secret,
                $uid,
                $uid,
                null,
                'OpenBuild automation (generateDocument)',
                IToken::TEMPORARY_TOKEN,
                IToken::DO_NOT_REMEMBER
            );

            return [$secret, $provider];
        } catch (Throwable $e) {
            $this->logger->warning('OpenBuild: DocumentGenerationService could not mint an internal auth token: '.$e->getMessage());
            return null;
        }//end try

    }//end mintOneTimeToken()

    /**
     * Best-effort invalidation of the one-off token minted by
     * {@see mintOneTimeToken()} — logs but never throws.
     *
     * @param object $provider The token provider returned by {@see mintOneTimeToken()}.
     * @param string $token    The token secret to invalidate.
     *
     * @return void
     */
    private function invalidateToken(object $provider, string $token): void
    {
        try {
            if (method_exists($provider, 'invalidateToken') === true) {
                $provider->invalidateToken($token);
            }
        } catch (Throwable $e) {
            $this->logger->warning('OpenBuild: DocumentGenerationService could not invalidate the internal auth token: '.$e->getMessage());
        }

    }//end invalidateToken()

    /**
     * `attach` output mode — write the returned bytes to the impersonated
     * owner's Nextcloud Files (design.md Decision 3).
     *
     * @param string $filename The download filename.
     * @param string $body     The generated document bytes.
     *
     * @return string|null The new file's NC file id, or null on failure.
     */
    private function attachToFiles(string $filename, string $body): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        try {
            $folder = $this->rootFolder->getUserFolder($user->getUID());

            // `Folder::getOrCreateFolder()` is not on every `nextcloud/ocp`
            // stub version this app's composer.json range resolves to
            // (same class of gap as `IToken::ONETIME_TOKEN` — see
            // mintOneTimeToken()'s docblock) — get-or-create is composed
            // manually from the widely-available `nodeExists`/`get`/
            // `newFolder` trio instead.
            if ($folder->nodeExists(self::ATTACH_SUBFOLDER) === true) {
                $target = $folder->get(self::ATTACH_SUBFOLDER);
            } else {
                $target = $folder->newFolder(self::ATTACH_SUBFOLDER);
            }

            if (($target instanceof Folder) === false) {
                // A non-folder node already occupies that path — should
                // never happen for a name this class alone manages, but
                // fail safe rather than fatal.
                $this->logger->error('OpenBuild: DocumentGenerationService attach target "'.self::ATTACH_SUBFOLDER.'" is not a folder.');
                return null;
            }

            $path = $this->uniqueChildName(folder: $target, filename: $filename);
            $file = $target->newFile($path, $body);

            return (string) $file->getId();
        } catch (Throwable $e) {
            $this->logger->error('OpenBuild: DocumentGenerationService attach-to-Files failed: '.$e->getMessage(), ['exception' => $e]);
            return null;
        }//end try

    }//end attachToFiles()

    /**
     * Avoid clobbering an existing file of the same name (unlikely but
     * possible on a fast-repeating trigger) by suffixing `-2`, `-3`, ….
     *
     * @param Folder $folder   The parent folder.
     * @param string $filename The desired filename.
     *
     * @return string
     */
    private function uniqueChildName(Folder $folder, string $filename): string
    {
        if ($folder->nodeExists($filename) === false) {
            return $filename;
        }

        $dot  = strrpos($filename, '.');
        $base = $filename;
        $ext  = '';
        if ($dot !== false) {
            $base = substr($filename, 0, $dot);
            $ext  = substr($filename, $dot);
        }

        $i = 2;
        while ($folder->nodeExists($base.'-'.$i.$ext) === true) {
            $i++;
        }

        return $base.'-'.$i.$ext;

    }//end uniqueChildName()

    /**
     * Write the `{ "ref": "<fileId>" }` reference onto the triggering
     * object's {@see self::ATTACHMENT_FIELD} (ADR-001 `{ref}` shape).
     *
     * @param string $schemaSlug The fired object's schema slug.
     * @param string $objectUuid The fired object's uuid.
     * @param string $fileId     The written file's NC file id.
     *
     * @return void
     */
    private function writeAttachmentReference(string $schemaSlug, string $objectUuid, string $fileId): void
    {
        try {
            $this->objectService->saveObject(
                object: [self::ATTACHMENT_FIELD => ['ref' => $fileId]],
                register: self::REGISTER_SLUG,
                schema: $schemaSlug,
                uuid: $objectUuid
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: DocumentGenerationService could not write the attachment reference onto "'.$objectUuid.'": '.$e->getMessage()
            );
        }

    }//end writeAttachmentReference()

    /**
     * `download-link` output mode — write the returned bytes to OpenBuild's
     * OWN app-private storage (never the user's Files tree) behind a random
     * token, with a metadata sidecar carrying the ~24h expiry
     * ({@see self::DOWNLOAD_LINK_TTL_SECONDS}) served by
     * `GeneratedDocumentController`.
     *
     * @param string $filename    The download filename.
     * @param string $body        The generated document bytes.
     * @param string $contentType The response's content type.
     *
     * @return string|null The signed download URL, or null on failure.
     */
    private function writeDownloadLink(string $filename, string $body, string $contentType): ?string
    {
        try {
            $appData = $this->appDataFactory->get('openbuild');
            try {
                $root = $appData->getFolder(self::APPDATA_FOLDER);
            } catch (Throwable $e) {
                $root = $appData->newFolder(self::APPDATA_FOLDER);
            }
        } catch (Throwable $e) {
            $this->logger->error('OpenBuild: DocumentGenerationService could not open app-data storage: '.$e->getMessage());
            return null;
        }

        $token = bin2hex(random_bytes(24));

        try {
            $tokenFolder = $root->newFolder($token);
            $tokenFolder->newFile('document', $body);
            $tokenFolder->newFile(
                'meta.json',
                json_encode(
                    [
                        'filename'    => $filename,
                        'contentType' => $contentType,
                        'expiresAt'   => (time() + self::DOWNLOAD_LINK_TTL_SECONDS),
                    ],
                    JSON_THROW_ON_ERROR
                )
            );
        } catch (Throwable $e) {
            $this->logger->error('OpenBuild: DocumentGenerationService could not persist the download-link artifact: '.$e->getMessage());
            return null;
        }

        return $this->urlGenerator->linkToRouteAbsolute(
            'openbuild.generatedDocument.download',
            ['token' => $token]
        );

    }//end writeDownloadLink()

    /**
     * `notify` output mode — dispatch through the existing
     * {@see RuleActionDispatcher} `send-notification` path (reuse, not a
     * second notification implementation).
     *
     * @param string      $objectUuid   The fired object's uuid (notification's `object` id).
     * @param string|null $fileRef      The `attach`-mode file id, if any.
     * @param string|null $downloadLink The `download-link`-mode signed URL, if any.
     *
     * @return void
     */
    private function dispatchNotification(string $objectUuid, ?string $fileRef, ?string $downloadLink): void
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return;
        }

        $subject = 'Your generated document is ready';
        if ($downloadLink !== null) {
            $subject = 'Your generated document is ready to download';
        } else if ($fileRef !== null) {
            $subject = 'Your generated document has been attached';
        }

        ($this->ruleActionDispatcher)(
            'send-notification',
            [
                'subject'      => $subject,
                'recipientUid' => $user->getUID(),
                'objectId'     => $objectUuid,
            ],
            []
        );

    }//end dispatchNotification()

    /**
     * Coerce an OR result entry to a plain associative array.
     *
     * @param mixed $object The OR object/result entry.
     *
     * @return array<string,mixed>
     */
    private function normalise(mixed $object): array
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

    }//end normalise()
}//end class
