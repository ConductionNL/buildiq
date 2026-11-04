<?php

/**
 * Golden test for the credential routing of GitHubPushService::push().
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

use OCA\Buildiq\Service\GitHub\BrokerAccess;
use OCA\Buildiq\Service\GitHub\PushTarget;
use OCA\Buildiq\Service\GitHubPushService;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

/**
 * Pins, for every push, which broker credential and which acting user each
 * GitHub call goes out under, on which method and path, with which body, and
 * what the push returns, throws and logs. Credentials, users, targets and
 * broker answers are crossed so a mix-up between them shows. A response body
 * carrying a token-shaped string checks that no recorded log or error holds a
 * token. Set GOLDEN_RECORD=1 to write a missing fixture.
 *
 * @covers \OCA\Buildiq\Service\GitHubPushService
 * @uses   \OCA\Buildiq\Service\GitHub\BrokerAccess
 * @uses   \OCA\Buildiq\Service\GitHub\PushTarget
 */
class GitHubPushCredentialRoutingGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/github-push-credential-routing.golden.json';

	/**
	 * A token-shaped string built at run time, so no literal token is in the repository.
	 *
	 * @return string
	 */
	private static function tokenShaped(): string {
		return 'gh' . 'p_' . str_repeat('Z9', 18);
	}//end tokenShaped()

	/**
	 * Broker answer scripts by name: a list of [method, path suffix, status, body].
	 *
	 * @return array<string,array<int,array{0:string,1:string,2:int,3:string}>>
	 */
	private function scripts(): array {
		$leak = '{"message":"bad credentials ' . self::tokenShaped() . '"}';
		return [
			'all succeed' => [],
			'repo exists' => [['GET', '', 200, '{"id":1}']],
			'create denied' => [['POST', '/repos', 403, $leak]],
			'blob fails' => [['POST', '/git/blobs', 500, $leak]],
			'ref fails' => [['POST', '/git/refs', 422, '{"message":"Reference already exists"}']],
			'pull fails' => [['POST', '/pulls', 404, '']],
			'broker throws' => [['POST', '/repos', -1, 'transport ' . self::tokenShaped()]],
		];
	}//end scripts()

	/**
	 * Run one push and record everything the broker and the logger saw.
	 *
	 * @param array<string,mixed> $case The case.
	 *
	 * @return array<string,mixed>
	 */
	private function runCase(array $case): array {
		$calls = [];
		$script = $case['script'];
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			static function (...$args) use (&$calls, $script): array {
				[$credentialId, $appId, $method, $path, $headers, $body, $actingUserId] = $args + [6 => null];
				$decoded = null;
				if ($body !== null) {
					$decoded = json_decode((string)$body, true);
				}

				$calls[] = [
					'credentialId' => $credentialId,
					'appId' => $appId,
					'actingUserId' => $actingUserId,
					'method' => $method,
					'path' => $path,
					'headers' => $headers,
					'body' => $decoded,
				];
				foreach ($script as [$m, $suffix, $status, $responseBody]) {
					$matches = ($m === $method && ($suffix === '' ? substr_count($path, '/') === 3 : str_ends_with($path, $suffix)));
					if ($matches === true && $status === -1) {
						throw new \RuntimeException($responseBody);
					}

					if ($matches === true) {
						return ['status' => $status, 'body' => $responseBody];
					}
				}

				if ($method === 'GET') {
					return ['status' => 404, 'body' => ''];
				}

				return ['status' => 201, 'body' => (string)json_encode(['sha' => 'sha-' . count($calls), 'html_url' => 'https://github.example/' . $path, 'default_branch' => 'trunk'])];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($broker);
		$logger = new class extends AbstractLogger {
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

		$service = new GitHubPushService($logger, $container);
		$outcome = [];
		try {
			$outcome['result'] = $this->push(service: $service, case: $case);
		} catch (Throwable $e) {
			$outcome['error'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['calls'] = $calls;
		$outcome['logs'] = $logger->lines;
		return $outcome;
	}//end runCase()

	/**
	 * Call push() through its public signature.
	 *
	 * @param GitHubPushService   $service The service.
	 * @param array<string,mixed> $case    The case.
	 *
	 * @return array<string,mixed>
	 */
	private function push(GitHubPushService $service, array $case): array {
		return $service->push(
			jobUuid: 'job-1',
			treeDir: $case['treeDir'],
			access: new BrokerAccess(credentialId: $case['credentialId'], actingUserId: $case['actingUserId']),
			target: new PushTarget(org: $case['org'], repo: $case['repo'], visibility: $case['visibility'])
		);
	}//end push()

	/**
	 * Every case matches its recorded broker traffic, result and logs.
	 *
	 * @return void
	 */
	public function testCredentialRoutingMatchesGolden(): void {
		$tree = sys_get_temp_dir() . '/bq-golden-tree-' . getmypid();
		@mkdir($tree . '/lib', 0777, true);
		file_put_contents($tree . '/README.md', "hello\n");
		file_put_contents($tree . '/lib/App.php', "<?php\n");

		$credentials = [
			'alice owns A' => ['cred-A', 'alice'],
			'background B' => ['cred-B', 'bob'],
			'no user' => ['cred-C', null],
			'empty credential' => ['', 'alice'],
		];
		$targets = [
			'acme/app private' => ['acme', 'app', 'private'],
			'odd names public' => ['Org Name', 'repo/with%chars', 'public'],
			'no repo' => ['acme', '', 'private'],
		];

		$actual = [];
		try {
			foreach ($credentials as $cn => [$credentialId, $user]) {
				foreach ($targets as $tn => [$org, $repo, $visibility]) {
					foreach ($this->scripts() as $sn => $script) {
						$actual["$cn / $tn / $sn"] = $this->runCase(
							case: [
								'treeDir' => $tree,
								'credentialId' => $credentialId,
								'actingUserId' => $user,
								'org' => $org,
								'repo' => $repo,
								'visibility' => $visibility,
								'script' => $script,
							]
						);
					}
				}
			}

			$actual['missing tree'] = $this->runCase(
				case: [
					'treeDir' => $tree . '/absent',
					'credentialId' => 'cred-A',
					'actingUserId' => 'alice',
					'org' => 'acme',
					'repo' => 'app',
					'visibility' => 'private',
					'script' => [],
				]
			);
		} finally {
			@unlink($tree . '/README.md');
			@unlink($tree . '/lib/App.php');
			@rmdir($tree . '/lib');
			@rmdir($tree);
		}//end try

		$json = (string)json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$json = str_replace($tree, '<tree>', $json);
		self::assertSame(0, preg_match('/gh[pousr]_[A-Za-z0-9]{20,}/', $json), 'a recorded call, log or error carries a token');

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
}//end class
