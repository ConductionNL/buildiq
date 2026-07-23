<?php

/**
 * OpenBuild RemoteTemplateStoreService
 *
 * Server-side proxy for the remote template "store" (openbuild-remote-template-
 * store). The store reads `application-template` objects from a REMOTE
 * OpenRegister-backed catalogue configured by an admin (registry base URL +
 * optional read token + register segment). All fetches run server-side — never
 * from the browser — so the catalogue URL/token stay private and browser CORS is
 * avoided. This service only CONSUMES the catalogue (search + resolve); the
 * install/clone is owned by StoreController via the existing template-clone path.
 *
 * Security (ADR-005): every outbound URL is SSRF-guarded with OpenRegister's
 * SecurityService::assertSafeFetchUrl (rejects private/reserved/loopback hosts +
 * non-http(s) schemes), fail-closed; the token is sent only as a Bearer header
 * and is never returned to callers; failures map to generic outcomes (the
 * upstream exception/message is logged server-side, never surfaced).
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
 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCA\OpenBuild\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only proxy to a remote OpenRegister-backed template catalogue.
 *
 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
 */
class RemoteTemplateStoreService
{
    /**
     * Outcome: the request succeeded.
     */
    public const OUTCOME_OK = 'ok';

    /**
     * Outcome: no registry configured — no network call made.
     */
    public const OUTCOME_NOT_CONFIGURED = 'not_configured';

    /**
     * Outcome: registry unreachable / timed out / non-2xx.
     */
    public const OUTCOME_UNREACHABLE = 'store_unreachable';

    /**
     * Outcome: registry returned an unparseable / unexpected body.
     */
    public const OUTCOME_INVALID = 'store_invalid_response';

    /**
     * The remote schema slug the catalogue exposes.
     */
    private const TEMPLATE_SCHEMA = 'application-template';

    /**
     * Connect + request timeout (seconds) for every remote fetch.
     */
    private const TIMEOUT = 10;

    /**
     * Constructor.
     *
     * @param IClientService  $clientService Nextcloud HTTP client factory.
     * @param IAppConfig      $appConfig     App config (registry URL / token / register).
     * @param LoggerInterface $logger        PSR logger (server-side diagnostics only).
     *
     * @return void
     */
    public function __construct(
        private readonly IClientService $clientService,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether a remote registry is configured (non-empty base URL).
     *
     * @return bool
     *
     * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
     */
    public function isConfigured(): bool
    {
        return trim($this->registryUrl()) !== '';

    }//end isConfigured()

    /**
     * Search the remote catalogue for templates.
     *
     * @param string|null $query Optional free-text search term.
     *
     * @return array{outcome: string, cards: array<int, array<string, mixed>>}
     *
     * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
     */
    public function searchTemplates(?string $query): array
    {
        if ($this->isConfigured() === false) {
            return ['outcome' => self::OUTCOME_NOT_CONFIGURED, 'cards' => []];
        }

        $params = ['_limit' => 50];
        if ($query !== null && trim($query) !== '') {
            $params['_search'] = trim($query);
        }

        $result = $this->fetch(params: $params);
        if ($result['outcome'] !== self::OUTCOME_OK) {
            return ['outcome' => $result['outcome'], 'cards' => []];
        }

        $cards = [];
        foreach ($result['results'] as $object) {
            if (is_array($object) === true) {
                $cards[] = $this->normaliseCard(object: $object);
            }
        }

        return ['outcome' => self::OUTCOME_OK, 'cards' => $cards];

    }//end searchTemplates()

    /**
     * Resolve a single remote template by slug, returning its full payload
     * (including `manifest` + `companionSchemas`) for install/clone.
     *
     * @param string $slug The template slug.
     *
     * @return array<string, mixed>|null The full template object, or null when unresolved / on error.
     *
     * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
     */
    public function resolveTemplate(string $slug): ?array
    {
        if ($this->isConfigured() === false) {
            return null;
        }

        $result = $this->fetch(params: ['slug' => $slug, '_limit' => 1]);
        if ($result['outcome'] !== self::OUTCOME_OK) {
            return null;
        }

        foreach ($result['results'] as $object) {
            if (is_array($object) === true && (string) ($object['slug'] ?? '') === $slug) {
                return $object;
            }
        }

        return null;

    }//end resolveTemplate()

    /**
     * Perform the SSRF-guarded GET against the remote catalogue's objects API.
     *
     * @param array<string, mixed> $params Query params merged into the request.
     *
     * @return array{outcome: string, results: array<int, mixed>}
     */
    private function fetch(array $params): array
    {
        try {
            $url = $this->buildUrl();
            $this->assertSafe(url: $url);
        } catch (Throwable $e) {
            $this->logger->warning(
                'OpenBuild store: rejected unsafe/invalid registry URL: '.$e->getMessage()
            );
            return ['outcome' => self::OUTCOME_UNREACHABLE, 'results' => []];
        }

        $options = [
            'timeout'         => self::TIMEOUT,
            'connect_timeout' => self::TIMEOUT,
            'query'           => $params,
            // SSRF hardening: never follow redirects. assertSafe() validates the
            // URL at a single point in time; following a 3xx would let a public
            // host redirect to a private/link-local/metadata address (or exploit
            // DNS rebinding between validation and connect) with the registry
            // Bearer token attached. A redirect instead surfaces as a non-2xx
            // status below and is handled as OUTCOME_UNREACHABLE.
            'allow_redirects' => false,
        ];

        $token = trim($this->appConfig->getValueString(Application::APP_ID, 'registry_token', ''));
        if ($token !== '') {
            $options['headers'] = ['Authorization' => 'Bearer '.$token];
        }

        try {
            $response = $this->clientService->newClient()->get($url, $options);
        } catch (Throwable $e) {
            $this->logger->warning('OpenBuild store: registry fetch failed: '.$e->getMessage());
            return ['outcome' => self::OUTCOME_UNREACHABLE, 'results' => []];
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->logger->warning('OpenBuild store: registry returned HTTP '.$status);
            return ['outcome' => self::OUTCOME_UNREACHABLE, 'results' => []];
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
            $this->logger->warning('OpenBuild store: registry returned an unparseable body');
            return ['outcome' => self::OUTCOME_INVALID, 'results' => []];
        }

        $results = ($decoded['results'] ?? null);
        if (is_array($results) === false) {
            // Some OR responses are a bare list; accept that shape too.
            $results = [];
            if (array_is_list($decoded) === true) {
                $results = $decoded;
            }
        }

        return ['outcome' => self::OUTCOME_OK, 'results' => $results];

    }//end fetch()

    /**
     * Build the remote objects-API URL for the application-template schema.
     *
     * @return string
     */
    private function buildUrl(): string
    {
        $base     = rtrim(trim($this->registryUrl()), '/');
        $register = trim($this->appConfig->getValueString(Application::APP_ID, 'registry_register', 'openbuild'));
        if ($register === '') {
            $register = 'openbuild';
        }

        return $base.'/index.php/apps/openregister/api/objects/'.rawurlencode($register).'/'.self::TEMPLATE_SCHEMA;

    }//end buildUrl()

    /**
     * SSRF-guard a URL via OpenRegister's SecurityService when available, with a
     * local scheme guard as a fail-closed fallback.
     *
     * @param string $url The URL to validate.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When the URL is unsafe or malformed.
     */
    private function assertSafe(string $url): void
    {
        // Prefer OpenRegister's SSRF guard (a hard dependency at runtime). The
        // dynamic class-string + call_user_func keeps static analysis happy when
        // OR's source is not on the analyser path; a missing class falls through
        // to the local scheme/host guard below (fail-closed).
        $guard = '\\OCA\\OpenRegister\\Service\\SecurityService';
        if (class_exists($guard) === true) {
            call_user_func([$guard, 'assertSafeFetchUrl'], $url);
            return;
        }

        // Fallback: enforce a present host + http(s) scheme (fail-closed).
        $parts = parse_url($url);
        if ($parts === false
            || empty($parts['scheme']) === true
            || empty($parts['host']) === true
            || in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true) === false
        ) {
            throw new \InvalidArgumentException('Invalid or unsafe registry URL.');
        }

    }//end assertSafe()

    /**
     * Flatten a remote template object to a search card (no manifest payload).
     *
     * @param array<string, mixed> $object The remote application-template object.
     *
     * @return array<string, mixed>
     */
    private function normaliseCard(array $object): array
    {
        return [
            'slug'          => (string) ($object['slug'] ?? ''),
            'title'         => (string) ($object['title'] ?? ($object['slug'] ?? '')),
            'description'   => (string) ($object['description'] ?? ''),
            'useCase'       => (string) ($object['useCase'] ?? ''),
            'category'      => (string) ($object['category'] ?? ''),
            'version'       => (string) ($object['version'] ?? ''),
            'screenshotUrl' => (string) ($object['screenshotUrl'] ?? ''),
        ];

    }//end normaliseCard()

    /**
     * The configured registry base URL.
     *
     * @return string
     */
    private function registryUrl(): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, 'registry_url', '');

    }//end registryUrl()
}//end class
