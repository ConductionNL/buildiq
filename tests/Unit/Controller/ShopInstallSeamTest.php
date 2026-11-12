<?php

/**
 * What ShopController::githubInstall() hands the install seam.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationsController;
use OCA\Buildiq\Controller\ShopController;
use OCA\Buildiq\Service\AppRepoParser;
use OCA\Buildiq\Service\GitHubCatalogService;
use OCA\Buildiq\Service\GitHubShopSearch;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Pins the arguments a GitHub install hands ApplicationsController's install
 * seam: the parsed template, the requested name and slug, the signed-in user
 * as owner, and the repo and broker credential the template was fetched with,
 * for a credential that is set, empty or missing and with and without a ref.
 * Forged userId and ownerUid request parameters never become the owner.
 *
 * @covers \OCA\Buildiq\Controller\ShopController
 * @uses   \OCA\Buildiq\Service\RepoSource
 * @uses \OCA\Buildiq\Controller\GitHubRequestParams
 */
class ShopInstallSeamTest extends TestCase {

	/**
	 * Every case hands the seam the expected arguments.
	 *
	 * @return void
	 */
	public function testGithubInstallHandsTheSeamTheTemplateOwnerAndSource(): void {
		$cases = [
			'credential and ref' => ['cred-1', 'v1.0', 'cred-1'],
			'empty credential' => ['', null, null],
			'missing credential' => [null, 'main', null],
		];
		foreach ($cases as $name => [$credential, $ref, $expected]) {
			$request = $this->createMock(IRequest::class);
			$request->method('getParam')->willReturnCallback(
				static fn (string $key): mixed => [
					'owner' => 'ConductionNL',
					'repo' => 'example-app',
					'ref' => $ref,
					'name' => 'Example',
					'slug' => 'example',
					'credentialId' => $credential,
					// Forged identity parameters: the owner must come from the session.
					'userId' => 'mallory',
					'ownerUid' => 'mallory',
				][$key] ?? null
			);
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('carol');
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($user);
			$catalog = $this->createMock(GitHubCatalogService::class);
			$catalog->method('fetchRepoFiles')->willReturn(['manifest.json' => '{}']);
			$parser = $this->createMock(AppRepoParser::class);
			$parser->method('parse')->willReturn(['slug' => 'tpl', 'manifest' => []]);
			$apps = $this->createMock(ApplicationsController::class);
			$captured = null;
			$apps->expects(self::once())->method('installFromTemplateArray')->willReturnCallback(
				static function (...$args) use (&$captured): array {
					$captured = $args;
					return ['status' => Http::STATUS_CREATED, 'data' => ['slug' => 'example']];
				}
			);

			$controller = new ShopController(
				request: $request,
				userSession: $session,
				catalogService: $catalog,
				repoParser: $parser,
				appsController: $apps,
				shopSearch: $this->createMock(GitHubShopSearch::class)
			);
			$response = $controller->githubInstall();

			self::assertSame(Http::STATUS_CREATED, $response->getStatus(), $name);
			self::assertSame(
				[
					'template' => ['slug' => 'tpl', 'manifest' => []],
					'name' => 'Example',
					'newSlug' => 'example',
					'ownerUid' => 'carol',
					'sourceOwner' => 'ConductionNL',
					'sourceRepo' => 'example-app',
					'sourceRef' => null,
					'credentialId' => $expected,
				],
				self::seamArguments(args: $captured),
				$name
			);
		}
	}//end testGithubInstallHandsTheSeamTheTemplateOwnerAndSource()

	/**
	 * The install seam's arguments by name.
	 *
	 * @param array<int,mixed> $args The arguments as passed.
	 *
	 * @return array<string,mixed> The arguments by name.
	 */
	private static function seamArguments(array $args): array {
		[$template, $name, $newSlug, $ownerUid, $source] = array_pad($args, 5, null);
		return [
			'template' => $template,
			'name' => $name,
			'newSlug' => $newSlug,
			'ownerUid' => $ownerUid,
			'sourceOwner' => $source?->owner,
			'sourceRepo' => $source?->repo,
			'sourceRef' => $source?->ref,
			'credentialId' => $source?->credentialId,
		];
	}//end seamArguments()
}//end class
