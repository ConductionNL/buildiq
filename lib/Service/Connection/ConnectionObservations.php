<?php

/**
 * Buildiq connection observations.
 *
 * Turns an outcome Buildiq already has, such as a store search outcome or a
 * webhook's HTTP status, into the status and message integriq's connection
 * registry shows (hydra change connection-registry, design D4 and D6). Pure:
 * it holds no state, reads nothing and sends nothing, so every mapping is
 * testable without a double.
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

/**
 * Maps call outcomes to connection statuses and messages.
 *
 * Every method answers `[status, message]`, or null when the outcome says
 * nothing about the connection (it is about one credential, one app or one
 * request) and so must not be reported.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
 */
class ConnectionObservations {

	/**
	 * HTTP statuses that say the other side refused the login.
	 *
	 * @var array<int, int>
	 */
	public const REFUSED_STATUSES = [401, 403];

	/**
	 * HTTP statuses that say a gateway could not reach the other side.
	 *
	 * A 500 is left out, because it is often about one request.
	 *
	 * @var array<int, int>
	 */
	public const GATEWAY_ERROR_STATUSES = [502, 503, 504];

	/**
	 * What a template store search outcome says about the store.
	 *
	 * The outcome values are OpenRegister `GenericStoreService::OUTCOME_*`.
	 *
	 * @param string $outcome     The search outcome.
	 * @param string $registryUrl The saved `registry_url`, for the host in the message.
	 *
	 * @return array{0: string, 1: string}|null The status and message, or null for an unknown outcome.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
	 */
	public function storeSearch(string $outcome, string $registryUrl): ?array {
		$store = $this->withHost(name: 'the template store', url: $registryUrl);

		return match ($outcome) {
			'ok' => ['configured', ucfirst($store) . ' answered the last search.'],
			'not_configured' => ['unconfigured', 'No store address is set. Set the registry URL under Template registry.'],
			'store_unreachable' => ['error', 'The last search could not reach ' . $store . '.'],
			'store_invalid_response' => ['error', ucfirst($store) . ' answered, but not with a list of templates.'],
			'rate_limited' => ['limited', ucfirst($store) . ' limited the last search.'],
			default => null,
		};
	}//end storeSearch()

	/**
	 * What a GitHub catalogue search outcome says about GitHub.
	 *
	 * The outcome values are `GitHubCatalogService::OUTCOME_*`. A search needs
	 * no credential, but push and pull need the broker, so a search that works
	 * without the broker is only part of the connection.
	 *
	 * @param string $outcome         The search outcome.
	 * @param bool   $brokerAvailable Whether OpenRegister's credential broker is installed.
	 *
	 * @return array{0: string, 1: string}|null The status and message, or null for an unknown outcome.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
	 */
	public function gitHubSearch(string $outcome, bool $brokerAvailable): ?array {
		if ($outcome === 'ok' && $brokerAvailable === false) {
			return [
				'limited',
				'The last catalogue search reached GitHub. Push and pull need the OpenRegister credential broker, which is not installed.',
			];
		}

		return match ($outcome) {
			'ok' => ['configured', 'The last catalogue search reached GitHub.'],
			'github_rate_limited' => [
				'limited',
				'GitHub limited the last catalogue search. Without a credential GitHub allows 60 requests an hour.',
			],
			'github_unreachable' => ['error', 'The last catalogue search could not reach GitHub.'],
			default => null,
		};
	}//end gitHubSearch()

	/**
	 * What a GitHub push or pull outcome says about GitHub.
	 *
	 * The outcome values are `GitHubAppSyncService::OUTCOME_*`. A denied
	 * credential, a conflict or an unlinked app is about one user or one app,
	 * so it is not reported.
	 *
	 * @param string $outcome The push or pull outcome.
	 *
	 * @return array{0: string, 1: string}|null The status and message, or null when the outcome is not about GitHub.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
	 */
	public function gitHubSync(string $outcome): ?array {
		return match ($outcome) {
			'ok' => ['configured', 'The last push or pull reached GitHub through the credential broker.'],
			'broker_unavailable' => [
				'limited',
				'Push and pull need the OpenRegister credential broker, which is not installed. Catalogue search still works.',
			],
			'github_unreachable' => ['error', 'The last push or pull could not reach GitHub.'],
			default => null,
		};
	}//end gitHubSync()

	/**
	 * What one HTTP call says about the connection behind it.
	 *
	 * @param string   $name       How the message names the other side, such as `Filinq`.
	 * @param int|null $httpStatus The answer's HTTP status, or null when nothing answered.
	 *
	 * @return array{0: string, 1: string}|null The status and message, or null when the answer is about one request.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
	 */
	public function httpCall(string $name, ?int $httpStatus): ?array {
		if ($httpStatus === null) {
			return ['error', 'The last call to ' . $name . ' got no answer.'];
		}

		if (in_array($httpStatus, self::REFUSED_STATUSES, true) === true) {
			return ['error', ucfirst($name) . ' refused the login (HTTP ' . $httpStatus . ').'];
		}

		if (in_array($httpStatus, self::GATEWAY_ERROR_STATUSES, true) === true) {
			return ['error', ucfirst($name) . ' answered HTTP ' . $httpStatus . ' on the last call.'];
		}

		if ($httpStatus >= 200 && $httpStatus < 400) {
			return ['configured', ucfirst($name) . ' answered the last call.'];
		}

		return null;
	}//end httpCall()

	/**
	 * How a message names a webhook receiver: its host, and nothing else from the URL.
	 *
	 * @param string $url The URL a rule posts to.
	 *
	 * @return string The name for a message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
	 */
	public function webhookName(string $url): string {
		return $this->withHost(name: 'the rule webhook', url: $url);
	}//end webhookName()

	/**
	 * Name a thing with the host of its URL, when the URL has one.
	 *
	 * Only the host leaves the URL: a path, a query or user info can carry a
	 * secret, and every admin reads the row.
	 *
	 * @param string $name The plain name.
	 * @param string $url  The URL to take the host from.
	 *
	 * @return string The name, followed by `at {host}` when there is a host.
	 */
	private function withHost(string $name, string $url): string {
		$host = parse_url(trim($url), PHP_URL_HOST);
		if (is_string($host) === false || $host === '') {
			return $name;
		}

		return $name . ' at ' . $host;
	}//end withHost()
}//end class
