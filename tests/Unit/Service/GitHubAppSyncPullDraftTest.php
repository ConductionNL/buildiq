<?php

/**
 * What GitHubAppSyncService::pull() creates, and what it refuses to create.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Exception\AppRepoParseException;
use OCA\Buildiq\Service\AppChannelApplier;
use OCA\Buildiq\Service\AppRepoCodec;
use OCA\Buildiq\Service\AppRepoParser;
use OCA\Buildiq\Service\AppRepoSerializer;
use OCA\Buildiq\Service\GitHubAppSyncService;
use OCA\Buildiq\Service\GitHubCatalogService;
use OCA\Buildiq\Service\RegisterStore;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins the non-destructive pull model of github-app-sync (REQ-GHAS-003): a
 * pull creates exactly one NEW draft ApplicationVersion stamped with the ref
 * and the resolved commit, never writes the Application (so productionVersion
 * is untouched), and creates nothing when the app is unlinked, the repo is
 * unreachable or the strict parse fails.
 *
 * @covers \OCA\Buildiq\Service\GitHubAppSyncService
 * @uses   \OCA\Buildiq\Exception\AppRepoParseException
 * @uses   \OCA\Buildiq\Service\GitHub\CredentialBrokerClient
 * @uses   \OCA\Buildiq\Kernel\Values\LocalApplicationRef
 * @uses   \OCA\Buildiq\Kernel\Values\RepoSource
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 * @uses   \OCA\Buildiq\Service\AppRepoCodec
 * @uses   \OCA\Buildiq\Service\RegisterStore
 */
class GitHubAppSyncPullDraftTest extends TestCase {

	/**
	 * Every saveObject() call: [object, register, schema].
	 *
	 * @var array<int,array{0:mixed,1:mixed,2:mixed}>
	 */
	private array $saves = [];

	/**
	 * The catalogue double.
	 *
	 * @var GitHubCatalogService&MockObject
	 */
	private GitHubCatalogService&MockObject $catalog;

	/**
	 * The parser double.
	 *
	 * @var AppRepoParser&MockObject
	 */
	private AppRepoParser&MockObject $parser;

	/**
	 * The channel applier double.
	 *
	 * @var AppChannelApplier&MockObject
	 */
	private AppChannelApplier&MockObject $applier;

	/**
	 * A linked application with a production version.
	 *
	 * @var array<string,mixed>
	 */
	private const APPLICATION = [
		'uuid' => 'app-uuid',
		'slug' => 'permit-tracker',
		'productionVersion' => 'prod-version-uuid',
		'githubRepo' => ['owner' => 'Conduction', 'name' => 'permit-tracker'],
	];

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saves = [];
		$this->catalog = $this->createMock(GitHubCatalogService::class);
		$this->parser = $this->createMock(AppRepoParser::class);
		$this->applier = $this->createMock(AppChannelApplier::class);
	}//end setUp()

	/**
	 * A pull creates one draft version stamped with ref and commit, and never saves the Application.
	 *
	 * @return void
	 */
	public function testPullCreatesOneDraftVersionAndLeavesTheApplicationAlone(): void {
		$this->catalog->method('fetchRepoFiles')->willReturn(['manifest.json' => '{}']);
		$this->catalog->method('resolveCommitSha')->willReturn('c0ffee');
		$this->parser->method('parse')->willReturn(['manifest' => ['pages' => ['home']], 'version' => '2.4.1']);
		$this->applier->method('apply')->willReturn(['warnings' => []]);

		$result = $this->service()->pull(application: self::APPLICATION, ref: 'release-2', credentialId: null, actingUserId: 'alice');

		self::assertCount(1, $this->saves);
		[$object, $register, $schema] = $this->saves[0];
		self::assertSame('buildiq', $register);
		self::assertSame('applicationVersion', $schema);
		self::assertSame('draft', $object['status']);
		self::assertSame('release-2', $object['sourceRef']);
		self::assertSame('c0ffee', $object['commitSha']);
		self::assertSame('app-uuid', $object['application']);
		self::assertSame('2.4.1', $object['semver']);
		self::assertSame(['pages' => ['home']], $object['manifest']);
		self::assertArrayNotHasKey('productionVersion', $object);
		self::assertSame('openbuild-permit-tracker-' . $object['slug'], $object['register']);

		self::assertSame('ok', $result['outcome']);
		self::assertSame('draft', $result['status']);
		self::assertSame('new-version-uuid', $result['versionUuid']);
		self::assertSame('c0ffee', $result['commitSha']);
		self::assertSame('release-2', $result['sourceRef']);
		self::assertSame($object['slug'], $result['versionSlug']);
	}//end testPullCreatesOneDraftVersionAndLeavesTheApplicationAlone()

	/**
	 * Without a resolvable commit the draft carries no commitSha, and a non-semver version falls back to 0.1.0.
	 *
	 * @return void
	 */
	public function testPullWithoutCommitOrSemverStampsNoShaAndSeedsTheVersion(): void {
		$this->catalog->method('fetchRepoFiles')->willReturn(['manifest.json' => '{}']);
		$this->catalog->method('resolveCommitSha')->willReturn(null);
		$this->parser->method('parse')->willReturn(['manifest' => [], 'version' => 'latest']);
		$this->applier->method('apply')->willReturn([]);

		$result = $this->service()->pull(application: self::APPLICATION, ref: 'main', credentialId: 'cred-1', actingUserId: 'alice');

		self::assertCount(1, $this->saves);
		self::assertArrayNotHasKey('commitSha', $this->saves[0][0]);
		self::assertSame('0.1.0', $this->saves[0][0]['semver']);
		self::assertNull($result['commitSha']);
		self::assertSame([], $result['warnings']);
	}//end testPullWithoutCommitOrSemverStampsNoShaAndSeedsTheVersion()

	/**
	 * An unlinked application (no linkage, or an empty owner or name) fetches and creates nothing.
	 *
	 * @return void
	 */
	public function testAnUnlinkedApplicationIsRefusedBeforeAnyFetch(): void {
		$this->catalog->expects(self::never())->method('fetchRepoFiles');
		$this->parser->expects(self::never())->method('parse');
		$service = $this->service();

		$cases = [
			'no linkage' => ['uuid' => 'a', 'slug' => 's'],
			'linkage not an array' => ['uuid' => 'a', 'githubRepo' => 'Conduction/x'],
			'empty owner' => ['uuid' => 'a', 'githubRepo' => ['owner' => '', 'name' => 'x']],
			'missing name' => ['uuid' => 'a', 'githubRepo' => ['owner' => 'Conduction']],
		];
		foreach ($cases as $name => $application) {
			$result = $service->pull(application: $application, ref: 'main', credentialId: null, actingUserId: 'alice');
			self::assertSame(['outcome' => 'not_linked'], $result, $name);
		}

		self::assertSame([], $this->saves);
	}//end testAnUnlinkedApplicationIsRefusedBeforeAnyFetch()

	/**
	 * An empty fetch is unreachable and nothing is parsed or created.
	 *
	 * @return void
	 */
	public function testAnUnreachableRepoCreatesNothing(): void {
		$this->catalog->method('fetchRepoFiles')->willReturn([]);
		$this->parser->expects(self::never())->method('parse');
		$this->applier->expects(self::never())->method('apply');

		$result = $this->service()->pull(application: self::APPLICATION, ref: 'main', credentialId: null, actingUserId: null);

		self::assertSame(['outcome' => 'github_unreachable'], $result);
		self::assertSame([], $this->saves);
	}//end testAnUnreachableRepoCreatesNothing()

	/**
	 * A strict-parse failure reaches the caller with its code and file, and nothing is created.
	 *
	 * @return void
	 */
	public function testAParseFailurePropagatesAndCreatesNothing(): void {
		$this->catalog->method('fetchRepoFiles')->willReturn(['manifest.json' => 'not json']);
		$this->catalog->expects(self::never())->method('resolveCommitSha');
		$this->parser->method('parse')->willThrowException(
			new AppRepoParseException(errorCode: 'manifest_invalid', message: 'bad manifest', filePath: 'manifest.json')
		);
		$this->applier->expects(self::never())->method('apply');

		try {
			$this->service()->pull(application: self::APPLICATION, ref: 'main', credentialId: null, actingUserId: 'alice');
			self::fail('The parse failure did not reach the caller.');
		} catch (AppRepoParseException $e) {
			self::assertSame('manifest_invalid', $e->getErrorCode());
			self::assertSame('manifest.json', $e->getFilePath());
		}

		self::assertSame([], $this->saves);
	}//end testAParseFailurePropagatesAndCreatesNothing()

	/**
	 * The service with recording doubles.
	 *
	 * @return GitHubAppSyncService
	 */
	private function service(): GitHubAppSyncService {
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willThrowException(new RuntimeException('none'));
		$registers->method('createFromArray')->willThrowException(new RuntimeException('no create'));

		$saved = $this->createMock(ObjectEntityInterface::class);
		$saved->method('jsonSerialize')->willReturn(['uuid' => 'new-version-uuid']);
		$saved->method('getObject')->willReturn(['uuid' => 'new-version-uuid']);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) use ($saved): ObjectEntityInterface {
				$this->saves[] = [($args['object'] ?? $args[0] ?? null), ($args['register'] ?? $args[2] ?? null), ($args['schema'] ?? $args[3] ?? null)];
				return $saved;
			}
		);

		return new GitHubAppSyncService(
			store: new RegisterStore(
				objectService: $objects,
				registerMapper: $registers,
				schemaMapper: $this->createMock(SchemaMapper::class)
			),
			codec: new AppRepoCodec(serializer: $this->createMock(AppRepoSerializer::class), parser: $this->parser),
			catalogService: $this->catalog,
			channelApplier: $this->applier,
			logger: new NullLogger(),
			container: $this->createMock(ContainerInterface::class)
		);
	}//end service()
}//end class
