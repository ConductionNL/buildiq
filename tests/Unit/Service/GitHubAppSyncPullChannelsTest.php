<?php

/**
 * What GitHubAppSyncService::pull() hands the channel applier.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppChannelApplier;
use OCA\Buildiq\Service\AppRepoParser;
use OCA\Buildiq\Service\AppRepoSerializer;
use OCA\Buildiq\Service\GitHubAppSyncService;
use OCA\Buildiq\Service\GitHubCatalogService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins the repo coordinates, ref, credential, acting user and local
 * application a pull passes to the channel applier, and that the pull's
 * result carries the applier's report and warnings.
 *
 * @covers \OCA\Buildiq\Service\GitHubAppSyncService
 * @uses   \OCA\Buildiq\Service\LocalApplicationRef
 * @uses   \OCA\Buildiq\Service\RepoSource
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
class GitHubAppSyncPullChannelsTest extends TestCase {

	/**
	 * The applier receives the pulled template, the linked repo, the ref,
	 * the credential, the acting user and this instance's application.
	 *
	 * @return void
	 */
	public function testPullHandsTheLinkedRepoAndLocalApplicationToTheApplier(): void {
		$cases = [
			'all set' => ['main', 'cred-1', 'alice', ['uuid' => 'app-uuid', 'slug' => 'local-app']],
			'no credential, no user' => ['v1.0', null, null, ['id' => 'app-id', 'slug' => 'other']],
			'empty credential' => ['dev', '', 'bob', ['uuid' => 'u2']],
		];
		foreach ($cases as $name => [$ref, $credentialId, $actingUserId, $app]) {
			$captured = null;
			$service = $this->service(captured: $captured);
			$application = $app + ['githubRepo' => ['owner' => 'Conduction', 'name' => 'example-app']];

			$result = $service->pull(application: $application, ref: $ref, credentialId: $credentialId, actingUserId: $actingUserId);

			self::assertSame(
				[
					'template' => ['manifest' => ['pages' => []], 'version' => '1.2.3'],
					'owner' => 'Conduction',
					'repo' => 'example-app',
					'ref' => $ref,
					'actingUserId' => $actingUserId,
					'credentialId' => $credentialId,
					'applicationUuid' => (string)($app['uuid'] ?? $app['id']),
					'applicationSlug' => (string)($app['slug'] ?? ''),
				],
				$captured,
				$name
			);
			self::assertSame(['report' => true, 'warnings' => ['w1']], $result['channels'], $name);
			self::assertSame(['w1'], $result['warnings'], $name);
		}
	}//end testPullHandsTheLinkedRepoAndLocalApplicationToTheApplier()

	/**
	 * The service with doubles for every collaborator; the applier records its arguments by name.
	 *
	 * @param mixed $captured Receives the applier's arguments.
	 *
	 * @return GitHubAppSyncService The service.
	 */
	private function service(mixed &$captured): GitHubAppSyncService {
		$catalog = $this->createMock(GitHubCatalogService::class);
		$catalog->method('fetchRepoFiles')->willReturn(['manifest.json' => '{}']);
		$catalog->method('resolveCommitSha')->willReturn('abc123');

		$parser = $this->createMock(AppRepoParser::class);
		$parser->method('parse')->willReturn(['manifest' => ['pages' => []], 'version' => '1.2.3']);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willThrowException(new RuntimeException('none'));
		$registers->method('createFromArray')->willThrowException(new RuntimeException('no create'));

		$objects = $this->createMock(ObjectServiceInterface::class);
		$saved = $this->createMock(ObjectEntityInterface::class);
		$saved->method('jsonSerialize')->willReturn(['uuid' => 'version-uuid']);
		$saved->method('getObject')->willReturn(['uuid' => 'version-uuid']);
		$objects->method('saveObject')->willReturn($saved);

		$applier = $this->createMock(AppChannelApplier::class);
		$applier->method('apply')->willReturnCallback(
			static function (...$args) use (&$captured): array {
				$captured = self::applyArguments(args: $args);
				return ['report' => true, 'warnings' => ['w1']];
			}
		);

		return new GitHubAppSyncService(
			$objects,
			$registers,
			$this->createMock(SchemaMapper::class),
			$this->createMock(AppRepoSerializer::class),
			$parser,
			$catalog,
			$applier,
			new NullLogger(),
			$this->createMock(ContainerInterface::class)
		);
	}//end service()

	/**
	 * The applier's arguments by name.
	 *
	 * @param array<int,mixed> $args The arguments as passed.
	 *
	 * @return array<string,mixed> The arguments by name.
	 */
	private static function applyArguments(array $args): array {
		[$template, $source, $actingUserId, $local] = array_pad($args, 4, null);

		// apply() reads an empty owner or repo exactly like a missing one.
		return [
			'template' => $template,
			'owner' => in_array($source?->owner, [null, ''], true) ? null : $source->owner,
			'repo' => in_array($source?->repo, [null, ''], true) ? null : $source->repo,
			'ref' => $source?->ref,
			'actingUserId' => $actingUserId,
			'credentialId' => $source?->credentialId,
			'applicationUuid' => $local?->uuid,
			'applicationSlug' => $local?->slug,
		];
	}//end applyArguments()
}//end class
