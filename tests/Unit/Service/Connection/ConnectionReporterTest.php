<?php

/**
 * ConnectionReporter unit tests.
 *
 * The reporter tells integriq's connection registry what Buildiq's calls met,
 * and asks integriq to resolve a connection again after a save. Every test
 * guards one way it could quietly stop telling the truth: reporting on every
 * request, never reporting a change, reporting a key integriq refuses, turning
 * a listener's failure into a failed call, or touching app config when
 * integriq is not installed.
 *
 * @category Tests
 * @package  OCA\Buildiq\Tests\Unit\Service\Connection
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

namespace OCA\Buildiq\Tests\Unit\Service\Connection;

use OCA\Buildiq\Service\Connection\ConnectionReporter;
use OCA\Integriq\Event\ConnectionRefreshRequestedEvent;
use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for ConnectionReporter.
 *
 * @covers \OCA\Buildiq\Service\Connection\ConnectionReporter
 */
class ConnectionReporterTest extends TestCase {

	/**
	 * Mocked event dispatcher.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * Mocked app config, backed by $this->store.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The app-config values, keyed by config key.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * The Unix time the clock answers.
	 *
	 * @var int
	 */
	private int $now = 1_760_000_000;

	/**
	 * Every event handed to the dispatcher.
	 *
	 * @var array<int, Event>
	 */
	private array $sent = [];

	/**
	 * Set up the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sent  = [];
		$this->store = ['registry_url' => 'https://store.gemeente.example/index.php'];

		$this->dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->sent[] = $event;
			}
		);

		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->store[$key] ?? $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);
		$this->appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->store[$key]);
			}
		);

		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The reporter as production builds it.
	 *
	 * @param IEventDispatcher|null $dispatcher Another dispatcher, or null for the recording one.
	 * @param IAppConfig|null       $appConfig  Another app config, or null for the backed one.
	 *
	 * @return ConnectionReporter
	 */
	private function reporter(?IEventDispatcher $dispatcher = null, ?IAppConfig $appConfig = null): ConnectionReporter {
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new ConnectionReporter(
			eventDispatcher: ($dispatcher ?? $this->dispatcher),
			appConfig: ($appConfig ?? $this->appConfig),
			timeFactory: $time,
			logger: $this->logger,
		);
	}//end reporter()

	/**
	 * The reporter as it behaves on an instance without integriq.
	 *
	 * Only the class lookup is replaced. The stubs make both event classes
	 * resolvable in this process, so absence is simulated at the one seam
	 * that asks.
	 *
	 * @return ConnectionReporter
	 */
	private function reporterWithoutIntegriq(): ConnectionReporter {
		$time = $this->createMock(originalClassName: ITimeFactory::class);

		return new class($this->dispatcher, $this->appConfig, $time, $this->logger) extends ConnectionReporter {

			/**
			 * Integriq is not installed, so no class resolves.
			 *
			 * @param string $eventClass The class name asked for.
			 *
			 * @return string|null Always null.
			 */
			protected function resolveEventClass(string $eventClass): ?string {
				return null;
			}//end resolveEventClass()
		};
	}//end reporterWithoutIntegriq()

	/**
	 * A store search reports with this app's id, the key, the status and the host.
	 *
	 * @return void
	 */
	public function testAStoreSearchReportsWithTheHost(): void {
		$this->assertTrue(condition: $this->reporter()->reportStoreSearch(outcome: 'store_unreachable'));

		$this->assertCount(expectedCount: 1, haystack: $this->sent);
		$event = $this->sent[0];
		$this->assertInstanceOf(expected: ConnectionStatusReportedEvent::class, actual: $event);
		$this->assertSame(expected: 'buildiq', actual: $event->app);
		$this->assertSame(expected: 'store', actual: $event->key);
		$this->assertSame(expected: 'error', actual: $event->status);
		$this->assertSame(expected: 'The last search could not reach the template store at store.gemeente.example.', actual: $event->message);
		$this->assertSame(expected: 'error|' . $this->now, actual: $this->store['connection_report_store']);
	}//end testAStoreSearchReportsWithTheHost()

	/**
	 * Each call site reports under its own declared key.
	 *
	 * @return void
	 */
	public function testEachCallSiteReportsUnderItsDeclaredKey(): void {
		$reporter = $this->reporter();

		$this->assertTrue(condition: $reporter->reportGitHubSearch(outcome: 'ok', brokerAvailable: false));
		$this->assertTrue(condition: $reporter->reportDocumentCall(httpStatus: 200));
		$this->assertTrue(condition: $reporter->reportWebhookCall(url: 'https://hooks.example.nl/x', httpStatus: 204));

		$this->assertSame(
			expected: [['github', 'limited'], ['documents', 'configured'], ['rule-webhooks', 'configured']],
			actual: array_map(static fn (Event $event): array => [$event->key, $event->status], $this->sent)
		);
	}//end testEachCallSiteReportsUnderItsDeclaredKey()

	/**
	 * A missing document route reports an error that names the route.
	 *
	 * @return void
	 */
	public function testAMissingDocumentRouteNamesTheRoute(): void {
		$this->assertTrue(condition: $this->reporter()->reportDocumentRouteMissing(route: 'docudesk.correspondence.generate'));

		$this->assertSame(expected: 'error', actual: $this->sent[0]->status);
		$this->assertStringContainsString(needle: 'docudesk.correspondence.generate', haystack: $this->sent[0]->message);
	}//end testAMissingDocumentRouteNamesTheRoute()

	/**
	 * An outcome about one request or one credential sends nothing and writes no memory.
	 *
	 * @return void
	 */
	public function testAnOutcomeAboutOneRequestSendsNothing(): void {
		$reporter = $this->reporter();

		$this->assertFalse(condition: $reporter->reportWebhookCall(url: 'https://hooks.example.nl/x', httpStatus: 404));
		$this->assertFalse(condition: $reporter->reportGitHubSync(outcome: 'broker_denied'));

		$this->assertSame(expected: [], actual: $this->sent);
		$this->assertArrayNotHasKey(key: 'connection_report_rule-webhooks', array: $this->store);
		$this->assertArrayNotHasKey(key: 'connection_report_github', array: $this->store);
	}//end testAnOutcomeAboutOneRequestSendsNothing()

	/**
	 * The same outcome reports once an hour, not on every call.
	 *
	 * @return void
	 */
	public function testTheSameStatusReportsOnceAnHour(): void {
		$reporter = $this->reporter();

		$this->assertTrue(condition: $reporter->reportStoreSearch(outcome: 'ok'));
		$this->now += 600;
		$this->assertFalse(condition: $reporter->reportStoreSearch(outcome: 'ok'));
		$this->now += (ConnectionReporter::REPEAT_SECONDS - 601);
		$this->assertFalse(condition: $reporter->reportStoreSearch(outcome: 'ok'));
		$this->now += 1;
		$this->assertTrue(condition: $reporter->reportStoreSearch(outcome: 'ok'));

		$this->assertCount(expectedCount: 2, haystack: $this->sent);
	}//end testTheSameStatusReportsOnceAnHour()

	/**
	 * A different outcome reports after five minutes, and not before.
	 *
	 * @return void
	 */
	public function testAChangedStatusWaitsFiveMinutes(): void {
		$reporter = $this->reporter();

		$this->assertTrue(condition: $reporter->reportWebhookCall(url: 'https://a.example.nl', httpStatus: 200));
		$this->now += (ConnectionReporter::CHANGE_SECONDS - 1);
		$this->assertFalse(condition: $reporter->reportWebhookCall(url: 'https://b.example.nl', httpStatus: null));
		$this->now += 1;
		$this->assertTrue(condition: $reporter->reportWebhookCall(url: 'https://b.example.nl', httpStatus: null));

		$this->assertSame(expected: ['configured', 'error'], actual: array_map(static fn (Event $event): string => $event->status, $this->sent));
	}//end testAChangedStatusWaitsFiveMinutes()

	/**
	 * Each connection keeps its own memory.
	 *
	 * @return void
	 */
	public function testEachConnectionKeepsItsOwnMemory(): void {
		$reporter = $this->reporter();

		$this->assertTrue(condition: $reporter->reportStoreSearch(outcome: 'ok'));
		$this->assertTrue(condition: $reporter->reportDocumentCall(httpStatus: 200));

		$this->assertCount(expectedCount: 2, haystack: $this->sent);
	}//end testEachConnectionKeepsItsOwnMemory()

	/**
	 * A save of a store key refreshes the store and clears its memory.
	 *
	 * @return void
	 */
	public function testAStoreSaveRefreshesTheStoreAndClearsItsMemory(): void {
		$reporter = $this->reporter();
		$reporter->reportStoreSearch(outcome: 'ok');
		$reporter->reportDocumentCall(httpStatus: 200);
		$this->sent = [];

		$refreshed = $reporter->refreshFromSave(savedKeys: ['register', 'registry_token']);

		$this->assertSame(expected: ['store'], actual: $refreshed);
		$this->assertCount(expectedCount: 1, haystack: $this->sent);
		$this->assertInstanceOf(expected: ConnectionRefreshRequestedEvent::class, actual: $this->sent[0]);
		$this->assertSame(expected: 'buildiq', actual: $this->sent[0]->app);
		$this->assertSame(expected: 'store', actual: $this->sent[0]->key);
		$this->assertArrayNotHasKey(key: 'connection_report_store', array: $this->store);
		$this->assertArrayHasKey(key: 'connection_report_documents', array: $this->store);

		// With the memory cleared the next search reports at once.
		$this->assertTrue(condition: $reporter->reportStoreSearch(outcome: 'ok'));
	}//end testAStoreSaveRefreshesTheStoreAndClearsItsMemory()

	/**
	 * A save that writes no store key sends nothing.
	 *
	 * @return void
	 */
	public function testAnUnrelatedSaveSendsNoRefresh(): void {
		$this->assertSame(expected: [], actual: $this->reporter()->refreshFromSave(savedKeys: ['register']));
		$this->assertSame(expected: [], actual: $this->sent);
	}//end testAnUnrelatedSaveSendsNoRefresh()

	/**
	 * Without integriq nothing is read, sent, stored, cleared or logged.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingIsReadSentStoredOrLogged(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->appConfig->expects($this->never())->method('getValueString');
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->appConfig->expects($this->never())->method('deleteKey');
		$this->logger->expects($this->never())->method('warning');

		$reporter = $this->reporterWithoutIntegriq();

		$this->assertFalse(condition: $reporter->reportStoreSearch(outcome: 'store_unreachable'));
		$this->assertFalse(condition: $reporter->reportWebhookCall(url: 'https://hooks.example.nl', httpStatus: null));
		$this->assertSame(expected: [], actual: $reporter->refreshFromSave(savedKeys: ['registry_url']));
	}//end testWithoutIntegriqNothingIsReadSentStoredOrLogged()

	/**
	 * The class lookup answers null for a class nobody ships, and the class for a stub.
	 *
	 * This is the real guard, not the test double above.
	 *
	 * @return void
	 */
	public function testTheLookupAnswersNullForAnAbsentClass(): void {
		$method   = new ReflectionMethod(ConnectionReporter::class, 'resolveEventClass');
		$reporter = $this->reporter();

		$this->assertNull(actual: $method->invoke($reporter, 'OCA\\Nobody\\Event\\ShipsThisEvent'));
		$this->assertSame(
			expected: '\\' . ConnectionReporter::STATUS_EVENT,
			actual: $method->invoke($reporter, ConnectionReporter::STATUS_EVENT)
		);
	}//end testTheLookupAnswersNullForAnAbsentClass()

	/**
	 * The event names are the ones the contract fixes.
	 *
	 * A string class name is exactly the reference that rots into a silent
	 * no-op after a rename, so it is compared to the stubs' real names.
	 *
	 * @return void
	 */
	public function testTheEventNamesAreTheContractNames(): void {
		$this->assertSame(expected: ConnectionStatusReportedEvent::class, actual: ConnectionReporter::STATUS_EVENT);
		$this->assertSame(expected: ConnectionRefreshRequestedEvent::class, actual: ConnectionReporter::REFRESH_EVENT);
	}//end testTheEventNamesAreTheContractNames()

	/**
	 * A listener that throws never escapes, and leaves the memory unwritten.
	 *
	 * An unwritten memory means the next call tries again instead of keeping
	 * quiet for an hour about a report that never landed.
	 *
	 * @return void
	 */
	public function testAThrowingListenerNeverEscapes(): void {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('registry down'));
		$this->logger->expects($this->exactly(count: 2))->method('warning')
			->with($this->stringContains(string: 'could not send'), $this->arrayHasKey(key: 'key'));

		$reporter = $this->reporter(dispatcher: $dispatcher);

		$this->assertFalse(condition: $reporter->reportStoreSearch(outcome: 'ok'));
		$this->assertSame(expected: [], actual: $reporter->refreshFromSave(savedKeys: ['registry_url']));
		$this->assertArrayNotHasKey(key: 'connection_report_store', array: $this->store);
	}//end testAThrowingListenerNeverEscapes()

	/**
	 * A failing app config never escapes into the call.
	 *
	 * @return void
	 */
	public function testAFailingAppConfigNeverEscapes(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willThrowException(new RuntimeException('type conflict'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains(string: 'could not report'), $this->arrayHasKey(key: 'exception'));

		$this->assertFalse(condition: $this->reporter(appConfig: $appConfig)->reportStoreSearch(outcome: 'ok'));
		$this->assertSame(expected: [], actual: $this->sent);
	}//end testAFailingAppConfigNeverEscapes()
}//end class
