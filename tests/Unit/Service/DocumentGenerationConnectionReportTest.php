<?php

/**
 * DocumentGenerationService connection-report tests.
 *
 * `callGenerate()` is the one place a document generation call leaves the app,
 * so it is the one place that knows whether Filinq answered. These tests guard
 * the wiring: a route that resolves to nothing is reported and never called, an
 * answer and a failure reach the reporter with their status, and the result is
 * the same without a reporter.
 *
 * @category Tests
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\Connection\ConnectionObservations;
use OCA\Buildiq\Service\Connection\ConnectionReporter;
use OCA\Buildiq\Service\DocumentGenerationService;
use OCA\Buildiq\Service\JobOwnerImpersonator;
use OCA\Buildiq\Service\RuleActionDispatcher;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Unit tests for the report DocumentGenerationService sends after the Filinq call.
 *
 * @covers \OCA\Buildiq\Service\DocumentGenerationService
 * @uses \OCA\Buildiq\Service\Connection\ConnectionObservations
 */
class DocumentGenerationConnectionReportTest extends TestCase {

	/**
	 * The bare instance URL the URL generator answers for an empty path.
	 *
	 * @var string
	 */
	private const ROOT_URL = 'https://cloud.test/';

	/**
	 * Every report handed over, as [method, argument].
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $reports = [];

	/**
	 * How many times the HTTP client was asked to post.
	 *
	 * @var int
	 */
	private int $posts = 0;

	/**
	 * How many tokens were invalidated.
	 *
	 * @var int
	 */
	private int $invalidated = 0;

	/**
	 * A reporter that records instead of dispatching.
	 *
	 * @return ConnectionReporter&MockObject
	 */
	private function reporter(): ConnectionReporter&MockObject {
		$reporter = $this->getMockBuilder(className: ConnectionReporter::class)
			->disableOriginalConstructor()
			->onlyMethods(['reportDocumentCall', 'reportDocumentRouteMissing', 'httpStatusOf'])
			->getMock();
		$reporter->method('reportDocumentCall')->willReturnCallback(
			function (?int $httpStatus): bool {
				$this->reports[] = ['reportDocumentCall', $httpStatus];
				return true;
			}
		);
		$reporter->method('reportDocumentRouteMissing')->willReturnCallback(
			function (string $route): bool {
				$this->reports[] = ['reportDocumentRouteMissing', $route];
				return true;
			}
		);
		$reporter->method('httpStatusOf')->willReturnCallback(
			static fn (Throwable $exception): ?int => (new ConnectionObservations())->httpStatusOf(exception: $exception)
		);

		return $reporter;
	}//end reporter()

	/**
	 * The service around a route URL and an HTTP answer.
	 *
	 * @param string                  $routeUrl What linkToRouteAbsolute() answers.
	 * @param int|Throwable           $answer   The status the post answers, or what it throws.
	 * @param ConnectionReporter|null $reporter The reporter, or none.
	 *
	 * @return DocumentGenerationService
	 */
	private function service(string $routeUrl, int|Throwable $answer, ?ConnectionReporter $reporter): DocumentGenerationService {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('owner');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$test     = $this;
		$provider = new class($test) {

			/**
			 * Constructor.
			 *
			 * @param DocumentGenerationConnectionReportTest $test Counts the invalidations.
			 */
			public function __construct(private readonly DocumentGenerationConnectionReportTest $test) {
			}//end __construct()

			/**
			 * Accept a token.
			 *
			 * @param mixed ...$args The token arguments.
			 *
			 * @return null
			 */
			public function generateToken(mixed ...$args): null {
				return null;
			}//end generateToken()

			/**
			 * Count an invalidation.
			 *
			 * @param mixed ...$args The token.
			 *
			 * @return void
			 */
			public function invalidateToken(mixed ...$args): void {
				$this->test->countInvalidation();
			}//end invalidateToken()
		};

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($provider);

		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn($routeUrl);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => self::ROOT_URL . ltrim($path, '/'));

		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('post')->willReturnCallback(
			function () use ($answer): IResponse {
				$this->posts++;
				if ($answer instanceof Throwable) {
					throw $answer;
				}

				$response = $this->createMock(originalClassName: IResponse::class);
				$response->method('getStatusCode')->willReturn($answer);
				$response->method('getBody')->willReturn('%PDF%');
				return $response;
			}
		);
		$clients = $this->createMock(originalClassName: IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new DocumentGenerationService(
			objectService: $this->createMock(originalClassName: ObjectServiceInterface::class),
			registerMapper: $this->createMock(originalClassName: RegisterMapper::class),
			schemaMapper: $this->createMock(originalClassName: SchemaMapper::class),
			ownerImpersonator: $this->createMock(originalClassName: JobOwnerImpersonator::class),
			ruleActionDispatcher: $this->createMock(originalClassName: RuleActionDispatcher::class),
			userSession: $session,
			urlGenerator: $urls,
			httpClientService: $clients,
			rootFolder: $this->createMock(originalClassName: IRootFolder::class),
			appDataFactory: $this->createMock(originalClassName: IAppDataFactory::class),
			container: $container,
			logger: new NullLogger(),
			connectionReporter: $reporter
		);
	}//end service()

	/**
	 * Count one token invalidation. Called by the provider double.
	 *
	 * @return void
	 */
	public function countInvalidation(): void {
		$this->invalidated++;
	}//end countInvalidation()

	/**
	 * Run the private Filinq call.
	 *
	 * @param DocumentGenerationService $service The service.
	 *
	 * @return array<string, mixed>|null What callGenerate() answered.
	 */
	private function call(DocumentGenerationService $service): ?array {
		$method = new ReflectionMethod(DocumentGenerationService::class, 'callGenerate');

		return $method->invoke($service, 'tpl-1', [['register' => 'buildiq', 'schema' => 'permit', 'id' => 'obj-1']], 'doc.pdf');
	}//end call()

	/**
	 * A route that resolves to the bare instance URL is reported, not called, and its token is invalidated.
	 *
	 * @return void
	 */
	public function testAMissingRouteIsReportedAndNotCalled(): void {
		$result = $this->call(service: $this->service(routeUrl: self::ROOT_URL, answer: 200, reporter: $this->reporter()));

		$this->assertNull(actual: $result);
		$this->assertSame(expected: 0, actual: $this->posts);
		$this->assertSame(expected: 1, actual: $this->invalidated);
		$this->assertSame(expected: [['reportDocumentRouteMissing', 'docudesk.correspondence.generate']], actual: $this->reports);
	}//end testAMissingRouteIsReportedAndNotCalled()

	/**
	 * An answer is reported with its status, and the result is unchanged.
	 *
	 * @return void
	 */
	public function testAnAnswerIsReportedWithItsStatus(): void {
		$url    = 'https://cloud.test/index.php/apps/filinq/api/correspondence/generate';
		$with   = $this->call(service: $this->service(routeUrl: $url, answer: 200, reporter: $this->reporter()));
		$without = $this->call(service: $this->service(routeUrl: $url, answer: 200, reporter: null));

		$this->assertSame(expected: $without, actual: $with);
		$this->assertSame(expected: [['reportDocumentCall', 200]], actual: $this->reports);
	}//end testAnAnswerIsReportedWithItsStatus()

	/**
	 * A post that got no answer is reported without a status, and still answers null.
	 *
	 * @return void
	 */
	public function testAPostWithoutAnAnswerIsReportedWithoutAStatus(): void {
		$url    = 'https://cloud.test/index.php/apps/filinq/api/correspondence/generate';
		$result = $this->call(service: $this->service(routeUrl: $url, answer: new RuntimeException('Connection refused'), reporter: $this->reporter()));

		$this->assertNull(actual: $result);
		$this->assertSame(expected: [['reportDocumentCall', null]], actual: $this->reports);
	}//end testAPostWithoutAnAnswerIsReportedWithoutAStatus()
}//end class
