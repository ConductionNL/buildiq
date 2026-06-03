<?php

/**
 * OpenBuild GitHub Push Service
 *
 * Pushes the generated app tree to a new GitHub repository and opens a
 * placeholder pull request. The PAT is method-scoped — never persisted on
 * the service instance, never logged, never echoed (Decision 3).
 *
 * Uses Nextcloud's built-in IClientService against the GitHub REST + Git
 * Data API directly (create-repo, create-blob, create-tree, create-commit,
 * create-ref, create-pull). This avoids pulling knplabs/github-api onto the
 * lockfile while keeping the wire surface stable (design.md Risks: "swap to
 * direct cURL in GitHubPushService — no architectural change").
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
 * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use FilesystemIterator;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * GitHub delivery target. PAT-handling contract documented in Decision 3.
 *
 * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
 */
class GitHubPushService
{
    private const API_BASE = 'https://api.github.com';

    private const BOOTSTRAP_BRANCH = 'bootstrap';

    /**
     * Constructor.
     *
     * @param IClientService  $clientService Nextcloud HTTP client factory.
     * @param LoggerInterface $logger        Logger.
     */
    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Push the generated tree to a new GitHub repo + open a placeholder PR.
     *
     * The PAT is method-scoped: it is read here, passed to the per-call
     * request builder, and never stored on `$this` or logged.
     *
     * @param string $jobUuid    Export job UUID — correlation key in audit
     *                           logs.
     * @param string $treeDir    Absolute path to the generated tree on disk.
     * @param string $pat        GitHub PAT — method-scoped, never
     *                           persisted.
     * @param string $org        Target GitHub organisation/owner.
     * @param string $repo       Target repository name.
     * @param string $visibility Repository visibility (`public`|`private`).
     *
     * @return array{repoUrl:string,pullRequestUrl:string} URLs of repo + PR.
     *
     * @throws RuntimeException On any GitHub API failure (auth, repo-exists, …).
     *
     * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
     */
    public function push(
        string $jobUuid,
        string $treeDir,
        string $pat,
        string $org='',
        string $repo='',
        string $visibility='private',
    ): array {
        // Audit log names only the job + repo — never the PAT, never tree contents.
        $this->logger->info(
            'OpenBuild GitHub push: creating repository',
            ['jobUuid' => $jobUuid, 'org' => $org, 'repo' => $repo]
        );

        if ($org === '' || $repo === '') {
            throw new RuntimeException('GitHub push requires both an organisation and a repository name.');
        }

        if (is_dir($treeDir) === false) {
            throw new RuntimeException('GitHub push: generated tree directory is missing: '.$treeDir);
        }

        $this->assertRepoAbsent(org: $org, repo: $repo, pat: $pat);
        $repoData = $this->createRepo(org: $org, repo: $repo, visibility: $visibility, pat: $pat);

        $defaultBranch = (string) ($repoData['default_branch'] ?? $this->resolveDefaultBranch(org: $org, pat: $pat));
        $commitSha     = $this->pushTree(org: $org, repo: $repo, branch: self::BOOTSTRAP_BRANCH, treeDir: $treeDir, pat: $pat);

        $prUrl = $this->openPullRequest(
            org: $org,
            repo: $repo,
            fromBranch: self::BOOTSTRAP_BRANCH,
            toBranch: $defaultBranch,
            title: 'chore: bootstrap from OpenBuild',
            body: 'This repository was bootstrapped from an OpenBuild application export. '
                .'Review the tree, then run `composer install` and `npm install` locally before merging.',
            pat: $pat
        );

        unset($pat);

        return [
            'repoUrl'        => (string) ($repoData['html_url'] ?? ('https://github.com/'.$org.'/'.$repo)),
            'pullRequestUrl' => $prUrl,
            'commitSha'      => $commitSha,
        ];
    }//end push()

    /**
     * Fail fast when the target repository already exists (REQ-OBEX-007).
     *
     * @param string $org  Organisation/owner.
     * @param string $repo Repository name.
     * @param string $pat  GitHub PAT — method-scoped.
     *
     * @return void
     *
     * @throws RuntimeException When the repo already exists.
     */
    private function assertRepoAbsent(string $org, string $repo, string $pat): void
    {
        $client   = $this->clientService->newClient();
        $existing = false;
        try {
            $response = $client->get(
                self::API_BASE.'/repos/'.rawurlencode($org).'/'.rawurlencode($repo),
                $this->requestOptions(pat: $pat)
            );
            $existing = ($response->getStatusCode() === 200);
        } catch (\Throwable $e) {
            // A 404 is the desired "absent" outcome; the NC client throws on
            // non-2xx, so a thrown 404 here means the repo is absent.
            if (str_contains($e->getMessage(), '404') === false) {
                throw new RuntimeException('GitHub auth failure: '.$this->scrub(message: $e->getMessage()));
            }
        }

        if ($existing === true) {
            throw new RuntimeException(
                sprintf('Repository %s/%s already exists', $org, $repo)
            );
        }
    }//end assertRepoAbsent()

    /**
     * Create a new GitHub repository under the given org.
     *
     * @param string $org        Organisation/owner.
     * @param string $repo       Repository name.
     * @param string $visibility `public`|`private`.
     * @param string $pat        GitHub PAT — method-scoped.
     *
     * @return array<string,mixed> Decoded repo payload.
     *
     * @throws RuntimeException On API failure.
     *
     * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
     */
    public function createRepo(string $org, string $repo, string $visibility, string $pat): array
    {
        $client = $this->clientService->newClient();
        $body   = [
            'name'        => $repo,
            'private'     => ($visibility === 'private'),
            'auto_init'   => true,
            'description' => 'Bootstrapped from OpenBuild',
        ];

        try {
            $response = $client->post(
                self::API_BASE.'/orgs/'.rawurlencode($org).'/repos',
                $this->requestOptions(pat: $pat, body: $body)
            );
        } catch (\Throwable $e) {
            throw new RuntimeException('GitHub create-repo failed: '.$this->scrub(message: $e->getMessage()));
        }

        return $this->decode(body: (string) $response->getBody());
    }//end createRepo()

    /**
     * Push the generated tree as a single commit on a new branch.
     *
     * Walks the on-disk tree, creates a blob per file via the Git Data API,
     * assembles a tree + commit, and points a new ref at it.
     *
     * @param string $org     Organisation/owner.
     * @param string $repo    Repository name.
     * @param string $branch  Branch to create (e.g. `bootstrap`).
     * @param string $treeDir Absolute path to the generated tree.
     * @param string $pat     GitHub PAT — method-scoped.
     *
     * @return string Commit SHA.
     *
     * @throws RuntimeException On API failure.
     *
     * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
     */
    public function pushTree(string $org, string $repo, string $branch, string $treeDir, string $pat): string
    {
        $base    = self::API_BASE.'/repos/'.rawurlencode($org).'/'.rawurlencode($repo);
        $entries = [];

        foreach ($this->walkTree(root: $treeDir) as $relativePath) {
            $contents  = (string) file_get_contents($treeDir.'/'.$relativePath);
            $blobSha   = $this->createBlob(base: $base, contents: $contents, pat: $pat);
            $entries[] = [
                'path' => $relativePath,
                'mode' => '100644',
                'type' => 'blob',
                'sha'  => $blobSha,
            ];
        }

        $tree    = $this->postJson(url: $base.'/git/trees', body: ['tree' => $entries], pat: $pat);
        $treeSha = (string) ($tree['sha'] ?? '');

        $commit    = $this->postJson(
            url: $base.'/git/commits',
            body: [
                'message' => 'chore: bootstrap from OpenBuild',
                'tree'    => $treeSha,
            ],
            pat: $pat
        );
        $commitSha = (string) ($commit['sha'] ?? '');

        $this->postJson(
            url: $base.'/git/refs',
            body: [
                'ref' => 'refs/heads/'.$branch,
                'sha' => $commitSha,
            ],
            pat: $pat
        );

        return $commitSha;
    }//end pushTree()

    /**
     * Open a pull request from one branch to another.
     *
     * @param string $org        Organisation/owner.
     * @param string $repo       Repository name.
     * @param string $fromBranch Source branch.
     * @param string $toBranch   Target branch.
     * @param string $title      PR title.
     * @param string $body       PR body.
     * @param string $pat        GitHub PAT — method-scoped.
     *
     * @return string PR URL.
     *
     * @throws RuntimeException On API failure.
     *
     * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
     */
    public function openPullRequest(
        string $org,
        string $repo,
        string $fromBranch,
        string $toBranch,
        string $title,
        string $body,
        string $pat,
    ): string {
        $pullRequest = $this->postJson(
            url: self::API_BASE.'/repos/'.rawurlencode($org).'/'.rawurlencode($repo).'/pulls',
            body: [
                'title' => $title,
                'head'  => $fromBranch,
                'base'  => $toBranch,
                'body'  => $body,
            ],
            pat: $pat
        );

        return (string) ($pullRequest['html_url'] ?? '');
    }//end openPullRequest()

    /**
     * Resolve the default branch for an org's repos.
     *
     * `development` when the Conduction ruleset applies (heuristic: org name
     * contains "conduction"), else `main` (OQ-2).
     *
     * @param string $org Target organisation.
     * @param string $pat GitHub PAT — method-scoped.
     *
     * @return string Default branch name.
     *
     * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
     */
    public function resolveDefaultBranch(string $org, string $pat): string
    {
        unset($pat);
        // Heuristic: Conduction orgs use `development` as integration branch.
        if (stripos($org, 'conduction') !== false) {
            return 'development';
        }

        return 'main';
    }//end resolveDefaultBranch()

    /**
     * Create a base64 blob and return its SHA.
     *
     * @param string $base     Repo API base URL.
     * @param string $contents Raw file contents.
     * @param string $pat      GitHub PAT — method-scoped.
     *
     * @return string Blob SHA.
     */
    private function createBlob(string $base, string $contents, string $pat): string
    {
        $blob = $this->postJson(
            url: $base.'/git/blobs',
            body: [
                'content'  => base64_encode($contents),
                'encoding' => 'base64',
            ],
            pat: $pat
        );

        return (string) ($blob['sha'] ?? '');
    }//end createBlob()

    /**
     * POST a JSON body and decode the response.
     *
     * @param string              $url  Absolute API URL.
     * @param array<string,mixed> $body Request body.
     * @param string              $pat  GitHub PAT — method-scoped.
     *
     * @return array<string,mixed> Decoded response payload.
     *
     * @throws RuntimeException On API failure.
     */
    private function postJson(string $url, array $body, string $pat): array
    {
        $client = $this->clientService->newClient();
        try {
            $response = $client->post($url, $this->requestOptions(pat: $pat, body: $body));
        } catch (\Throwable $e) {
            throw new RuntimeException('GitHub API call failed: '.$this->scrub(message: $e->getMessage()));
        }

        return $this->decode(body: (string) $response->getBody());
    }//end postJson()

    /**
     * Build the request options array (headers + optional JSON body).
     *
     * The PAT lives only inside the returned array, scoped to a single call.
     *
     * @param string                   $pat  GitHub PAT — method-scoped.
     * @param array<string,mixed>|null $body Optional JSON body.
     *
     * @return array<string,mixed> Guzzle-compatible options.
     */
    private function requestOptions(string $pat, ?array $body=null): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer '.$pat,
                'Accept'        => 'application/vnd.github+json',
                'User-Agent'    => 'OpenBuild-Exporter',
            ],
            'timeout' => 30,
        ];

        if ($body !== null) {
            $options['body'] = json_encode($body);
            $options['headers']['Content-Type'] = 'application/json';
        }

        return $options;
    }//end requestOptions()

    /**
     * Decode a JSON response body into an array.
     *
     * @param string $body Raw response body.
     *
     * @return array<string,mixed> Decoded payload (empty on non-array).
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end decode()

    /**
     * Recursively walk a tree, returning sorted relative file paths.
     *
     * @param string $root Tree root directory.
     *
     * @return array<int,string> Sorted relative file paths.
     */
    private function walkTree(string $root): array
    {
        $paths    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() === false) {
                continue;
            }

            $paths[] = ltrim(str_replace($root, '', $fileInfo->getPathname()), '/');
        }

        sort($paths);
        return $paths;
    }//end walkTree()

    /**
     * Remove any leaked PAT-shaped tokens from an error message before it is
     * surfaced into errorMessage / logs (defence in depth for Decision 3).
     *
     * @param string $message Raw error message.
     *
     * @return string Scrubbed message.
     */
    private function scrub(string $message): string
    {
        return (string) preg_replace('/gh[pousr]_[A-Za-z0-9]{20,}/', '[redacted-token]', $message);
    }//end scrub()
}//end class
