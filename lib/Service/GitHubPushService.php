<?php

/**
 * Buildiq GitHub Push Service
 *
 * SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pushes the generated app tree to a new GitHub repository and opens a
 * placeholder pull request, over the GitHub REST + Git Data API (create-repo,
 * create-blob, create-tree, create-commit, create-ref, create-pull).
 *
 * Buildiq holds NO GitHub token. Decision 3 originally said the PAT was
 * "method-scoped — never persisted on the service instance", but the PAT was
 * still typed into an Buildiq dialog, POSTed to an Buildiq controller,
 * persisted by Buildiq for the duration of the job, and replayed by Buildiq
 * against api.github.com. Method scope is not custody: the app could read the
 * secret, so the app was the trust boundary.
 *
 * Every call now goes through OpenRegister's credential broker. This service
 * sends {method, path, body} plus a credential UUID; the broker checks the
 * owner, the allowed-app grant and the allow-rules, then injects the token
 * server-side. The token never enters this process. When the broker is absent
 * we fail closed — there is no token-bearing fallback left to fall back to.
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
 * @spec openspec/changes/archive/2026-06-14-openbuild-exporter/tasks.md#task-6.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Service\GitHub\BrokerAccess;
use OCA\Buildiq\Service\GitHub\CredentialBrokerClient;
use OCA\Buildiq\Service\GitHub\PushTarget;
use OCA\Buildiq\Service\GitHub\TokenScrubber;
use FilesystemIterator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * GitHub delivery target. PAT-handling contract documented in Decision 3.
 *
 * @spec openspec/changes/archive/2026-06-14-openbuild-exporter/tasks.md#task-6.2
 */
class GitHubPushService {
	/**
	 * OpenRegister's credential broker.
	 *
	 * This service used to take the user's GitHub Personal Access Token: the PAT was
	 * typed into an Buildiq dialog, POSTed to an Buildiq controller, persisted by
	 * Buildiq, and replayed by Buildiq against api.github.com. Encryption at rest
	 * did not change that — custody was the app's, which is exactly what the broker
	 * exists to prevent.
	 *
	 * Every GitHub call now goes through the broker: Buildiq sends {method, path,
	 * body} plus a credential UUID, and the broker injects the token. The token never
	 * reaches this process. Resolved lazily (class_exists + the injected container),
	 * mirroring GitHubAppSyncService; when the broker is absent we fail closed rather than fall
	 * back to any token-bearing path.
	 *
	 * @var string
	 */
	private const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

	/**
	 * The branch the generated tree is pushed to, before the PR is opened against the
	 * repo's default branch.
	 *
	 * There is deliberately no API_BASE const: https://api.github.com is the broker's
	 * host-lock, and the broker is the one place it may be asserted. A service that can
	 * name the host can name a different one.
	 *
	 * @var string
	 */
	private const BOOTSTRAP_BRANCH = 'bootstrap';

	/**
	 * Cap on the failure detail text folded into a thrown exception / log line,
	 * so an oversized GitHub error body cannot bloat `errorMessage`.
	 *
	 * @var int
	 */
	private const MAX_FAILURE_DETAIL_LENGTH = 300;

	/**
	 * The real reason the most recent {@see brokerCall()} returned `null` —
	 * `HTTP {status}: {body excerpt}` for a completed non-2xx response, or
	 * `transport error: {message}` for a caught broker exception. Reset to
	 * `null` at the top of every `brokerCall()`, so a caller reading it
	 * immediately after its own call never sees a stale value from an
	 * earlier, unrelated failure (e.g. `assertRepoAbsent()`'s quiet 404).
	 * Always scrubbed of GitHub PAT-shaped tokens before being set.
	 *
	 * @var string|null
	 */
	private ?string $lastFailureDetail = null;

	/**
	 * Sends the JSON calls through OpenRegister's credential broker.
	 *
	 * @var CredentialBrokerClient
	 */
	private readonly CredentialBrokerClient $broker;

	/**
	 * Constructor.
	 *
	 * No HTTP client: this service no longer makes outbound calls of its own.
	 *
	 * @param LoggerInterface $logger Logger.
	 * @param ContainerInterface $container DI container, resolves the OpenRegister
	 *                                      broker at call time (never the global server).
	 */
	public function __construct(
		private LoggerInterface $logger,
		ContainerInterface $container,
	) {
		$this->broker = new CredentialBrokerClient(container: $container);
	}//end __construct()

	/**
	 * Push the generated tree to a new GitHub repo + open a placeholder PR.
	 *
	 * @param string $jobUuid Export job UUID — correlation key in audit logs.
	 * @param string $treeDir Absolute path to the generated tree on disk.
	 * @param BrokerAccess $access The broker credential and its owner. Not a token:
	 *                             this service cannot read the secret behind it.
	 * @param PushTarget $target The organisation, repository and visibility.
	 *
	 * @return array{repoUrl:string,pullRequestUrl:string,commitSha:string} URLs of repo + PR.
	 *
	 * @throws RuntimeException On any GitHub API failure (auth, repo-exists, …).
	 *
	 * @spec openspec/changes/archive/2026-06-14-openbuild-exporter/tasks.md#task-6.2
	 */
	public function push(string $jobUuid, string $treeDir, BrokerAccess $access, PushTarget $target): array {
		$org = $target->org;
		$repo = $target->repo;
		// Audit log names only the job + repo — never the credential, never tree contents.
		$this->logger->info(
			'Buildiq GitHub push: creating repository',
			['jobUuid' => $jobUuid, 'org' => $org, 'repo' => $repo]
		);

		if ($this->isBrokerAvailable() === false) {
			// Fail closed. There is deliberately no token-bearing fallback: the whole
			// point of this change is that Buildiq can no longer hold a PAT.
			throw new RuntimeException(
				'GitHub push requires the OpenRegister credential broker, which is not available.'
			);
		}

		if ($access->credentialId === '') {
			throw new RuntimeException('GitHub push requires a broker credential.');
		}

		if ($org === '' || $repo === '') {
			throw new RuntimeException('GitHub push requires both an organisation and a repository name.');
		}

		if (is_dir($treeDir) === false) {
			throw new RuntimeException('GitHub push: generated tree directory is missing: ' . $treeDir);
		}

		$this->assertRepoAbsent(target: $target, access: $access);
		$repoData = $this->createRepo(target: $target, access: $access);

		// The created repo reports its own default branch; `auto_init` guarantees one,
		// so there is no separate lookup (and GET /user is not needed at all).
		$defaultBranch = (string)($repoData['default_branch'] ?? 'main');
		$commitSha = $this->pushTree(target: $target, branch: self::BOOTSTRAP_BRANCH, treeDir: $treeDir, access: $access);
		$prUrl = $this->openPullRequest(target: $target, toBranch: $defaultBranch, access: $access);

		return [
			'repoUrl' => (string)($repoData['html_url'] ?? ('https://github.com/' . $org . '/' . $repo)),
			'pullRequestUrl' => $prUrl,
			'commitSha' => $commitSha,
		];
	}//end push()

	/**
	 * Fail fast when the target repository already exists (REQ-OBEX-007).
	 *
	 * @param PushTarget $target The organisation, repository and visibility.
	 * @param BrokerAccess $access The broker credential and its owner (background context).
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the repo already exists.
	 */
	private function assertRepoAbsent(PushTarget $target, BrokerAccess $access): void {
		// Absent is the desired outcome, so a broker denial/404 here is NOT an error —
		// only a successful 200 means the repo already exists.
		$existing = $this->brokerCall(
			request: ['method' => 'GET', 'path' => $target->repoPath(), 'body' => null],
			access: $access,
			failQuietly: true
		);

		if ($existing !== null) {
			throw new RuntimeException(
				sprintf('Repository %s/%s already exists', $target->org, $target->repo)
			);
		}
	}//end assertRepoAbsent()

	/**
	 * Create a new GitHub repository under the given org.
	 *
	 * @param PushTarget $target The organisation, repository and visibility.
	 * @param BrokerAccess $access The broker credential and its owner (background context).
	 *
	 * @return array<string,mixed> Decoded repo payload.
	 *
	 * @throws RuntimeException On API failure.
	 *
	 * @spec openspec/changes/archive/2026-06-14-openbuild-exporter/tasks.md#task-6.2
	 */
	public function createRepo(PushTarget $target, BrokerAccess $access): array {
		$created = $this->brokerCall(
			request: [
				'method' => 'POST',
				'path' => '/orgs/' . rawurlencode($target->org) . '/repos',
				'body' => [
					'name' => $target->repo,
					'private' => ($target->visibility === 'private'),
					'auto_init' => true,
					'description' => 'Bootstrapped from Buildiq',
				],
			],
			access: $access
		);

		if ($created === null) {
			throw new RuntimeException('GitHub create-repo failed.' . $this->failureSuffix());
		}

		return $created;
	}//end createRepo()

	/**
	 * Push the generated tree as a single commit on a new branch.
	 *
	 * Walks the on-disk tree, creates a blob per file via the Git Data API,
	 * assembles a tree + commit, and points a new ref at it.
	 *
	 * @param PushTarget $target The organisation, repository and visibility.
	 * @param string $branch Branch to create (e.g. `bootstrap`).
	 * @param string $treeDir Absolute path to the generated tree.
	 * @param BrokerAccess $access The broker credential and its owner (background context).
	 *
	 * @return string Commit SHA.
	 *
	 * @throws RuntimeException On API failure.
	 *
	 * @spec openspec/changes/archive/2026-06-14-openbuild-exporter/tasks.md#task-6.2
	 */
	public function pushTree(PushTarget $target, string $branch, string $treeDir, BrokerAccess $access): string {
		$base = $target->repoPath();
		$entries = [];

		foreach ($this->walkTree(root: $treeDir) as $relativePath) {
			$contents = (string)file_get_contents($treeDir . '/' . $relativePath);
			$entries[] = [
				'path' => $relativePath,
				'mode' => '100644',
				'type' => 'blob',
				'sha' => $this->createBlob(base: $base, contents: $contents, access: $access),
			];
		}

		$tree = $this->postJson(
			path: $base . '/git/trees',
			body: ['tree' => $entries],
			access: $access
		);
		$treeSha = (string)($tree['sha'] ?? '');

		$commit = $this->postJson(
			path: $base . '/git/commits',
			body: [
				'message' => 'chore: bootstrap from Buildiq',
				'tree' => $treeSha,
			],
			access: $access
		);
		$commitSha = (string)($commit['sha'] ?? '');

		$this->postJson(
			path: $base . '/git/refs',
			body: [
				'ref' => 'refs/heads/' . $branch,
				'sha' => $commitSha,
			],
			access: $access
		);

		return $commitSha;
	}//end pushTree()

	/**
	 * Open the bootstrap pull request from the bootstrap branch to the default branch.
	 *
	 * @param PushTarget $target The organisation, repository and visibility.
	 * @param string $toBranch Target branch.
	 * @param BrokerAccess $access The broker credential and its owner (background context).
	 *
	 * @return string PR URL.
	 *
	 * @throws RuntimeException On API failure.
	 *
	 * @spec openspec/changes/archive/2026-06-14-openbuild-exporter/tasks.md#task-6.2
	 */
	public function openPullRequest(PushTarget $target, string $toBranch, BrokerAccess $access): string {
		$pullRequest = $this->postJson(
			path: $target->repoPath() . '/pulls',
			body: [
				'title' => 'chore: bootstrap from Buildiq',
				'head' => self::BOOTSTRAP_BRANCH,
				'base' => $toBranch,
				'body' => 'This repository was bootstrapped from an Buildiq application export. '
					. 'Review the tree, then run `composer install` and `npm install` locally before merging.',
			],
			access: $access
		);

		return (string)($pullRequest['html_url'] ?? '');
	}//end openPullRequest()

	/**
	 * Create a base64 blob and return its SHA.
	 *
	 * @param string $base Repo-relative API path prefix.
	 * @param string $contents Raw file contents.
	 * @param BrokerAccess $access The broker credential and its owner (background context).
	 *
	 * @return string Blob SHA.
	 */
	private function createBlob(string $base, string $contents, BrokerAccess $access): string {
		$blob = $this->postJson(
			path: $base . '/git/blobs',
			body: [
				'content' => base64_encode($contents),
				'encoding' => 'base64',
			],
			access: $access
		);

		return (string)($blob['sha'] ?? '');
	}//end createBlob()

	/**
	 * POST a JSON body through the broker and decode the response.
	 *
	 * @param string $path GitHub-relative path (host comes from the
	 *                     broker's host-lock, not from us).
	 * @param array<string,mixed> $body Request body.
	 * @param BrokerAccess $access The broker credential and its owner (background context).
	 *
	 * @return array<string,mixed> Decoded response payload.
	 *
	 * @throws RuntimeException On API failure.
	 */
	private function postJson(string $path, array $body, BrokerAccess $access): array {
		$decoded = $this->brokerCall(
			request: ['method' => 'POST', 'path' => $path, 'body' => $body],
			access: $access
		);

		if ($decoded === null) {
			throw new RuntimeException(
				'GitHub API call failed: POST ' . $path . $this->failureSuffix()
			);
		}

		return $decoded;
	}//end postJson()

	/**
	 * Route one GitHub call through OpenRegister's credential broker.
	 *
	 * We send only {method, path, body}: the base URL is the broker's host-lock and
	 * the Authorization header is injected there from the vault. This process never
	 * sees the token, so there is nothing here to leak into a log or an exception.
	 *
	 * A non-2xx (including a broker 403 for an unlisted method/path) returns `null`
	 * rather than throwing, because the one caller that needs to tell "absent" from
	 * "error" — assertRepoAbsent() — treats absence as the happy path. Every other
	 * caller turns `null` into a RuntimeException.
	 *
	 * @param array{method: string, path: string, body: array<string,mixed>|null} $request HTTP method, GitHub-relative path and optional JSON body.
	 * @param BrokerAccess $access The broker credential and its owner (background context).
	 * @param bool $failQuietly Suppress the error log on failure.
	 *
	 * @return array<string,mixed>|null Decoded payload, or null on any non-2xx.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) TokenScrubber is a pure text function with no collaborators.
	 */
	private function brokerCall(array $request, BrokerAccess $access, bool $failQuietly = false): ?array {
		$this->lastFailureDetail = null;
		$method = (string)$request['method'];
		$path = (string)$request['path'];

		try {
			$response = $this->broker->sendJson(request: $request, access: $access);
		} catch (\Throwable $e) {
			// The broker never puts the secret in its exception messages, but this
			// path is also reached for transport errors, so scrub anyway.
			$scrubbed = TokenScrubber::scrub(message: $e->getMessage());
			$this->lastFailureDetail = 'transport error: ' . $scrubbed;

			if ($failQuietly === false) {
				$this->logger->warning(
					'Buildiq GitHub push: broker call failed for ' . $method . ' ' . $path
					. ': ' . $scrubbed
				);
			}

			return null;
		}//end try

		$status = (int)($response['status'] ?? 0);
		if ($status < 200 || $status >= 300) {
			$this->lastFailureDetail = $this->failureDetailFromStatus(
				status: $status,
				body: (string)($response['body'] ?? '')
			);

			if ($failQuietly === false) {
				$this->logger->warning(
					'Buildiq GitHub push: ' . $method . ' ' . $path . ' returned ' . $this->lastFailureDetail
				);
			}

			return null;
		}

		return $this->decode(body: (string)($response['body'] ?? ''));
	}//end brokerCall()

	/**
	 * Build the `HTTP {status}: {body excerpt}` failure detail from a completed
	 * non-2xx broker response, scrubbing any GitHub PAT-shaped token and
	 * truncating the body to {@see MAX_FAILURE_DETAIL_LENGTH}.
	 *
	 * Pulled out of {@see brokerCall()} so the detail-assembly logic is testable
	 * without a live broker (a unit test hands in a container that resolves
	 * nothing).
	 *
	 * @param int $status The upstream HTTP status code.
	 * @param string $body The raw (unscrubbed) upstream response body.
	 *
	 * @return string The `HTTP {status}` detail, with a scrubbed/truncated body
	 *                excerpt appended when the body is non-empty.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) TokenScrubber is a pure text function with no collaborators.
	 */
	private function failureDetailFromStatus(int $status, string $body): string {
		$scrubbedBody = $this->truncate(value: TokenScrubber::scrub(message: $body));

		$detail = 'HTTP ' . $status;
		if ($scrubbedBody !== '') {
			$detail .= ': ' . $scrubbedBody;
		}

		return $detail;
	}//end failureDetailFromStatus()

	/**
	 * Whether OpenRegister's credential broker is installed.
	 *
	 * @return bool True when the broker class can be resolved.
	 *
	 * @spec openspec/changes/archive/2026-06-14-openbuild-exporter/tasks.md#task-6.2
	 */
	public function isBrokerAvailable(): bool {
		return class_exists(self::BROKER_CLASS) === true;
	}//end isBrokerAvailable()

	/**
	 * Decode a JSON response body into an array.
	 *
	 * @param string $body Raw response body.
	 *
	 * @return array<string,mixed> Decoded payload (empty on non-array).
	 */
	private function decode(string $body): array {
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
	private function walkTree(string $root): array {
		$paths = [];
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
	 * Truncate a string to {@see MAX_FAILURE_DETAIL_LENGTH}, so a large GitHub
	 * error body cannot bloat an exception message or a log line.
	 *
	 * @param string $value The (already-scrubbed) string to cap.
	 *
	 * @return string The capped string, with a trailing ellipsis when cut.
	 */
	private function truncate(string $value): string {
		if (strlen($value) <= self::MAX_FAILURE_DETAIL_LENGTH) {
			return $value;
		}

		return substr($value, 0, self::MAX_FAILURE_DETAIL_LENGTH) . '…';
	}//end truncate()

	/**
	 * The `' — {detail}'` suffix to append to a generic failure message, built
	 * from the most recent {@see brokerCall()} failure — or `''` when no
	 * detail was captured (should not normally happen once `brokerCall()` has
	 * returned `null`, but a caller must never throw on a missing detail).
	 *
	 * @return string
	 */
	private function failureSuffix(): string {
		if ($this->lastFailureDetail === null || $this->lastFailureDetail === '') {
			return '';
		}

		return ' — ' . $this->lastFailureDetail;
	}//end failureSuffix()
}//end class
