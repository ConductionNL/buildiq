<?php

/**
 * Golden test for the credential routing of GitHubAppSyncService.
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

use OCA\Buildiq\Service\AppChannelApplier;
use OCA\Buildiq\Service\AppRepoCodec;
use OCA\Buildiq\Service\AppRepoParser;
use OCA\Buildiq\Service\AppRepoSerializer;
use OCA\Buildiq\Service\GitHub\AppPushRequest;
use OCA\Buildiq\Service\GitHub\BrokerAccess;
use OCA\Buildiq\Service\GitHub\BrokeredRepo;
use OCA\Buildiq\Service\GitHub\PushTarget;
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
use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

/**
 * Pins, for every push, template publish and link, which broker credential and
 * acting user each GitHub call goes out under, on which method and path, with
 * which body, and what the service returns, saves and logs. Credentials, repos
 * and broker answers are crossed so a mix-up between them shows. Broker answers
 * carrying token-shaped strings check that nothing recorded holds a token or a
 * piece of one. Set GOLDEN_RECORD=1 to write a missing fixture.
 *
 * @covers \OCA\Buildiq\Service\GitHubAppSyncService
 * @uses   \OCA\Buildiq\Service\GitHub\AppPushRequest
 * @uses   \OCA\Buildiq\Service\GitHub\BrokerAccess
 * @uses   \OCA\Buildiq\Service\GitHub\BrokeredRepo
 * @uses   \OCA\Buildiq\Service\GitHub\FileCommit
 * @uses   \OCA\Buildiq\Service\GitHub\PushTarget
 * @uses   \OCA\Buildiq\Service\GitHub\TokenScrubber
 * @uses   \OCA\Buildiq\Service\AppRepoCodec
 * @uses   \OCA\Buildiq\Service\RegisterStore
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
class GitHubAppSyncCredentialRoutingGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/github-app-sync-credential-routing.golden.json';

	private const TOKEN_PATTERN = '/gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}/';

	/**
	 * A piece of the token-shaped strings, long enough that no other recorded text holds it.
	 */
	private const TOKEN_FRAGMENT = 'Z9Z9Z9';

	/**
	 * The classic token-shaped string, built at run time so no literal token is in the repository.
	 *
	 * @return string
	 */
	private static function token(): string {
		return 'gh' . 'p_' . str_repeat('Z9', 18);
	}//end token()

	/**
	 * The fine-grained token-shaped string, built at run time.
	 *
	 * @return string
	 */
	private static function fineGrainedToken(): string {
		return 'github' . '_pat_' . str_repeat('Z9_', 27);
	}//end fineGrainedToken()

	/**
	 * Broker answers by name: a list of [method, path regex, status, body], status -1 throws.
	 *
	 * @return array<string,array<int,array{0:string,1:string,2:int,3:string}>>
	 */
	private function scripts(): array {
		$leak = '{"message":"bad credentials ' . self::token() . '"}';
		$fine = '{"message":"bad credentials ' . self::fineGrainedToken() . '"}';
		$straddling = str_repeat('x', 285) . self::token();
		$thrown = 'transport ' . self::token();
		return [
			'all succeed' => [],
			'empty branch' => [['GET', '#/git/ref/heads/#', 404, '{"message":"Not Found"}']],
			'ref without sha' => [['GET', '#/git/ref/heads/#', 200, '{"object":{}}']],
			'head commit unreadable' => [['GET', '#/git/commits/#', 500, $leak]],
			'blob throws' => [['POST', '#/git/blobs$#', -1, $thrown]],
			'blob fails' => [['POST', '#/git/blobs$#', 500, $leak]],
			'blob answers no json' => [['POST', '#/git/blobs$#', 201, 'not json']],
			'tree throws' => [['POST', '#/git/trees$#', -1, 'transport ' . self::fineGrainedToken()]],
			'tree forbidden' => [['POST', '#/git/trees$#', 403, $fine]],
			'tree fails' => [['POST', '#/git/trees$#', 500, $straddling]],
			'commit throws' => [['POST', '#/git/commits$#', -1, $thrown]],
			'commit forbidden' => [['POST', '#/git/commits$#', 403, $leak]],
			'commit fails' => [['POST', '#/git/commits$#', 502, '']],
			'ref throws' => [['PATCH', '#/git/refs/#', -1, $thrown]],
			'ref conflict' => [['PATCH', '#/git/refs/#', 422, '{"message":"not a fast forward"}']],
			'ref forbidden' => [['PATCH', '#/git/refs/#', 403, $leak]],
			'ref fails' => [['PATCH', '#/git/refs/#', 500, $leak]],
			'new ref conflict' => [['GET', '#/git/ref/heads/#', 404, ''], ['POST', '#/git/refs$#', 422, '{"message":"exists"}']],
			'new ref throws' => [['GET', '#/git/ref/heads/#', 404, ''], ['POST', '#/git/refs$#', -1, $thrown]],
			'create throws' => [['POST', '#/repos$#', -1, $thrown]],
			'create fails' => [['POST', '#/repos$#', 500, $leak]],
			'create taken' => [['POST', '#/repos$#', 422, '{"message":"name already exists"}']],
			'create taken, no user' => [['POST', '#/repos$#', 422, ''], ['GET', '#^/user$#', 500, $leak]],
			'create taken, repo unreadable' => [['POST', '#/repos$#', 422, ''], ['GET', '#^/repos/[^/]+/[^/]+$#', 500, $leak]],
			'create without owner' => [['POST', '#/repos$#', 201, '{"default_branch":"made"}']],
			'create without branch' => [['POST', '#/repos$#', 201, '{"owner":{"login":"made-owner"}}']],
			'topic fails' => [['PUT', '#/topics$#', 500, $leak]],
		];
	}//end scripts()

	/**
	 * The answer the fake GitHub gives when the script does not speak.
	 *
	 * @param string $method The HTTP method.
	 * @param string $path   The path.
	 * @param int    $count  The number of calls so far.
	 *
	 * @return array{status:int,body:string}
	 */
	private static function defaultAnswer(string $method, string $path, int $count): array {
		$data = ['sha' => 'sha-' . $count];
		if ($method === 'GET' && $path === '/user') {
			$data = ['login' => 'token-user'];
		} elseif ($method === 'GET' && str_contains($path, '/git/ref/heads/') === true) {
			$data = ['object' => ['sha' => 'head-commit']];
		} elseif ($method === 'GET' && str_contains($path, '/git/commits/') === true) {
			$data = ['tree' => ['sha' => 'head-tree']];
		} elseif ($method === 'GET') {
			$data = ['default_branch' => 'remote-default'];
		} elseif ($method === 'POST' && str_ends_with($path, '/repos') === true) {
			$login = 'token-user';
			if (str_starts_with($path, '/orgs/') === true) {
				$login = rawurldecode(explode('/', $path)[2]);
			}

			$data = ['owner' => ['login' => $login], 'default_branch' => 'fresh-default'];
		}

		return ['status' => 200, 'body' => (string)json_encode($data)];
	}//end defaultAnswer()

	/**
	 * Build the service with fakes that record what it sends, saves and logs.
	 *
	 * @param array<int,array{0:string,1:string,2:int,3:string}> $script  The broker's answers.
	 * @param array<string,mixed>                                $trace   Receives calls, saved and the logger.
	 * @param bool                                               $present Whether the broker is installed.
	 *
	 * @return GitHubAppSyncService
	 */
	private function service(array $script, array &$trace, bool $present = true): GitHubAppSyncService {
		$trace = ['calls' => [], 'saved' => [], 'logger' => $this->recordingLogger()];

		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			static function (...$args) use (&$trace, $script): array {
				[$credentialId, $appId, $method, $path, $headers, $body, $actingUserId] = $args + [6 => null];
				$trace['calls'][] = [
					'credentialId' => $credentialId,
					'appId' => $appId,
					'actingUserId' => $actingUserId,
					'request' => $method . ' ' . $path,
					'headers' => $headers,
					'body' => $body,
				];
				foreach ($script as [$m, $pattern, $status, $responseBody]) {
					if ($m !== $method || preg_match($pattern, $path) !== 1) {
						continue;
					}

					if ($status === -1) {
						throw new \RuntimeException($responseBody);
					}

					return ['status' => $status, 'body' => $responseBody];
				}

				return self::defaultAnswer(method: $method, path: $path, count: count($trace['calls']));
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($broker);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturn(
			[
				['uuid' => 'v1', 'application' => 'app-1', 'slug' => 'v1'],
				['uuid' => 'v2', 'application' => 'app-1', 'slug' => 'v2'],
				['uuid' => 'other', 'application' => 'app-9', 'slug' => 'other'],
			]
		);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) use (&$trace): ObjectEntityInterface {
				$trace['saved'][] = ['object' => $args[0], 'target' => array_values(array_filter(array_slice($args, 1), 'is_string'))];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn(new Register());
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn(new Schema());

		$serializer = $this->createMock(AppRepoSerializer::class);
		$serializer->method('serialize')->willReturnCallback(
			static fn (array $application, array $version): array => [
				'manifest.json' => '{"version":"' . $version['slug'] . '","app":"' . $application['uuid'] . '"}',
				'schemas/a b.json' => "bytes\n",
			]
		);
		$serializer->method('serializeTemplate')->willReturnCallback(
			static fn (array $template): array => ['openbuild-app.json' => '{"template":"' . $template['slug'] . '"}']
		);

		$arguments = [
			new RegisterStore(objectService: $objects, registerMapper: $registers, schemaMapper: $schemas),
			new AppRepoCodec(serializer: $serializer, parser: $this->createMock(AppRepoParser::class)),
			$this->createMock(GitHubCatalogService::class),
			$this->createMock(AppChannelApplier::class),
			$trace['logger'],
			$container,
		];
		if ($present === true) {
			return new GitHubAppSyncService(...$arguments);
		}

		$absent = $this->getMockBuilder(GitHubAppSyncService::class)
			->setConstructorArgs($arguments)
			->onlyMethods(['isBrokerAvailable'])
			->getMock();
		$absent->method('isBrokerAvailable')->willReturn(false);
		return $absent;
	}//end service()

	/**
	 * A logger that keeps every line.
	 *
	 * @return AbstractLogger
	 */
	private function recordingLogger(): AbstractLogger {
		return new class extends AbstractLogger {
			/**
			 * Recorded log lines.
			 *
			 * @var array<int,string>
			 */
			public array $lines = [];

			/**
			 * Record one log line.
			 *
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array<mixed>      $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = $level . ': ' . $message . ' ' . json_encode($context);
			}//end log()
		};
	}//end recordingLogger()

	/**
	 * Run one call against the fakes and record everything it did.
	 *
	 * @param array<int,array{0:string,1:string,2:int,3:string}> $script  The broker's answers.
	 * @param callable(GitHubAppSyncService):array<mixed>        $call    The call.
	 * @param bool                                               $present Whether the broker is installed.
	 *
	 * @return array<string,mixed>
	 */
	private function record(array $script, callable $call, bool $present = true): array {
		$trace = [];
		$service = $this->service(script: $script, trace: $trace, present: $present);
		$outcome = [];
		try {
			$outcome['result'] = $call($service);
		} catch (Throwable $e) {
			$outcome['error'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['calls'] = $trace['calls'];
		$outcome['saved'] = $trace['saved'];
		$outcome['logs'] = $trace['logger']->lines;
		return $outcome;
	}//end record()

	/**
	 * One outcome with each broker call on one line, for the fixture.
	 *
	 * @param array<string,mixed> $outcome The recorded outcome.
	 *
	 * @return array<string,mixed>
	 */
	private static function compact(array $outcome): array {
		$outcome['calls'] = array_map(
			static fn (array $sent): string => implode(
				' | ',
				[$sent['credentialId'], $sent['appId'], (string)($sent['actingUserId'] ?? '(no user)'), $sent['request'], (string)json_encode($sent['headers']), (string)($sent['body'] ?? '(no body)')]
			),
			$outcome['calls']
		);
		return $outcome;
	}//end compact()

	/**
	 * Call push() through its public signature.
	 *
	 * @param GitHubAppSyncService $service The service.
	 * @param array<string,mixed>  $case    Application, versionSlug, override, credential, user, visibility.
	 *
	 * @return array<string,mixed>
	 */
	private function push(GitHubAppSyncService $service, array $case): array {
		$override = ($case['override'] ?? null);
		$newRepo = null;
		if ($override !== null) {
			$newRepo = new PushTarget(org: (string)($override['org'] ?? ''), repo: $override['name'], visibility: $case['visibility'] ?? 'public');
		}

		return $service->push(
			application: $case['application'],
			request: new AppPushRequest(
				access: new BrokerAccess(credentialId: $case['credential'], actingUserId: $case['user']),
				versionSlug: $case['versionSlug'] ?? null,
				newRepo: $newRepo
			)
		);
	}//end push()

	/**
	 * Call publishTemplate() through its public signature.
	 *
	 * @param GitHubAppSyncService $service The service.
	 * @param array<string,mixed>  $case    Template, org, repoName, credential, user, visibility.
	 *
	 * @return array<string,mixed>
	 */
	private function publish(GitHubAppSyncService $service, array $case): array {
		return $service->publishTemplate(
			template: $case['template'] ?? ['slug' => 'tpl'],
			repo: new BrokeredRepo(
				target: new PushTarget(org: (string)($case['org'] ?? ''), repo: (string)($case['repoName'] ?? ''), visibility: $case['visibility'] ?? 'public'),
				access: new BrokerAccess(credentialId: $case['credential'], actingUserId: $case['user'])
			)
		);
	}//end publish()

	/**
	 * Call link() through its public signature.
	 *
	 * @param GitHubAppSyncService $service The service.
	 * @param array<string,mixed>  $case    Owner, name, credential, user.
	 *
	 * @return array<string,mixed>
	 */
	private function link(GitHubAppSyncService $service, array $case): array {
		return $service->link(
			application: ['uuid' => 'app-1', 'slug' => 'my-app'],
			repo: new BrokeredRepo(
				target: new PushTarget(org: $case['owner'], repo: $case['name']),
				access: new BrokerAccess(credentialId: (string)$case['credential'], actingUserId: $case['user'])
			)
		);
	}//end link()

	/**
	 * The application shapes a push can start from.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function pushStarts(): array {
		$app = ['uuid' => 'app-1', 'slug' => 'my-app', 'productionVersion' => 'v2'];
		$linked = $app + ['githubRepo' => ['owner' => 'acme', 'name' => 'app'], 'githubDefaultBranch' => 'trunk'];
		return [
			'linked' => ['application' => $linked],
			'linked, odd names' => ['application' => ['githubRepo' => ['owner' => 'Org Name', 'name' => 'repo/with%chars'], 'githubDefaultBranch' => 'feat/x y'] + $app],
			'linked, no branch' => ['application' => $app + ['githubRepo' => ['owner' => 'acme', 'name' => 'app']]],
			'half linked' => ['application' => $app + ['githubRepo' => ['owner' => 'acme']]],
			'unlinked' => ['application' => $app],
			'new repo, own account, private' => ['application' => $app, 'override' => ['owner' => 'ignored', 'name' => 'fresh', 'org' => ''], 'visibility' => 'private'],
			'new repo, org, public' => ['application' => $app, 'override' => ['owner' => '', 'name' => 'fresh', 'org' => ' Org Name '], 'visibility' => 'public'],
			'new repo over a link' => ['application' => $linked, 'override' => ['name' => 'fresh'], 'visibility' => 'private'],
		];
	}//end pushStarts()

	/**
	 * Every push case.
	 *
	 * @param array<string,array{0:string,1:?string}> $credentials The credentials and their users.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function pushCases(array $credentials): array {
		$cases = [];
		$starts = $this->pushStarts();
		foreach ($credentials as $cn => [$credential, $user]) {
			foreach ($starts as $sn => $start) {
				$cases["push / $cn / $sn"] = fn (GitHubAppSyncService $s): array => $this->push($s, $start + ['credential' => $credential, 'user' => $user]);
			}
		}

		foreach (['v1', 'nope', ''] as $slug) {
			$cases["push / version '$slug'"] = fn (GitHubAppSyncService $s): array => $this->push(
				$s,
				$starts['linked'] + ['versionSlug' => $slug, 'credential' => 'cred-A', 'user' => 'alice']
			);
		}

		$withoutProduction = ['application' => ['uuid' => 'app-1', 'slug' => 'my-app', 'githubRepo' => ['owner' => 'acme', 'name' => 'app']]];
		$cases['push / no production version'] = fn (GitHubAppSyncService $s): array => $this->push(
			$s,
			$withoutProduction + ['credential' => 'cred-A', 'user' => 'alice']
		);
		$cases['push / no version of this app'] = fn (GitHubAppSyncService $s): array => $this->push(
			$s,
			['application' => ['uuid' => 'app-0', 'slug' => 'none'], 'credential' => 'cred-A', 'user' => 'alice']
		);

		return $cases;
	}//end pushCases()

	/**
	 * Every publish case.
	 *
	 * @param array<string,array{0:string,1:?string}> $credentials The credentials and their users.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function publishCases(array $credentials): array {
		$shapes = [
			'own account, default name' => ['org' => null, 'repoName' => null, 'visibility' => 'public'],
			'org, default name' => ['org' => ' Org Name ', 'repoName' => null, 'visibility' => 'private'],
			'own account, named' => ['org' => null, 'repoName' => ' custom ', 'visibility' => 'private'],
			'org, named' => ['org' => 'acme', 'repoName' => 'custom', 'visibility' => 'public'],
			'blank org, blank name' => ['org' => '', 'repoName' => '  ', 'visibility' => 'public'],
		];
		$cases = [];
		foreach ($credentials as $cn => [$credential, $user]) {
			foreach ($shapes as $sn => $shape) {
				$cases["publish / $cn / $sn"] = fn (GitHubAppSyncService $s): array => $this->publish($s, $shape + ['credential' => $credential, 'user' => $user]);
			}
		}

		$cases['publish / blank slug'] = fn (GitHubAppSyncService $s): array => $this->publish(
			$s,
			['template' => ['slug' => ' '], 'credential' => 'cred-A', 'user' => 'alice']
		);
		return $cases;
	}//end publishCases()

	/**
	 * Every case under its name: a call, and the script it runs against.
	 *
	 * @return array<string,array{0:callable,1:array<int,mixed>}>
	 */
	private function cases(): array {
		$credentials = ['alice owns A' => ['cred-A', 'alice'], 'bob owns B' => ['cred-B', 'bob'], 'no user' => ['cred-C', null]];
		$scripts = $this->scripts();

		$cases = [];
		foreach (array_merge($this->pushCases($credentials), $this->publishCases($credentials)) as $name => $call) {
			$cases[$name] = [$call, []];
		}

		$starts = $this->pushStarts();
		foreach ($scripts as $sn => $script) {
			$cases["push linked / $sn"] = [
				fn (GitHubAppSyncService $s): array => $this->push($s, $starts['linked'] + ['credential' => 'cred-A', 'user' => 'alice']),
				$script,
			];
			$cases["push new repo / $sn"] = [
				fn (GitHubAppSyncService $s): array => $this->push($s, $starts['new repo, org, public'] + ['credential' => 'cred-B', 'user' => 'bob']),
				$script,
			];
			$cases["publish own account / $sn"] = [
				fn (GitHubAppSyncService $s): array => $this->publish($s, ['credential' => 'cred-A', 'user' => 'alice']),
				$script,
			];
			$cases["publish org / $sn"] = [
				fn (GitHubAppSyncService $s): array => $this->publish($s, ['org' => 'acme', 'credential' => 'cred-B', 'user' => 'bob']),
				$script,
			];
		}

		$cases['publish, org with spaces / create taken'] = [
			fn (GitHubAppSyncService $s): array => $this->publish($s, ['org' => ' Org Name ', 'credential' => 'cred-B', 'user' => 'bob']),
			$scripts['create taken'],
		];
		$cases['two callers through one instance'] = [
			fn (GitHubAppSyncService $s): array => [
				$this->push($s, $starts['linked'] + ['credential' => 'cred-A', 'user' => 'alice']),
				$this->publish($s, ['org' => 'beta', 'credential' => 'cred-B', 'user' => 'bob']),
				$this->link($s, ['owner' => 'gamma', 'name' => 'app', 'credential' => 'cred-C', 'user' => null]),
			],
			[],
		];

		foreach (['A' => ['cred-A', 'alice'], 'no user' => ['cred-C', null], 'empty credential' => ['', 'alice'], 'no credential' => [null, 'alice']] as $cn => [$credential, $user]) {
			$link = ['owner' => 'Org Name', 'name' => 'repo/with%chars', 'credential' => $credential, 'user' => $user];
			$cases["link / $cn / all succeed"] = [fn (GitHubAppSyncService $s): array => $this->link($s, $link), []];
			$cases["link / $cn / repo unreadable"] = [fn (GitHubAppSyncService $s): array => $this->link($s, $link), [['GET', '#^/repos/#', 404, self::token()]]];
			$cases["link / $cn / throws"] = [fn (GitHubAppSyncService $s): array => $this->link($s, $link), [['GET', '#^/repos/#', -1, self::token()]]];
			$cases["link / $cn / no default branch"] = [fn (GitHubAppSyncService $s): array => $this->link($s, $link), [['GET', '#^/repos/#', 200, '{}']]];
		}

		return $cases;
	}//end cases()

	/**
	 * Every case matches its recorded broker traffic, result, saves and logs.
	 *
	 * @return void
	 */
	public function testCredentialRoutingMatchesGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => [$call, $script]) {
			$actual[$name] = self::compact(outcome: $this->record(script: $script, call: $call));
		}

		$starts = $this->pushStarts();
		$actual['no broker / push'] = self::compact(outcome: $this->record([], fn (GitHubAppSyncService $s): array => $this->push($s, $starts['linked'] + ['credential' => 'cred-A', 'user' => 'alice']), false));
		$actual['no broker / publish'] = self::compact(outcome: $this->record([], fn (GitHubAppSyncService $s): array => $this->publish($s, ['credential' => 'cred-A', 'user' => 'alice']), false));
		$actual['no broker / link'] = self::compact(outcome: $this->record([], fn (GitHubAppSyncService $s): array => $this->link($s, ['owner' => 'acme', 'name' => 'app', 'credential' => 'cred-A', 'user' => 'alice']), false));

		$json = (string)json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		self::assertSame(0, preg_match(self::TOKEN_PATTERN, $json), 'a recorded call, log or result carries a token');
		self::assertStringNotContainsString(self::TOKEN_FRAGMENT, $json, 'a recorded call, log or result carries a piece of a token');

		if (file_exists(self::FIXTURE) === false && getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, $json . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$current = json_decode($json, true);
		foreach ($current as $name => $result) {
			self::assertSame($expected[$name] ?? null, $result, $name);
		}

		self::assertSame(array_keys($expected), array_keys($current));
	}//end testCredentialRoutingMatchesGolden()

	/**
	 * Whatever the fixture holds: every call of a case carries that case's own
	 * credential and acting user, and Buildiq's app id.
	 *
	 * @return void
	 */
	public function testEveryCallCarriesItsOwnCredentialAndUser(): void {
		$owners = ['cred-A' => 'alice', 'cred-B' => 'bob', 'cred-C' => null];
		$seen = 0;
		foreach ($this->cases() as $name => [$call, $script]) {
			foreach ($this->record(script: $script, call: $call)['calls'] as $sent) {
				$seen++;
				self::assertSame('buildiq', $sent['appId'], $name);
				self::assertArrayHasKey($sent['credentialId'], $owners, $name);
				self::assertSame($owners[$sent['credentialId']], $sent['actingUserId'], $name);
			}
		}

		self::assertGreaterThan(500, $seen);
	}//end testEveryCallCarriesItsOwnCredentialAndUser()
}//end class
