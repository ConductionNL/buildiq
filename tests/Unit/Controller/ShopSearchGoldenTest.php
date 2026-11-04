<?php

/**
 * Golden test for ShopController::githubSearch().
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationsController;
use OCA\Buildiq\Controller\ShopController;
use OCA\Buildiq\Service\AppRepoParser;
use OCA\Buildiq\Service\Connection\ConnectionReporter;
use OCA\Buildiq\Service\GitHubCatalogService;
use OCA\Buildiq\Service\GitHubShopSearch;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the GitHub search response over signed-in and anonymous callers,
 * query and credential parameters, every catalogue outcome including a
 * throw, broker availability and a present or absent connection reporter:
 * the status, the body, the arguments the catalogue receives, what the
 * reporter is told and the log lines. Set GOLDEN_RECORD=1 to write a
 * missing fixture.
 *
 * @covers \OCA\Buildiq\Controller\ShopController
 * @covers \OCA\Buildiq\Service\GitHubShopSearch
 */
class ShopSearchGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/shop-search.golden.json';

	/**
	 * Calls recorded from the doubles, in order.
	 *
	 * @var array<int,mixed>
	 */
	private array $calls = [];

	/**
	 * Every case, by name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function cases(): array {
		$cases = [];
		$outcomes = [
			'ok' => ['outcome' => 'ok', 'cards' => [['slug' => 'a']], 'brokerUsed' => true, 'rateLimited' => false],
			'rate limited' => ['outcome' => 'rate_limited', 'cards' => [], 'brokerUsed' => false, 'rateLimited' => true],
			'throws' => 'throws',
		];
		foreach ($outcomes as $on => $outcome) {
			foreach ([true, false] as $broker) {
				foreach ([true, false] as $reporter) {
					foreach (['q and credential' => ['topic', 'cred-1'], 'no q, empty credential' => [null, ''], 'array q, int credential' => [['x'], 5]] as $pn => [$q, $credential]) {
						$cases[sprintf('%s / broker %d / reporter %d / %s', $on, (int)$broker, (int)$reporter, $pn)] = [
							'user' => 'alice', 'outcome' => $outcome, 'broker' => $broker, 'reporter' => $reporter, 'q' => $q, 'credential' => $credential,
						];
					}
				}
			}
		}

		$cases['anonymous'] = ['user' => null, 'outcome' => $outcomes['ok'], 'broker' => true, 'reporter' => true, 'q' => 'x', 'credential' => 'c'];
		return $cases;
	}//end cases()

	/**
	 * Every case matches its recorded output.
	 *
	 * @return void
	 */
	public function testGithubSearchMatchesGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->calls = [];
			$response = $this->controller(case: $case)->githubSearch();
			$actual[$name] = ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $this->calls];
		}

		if (file_exists(self::FIXTURE) === false && getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($actual as $name => $result) {
			self::assertSame($expected[$name] ?? null, json_decode((string)json_encode($result), true), $name);
		}

		self::assertSame(array_keys($expected), array_keys($actual));
	}//end testGithubSearchMatchesGolden()

	/**
	 * The controller for one case, with recording doubles.
	 *
	 * @param array<string,mixed> $case The case.
	 *
	 * @return ShopController The controller.
	 */
	private function controller(array $case): ShopController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($case['user'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($case['user']);
		}

		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key): mixed => ['q' => $case['q'], 'credentialId' => $case['credential']][$key] ?? null
		);

		$catalog = $this->createMock(GitHubCatalogService::class);
		$catalog->method('isBrokerAvailable')->willReturnCallback(function () use ($case): bool {
			$this->calls[] = 'isBrokerAvailable';
			return $case['broker'];
		});
		$catalog->method('search')->willReturnCallback(function (?string $query, ?string $actingUserId, ?string $credentialId) use ($case): array {
			$this->calls[] = ['search' => compact('query', 'actingUserId', 'credentialId')];
			if ($case['outcome'] === 'throws') {
				throw new RuntimeException('github down');
			}

			return $case['outcome'];
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message): void {
			$this->calls[] = ['error' => $message];
		});

		$reporter = null;
		if ($case['reporter'] === true) {
			$reporter = $this->createMock(ConnectionReporter::class);
			$reporter->method('reportGitHubSearch')->willReturnCallback(function (string $outcome, bool $brokerAvailable): bool {
				$this->calls[] = ['report' => compact('outcome', 'brokerAvailable')];
				return true;
			});
		}

		return $this->build(request: $request, logger: $logger, session: $session, catalog: $catalog, reporter: $reporter);
	}//end controller()

	/**
	 * Construct the controller from its doubles.
	 *
	 * @param IRequest                $request  Request.
	 * @param LoggerInterface         $logger   Logger.
	 * @param IUserSession            $session  Session.
	 * @param GitHubCatalogService    $catalog  Catalogue.
	 * @param ConnectionReporter|null $reporter Reporter.
	 *
	 * @return ShopController The controller.
	 */
	private function build(IRequest $request, LoggerInterface $logger, IUserSession $session, GitHubCatalogService $catalog, ?ConnectionReporter $reporter): ShopController {
		return new ShopController(
			request: $request,
			userSession: $session,
			catalogService: $catalog,
			repoParser: $this->createMock(AppRepoParser::class),
			appsController: $this->createMock(ApplicationsController::class),
			shopSearch: new GitHubShopSearch(catalogService: $catalog, logger: $logger, connectionReporter: $reporter)
		);
	}//end build()
}//end class
