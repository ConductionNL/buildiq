<?php

/**
 * Unit tests for the per-version branch of GitHubAppSyncService::push().
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/lifecycle-git-branches/tasks.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Kernel\Values\AppPushRequest;
use OCA\Buildiq\Kernel\Values\BrokerAccess;
use OCA\Buildiq\Service\AppChannelApplier;
use OCA\Buildiq\Service\AppRepoCodec;
use OCA\Buildiq\Service\AppRepoParser;
use OCA\Buildiq\Service\AppRepoSerializer;
use OCA\Buildiq\Service\GitHubAppSyncService;
use OCA\Buildiq\Service\GitHubCatalogService;
use OCA\Buildiq\Service\RegisterStore;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * A version with its own `githubBranch` pushes there, creating the branch from
 * the default branch head when it is missing; production keeps the default branch.
 *
 * @covers \OCA\Buildiq\Service\GitHubAppSyncService
 * @uses   \OCA\Buildiq\Service\GitHub\CredentialBrokerClient
 * @uses   \OCA\Buildiq\Service\GitHub\FileCommit
 * @uses   \OCA\Buildiq\Service\RegisterStore
 * @uses   \OCA\Buildiq\Service\AppRepoCodec
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 * @uses   \OCA\Buildiq\Kernel\Values\AppPushRequest
 * @uses   \OCA\Buildiq\Kernel\Values\BrokerAccess
 * @uses   \OCA\Buildiq\Kernel\Values\BrokeredRepo
 * @uses   \OCA\Buildiq\Kernel\Values\PushTarget
 *
 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-each-version-pushes-to-its-own-branch-req-bqgb-002
 */
final class GitHubAppSyncServiceBranchTest extends TestCase {

	/**
	 * Every broker call, as `METHOD path` => body.
	 *
	 * @var array<int,array{0:string,1:mixed}>
	 */
	private array $calls = [];

	/**
	 * The branches the repository has, growing as the service creates one.
	 *
	 * @var array<int,string>
	 */
	private array $branches = [];

	/**
	 * The service over a broker on which only the given branches exist.
	 *
	 * @param array<int,string> $branches The branches the repository has.
	 *
	 * @return GitHubAppSyncService
	 */
	private function service(array $branches): GitHubAppSyncService {
		$this->branches = $branches;
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			function (...$args): array {
				[, , $method, $path, , $encoded] = $args;
				$body = json_decode((string)$encoded, true);
				$this->calls[] = [$method . ' ' . $path, $body];
				if ($method === 'POST' && str_ends_with($path, '/git/refs') === true) {
					$this->branches[] = substr((string)($body['ref'] ?? ''), strlen('refs/heads/'));
				}

				if ($method === 'GET' && preg_match('#/git/ref/heads/(.+)$#', $path, $m) === 1) {
					if (in_array(rawurldecode($m[1]), $this->branches, true) === false) {
						return ['status' => 404, 'body' => '{"message":"Not Found"}'];
					}

					return ['status' => 200, 'body' => '{"object":{"sha":"head-of-' . rawurldecode($m[1]) . '"}}'];
				}

				if ($method === 'GET' && str_contains($path, '/git/commits/') === true) {
					return ['status' => 200, 'body' => '{"tree":{"sha":"tree-1"}}'];
				}

				return ['status' => 201, 'body' => '{"sha":"new-sha"}'];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($broker);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturn(
			[
				['uuid' => 'v-prod', 'application' => 'app-1', 'slug' => 'production', 'githubBranch' => 'production'],
				['uuid' => 'v-fees', 'application' => 'app-1', 'slug' => 'permit-fees', 'githubBranch' => 'permit-fees'],
				['uuid' => 'v-bad', 'application' => 'app-1', 'slug' => 'bad', 'githubBranch' => 'a..b'],
			]
		);
		$objects->method('saveObject')->willReturn($this->createMock(ObjectEntityInterface::class));
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn(new Register());
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn(new Schema());
		$serializer = $this->createMock(AppRepoSerializer::class);
		$serializer->method('serialize')->willReturn(['manifest.json' => '{}']);

		return new GitHubAppSyncService(
			new RegisterStore(objectService: $objects, registerMapper: $registers, schemaMapper: $schemas),
			new AppRepoCodec(serializer: $serializer, parser: $this->createMock(AppRepoParser::class)),
			$this->createMock(GitHubCatalogService::class),
			$this->createMock(AppChannelApplier::class),
			new NullLogger(),
			$container
		);
	}//end service()

	/**
	 * Push one version of the linked app.
	 *
	 * @param GitHubAppSyncService $service     The service.
	 * @param string               $versionSlug The version.
	 *
	 * @return array<string,mixed>
	 */
	private static function push(GitHubAppSyncService $service, string $versionSlug): array {
		return $service->push(
			application: [
				'uuid' => 'app-1',
				'slug' => 'vergunningen',
				'githubRepo' => ['owner' => 'gemeente', 'name' => 'vergunningen'],
				'githubDefaultBranch' => 'main',
				'productionVersion' => 'v-prod',
			],
			request: new AppPushRequest(access: new BrokerAccess(credentialId: 'cred-1', actingUserId: 'anna'), versionSlug: $versionSlug, newRepo: null)
		);
	}//end push()

	/**
	 * The ref calls made, in order.
	 *
	 * @return array<int,string>
	 */
	private function refCalls(): array {
		return array_values(
			array_filter(
				array_column($this->calls, 0),
				static fn (string $call): bool => str_contains($call, '/git/ref') === true
			)
		);
	}//end refCalls()

	/**
	 * A version whose branch exists pushes onto it and leaves main alone.
	 *
	 * @return void
	 */
	public function testAnExistingVersionBranchIsPushedTo(): void {
		$result = self::push(service: $this->service(branches: ['main', 'permit-fees']), versionSlug: 'permit-fees');

		$this->assertSame('ok', $result['outcome']);
		$this->assertSame('permit-fees', $result['branch']);
		$this->assertSame(
			[
				'GET /repos/gemeente/vergunningen/git/ref/heads/permit-fees',
				'GET /repos/gemeente/vergunningen/git/ref/heads/permit-fees',
				'PATCH /repos/gemeente/vergunningen/git/refs/heads/permit-fees',
			],
			$this->refCalls()
		);
	}//end testAnExistingVersionBranchIsPushedTo()

	/**
	 * A missing version branch is made from the default branch head, then pushed to.
	 *
	 * @return void
	 */
	public function testAMissingVersionBranchIsCreatedFromTheDefaultHead(): void {
		$result = self::push(service: $this->service(branches: ['main']), versionSlug: 'permit-fees');

		$this->assertSame('ok', $result['outcome']);
		$this->assertSame('permit-fees', $result['branch']);
		$created = array_values(array_filter($this->calls, static fn (array $call): bool => $call[0] === 'POST /repos/gemeente/vergunningen/git/refs'));
		$this->assertCount(1, $created);
		$this->assertSame(['ref' => 'refs/heads/permit-fees', 'sha' => 'head-of-main'], $created[0][1]);
		$this->assertNotContains('PATCH /repos/gemeente/vergunningen/git/refs/heads/main', $this->refCalls());
	}//end testAMissingVersionBranchIsCreatedFromTheDefaultHead()

	/**
	 * The production version pushes to the default branch, whatever its githubBranch says.
	 *
	 * @return void
	 */
	public function testProductionPushesToTheDefaultBranch(): void {
		$result = self::push(service: $this->service(branches: ['main', 'production']), versionSlug: 'production');

		$this->assertSame('main', $result['branch']);
		$this->assertContains('PATCH /repos/gemeente/vergunningen/git/refs/heads/main', $this->refCalls());
		$this->assertNotContains('POST /repos/gemeente/vergunningen/git/refs', array_column($this->calls, 0));
	}//end testProductionPushesToTheDefaultBranch()

	/**
	 * A stored branch name with `..` is not used; the version pushes to the default branch.
	 *
	 * @return void
	 */
	public function testAnUnsafeBranchNameFallsBackToTheDefault(): void {
		$result = self::push(service: $this->service(branches: ['main']), versionSlug: 'bad');

		$this->assertSame('main', $result['branch']);
	}//end testAnUnsafeBranchNameFallsBackToTheDefault()
}//end class
