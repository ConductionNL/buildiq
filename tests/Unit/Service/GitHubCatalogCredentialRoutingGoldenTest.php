<?php

/**
 * Golden test for the credential routing of GitHubCatalogService's repository fetches.
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

use OCA\Buildiq\Service\GitHubCatalogService;
use OCA\Buildiq\Service\GitHubFormCatalogService;
use OCA\Buildiq\Service\RepoSource;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Pins which credential and acting user each repository fetch goes out under, on
 * which path, when it falls back to an anonymous request (which carries neither),
 * which tree entries it reads, and what it returns and logs. Broker answers
 * carrying token-shaped strings check that no log holds a token. Set
 * GOLDEN_RECORD=1 to write a missing fixture.
 *
 * @covers \OCA\Buildiq\Service\GitHubCatalogService
 * @uses   \OCA\Buildiq\Service\GitHubFormCatalogService
 * @uses   \OCA\Buildiq\Service\RepoSource
 */
class GitHubCatalogCredentialRoutingGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/github-catalog-credential-routing.golden.json';

	private const TOKEN_PATTERN = '/gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}/';

	/**
	 * The channel limit of the fetch.
	 */
	private const MAX_CHANNEL_FILES = 2048;

	/**
	 * A token-shaped string built at run time, so no literal token is in the repository.
	 *
	 * @return string
	 */
	private static function token(): string {
		return 'gh' . 'p_' . str_repeat('Z9', 18);
	}//end token()

	/**
	 * A contents-API answer.
	 *
	 * @param string $text The file's text.
	 *
	 * @return string
	 */
	private static function contents(string $text): string {
		return (string)json_encode(['content' => chunk_split(base64_encode($text), 8, "\n"), 'encoding' => 'base64']);
	}//end contents()

	/**
	 * The repository the fake GitHub serves: tree listing, schema listing, contents.
	 *
	 * @param string $path The GitHub-relative path.
	 *
	 * @return array{status:int,body:string}
	 */
	private static function github(string $path): array {
		if (str_starts_with($path, '/search/repositories') === true) {
			return [
				'status' => 200,
				'body' => (string)json_encode(
					['items' => [['full_name' => 'acme/app', 'owner' => ['login' => 'acme'], 'name' => 'app', 'default_branch' => 'main', 'stargazers_count' => 1]]]
				),
			];
		}

		if (str_contains($path, '/git/trees/') === true) {
			return [
				'status' => 200,
				'body' => (string)json_encode(
					[
						'tree' => [
							['type' => 'blob', 'path' => 'data-registers/reg.json'],
							['type' => 'blob', 'path' => 'connectors/source/conn.json'],
							['type' => 'blob', 'path' => 'automations/auto.json'],
							['type' => 'blob', 'path' => 'skills/pkg/skill.md'],
							['type' => 'blob', 'path' => 'flows/one.json'],
							['type' => 'blob', 'path' => 'agents/one.json'],
							['type' => 'blob', 'path' => 'unrelated/no.json'],
							['type' => 'blob', 'path' => 'flows/../../secret.json'],
							['type' => 'blob', 'path' => ''],
							['type' => 'tree', 'path' => 'flows/sub'],
							'not an entry',
						],
					]
				),
			];
		}//end if

		if (str_ends_with(explode('?', $path)[0], '/contents/schemas') === true) {
			return [
				'status' => 200,
				'body' => (string)json_encode(
					[
						['type' => 'file', 'name' => 'a.json'],
						['type' => 'file', 'name' => 'b.txt'],
						['type' => 'dir', 'name' => 'c.json'],
						'not an entry',
					]
				),
			];
		}

		if (str_contains($path, '/commits/') === true) {
			return ['status' => 200, 'body' => '{"sha":"resolved-sha"}'];
		}

		if (str_ends_with(explode('?', $path)[0], '/openbuild-app.json') === true) {
			return ['status' => 200, 'body' => self::contents('{"name":"from ' . explode('/', $path)[3] . '"}')];
		}

		return ['status' => 200, 'body' => self::contents('text of ' . explode('?', $path)[0])];
	}//end github()

	/**
	 * Run one call against the fakes and record everything it did.
	 *
	 * @param array<int,array{0:string,1:int,2:string}> $script Broker answers: [path regex, status, body], status -1 throws.
	 * @param callable(GitHubCatalogService):mixed      $call   The call.
	 *
	 * @return array<string,mixed>
	 */
	private function record(array $script, callable $call): array {
		$brokered = [];
		$anonymous = [];

		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			static function (...$args) use (&$brokered, $script): array {
				[$credentialId, $appId, $method, $path, $headers, $body, $actingUserId] = $args + [6 => null];
				$brokered[] = [
					'credentialId' => $credentialId,
					'appId' => $appId,
					'actingUserId' => $actingUserId,
					'request' => $method . ' ' . $path,
					'headers' => $headers,
					'body' => $body,
				];
				foreach ($script as [$pattern, $status, $responseBody]) {
					if (preg_match($pattern, $path) !== 1) {
						continue;
					}

					if ($status === -1) {
						throw new \RuntimeException($responseBody);
					}

					return ['status' => $status, 'body' => $responseBody];
				}

				return self::github(path: $path);
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($broker);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use (&$anonymous): IResponse {
				$anonymous[] = ['url' => $url, 'options' => $options];
				$answer = self::github(path: str_replace('https://api.github.com', '', $url));
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($answer['status']);
				$response->method('getBody')->willReturn($answer['body']);
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		$logger = $this->recordingLogger();
		$service = new GitHubCatalogService(
			clientService: $clients,
			cacheFactory: $cacheFactory,
			logger: $logger,
			container: $container
		);

		$result = $call($service);
		return ['result' => $result, 'brokered' => $brokered, 'anonymous' => $anonymous, 'logs' => $logger->lines];
	}//end record()

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
	 * One outcome with each request on one line, for the fixture.
	 *
	 * @param array<string,mixed> $outcome The recorded outcome.
	 *
	 * @return array<string,mixed>
	 */
	private static function compact(array $outcome): array {
		$outcome['brokered'] = array_map(
			static fn (array $sent): string => implode(
				' | ',
				[$sent['credentialId'], $sent['appId'], (string)($sent['actingUserId'] ?? '(no user)'), $sent['request'], (string)json_encode($sent['headers']), (string)($sent['body'] ?? '(no body)')]
			),
			$outcome['brokered']
		);
		$outcome['anonymous'] = array_map(
			static fn (array $sent): string => $sent['url'] . ' | ' . json_encode($sent['options']),
			$outcome['anonymous']
		);
		return $outcome;
	}//end compact()

	/**
	 * Every repository fetch, each through its public signature.
	 *
	 * @param array{0:string,1:string,2:?string}      $repo     Owner, repo, ref.
	 * @param array{0:?string,1:?string}              $identity Credential, acting user.
	 *
	 * @return array<string,callable(GitHubCatalogService):mixed>
	 */
	private function fetches(array $repo, array $identity): array {
		[$owner, $name, $ref] = $repo;
		[$credential, $user] = $identity;
		$source = new RepoSource(owner: $owner, repo: $name, ref: $ref, credentialId: $credential);
		return [
			'files' => static fn (GitHubCatalogService $s): array => $s->fetchRepoFiles(source: $source, actingUserId: $user),
			'descriptor' => static fn (GitHubCatalogService $s): ?array => $s->fetchDescriptor(source: $source, actingUserId: $user),
			'commit' => static fn (GitHubCatalogService $s): ?string => $s->resolveCommitSha(source: $source, actingUserId: $user),
			'one file' => static fn (GitHubCatalogService $s): ?string => $s->fetchFileContents(source: $source, path: 'dir name/a b.json', actingUserId: $user),
		];
	}//end fetches()

	/**
	 * The channel fetch's limit: failed reads do not count, and passing it is logged.
	 *
	 * @param int $listed The number of channel blobs the tree lists.
	 *
	 * @return array<string,mixed>
	 */
	private function truncation(int $listed): array {
		$tree = [];
		for ($i = 0; $i < $listed; $i++) {
			$tree[] = ['type' => 'blob', 'path' => 'flows/' . $i . '.json'];
		}

		$outcome = $this->record(
			script: [
				['#/git/trees/#', 200, (string)json_encode(['tree' => $tree])],
				['#/contents/flows/[0-2]\.json#', 404, ''],
				['#/contents/(openbuild-app|manifest)\.json|/contents/schemas#', 404, ''],
			],
			call: static fn (GitHubCatalogService $s): array => $s->fetchRepoFiles(
				source: new RepoSource(owner: 'acme', repo: 'big', ref: null, credentialId: 'cred-A'),
				actingUserId: 'alice'
			)
		);

		return [
			'files' => count($outcome['result']),
			'first' => array_key_first($outcome['result']),
			'last' => array_key_last($outcome['result']),
			'calls' => count($outcome['brokered']),
			'logs' => $outcome['logs'],
		];
	}//end truncation()

	/**
	 * Every case under its name.
	 *
	 * @return array<string,array{0:callable,1:array<int,array{0:string,1:int,2:string}>}>
	 */
	private function cases(): array {
		$identities = [
			'alice owns A' => ['cred-A', 'alice'],
			'bob owns B' => ['cred-B', 'bob'],
			'no user' => ['cred-C', null],
			'empty credential' => ['', 'alice'],
			'no credential' => [null, 'alice'],
		];
		$repos = [
			'on main' => ['acme', 'app', 'main'],
			'no ref' => ['acme', 'other', null],
			'empty ref' => ['beta', 'app', ''],
			'slash ref' => ['acme', 'app', 'feat/x'],
			'bad owner' => ['ac me', 'app', 'main'],
			'bad repo' => ['acme', '../app', 'main'],
			'bad ref' => ['acme', 'app', 'a b'],
		];
		$noCache = $this->createMock(ICacheFactory::class);
		$noCache->method('isAvailable')->willReturn(false);
		$scripts = [
			'broker refuses, anonymous answers' => [['#.#', -1, 'not admitted ' . self::token()]],
			'broker answers 403' => [['#.#', 403, '{"message":"rate limit ' . self::token() . '"}']],
			'broker answers 500' => [['#.#', 500, self::token()]],
			'tree refused' => [['#/git/trees/#', 404, '']],
			'tree without entries' => [['#/git/trees/#', 200, '{"truncated":true}']],
			'tree not json' => [['#/git/trees/#', 200, 'nope']],
			'bad base64' => [['#/contents/#', 200, '{"content":"***","encoding":"base64"}']],
			'plain content' => [['#/contents/#', 200, '{"content":"plain"}']],
			'empty content' => [['#/contents/#', 200, '{"content":""}']],
			'contents not json' => [['#/contents/#', 200, 'nope']],
			'commit without sha' => [['#/commits/#', 200, '{}']],
			'commit not json' => [['#/commits/#', 200, 'nope']],
			'descriptor is a list' => [['#/openbuild-app\.json#', 200, self::contents('[1]')]],
			'descriptor not json' => [['#/openbuild-app\.json#', 200, self::contents('nope')]],
		];

		$cases = [];
		foreach ($identities as $in => $identity) {
			[$credential, $user] = $identity;
			$cases["search / $in"] = [
				static fn (GitHubCatalogService $s): array => $s->search(query: 'term', actingUserId: $user, credentialId: $credential),
				[],
			];
			$cases["forms / $in"] = [
				static fn (GitHubCatalogService $s): array => (new GitHubFormCatalogService(catalog: $s, cacheFactory: $noCache))->searchForms(
					query: 'term',
					actingUserId: $user,
					credentialId: $credential
				),
				[],
			];
			foreach ($repos as $rn => $repo) {
				foreach ($this->fetches(repo: $repo, identity: $identity) as $fn => $call) {
					$cases["$fn / $in / $rn"] = [$call, []];
				}
			}
		}

		foreach ($scripts as $sn => $script) {
			foreach ($this->fetches(repo: $repos['on main'], identity: $identities['alice owns A']) as $fn => $call) {
				$cases["$fn / $sn"] = [$call, $script];
			}
		}

		return $cases;
	}//end cases()

	/**
	 * Every case matches its recorded traffic, result and logs.
	 *
	 * @return void
	 */
	public function testCredentialRoutingMatchesGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => [$call, $script]) {
			$actual[$name] = self::compact(outcome: $this->record(script: $script, call: $call));
		}

		$actual['channel limit reached exactly'] = $this->truncation(listed: self::MAX_CHANNEL_FILES + 3);
		$actual['channel limit passed'] = $this->truncation(listed: self::MAX_CHANNEL_FILES + 4);

		foreach ($actual as $name => $outcome) {
			self::assertSame(0, preg_match(self::TOKEN_PATTERN, (string)json_encode($outcome['logs'], JSON_UNESCAPED_SLASHES)), $name);
		}

		$json = (string)json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
	 * Whatever the fixture holds: a call through the broker carries its case's own
	 * credential and acting user, and no anonymous request carries an authorisation.
	 *
	 * @return void
	 */
	public function testEveryCallCarriesItsOwnCredentialAndUser(): void {
		$owners = ['cred-A' => 'alice', 'cred-B' => 'bob', 'cred-C' => null];
		$seen = 0;
		foreach ($this->cases() as $name => [$call, $script]) {
			$outcome = $this->record(script: $script, call: $call);
			foreach ($outcome['brokered'] as $sent) {
				$seen++;
				self::assertSame('buildiq', $sent['appId'], $name);
				self::assertSame($owners[$sent['credentialId']], $sent['actingUserId'], $name);
			}

			foreach ($outcome['anonymous'] as $sent) {
				self::assertStringStartsWith('https://api.github.com/', $sent['url'], $name);
				self::assertSame(['Accept', 'User-Agent'], array_keys($sent['options']['headers']), $name);
			}
		}

		self::assertGreaterThan(100, $seen);
	}//end testEveryCallCarriesItsOwnCredentialAndUser()

	/**
	 * A cached descriptor answers only the credential that fetched it: one user's
	 * private descriptor is never served to a request under another credential.
	 *
	 * @return void
	 */
	public function testACachedDescriptorStaysWithItsCredential(): void {
		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(static function (string $key) use (&$store): mixed {
			return $store[$key] ?? null;
		});
		$cache->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$store): bool {
			$store[$key] = $value;
			return true;
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(true);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$requests = [];
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			static function (...$args) use (&$requests): array {
				$requests[] = $args[0];
				return self::github(path: $args[3]);
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($broker);
		$service = new GitHubCatalogService(
			clientService: $this->createMock(IClientService::class),
			cacheFactory: $cacheFactory,
			logger: $this->recordingLogger(),
			container: $container
		);

		foreach (['cred-A', 'cred-A', 'cred-B'] as $credential) {
			$service->fetchDescriptor(
				source: new RepoSource(owner: 'acme', repo: 'app', ref: 'main', credentialId: $credential),
				actingUserId: 'alice'
			);
		}

		self::assertSame(['cred-A', 'cred-B'], $requests);
	}//end testACachedDescriptorStaysWithItsCredential()
}//end class
