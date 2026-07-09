<?php

/**
 * OpenBuild GitHubCatalogService
 *
 * Server-side GitHub source for the shop (github-shop-catalogue). Searches GitHub
 * for OpenBuild apps by the `topic:openbuild-app` discovery topic, fetches each
 * hit's root `openbuild-app.json` descriptor for a card, and — on install —
 * fetches the whole repo file map for github-app-repo-format's AppRepoParser.
 *
 * SSRF-safe by construction (design.md Decision 2/9): every outbound host is the
 * compile-time constant `api.github.com` — there is NO admin-configurable URL —
 * and `owner`/`repo`/`ref` are pattern-validated before path interpolation.
 * Browsing is anonymous-first (usable with no credential); when the acting user
 * supplies an allowed broker `github` credential the call is transparently
 * upgraded through OpenRegister's CredentialBrokerService so the token stays
 * broker-side and NEVER enters OpenBuild. The broker is resolved lazily
 * (`class_exists` + `Server::get`, mirroring RemoteTemplateStoreService) so a
 * missing/older OpenRegister falls back to anonymous cleanly. Results + descriptors
 * are cached short-TTL against the tight anonymous rate limit; the raw GitHub body
 * and any token are never returned or logged.
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
 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fixed-host, SSRF-safe GitHub catalogue source with optional broker upgrade.
 *
 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
 */
class GitHubCatalogService
{
    /**
     * The fixed GitHub API host — compile-time constant, no SSRF surface.
     */
    private const API_BASE = 'https://api.github.com';

    /**
     * The discovery topic every conforming OpenBuild app repo carries.
     */
    private const DISCOVERY_TOPIC = 'topic:openbuild-app';

    /**
     * The credential-broker service FQCN (resolved lazily; may be absent).
     */
    private const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

    /**
     * The broker `appId` OpenBuild identifies itself with.
     */
    private const APP_ID = 'openbuild';

    /**
     * Cache namespace for search results + descriptors.
     */
    private const CACHE_NS = 'openbuild_github_catalog';

    /**
     * Search-result cache TTL (seconds).
     */
    private const SEARCH_TTL = 60;

    /**
     * Descriptor cache TTL (seconds).
     */
    private const DESCRIPTOR_TTL = 300;

    /**
     * Connect + request timeout (seconds) for every anonymous call.
     */
    private const TIMEOUT = 10;

    /**
     * Maximum number of search hits turned into cards (per-hit descriptor cost).
     */
    private const MAX_HITS = 30;

    /**
     * Safe owner/repo pattern (GitHub allows alnum, `-`, `_`, `.`).
     */
    private const OWNER_REPO_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

    /**
     * Safe ref pattern (branch/tag/sha; no path traversal / spaces).
     */
    private const REF_PATTERN = '/^[A-Za-z0-9._\/-]{1,255}$/';

    /**
     * Outcome: the request succeeded.
     */
    public const OUTCOME_OK = 'ok';

    /**
     * Outcome: GitHub rate-limited and no cached result was available.
     */
    public const OUTCOME_RATE_LIMITED = 'github_rate_limited';

    /**
     * Outcome: transport failure / non-2xx that is not a rate limit.
     */
    public const OUTCOME_UNREACHABLE = 'github_unreachable';

    /**
     * The distributed cache, or null when no cache backend is available.
     *
     * @var ICache|null
     */
    private readonly ?ICache $cache;

    /**
     * Constructor.
     *
     * @param IClientService  $clientService NC HTTP client factory (anonymous calls).
     * @param ICacheFactory   $cacheFactory  NC cache factory (short-TTL server cache).
     * @param LoggerInterface $logger        PSR logger (secret-free diagnostics only).
     *
     * @return void
     */
    public function __construct(
        private readonly IClientService $clientService,
        ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
    ) {
        $cache = null;
        if ($cacheFactory->isAvailable() === true) {
            $cache = $cacheFactory->createDistributed(self::CACHE_NS);
        }

        $this->cache = $cache;
    }//end __construct()

    /**
     * Whether the OpenRegister credential broker is present on this instance.
     *
     * @return bool
     *
     * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
     */
    public function isBrokerAvailable(): bool
    {
        return class_exists(self::BROKER_CLASS) === true;
    }//end isBrokerAvailable()

    /**
     * Search GitHub for `topic:openbuild-app` repos and build installable cards.
     *
     * @param string|null $query        Optional free-text term appended to the topic query.
     * @param string|null $actingUserId The session UID (broker owner-guard identity), or null.
     * @param string|null $credentialId Optional allowed `github` credential to upgrade the call.
     *
     * @return array{outcome:string,cards:array<int,array<string,mixed>>,brokerUsed:bool,rateLimited:bool}
     *
     * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
     */
    public function search(?string $query, ?string $actingUserId, ?string $credentialId=null): array
    {
        $term      = trim((string) $query);
        $normalise = strtolower($term);
        $cacheKey  = 'search:'.md5($normalise.'|'.((string) $credentialId));

        $cached = $this->cacheGet(key: $cacheKey);
        if (is_array($cached) === true) {
            return $cached;
        }

        $queryString = self::DISCOVERY_TOPIC;
        if ($term !== '') {
            $queryString .= ' '.$term;
        }

        $path = '/search/repositories?q='.rawurlencode($queryString).'&per_page='.self::MAX_HITS;

        $result = $this->get(path: $path, actingUserId: $actingUserId, credentialId: $credentialId);
        if ($result['ok'] === false) {
            // Rate-limited with no fresh result — surface a generic outcome.
            $failure = self::OUTCOME_UNREACHABLE;
            if ($result['rateLimited'] === true) {
                $failure = self::OUTCOME_RATE_LIMITED;
            }

            return [
                'outcome'     => $failure,
                'cards'       => [],
                'brokerUsed'  => $result['brokerUsed'],
                'rateLimited' => $result['rateLimited'],
            ];
        }

        $decoded = json_decode($result['body'], true);
        $items   = [];
        if (is_array($decoded) === true && is_array($decoded['items'] ?? null) === true) {
            $items = $decoded['items'];
        }

        $cards = [];
        foreach (array_slice($items, 0, self::MAX_HITS) as $item) {
            if (is_array($item) === false) {
                continue;
            }

            $card = $this->buildCard(item: $item, actingUserId: $actingUserId, credentialId: $credentialId);
            if ($card !== null) {
                $cards[] = $card;
            }
        }

        $payload = [
            'outcome'     => self::OUTCOME_OK,
            'cards'       => $cards,
            'brokerUsed'  => $result['brokerUsed'],
            'rateLimited' => false,
        ];
        $this->cacheSet(key: $cacheKey, value: $payload, ttl: self::SEARCH_TTL);

        return $payload;
    }//end search()

    /**
     * Fetch + decode a repo's root `openbuild-app.json` descriptor.
     *
     * @param string      $owner        Repo owner (pattern-validated).
     * @param string      $repo         Repo name (pattern-validated).
     * @param string|null $ref          Optional git ref (pattern-validated).
     * @param string|null $actingUserId The session UID (broker identity), or null.
     * @param string|null $credentialId Optional allowed `github` credential.
     *
     * @return array<string,mixed>|null The parsed descriptor, or null when missing/unparseable.
     *
     * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
     */
    public function fetchDescriptor(
        string $owner,
        string $repo,
        ?string $ref,
        ?string $actingUserId,
        ?string $credentialId=null
    ): ?array {
        if ($this->validRepo(owner: $owner, repo: $repo, ref: $ref) === false) {
            return null;
        }

        $cacheKey = 'descriptor:'.$owner.'/'.$repo.'@'.((string) $ref).'|'.((string) $credentialId);
        $cached   = $this->cacheGet(key: $cacheKey);
        if (is_array($cached) === true) {
            return $cached;
        }

        $contents = $this->fetchFileContents(
            owner: $owner,
            repo: $repo,
            path: AppRepoParser::DESCRIPTOR_FILE,
            ref: $ref,
            actingUserId: $actingUserId,
            credentialId: $credentialId
        );
        if ($contents === null) {
            return null;
        }

        $decoded = json_decode($contents, true);
        if (is_array($decoded) === false || array_is_list($decoded) === true) {
            return null;
        }

        $this->cacheSet(key: $cacheKey, value: $decoded, ttl: self::DESCRIPTOR_TTL);

        return $decoded;
    }//end fetchDescriptor()

    /**
     * Resolve the exact commit SHA a ref points at (for pull provenance).
     *
     * @param string      $owner        Repo owner (pattern-validated).
     * @param string      $repo         Repo name (pattern-validated).
     * @param string      $ref          The git ref (branch/tag/sha, pattern-validated).
     * @param string|null $actingUserId The session UID (broker identity), or null.
     * @param string|null $credentialId Optional allowed `github` credential.
     *
     * @return string|null The resolved commit SHA, or null when unresolvable.
     *
     * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
     */
    public function resolveCommitSha(
        string $owner,
        string $repo,
        string $ref,
        ?string $actingUserId,
        ?string $credentialId=null
    ): ?string {
        if ($this->validRepo(owner: $owner, repo: $repo, ref: $ref) === false || $ref === '') {
            return null;
        }

        $path   = '/repos/'.rawurlencode($owner).'/'.rawurlencode($repo).'/commits/'.rawurlencode($ref);
        $result = $this->get(path: $path, actingUserId: $actingUserId, credentialId: $credentialId);
        if ($result['ok'] === false) {
            return null;
        }

        $decoded = json_decode($result['body'], true);
        if (is_array($decoded) === false) {
            return null;
        }

        $sha = (string) ($decoded['sha'] ?? '');
        if ($sha === '') {
            return null;
        }

        return $sha;
    }//end resolveCommitSha()

    /**
     * Fetch the repo file map (`path => contents`) AppRepoParser::parse expects:
     * `openbuild-app.json`, `manifest.json`, and every `schemas/*.json`.
     *
     * @param string      $owner        Repo owner (pattern-validated).
     * @param string      $repo         Repo name (pattern-validated).
     * @param string|null $ref          Optional git ref (pattern-validated).
     * @param string|null $actingUserId The session UID (broker identity), or null.
     * @param string|null $credentialId Optional allowed `github` credential.
     *
     * @return array<string,string> The file map (may be empty when the repo is unreachable).
     *
     * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
     */
    public function fetchRepoFiles(
        string $owner,
        string $repo,
        ?string $ref,
        ?string $actingUserId,
        ?string $credentialId=null
    ): array {
        if ($this->validRepo(owner: $owner, repo: $repo, ref: $ref) === false) {
            return [];
        }

        $files = [];
        foreach ([AppRepoParser::DESCRIPTOR_FILE, AppRepoParser::MANIFEST_FILE] as $rootFile) {
            $contents = $this->fetchFileContents(
                owner: $owner,
                repo: $repo,
                path: $rootFile,
                ref: $ref,
                actingUserId: $actingUserId,
                credentialId: $credentialId
            );
            if ($contents !== null) {
                $files[$rootFile] = $contents;
            }
        }

        $schemaPaths = $this->listSchemaFiles(
            owner: $owner,
            repo: $repo,
            ref: $ref,
            actingUserId: $actingUserId,
            credentialId: $credentialId
        );
        foreach ($schemaPaths as $schemaPath) {
            $contents = $this->fetchFileContents(
                owner: $owner,
                repo: $repo,
                path: $schemaPath,
                ref: $ref,
                actingUserId: $actingUserId,
                credentialId: $credentialId
            );
            if ($contents !== null) {
                $files[$schemaPath] = $contents;
            }
        }

        return $files;
    }//end fetchRepoFiles()

    /**
     * Build a card from a search hit's descriptor (non-installable when the
     * descriptor is missing/unparseable — the hit is surfaced, never dropped).
     *
     * @param array<string,mixed> $item         A GitHub search-result item.
     * @param string|null         $actingUserId The session UID (broker identity), or null.
     * @param string|null         $credentialId Optional allowed `github` credential.
     *
     * @return array<string,mixed>|null The card, or null when the item lacks an owner/name.
     */
    private function buildCard(array $item, ?string $actingUserId, ?string $credentialId): ?array
    {
        $fullName  = (string) ($item['full_name'] ?? '');
        $nameParts = explode('/', $fullName);
        $owner     = (string) ($item['owner']['login'] ?? '');
        if ($owner === '') {
            $owner = (string) ($nameParts[0] ?? '');
        }

        $repo = (string) ($item['name'] ?? '');
        if ($repo === '') {
            $repo = (string) ($nameParts[1] ?? '');
        }

        if ($owner === '' || $repo === '') {
            return null;
        }

        $stars      = (int) ($item['stargazers_count'] ?? 0);
        $descriptor = $this->fetchDescriptor(
            owner: $owner,
            repo: $repo,
            ref: null,
            actingUserId: $actingUserId,
            credentialId: $credentialId
        );

        if ($descriptor === null) {
            return [
                'owner'       => $owner,
                'repo'        => $repo,
                'stars'       => $stars,
                'installable' => false,
                'unparseable' => true,
            ];
        }

        $credentials = [];
        if (is_array($descriptor['credentials'] ?? null) === true) {
            $credentials = $descriptor['credentials'];
        }

        return [
            'owner'       => $owner,
            'repo'        => $repo,
            'stars'       => $stars,
            'installable' => true,
            'unparseable' => false,
            'slug'        => (string) ($descriptor['slug'] ?? ''),
            'name'        => (string) ($descriptor['name'] ?? $repo),
            'description' => (string) ($descriptor['description'] ?? ''),
            'category'    => (string) ($descriptor['category'] ?? ''),
            'appType'     => (string) ($descriptor['appType'] ?? ''),
            'version'     => (string) ($descriptor['version'] ?? ''),
            'credentials' => $credentials,
        ];
    }//end buildCard()

    /**
     * List `schemas/*.json` paths via the contents API directory listing.
     *
     * @param string      $owner        Repo owner.
     * @param string      $repo         Repo name.
     * @param string|null $ref          Optional git ref.
     * @param string|null $actingUserId The session UID (broker identity), or null.
     * @param string|null $credentialId Optional allowed `github` credential.
     *
     * @return array<int,string> Repo-relative `schemas/<slug>.json` paths.
     */
    private function listSchemaFiles(
        string $owner,
        string $repo,
        ?string $ref,
        ?string $actingUserId,
        ?string $credentialId
    ): array {
        $path = '/repos/'.rawurlencode($owner).'/'.rawurlencode($repo).'/contents/schemas';
        if ($ref !== null && $ref !== '') {
            $path .= '?ref='.rawurlencode($ref);
        }

        $result = $this->get(path: $path, actingUserId: $actingUserId, credentialId: $credentialId);
        if ($result['ok'] === false) {
            return [];
        }

        $decoded = json_decode($result['body'], true);
        if (is_array($decoded) === false) {
            return [];
        }

        $paths = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) === false) {
                continue;
            }

            $name = (string) ($entry['name'] ?? '');
            if ((string) ($entry['type'] ?? '') === 'file' && str_ends_with($name, '.json') === true) {
                $paths[] = 'schemas/'.$name;
            }
        }

        return $paths;
    }//end listSchemaFiles()

    /**
     * Fetch a single file's decoded contents via the GitHub contents API.
     *
     * @param string      $owner        Repo owner.
     * @param string      $repo         Repo name.
     * @param string      $path         Repo-relative file path.
     * @param string|null $ref          Optional git ref.
     * @param string|null $actingUserId The session UID (broker identity), or null.
     * @param string|null $credentialId Optional allowed `github` credential.
     *
     * @return string|null The decoded file contents, or null when absent/unreadable.
     */
    private function fetchFileContents(
        string $owner,
        string $repo,
        string $path,
        ?string $ref,
        ?string $actingUserId,
        ?string $credentialId
    ): ?string {
        $apiPath = '/repos/'.rawurlencode($owner).'/'.rawurlencode($repo).'/contents/'
            .implode('/', array_map('rawurlencode', explode('/', $path)));
        if ($ref !== null && $ref !== '') {
            $apiPath .= '?ref='.rawurlencode($ref);
        }

        $result = $this->get(path: $apiPath, actingUserId: $actingUserId, credentialId: $credentialId);
        if ($result['ok'] === false) {
            return null;
        }

        $decoded = json_decode($result['body'], true);
        if (is_array($decoded) === false) {
            return null;
        }

        $content  = (string) ($decoded['content'] ?? '');
        $encoding = (string) ($decoded['encoding'] ?? '');
        if ($encoding === 'base64') {
            $raw = base64_decode(str_replace("\n", '', $content), true);
            if ($raw === false) {
                return null;
            }

            return $raw;
        }

        if ($content === '') {
            return null;
        }

        return $content;
    }//end fetchFileContents()

    /**
     * Perform a GET — via the broker when a credential is supplied and the broker
     * admits the call, else anonymously (feature-detect + fall back).
     *
     * @param string      $path         The GitHub-relative path (starts with `/`).
     * @param string|null $actingUserId The session UID (broker owner-guard identity), or null.
     * @param string|null $credentialId Optional allowed `github` credential.
     *
     * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool}
     */
    private function get(string $path, ?string $actingUserId, ?string $credentialId): array
    {
        if ($credentialId !== null && $credentialId !== '' && $this->isBrokerAvailable() === true) {
            $brokered = $this->brokerGet(path: $path, credentialId: $credentialId, actingUserId: $actingUserId);
            if ($brokered !== null) {
                return $brokered;
            }

            // Broker denied / rules missing — fall through to anonymous.
        }

        return $this->anonymousGet(path: $path);
    }//end get()

    /**
     * Route a GET through the OpenRegister credential broker.
     *
     * @param string      $path         The GitHub-relative path.
     * @param string      $credentialId The `github` credential UUID.
     * @param string|null $actingUserId The session UID for the broker owner guard.
     *
     * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool}|null Null when the broker
     *         denies the call (caller falls back to anonymous).
     */
    private function brokerGet(string $path, string $credentialId, ?string $actingUserId): ?array
    {
        try {
            $broker   = Server::get(self::BROKER_CLASS);
            $response = $broker->request(
                $credentialId,
                self::APP_ID,
                'GET',
                $path,
                ['Accept' => 'application/vnd.github+json'],
                null,
                $actingUserId
            );
        } catch (Throwable $e) {
            $this->logger->debug('OpenBuild GitHub catalog: broker call not admitted, falling back to anonymous.');
            return null;
        }

        $status = (int) ($response['status'] ?? 0);
        $body   = (string) ($response['body'] ?? '');

        return [
            'ok'          => ($status >= 200 && $status < 300),
            'status'      => $status,
            'body'        => $body,
            'rateLimited' => ($status === 403 || $status === 429),
            'brokerUsed'  => true,
        ];
    }//end brokerGet()

    /**
     * Perform an anonymous GET against the fixed GitHub host.
     *
     * @param string $path The GitHub-relative path.
     *
     * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool}
     */
    private function anonymousGet(string $path): array
    {
        try {
            $response = $this->clientService->newClient()->get(
                self::API_BASE.$path,
                [
                    'timeout'         => self::TIMEOUT,
                    'connect_timeout' => self::TIMEOUT,
                    'headers'         => [
                        'Accept'     => 'application/vnd.github+json',
                        'User-Agent' => 'OpenBuild-Shop',
                    ],
                ]
            );
        } catch (Throwable $e) {
            $rateLimited = (str_contains($e->getMessage(), '403') === true || str_contains($e->getMessage(), '429') === true);
            return ['ok' => false, 'status' => 0, 'body' => '', 'rateLimited' => $rateLimited, 'brokerUsed' => false];
        }

        $status = $response->getStatusCode();

        return [
            'ok'          => ($status >= 200 && $status < 300),
            'status'      => $status,
            'body'        => (string) $response->getBody(),
            'rateLimited' => ($status === 403 || $status === 429),
            'brokerUsed'  => false,
        ];
    }//end anonymousGet()

    /**
     * Validate owner/repo/ref against safe patterns before path interpolation.
     *
     * @param string      $owner The repo owner.
     * @param string      $repo  The repo name.
     * @param string|null $ref   Optional git ref.
     *
     * @return bool
     */
    private function validRepo(string $owner, string $repo, ?string $ref): bool
    {
        if (preg_match(self::OWNER_REPO_PATTERN, $owner) !== 1 || preg_match(self::OWNER_REPO_PATTERN, $repo) !== 1) {
            return false;
        }

        if ($ref !== null && $ref !== '' && preg_match(self::REF_PATTERN, $ref) !== 1) {
            return false;
        }

        return true;
    }//end validRepo()

    /**
     * Read a value from the short-TTL cache (no-op when no cache backend).
     *
     * @param string $key The cache key.
     *
     * @return mixed The cached value, or null on a miss / no cache.
     */
    private function cacheGet(string $key): mixed
    {
        if ($this->cache === null) {
            return null;
        }

        return $this->cache->get($key);
    }//end cacheGet()

    /**
     * Write a value to the short-TTL cache (no-op when no cache backend).
     *
     * @param string $key   The cache key.
     * @param mixed  $value The value to cache.
     * @param int    $ttl   The TTL in seconds.
     *
     * @return void
     */
    private function cacheSet(string $key, mixed $value, int $ttl): void
    {
        if ($this->cache === null) {
            return;
        }

        $this->cache->set($key, $value, $ttl);
    }//end cacheSet()
}//end class
