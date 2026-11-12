<?php

/**
 * Buildiq DocumentGenerationService
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Imperative half of the `generateDocument` automation action (design.md
 * Decision 1/2/3 of automation-document-action). On trigger fire, calls
 * Docudesk's EXISTING, already-integration-tested public route
 * `POST /apps/filinq/api/correspondence/generate` — the SAME pinned route
 * `docudesk-document-templates` REQ-DDT-006 already closes the integration
 * surface to for interactive, browser-driven generation — and nothing else.
 * This class never imports an `OCA\Filinq\*` class and never reads a
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
 * `download-link` writes the bytes to Buildiq's OWN app-private storage
 * (`OCP\Files\IAppData` — never the user-visible Files tree, satisfying "no
 * file is written to Nextcloud Files") behind a random, single-use-scoped
 * token with a ~24h TTL, served by {@see \OCA\Buildiq\Controller\GeneratedDocumentController};
 * `notify` dispatches a notification through the existing
 * {@see RuleActionDispatcher} (reuse, not a second notification path) and
 * MUST be paired with `attach` and/or `download-link`.
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
 * @spec openspec/changes/automation-document-action/tasks.md#2.1
 * @spec openspec/changes/automation-document-action/tasks.md#2.2
 * @spec openspec/changes/automation-document-action/tasks.md#2.3
 * @spec openspec/changes/automation-document-action/tasks.md#2.4
 * @spec openspec/changes/automation-document-action/tasks.md#2.5
 * @spec openspec/changes/automation-document-action/specs/automation-document-action/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Service\Connection\ConnectionReporter;
use OCA\Buildiq\Support\FleetAppId;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
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
class DocumentGenerationService {
	/**
	 * Shared Buildiq register slug (matches {@see AutomationCompilerService::REGISTER_SLUG}).
	 */
	public const REGISTER_SLUG = AutomationCompilerService::REGISTER_SLUG;

	/**
	 * Schema slug of the `application` object.
	 */
	private const APPLICATION_SCHEMA = 'built-app';

	/**
	 * Route name of the generate route, without its app id
	 * (`OCA\Filinq\Controller\CorrespondenceController::generate()`,
	 * `appinfo/routes.php` entry `correspondence#generate`). The app id in
	 * front is resolved per instance, see {@see self::generateRoute()}.
	 */
	private const GENERATE_ROUTE_SUFFIX = 'correspondence.generate';

	/**
	 * Field the `attach` output mode writes `{ "ref": "<fileId>" }" to on
	 * the triggering object.
	 */
	public const ATTACHMENT_FIELD = 'generatedDocument';

	/**
	 * `attach` output mode's subfolder name in the impersonated owner's
	 * Nextcloud Files.
	 */
	private const ATTACH_SUBFOLDER = 'Buildiq generated documents';

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
	 * @param ObjectServiceInterface $objectService OpenRegister object service (ADR-022 boundary)
	 *                                              — resolves the owning Application by slug
	 *                                              and writes the `attach`-mode object file
	 *                                              reference.
	 * @param RegisterMapper $registerMapper Resolves the shared `buildiq` register id.
	 * @param SchemaMapper $schemaMapper Resolves the `application` schema id.
	 * @param JobOwnerImpersonator $ownerImpersonator Impersonates the Application owner (design.md Decision 1).
	 * @param RuleActionDispatcher $ruleActionDispatcher Dispatches the `notify` output mode's notification
	 *                                                   (reuse of the existing send-notification path).
	 * @param IUserSession $userSession Reads the impersonated user for the token mint.
	 * @param IURLGenerator $urlGenerator Builds the absolute Docudesk generate-route URL.
	 * @param IClientService $httpClientService NC HTTP client factory (the one internal call).
	 * @param IRootFolder $rootFolder `attach` mode — writes to the owner's
	 *                                Nextcloud Files.
	 * @param IAppDataFactory $appDataFactory `download-link` mode — writes to
	 *                                        Buildiq's own app-private storage (never
	 *                                        the user's Files tree).
	 * @param ContainerInterface $container PSR container — lazily resolves the
	 *                                      optional NC-core token provider (see {@see
	 *                                      self::TOKEN_PROVIDER_CLASS}).
	 * @param LoggerInterface $logger PSR logger.
	 * @param ConnectionReporter|null $connectionReporter Tells integriq what the Filinq call met, or nothing when absent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
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
		private readonly ?ConnectionReporter $connectionReporter = null,
	) {

	}//end __construct()

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
	 * @spec openspec/changes/automation-document-action/tasks.md#2.1
	 * @spec openspec/changes/automation-document-action/tasks.md#2.2
	 * @spec openspec/changes/automation-document-action/specs/automation-document-action/spec.md
	 */
	public function generate(array $automation, array $action, string $schemaSlug, string $objectUuid): bool {
		$templateId = (string)($action['templateId'] ?? '');
		$outputModes = $this->normaliseOutputModes(raw: $action['output'] ?? null);
		$run = new DocumentRun(templateId: $templateId, outputModes: $outputModes, schemaSlug: $schemaSlug, objectUuid: $objectUuid);
		if ($this->isRunnable(run: $run) === false) {
			return false;
		}

		$applicationUuid = $this->applicationUuidFor(automation: $automation);
		if ($applicationUuid === null) {
			return false;
		}

		return $this->runAsOwner(applicationUuid: $applicationUuid, run: $run);
	}//end generate()

	/**
	 * Whether the action names a template and at least one valid output mode.
	 *
	 * @param DocumentRun $run The generation.
	 *
	 * @return bool False (after a warning) when the action config is invalid.
	 */
	private function isRunnable(DocumentRun $run): bool {
		if ($run->templateId === '' || $run->outputModes === []) {
			$this->logger->warning('Buildiq: DocumentGenerationService skipped — invalid generateDocument action config.');
			return false;
		}

		return true;
	}//end isRunnable()

	/**
	 * The uuid of the Application the automation belongs to.
	 *
	 * @param array<string,mixed> $automation The matching Automation object (needs `applicationSlug`).
	 *
	 * @return string|null Null (after a warning) when the Application cannot be resolved.
	 */
	private function applicationUuidFor(array $automation): ?string {
		$applicationSlug = (string)($automation['applicationSlug'] ?? '');
		$applicationUuid = $this->resolveApplicationUuid(slug: $applicationSlug);
		if ($applicationUuid === null) {
			$this->logger->warning(
				'Buildiq: DocumentGenerationService could not resolve Application "' . $applicationSlug . '" — generation skipped.'
			);
		}

		return $applicationUuid;
	}//end applicationUuidFor()

	/**
	 * Run the generation as the owning Application's owner.
	 *
	 * @param string      $applicationUuid The owning Application's uuid.
	 * @param DocumentRun $run             The generation.
	 *
	 * @return bool Whether generation succeeded.
	 */
	private function runAsOwner(string $applicationUuid, DocumentRun $run): bool {
		try {
			$result = $this->ownerImpersonator->runAsOwner(
				objectId: $applicationUuid,
				work: fn (): bool => $this->generateImpersonated(run: $run)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: DocumentGenerationService failed for object "' . $run->objectUuid . '": ' . $e->getMessage(),
				['exception' => $e]
			);
			return false;
		}

		return (bool)$result;
	}//end runAsOwner()

	/**
	 * The impersonated unit of work: call Docudesk, then dispatch every
	 * configured output mode.
	 *
	 * @param DocumentRun $run The generation (validated, non-empty output modes).
	 *
	 * @return bool
	 */
	private function generateImpersonated(DocumentRun $run): bool {
		$dataRef = ['register' => self::REGISTER_SLUG, 'schema' => $run->schemaSlug, 'id' => $run->objectUuid];

		$response = $this->callGenerate(templateId: $run->templateId, dataRefs: [$dataRef], filename: $run->filename());
		if ($response === null) {
			return false;
		}

		$this->attachOutput(run: $run, response: $response);
		$this->downloadLinkOutput(run: $run, response: $response);

		if (in_array('notify', $run->outputModes, true) === true) {
			$this->dispatchNotification(run: $run);
		}

		return $run->fileRef !== null || $run->downloadLink !== null || in_array('notify', $run->outputModes, true) === true;
	}//end generateImpersonated()

	/**
	 * `attach` output mode, when configured: write the document to Files and
	 * reference it on the object.
	 *
	 * @param DocumentRun                                         $run      The generation.
	 * @param array{status:int,body:string,contentType:?string} $response Docudesk's answer.
	 *
	 * @return void
	 */
	private function attachOutput(DocumentRun $run, array $response): void {
		if (in_array('attach', $run->outputModes, true) === false) {
			return;
		}

		$run->fileRef = $this->attachToFiles(filename: $run->filename(), body: (string)$response['body']);
		if ($run->fileRef !== null) {
			$this->writeAttachmentReference(schemaSlug: $run->schemaSlug, objectUuid: $run->objectUuid, fileId: $run->fileRef);
		}
	}//end attachOutput()

	/**
	 * `download-link` output mode, when configured.
	 *
	 * @param DocumentRun                                         $run      The generation.
	 * @param array{status:int,body:string,contentType:?string} $response Docudesk's answer.
	 *
	 * @return void
	 */
	private function downloadLinkOutput(DocumentRun $run, array $response): void {
		if (in_array('download-link', $run->outputModes, true) === false) {
			return;
		}

		$run->downloadLink = $this->writeDownloadLink(
			filename: $run->filename(),
			body: (string)$response['body'],
			contentType: (string)($response['contentType'] ?? 'application/octet-stream')
		);
	}//end downloadLinkOutput()

	/**
	 * Validate + normalise the action's `output` field to a non-empty list
	 * of known modes; `notify` alone (with neither `attach` nor
	 * `download-link`) is rejected as incomplete (design.md Decision 3).
	 *
	 * @param mixed $raw The action's raw `output` value.
	 *
	 * @return array<int,string> Normalised modes, or `[]` when invalid.
	 */
	private function normaliseOutputModes(mixed $raw): array {
		$modes = [];
		if (is_array($raw) === true) {
			$modes = $raw;
		} elseif (is_string($raw) === true && $raw !== '') {
			// Tolerate the single-string shorthand shown in design.md's seed
			// data example.
			$modes = [$raw];
		}

		$modes = array_values(
			array_unique(
				array_filter(
					$modes,
					static fn ($mode): bool => is_string($mode) === true && in_array($mode, self::OUTPUT_MODES, true) === true
				)
			)
		);

		if ($modes === []) {
			return [];
		}

		$hasNotify = in_array('notify', $modes, true);
		$hasDeliveryMode = in_array('attach', $modes, true) === true || in_array('download-link', $modes, true) === true;
		if ($hasNotify === true && $hasDeliveryMode === false) {
			return [];
		}

		return $modes;
	}//end normaliseOutputModes()

	/**
	 * Resolve the owning Application's uuid by slug (established scattered
	 * pattern in this codebase — see e.g.
	 * {@see \OCA\Buildiq\Service\ManifestResolverService::findApplicationBySlug()}).
	 *
	 * @param string $slug The Application slug.
	 *
	 * @return string|null
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	private function resolveApplicationUuid(string $slug): ?string {
		if ($slug === '') {
			return null;
		}

		try {
			$registerId = $this->registerMapper->find(self::REGISTER_SLUG, _multitenancy: false)->getId();
			$schemaId = $this->schemaMapper->find(self::APPLICATION_SCHEMA, _multitenancy: false)->getId();

			$results = $this->objectService->searchObjects(
				query: [
					'@self' => ['register' => $registerId, 'schema' => $schemaId],
					'slug' => $slug,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: DocumentGenerationService findApplicationBySlug failed for "' . $slug . '": ' . $e->getMessage());
			return null;
		}

		if (empty($results) === true) {
			return null;
		}

		$application = ObjectRecord::toArray(object: $results[0]);
		$uuid = (string)($application['id'] ?? $application['uuid'] ?? '');

		if ($uuid === '') {
			return null;
		}

		return $uuid;
	}//end resolveApplicationUuid()

	/**
	 * Resolve the generate route on this instance.
	 *
	 * The document app was renamed from docudesk to filinq, and its routes
	 * are registered under whichever id the instance runs. Each candidate id
	 * is tried newest first. Nextcloud's router answers '' for a route no
	 * enabled app registers, and linkToRouteAbsolute() turns that into the
	 * bare instance URL, so both mean "not this one".
	 *
	 * @return array{0: string, 1: string|null} The route name, and its URL or
	 *                                          null when no candidate answers
	 *                                          (the name is then the newest
	 *                                          candidate, for the report).
	 *
	 * @spec openspec/changes/automation-document-action/tasks.md#2.1
	 */
	private function generateRoute(): array {
		$candidates = FleetAppId::CANDIDATES['filinq'];
		$root       = $this->urlGenerator->getAbsoluteURL('');
		foreach ($candidates as $appId) {
			$route = $appId . '.' . self::GENERATE_ROUTE_SUFFIX;
			$url   = $this->urlGenerator->linkToRouteAbsolute($route);
			if ($url !== '' && $url !== $root) {
				return [$route, $url];
			}
		}

		return [$candidates[0] . '.' . self::GENERATE_ROUTE_SUFFIX, null];

	}//end generateRoute()

	/**
	 * The one internal HTTP call to Docudesk's pinned generate route, HTTP
	 * Basic-authenticated with a single-use token minted for the currently
	 * impersonated user (see class docblock "Transport detail").
	 *
	 * What the call met is reported to integriq's connection registry, at most
	 * once an hour while it stays the same (adopt-connection-registry). The
	 * report never changes the result.
	 *
	 * @param string $templateId Docudesk template UUID.
	 * @param array<int,array<string,string>> $dataRefs Exactly one `{register,schema,id}` entry
	 *                                                  (REQ: "Object data maps to template variables
	 *                                                  exclusively via dataRefs" — no flattening here).
	 * @param string $filename Requested download filename.
	 *
	 * @return array{status:int,body:string,contentType:?string}|null Null on any failure.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
	 */
	private function callGenerate(string $templateId, array $dataRefs, string $filename): ?array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			$this->logger->warning('Buildiq: DocumentGenerationService has no impersonated user active — Docudesk call skipped.');
			return null;
		}

		$uid = $user->getUID();
		$minted = $this->mintOneTimeToken(uid: $uid);
		if ($minted === null) {
			$this->logger->error(
				'Buildiq: DocumentGenerationService could not mint an internal auth token for "' . $uid . '" — Docudesk call skipped.'
			);
			return null;
		}

		[$token, $provider] = $minted;
		[$route, $url] = $this->generateRoute();
		if ($url === null) {
			$this->invalidateToken(provider: $provider, token: $token);
			$this->logger->error(
				'Buildiq: DocumentGenerationService found no route "' . $route . '", so the document call is skipped. '
				. 'Tried these app ids in order: ' . implode(', ', FleetAppId::CANDIDATES['filinq']) . '.'
			);
			$this->connectionReporter?->reportDocumentRouteMissing(route: $route);
			return null;
		}

		try {
			try {
				$response = $this->httpClientService->newClient()->post(
					$url,
					[
						'auth' => [$uid, $token],
						'headers' => ['OCS-APIRequest' => 'true'],
						'json' => [
							'templateId' => $templateId,
							'dataRefs' => $dataRefs,
							'options' => ['format' => 'pdf'],
							'filename' => $filename,
						],
						'timeout' => 30,
					]
				);
			} catch (Throwable $e) {
				$this->logger->error('Buildiq: DocumentGenerationService Docudesk call failed: ' . $e->getMessage(), ['exception' => $e]);
				$this->connectionReporter?->reportDocumentCall(httpStatus: $this->connectionReporter->httpStatusOf(exception: $e));
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
		$this->connectionReporter?->reportDocumentCall(httpStatus: $status);
		if ($status < 200 || $status >= 300) {
			$this->logger->warning('Buildiq: DocumentGenerationService Docudesk call returned status ' . $status . '.');
			return null;
		}

		return [
			'status' => $status,
			'body' => $response->getBody(),
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
	private function mintOneTimeToken(string $uid): ?array {
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
				'Buildiq automation (generateDocument)',
				IToken::TEMPORARY_TOKEN,
				IToken::DO_NOT_REMEMBER
			);

			return [$secret, $provider];
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: DocumentGenerationService could not mint an internal auth token: ' . $e->getMessage());
			return null;
		}//end try

	}//end mintOneTimeToken()

	/**
	 * Best-effort invalidation of the one-off token minted by
	 * {@see mintOneTimeToken()} — logs but never throws.
	 *
	 * @param object $provider The token provider returned by {@see mintOneTimeToken()}.
	 * @param string $token The token secret to invalidate.
	 *
	 * @return void
	 */
	private function invalidateToken(object $provider, string $token): void {
		try {
			if (method_exists($provider, 'invalidateToken') === true) {
				$provider->invalidateToken($token);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: DocumentGenerationService could not invalidate the internal auth token: ' . $e->getMessage());
		}

	}//end invalidateToken()

	/**
	 * `attach` output mode — write the returned bytes to the impersonated
	 * owner's Nextcloud Files (design.md Decision 3).
	 *
	 * @param string $filename The download filename.
	 * @param string $body The generated document bytes.
	 *
	 * @return string|null The new file's NC file id, or null on failure.
	 */
	private function attachToFiles(string $filename, string $body): ?string {
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
			$target = null;
			if ($folder->nodeExists(self::ATTACH_SUBFOLDER) === true) {
				$target = $folder->get(self::ATTACH_SUBFOLDER);
			}

			if ($target === null) {
				$target = $folder->newFolder(self::ATTACH_SUBFOLDER);
			}

			if (($target instanceof Folder) === false) {
				// A non-folder node already occupies that path — should
				// never happen for a name this class alone manages, but
				// fail safe rather than fatal.
				$this->logger->error('Buildiq: DocumentGenerationService attach target "' . self::ATTACH_SUBFOLDER . '" is not a folder.');
				return null;
			}

			$path = $this->uniqueChildName(folder: $target, filename: $filename);
			$file = $target->newFile($path, $body);

			return (string)$file->getId();
		} catch (Throwable $e) {
			$this->logger->error('Buildiq: DocumentGenerationService attach-to-Files failed: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}//end try

	}//end attachToFiles()

	/**
	 * Avoid clobbering an existing file of the same name (unlikely but
	 * possible on a fast-repeating trigger) by suffixing `-2`, `-3`, ….
	 *
	 * @param Folder $folder The parent folder.
	 * @param string $filename The desired filename.
	 *
	 * @return string
	 */
	private function uniqueChildName(Folder $folder, string $filename): string {
		if ($folder->nodeExists($filename) === false) {
			return $filename;
		}

		$dot = strrpos($filename, '.');
		$base = $filename;
		$ext = '';
		if ($dot !== false) {
			$base = substr($filename, 0, $dot);
			$ext = substr($filename, $dot);
		}

		$seq = 2;
		while ($folder->nodeExists($base . '-' . $seq . $ext) === true) {
			$seq++;
		}

		return $base . '-' . $seq . $ext;
	}//end uniqueChildName()

	/**
	 * Write the `{ "ref": "<fileId>" }` reference onto the triggering
	 * object's {@see self::ATTACHMENT_FIELD} (ADR-001 `{ref}` shape).
	 *
	 * @param string $schemaSlug The fired object's schema slug.
	 * @param string $objectUuid The fired object's uuid.
	 * @param string $fileId The written file's NC file id.
	 *
	 * @return void
	 */
	private function writeAttachmentReference(string $schemaSlug, string $objectUuid, string $fileId): void {
		try {
			$this->objectService->saveObject(
				object: [self::ATTACHMENT_FIELD => ['ref' => $fileId]],
				register: self::REGISTER_SLUG,
				schema: $schemaSlug,
				uuid: $objectUuid
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: DocumentGenerationService could not write the attachment reference onto "' . $objectUuid . '": ' . $e->getMessage()
			);
		}

	}//end writeAttachmentReference()

	/**
	 * `download-link` output mode — write the returned bytes to Buildiq's
	 * OWN app-private storage (never the user's Files tree) behind a random
	 * token, with a metadata sidecar carrying the ~24h expiry
	 * ({@see self::DOWNLOAD_LINK_TTL_SECONDS}) served by
	 * `GeneratedDocumentController`.
	 *
	 * @param string $filename The download filename.
	 * @param string $body The generated document bytes.
	 * @param string $contentType The response's content type.
	 *
	 * @return string|null The signed download URL, or null on failure.
	 */
	private function writeDownloadLink(string $filename, string $body, string $contentType): ?string {
		try {
			$appData = $this->appDataFactory->get('buildiq');
			try {
				$root = $appData->getFolder(self::APPDATA_FOLDER);
			} catch (Throwable $e) {
				$root = $appData->newFolder(self::APPDATA_FOLDER);
			}
		} catch (Throwable $e) {
			$this->logger->error('Buildiq: DocumentGenerationService could not open app-data storage: ' . $e->getMessage());
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
						'filename' => $filename,
						'contentType' => $contentType,
						'expiresAt' => (time() + self::DOWNLOAD_LINK_TTL_SECONDS),
					],
					JSON_THROW_ON_ERROR
				)
			);
		} catch (Throwable $e) {
			$this->logger->error('Buildiq: DocumentGenerationService could not persist the download-link artifact: ' . $e->getMessage());
			return null;
		}

		return $this->urlGenerator->linkToRouteAbsolute(
			'buildiq.generatedDocument.download',
			['token' => $token]
		);

	}//end writeDownloadLink()

	/**
	 * `notify` output mode — dispatch through the existing
	 * {@see RuleActionDispatcher} `send-notification` path (reuse, not a
	 * second notification implementation).
	 *
	 * @param DocumentRun $run The generation: its object, and the attach / download-link results.
	 *
	 * @return void
	 */
	private function dispatchNotification(DocumentRun $run): void {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		($this->ruleActionDispatcher)(
			'send-notification',
			[
				'subject' => $this->notificationSubject(run: $run),
				'recipientUid' => $user->getUID(),
				'objectId' => $run->objectUuid,
			],
			[]
		);

	}//end dispatchNotification()

	/**
	 * The notification subject: a download link wins over an attachment.
	 *
	 * @param DocumentRun $run The generation.
	 *
	 * @return string
	 */
	private function notificationSubject(DocumentRun $run): string {
		if ($run->downloadLink !== null) {
			return 'Your generated document is ready to download';
		}

		if ($run->fileRef !== null) {
			return 'Your generated document has been attached';
		}

		return 'Your generated document is ready';
	}//end notificationSubject()
}//end class
