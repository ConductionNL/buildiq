<?php

/**
 * RuleActionDispatcher connection-report tests.
 *
 * `dispatchWebhook()` is the one place a rule's webhook leaves the app, so it is
 * the one place that knows whether the receiver answered. These tests guard the
 * wiring: an answer and a failure both reach the reporter with the URL and the
 * status, a failure still fails exactly as before, and a dispatcher built
 * without a reporter behaves as it always did.
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
use OCA\Buildiq\Service\JobOwnerImpersonator;
use OCA\Buildiq\Service\RuleActionDispatcher;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IUserSession;
use OCP\Notification\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Unit tests for the report the dispatcher sends after each webhook.
 *
 * @covers \OCA\Buildiq\Service\RuleActionDispatcher
 */
class RuleActionDispatcherConnectionReportTest extends TestCase {

	/**
	 * The webhook URL the rule names.
	 *
	 * @var string
	 */
	private const URL = 'https://user:secret@hooks.example.nl/path?token=abc';

	/**
	 * Every webhook report handed over, as [url, httpStatus].
	 *
	 * @var array<int, array{0: string, 1: int|null}>
	 */
	private array $reports = [];

	/**
	 * A reporter that records webhook reports instead of dispatching.
	 *
	 * @return ConnectionReporter&MockObject
	 */
	private function reporter(): ConnectionReporter&MockObject {
		$reporter = $this->getMockBuilder(className: ConnectionReporter::class)
			->disableOriginalConstructor()
			->onlyMethods(['reportWebhookCall', 'httpStatusOf'])
			->getMock();
		$reporter->method('reportWebhookCall')->willReturnCallback(
			function (string $url, ?int $httpStatus): bool {
				$this->reports[] = [$url, $httpStatus];
				return true;
			}
		);
		$reporter->method('httpStatusOf')->willReturnCallback(
			static fn (Throwable $exception): ?int => (new ConnectionObservations())->httpStatusOf(exception: $exception)
		);

		return $reporter;
	}//end reporter()

	/**
	 * A dispatcher whose HTTP client answers the given status or throws.
	 *
	 * @param int|Throwable           $answer   The status to answer, or what to throw.
	 * @param ConnectionReporter|null $reporter The reporter, or none.
	 *
	 * @return RuleActionDispatcher
	 */
	private function dispatcher(int|Throwable $answer, ?ConnectionReporter $reporter): RuleActionDispatcher {
		$client = $this->createMock(originalClassName: IClient::class);
		if ($answer instanceof Throwable) {
			$client->method('post')->willThrowException($answer);
		} else {
			$response = $this->createMock(originalClassName: IResponse::class);
			$response->method('getStatusCode')->willReturn($answer);
			$client->method('post')->willReturn($response);
		}

		$clients = $this->createMock(originalClassName: IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new RuleActionDispatcher(
			objectService: $this->createMock(originalClassName: ObjectServiceInterface::class),
			notificationManager: $this->createMock(originalClassName: IManager::class),
			httpClientService: $clients,
			userSession: $this->createMock(originalClassName: IUserSession::class),
			ownerImpersonator: $this->createMock(originalClassName: JobOwnerImpersonator::class),
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			connectionReporter: $reporter
		);
	}//end dispatcher()

	/**
	 * An exception that carries an answer, the way Guzzle's request exceptions do.
	 *
	 * @param int $status The answer's HTTP status.
	 *
	 * @return RuntimeException
	 */
	private function answeredException(int $status): RuntimeException {
		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getStatusCode')->willReturn($status);

		return new class('Server error: 503', $response) extends RuntimeException {

			/**
			 * Constructor.
			 *
			 * @param string $message  The message.
			 * @param object $response The answer.
			 */
			public function __construct(string $message, private readonly object $response) {
				parent::__construct($message);
			}//end __construct()

			/**
			 * The answer the call got.
			 *
			 * @return object
			 */
			public function getResponse(): object {
				return $this->response;
			}//end getResponse()
		};
	}//end answeredException()

	/**
	 * An answer hands the URL and the status to the reporter, and the status is still returned.
	 *
	 * @return void
	 */
	public function testAnAnswerHandsTheUrlAndStatusOver(): void {
		$status = ($this->dispatcher(answer: 204, reporter: $this->reporter()))('webhook', ['url' => self::URL], []);

		$this->assertSame(expected: 204, actual: $status);
		$this->assertSame(expected: [[self::URL, 204]], actual: $this->reports);
	}//end testAnAnswerHandsTheUrlAndStatusOver()

	/**
	 * A refused post hands its answer's status over, and the action still fails as before.
	 *
	 * @return void
	 */
	public function testARefusedPostHandsItsStatusOverAndStillFails(): void {
		$result = ($this->dispatcher(answer: $this->answeredException(status: 503), reporter: $this->reporter()))('webhook', ['url' => self::URL], []);

		$this->assertNull(actual: $result);
		$this->assertSame(expected: [[self::URL, 503]], actual: $this->reports);
	}//end testARefusedPostHandsItsStatusOverAndStillFails()

	/**
	 * A post that got no answer reports no status.
	 *
	 * @return void
	 */
	public function testAPostWithoutAnAnswerReportsNoStatus(): void {
		($this->dispatcher(answer: new RuntimeException('Could not resolve host'), reporter: $this->reporter()))('webhook', ['url' => self::URL], []);

		$this->assertSame(expected: [[self::URL, null]], actual: $this->reports);
	}//end testAPostWithoutAnAnswerReportsNoStatus()

	/**
	 * A webhook action without a URL posts nothing and reports nothing.
	 *
	 * @return void
	 */
	public function testAWebhookWithoutAUrlReportsNothing(): void {
		($this->dispatcher(answer: 200, reporter: $this->reporter()))('webhook', [], []);

		$this->assertSame(expected: [], actual: $this->reports);
	}//end testAWebhookWithoutAUrlReportsNothing()

	/**
	 * Without a reporter the dispatcher answers exactly as before.
	 *
	 * @return void
	 */
	public function testWithoutAReporterTheResultIsUnchanged(): void {
		$this->assertSame(expected: 200, actual: ($this->dispatcher(answer: 200, reporter: null))('webhook', ['url' => self::URL], []));
		$this->assertNull(actual: ($this->dispatcher(answer: new RuntimeException('down'), reporter: null))('webhook', ['url' => self::URL], []));
	}//end testWithoutAReporterTheResultIsUnchanged()
}//end class
