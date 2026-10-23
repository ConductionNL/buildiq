<?php

/**
 * Buildiq connection reporter.
 *
 * Tells integriq's connection registry what only Buildiq can see about its
 * outside connections: what the last store search, GitHub call, document
 * generation call or rule webhook met, and which connection a settings save
 * touched. Integriq owns the rows the Integrations page lists and works out
 * each status itself (hydra change connection-registry, design D4). Buildiq
 * reports, and asks for a fresh resolve after a save.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Connection
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

namespace OCA\Buildiq\Service\Connection;

use OCA\Buildiq\AppInfo\Application;
use OCA\Buildiq\Support\FleetAppId;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends connection reports and refresh requests to integriq.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
 */
class ConnectionReporter {

	/**
	 * The app id integriq keys the rows by.
	 *
	 * @var string
	 */
	public const APP_ID = Application::APP_ID;

	/**
	 * Integriq's report event (ADR-041). Named by string so Buildiq stays
	 * installable without integriq: the class only exists when integriq does.
	 *
	 * @var string
	 */
	public const STATUS_EVENT = 'OCA\Integriq\Event\ConnectionStatusReportedEvent';

	/**
	 * Integriq's refresh event. Named by string for the same reason.
	 *
	 * @var string
	 */
	public const REFRESH_EVENT = 'OCA\Integriq\Event\ConnectionRefreshRequestedEvent';

	/**
	 * The keys `lib/Settings/connections.json` declares, in declared order.
	 *
	 * A key outside this set is a caller's typo, not a new connection. A unit
	 * test keeps the two equal.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = ['store', 'github', 'documents', 'rule-webhooks'];

	/**
	 * The statuses integriq accepts in a report (design D6), `limited` included.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = ['configured', 'limited', 'unconfigured', 'simulated', 'unavailable', 'error'];

	/**
	 * App-config keys per connection whose save asks integriq to resolve again.
	 *
	 * The declared `requiredConfig`, plus the two keys that change what a
	 * store search meets. A unit test keeps the declared keys inside this map.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const REFRESH_KEYS = [
		'store' => ['registry_url', 'registry_register', 'registry_token'],
	];

	/**
	 * Prefix of the app-config key that remembers the last report per connection.
	 *
	 * @var string
	 */
	public const MEMORY_KEY_PREFIX = 'connection_report_';

	/**
	 * Seconds after which the same status is reported again.
	 *
	 * @var int
	 */
	public const REPEAT_SECONDS = 3600;

	/**
	 * Seconds that must pass before a different status is reported.
	 *
	 * @var int
	 */
	public const CHANGE_SECONDS = 300;

	/**
	 * The pure outcome mapper.
	 *
	 * @var ConnectionObservations
	 */
	private readonly ConnectionObservations $observations;

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Sends the integriq events (ADR-041).
	 * @param IAppConfig       $appConfig       Reads the store address and keeps the report memory.
	 * @param ITimeFactory     $timeFactory     Tells the time for the report memory.
	 * @param LoggerInterface  $logger          Records what could not be sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->observations = new ConnectionObservations();
	}//end __construct()

	/**
	 * Report what a template store search met.
	 *
	 * @param string $outcome The `GenericStoreService` search outcome.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
	 */
	public function reportStoreSearch(string $outcome): bool {
		return $this->reportObserved(
			key: 'store',
			observe: fn (): ?array => $this->observations->storeSearch(
				outcome: $outcome,
				registryUrl: $this->appConfig->getValueString(self::APP_ID, 'registry_url', '')
			)
		);
	}//end reportStoreSearch()

	/**
	 * Report what a GitHub catalogue search met.
	 *
	 * @param string $outcome         The `GitHubCatalogService` search outcome.
	 * @param bool   $brokerAvailable Whether OpenRegister's credential broker is installed.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
	 */
	public function reportGitHubSearch(string $outcome, bool $brokerAvailable): bool {
		return $this->reportObserved(
			key: 'github',
			observe: fn (): ?array => $this->observations->gitHubSearch(outcome: $outcome, brokerAvailable: $brokerAvailable)
		);
	}//end reportGitHubSearch()

	/**
	 * Report what a GitHub push or pull met.
	 *
	 * @param string $outcome The `GitHubAppSyncService` outcome.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
	 */
	public function reportGitHubSync(string $outcome): bool {
		return $this->reportObserved(
			key: 'github',
			observe: fn (): ?array => $this->observations->gitHubSync(outcome: $outcome)
		);
	}//end reportGitHubSync()

	/**
	 * Report what one document generation call to Filinq met.
	 *
	 * @param int|null $httpStatus The answer's HTTP status, or null when nothing answered.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
	 */
	public function reportDocumentCall(?int $httpStatus): bool {
		return $this->reportObserved(
			key: 'documents',
			observe: fn (): ?array => $this->observations->httpCall(name: 'Filinq', httpStatus: $httpStatus)
		);
	}//end reportDocumentCall()

	/**
	 * Report that the document generation route resolves to no URL, so no call was made.
	 *
	 * @param string $route The route name that did not resolve.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
	 */
	public function reportDocumentRouteMissing(string $route): bool {
		return $this->reportObserved(
			key: 'documents',
			observe: static fn (): array => [
				'error',
				'No route answers to ' . $route . ', so no document was generated. '
				. 'Install and enable ' . self::documentAppNames() . '.',
			]
		);
	}//end reportDocumentRouteMissing()

	/**
	 * Name every app id that can answer the document route, newest first.
	 *
	 * The document app was renamed from docudesk to filinq. An instance runs
	 * one or the other, so a message that names only the new app sends an
	 * admin on the old one looking for the wrong thing.
	 *
	 * @return string The app names, for example "Filinq, or Docudesk".
	 *
	 * @spec exclude Message-building detail of the report above; it carries no
	 *  requirement of its own and is asserted through that report's tests.
	 */
	private static function documentAppNames(): string {
		$names = array_map(
			static fn (string $appId): string => ucfirst($appId),
			FleetAppId::CANDIDATES['filinq']
		);

		$newest = array_shift($names);
		if ($names === []) {
			return $newest;
		}

		return $newest . ', or ' . implode(', or ', $names);
	}//end documentAppNames()

	/**
	 * Report what one rule webhook call met.
	 *
	 * @param string   $url        The URL the rule posted to. Only its host reaches the message.
	 * @param int|null $httpStatus The answer's HTTP status, or null when nothing answered.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
	 */
	public function reportWebhookCall(string $url, ?int $httpStatus): bool {
		return $this->reportObserved(
			key: 'rule-webhooks',
			observe: fn (): ?array => $this->observations->httpCall(
				name: $this->observations->webhookName(url: $url),
				httpStatus: $httpStatus
			)
		);
	}//end reportWebhookCall()

	/**
	 * The HTTP status a failed call still carries, for {@see reportDocumentCall()} and {@see reportWebhookCall()}.
	 *
	 * Pure: reads, stores and sends nothing.
	 *
	 * @param Throwable $exception What the call threw.
	 *
	 * @return int|null The answer's HTTP status, or null when nothing answered.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
	 */
	public function httpStatusOf(Throwable $exception): ?int {
		return $this->observations->httpStatusOf(exception: $exception);
	}//end httpStatusOf()

	/**
	 * Ask integriq to resolve every connection whose config keys a save wrote.
	 *
	 * Clears that connection's report memory too, so the next call reports at
	 * once instead of waiting out the hour. Integriq reads the saved values
	 * itself and decides the status (design D6). Never throws.
	 *
	 * @param array<int, string> $savedKeys The app-config keys the save wrote.
	 *
	 * @return array<int, string> The connection keys a refresh was sent for.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-002-a-store-settings-save-asks-integriq-to-look-again
	 */
	public function refreshFromSave(array $savedKeys): array {
		$eventClass = $this->resolveEventClass(eventClass: self::REFRESH_EVENT);
		if ($eventClass === null) {
			return [];
		}

		$refreshed = [];
		foreach (self::REFRESH_KEYS as $key => $configKeys) {
			if (array_intersect($configKeys, $savedKeys) === []) {
				continue;
			}

			$this->forget(key: $key);
			$sent = $this->send(
				key: $key,
				build: static fn (): object => new $eventClass(
					app: self::APP_ID,
					key: $key,
				)
			);
			if ($sent === true) {
				$refreshed[] = $key;
			}
		}

		return $refreshed;
	}//end refreshFromSave()

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @param string $eventClass The fully qualified class name, without a leading backslash.
	 *
	 * @return string|null The class name to instantiate, or null when absent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
	 */
	protected function resolveEventClass(string $eventClass): ?string {
		$qualified = '\\' . $eventClass;
		if (class_exists($qualified) === false) {
			return null;
		}

		return $qualified;
	}//end resolveEventClass()

	/**
	 * Observe, throttle and send one status report. Never throws.
	 *
	 * Without integriq the class check fails first, so nothing is read,
	 * stored, sent or logged.
	 *
	 * @param string                                     $key     One of {@see self::KEYS}.
	 * @param callable(): (array{0: string, 1: string}|null) $observe Works out the status and message, or null to report nothing.
	 *
	 * @return bool True when a report was sent.
	 */
	private function reportObserved(string $key, callable $observe): bool {
		$eventClass = $this->resolveEventClass(eventClass: self::STATUS_EVENT);
		if ($eventClass === null) {
			return false;
		}

		try {
			$observed = $observe();
			if ($observed === null) {
				return false;
			}

			[$status, $message] = $observed;

			$now = $this->timeFactory->getTime();
			if ($this->isDue(key: $key, status: $status, now: $now) === false) {
				return false;
			}

			$sent = $this->send(
				key: $key,
				build: static fn (): object => new $eventClass(
					app: self::APP_ID,
					key: $key,
					status: $status,
					message: $message,
				)
			);
			if ($sent === true) {
				$this->appConfig->setValueString(self::APP_ID, self::MEMORY_KEY_PREFIX . $key, $status . '|' . $now);
			}

			return $sent;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: could not report a connection to integriq',
				['key' => $key, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end reportObserved()

	/**
	 * Whether the report memory allows a report with this status now.
	 *
	 * A different status waits five minutes after the last report, so two
	 * receivers that disagree cannot report on every call. The same status
	 * reports again after an hour.
	 *
	 * @param string $key    The connection key.
	 * @param string $status The status the call observed.
	 * @param int    $now    The current Unix time.
	 *
	 * @return bool
	 */
	private function isDue(string $key, string $status, int $now): bool {
		$memory = $this->appConfig->getValueString(self::APP_ID, self::MEMORY_KEY_PREFIX . $key, '');
		$parts  = explode('|', $memory, 2);
		if (count($parts) !== 2 || ctype_digit($parts[1]) === false) {
			return true;
		}

		$elapsed = ($now - (int) $parts[1]);
		if ($parts[0] === $status) {
			return $elapsed >= self::REPEAT_SECONDS;
		}

		return $elapsed >= self::CHANGE_SECONDS;
	}//end isDue()

	/**
	 * Clear the report memory of one connection.
	 *
	 * @param string $key The connection key.
	 *
	 * @return void
	 */
	private function forget(string $key): void {
		try {
			$this->appConfig->deleteKey(self::APP_ID, self::MEMORY_KEY_PREFIX . $key);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: could not clear a connection report memory',
				['key' => $key, 'exception' => $e->getMessage()]
			);
		}
	}//end forget()

	/**
	 * Build and dispatch one event, swallowing anything a listener throws.
	 *
	 * @param string             $key   The connection the event is about, for the log.
	 * @param callable(): object $build Builds the event.
	 *
	 * @return bool True when the event was dispatched without an exception.
	 */
	private function send(string $key, callable $build): bool {
		try {
			$event = $build();
			if (($event instanceof Event) === false) {
				return false;
			}

			$this->eventDispatcher->dispatchTyped($event);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: could not send a connection event to integriq',
				['key' => $key, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end send()
}//end class
