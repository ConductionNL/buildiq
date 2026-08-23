<?php

/**
 * Buildiq GitHubCatalogService unit tests
 *
 * Regression coverage for a defect found live during
 * app-repo-format-flow-agent-export's round-trip verification: the flows/
 * and agents/ channels are written by `AppRepoSerializer` and understood by
 * `AppRepoParser`, but `fetchChannelFiles()`'s own fetch-side prefix
 * allowlist was never extended to match, so `github/pull` (and the shop
 * install path, which shares this same method) silently never downloaded
 * either channel from GitHub — the parser reported `declared: 0` for both
 * even though the files existed in the published repository. Verified live
 * against a real disposable GitHub repo before AND after the fix; this test
 * pins the fetch-side contract so it cannot regress silently again the way
 * it did once already (the class's own docblock on `fetchRepoFiles()`
 * documents the FIRST time this exact class of bug shipped, for
 * data-registers/connectors/automations/skills).
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\GitHubCatalogService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for {@see GitHubCatalogService::fetchRepoFiles()} — the v2 channel fetch allowlist.
 */
final class GitHubCatalogServiceTest extends TestCase {

	/**
	 * @var IClientService&MockObject
	 */
	private IClientService&MockObject $clientService;

	/**
	 * Build the service under test, with caching disabled (no distributed cache
	 * backend available in the unit environment).
	 *
	 * @return GitHubCatalogService
	 */
	private function makeService(): GitHubCatalogService {
		$this->clientService = $this->createMock(IClientService::class);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		return new GitHubCatalogService(
			clientService: $this->clientService,
			cacheFactory: $cacheFactory,
			logger: new NullLogger()
		);
	}//end makeService()

	/**
	 * A 200 JSON response, the shape every `anonymousGet()` caller expects back.
	 *
	 * @param array<string,mixed>|array<int,mixed> $decoded The JSON body, pre-decode.
	 * @param int $status HTTP status.
	 *
	 * @return IResponse&MockObject
	 */
	private function jsonResponse(array $decoded, int $status = 200): IResponse&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn((string)json_encode($decoded));

		return $response;
	}//end jsonResponse()

	/**
	 * A GitHub Contents-API response body: base64 content + encoding.
	 *
	 * @param string $raw The raw (pre-encode) file contents.
	 *
	 * @return array<string,mixed>
	 */
	private function contentsBody(string $raw): array {
		return ['content' => base64_encode($raw), 'encoding' => 'base64'];
	}//end contentsBody()

	/**
	 * The core regression: `fetchRepoFiles()` must return the flows/ and
	 * agents/ entries a repository actually carries — not just the four
	 * older v2 channels — and must still exclude a path outside every known
	 * channel prefix.
	 *
	 * @return void
	 */
	public function testFetchRepoFilesIncludesFlowsAndAgentsChannels(): void {
		$service = $this->makeService();

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url) {
			if (str_contains($url, '/git/trees/') === true) {
				return $this->jsonResponse([
					'tree' => [
						['type' => 'blob', 'path' => 'flows/11111111-1111-1111-1111-111111111111.json'],
						['type' => 'blob', 'path' => 'agents/22222222-2222-2222-2222-222222222222.json'],
						['type' => 'blob', 'path' => 'data-registers/reg.json'],
						['type' => 'blob', 'path' => 'connectors/source/conn.json'],
						['type' => 'blob', 'path' => 'automations/auto.json'],
						['type' => 'blob', 'path' => 'skills/pkg/skill.json'],
						// Outside every known channel prefix — must NOT be fetched.
						['type' => 'blob', 'path' => 'unrelated/should-not-fetch.json'],
						// A directory entry sharing a channel prefix — must be
						// ignored (`type` is `tree`, not `blob`).
						['type' => 'tree', 'path' => 'flows'],
					],
				]);
			}

			if (str_contains($url, '/contents/flows/') === true) {
				return $this->jsonResponse($this->contentsBody('{"uuid":"11111111-1111-1111-1111-111111111111","name":"F"}'));
			}

			if (str_contains($url, '/contents/agents/') === true) {
				return $this->jsonResponse($this->contentsBody('{"applicationSlug":"src","name":"A"}'));
			}

			if (str_contains($url, '/contents/data-registers/') === true
				|| str_contains($url, '/contents/connectors/') === true
				|| str_contains($url, '/contents/automations/') === true
				|| str_contains($url, '/contents/skills/') === true
			) {
				return $this->jsonResponse($this->contentsBody('{}'));
			}

			// openbuild-app.json, manifest.json, contents/schemas — not under
			// test here; answer 404 so the caller degrades to "not found"
			// without needing every root file mocked.
			return $this->jsonResponse(['message' => 'Not Found'], 404);
		});

		$this->clientService->method('newClient')->willReturn($client);

		$files = $service->fetchRepoFiles(
			owner: 'example-owner',
			repo: 'example-repo',
			ref: null,
			actingUserId: null,
			credentialId: null
		);

		$this->assertArrayHasKey('flows/11111111-1111-1111-1111-111111111111.json', $files, 'flows/ channel was not fetched — the exact defect this test guards against.');
		$this->assertArrayHasKey('agents/22222222-2222-2222-2222-222222222222.json', $files, 'agents/ channel was not fetched — the exact defect this test guards against.');
		$this->assertArrayHasKey('data-registers/reg.json', $files);
		$this->assertArrayHasKey('connectors/source/conn.json', $files);
		$this->assertArrayHasKey('automations/auto.json', $files);
		$this->assertArrayHasKey('skills/pkg/skill.json', $files);
		$this->assertArrayNotHasKey('unrelated/should-not-fetch.json', $files);
	}//end testFetchRepoFilesIncludesFlowsAndAgentsChannels()
}//end class
