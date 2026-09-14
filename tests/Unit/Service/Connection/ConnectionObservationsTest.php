<?php

/**
 * ConnectionObservations unit tests.
 *
 * The mapper decides what the Integrations page says about a connection. Each
 * test guards one way it could stop telling the truth: calling an unreachable
 * store configured, calling a per-credential denial a broken GitHub, calling
 * one bad request a broken receiver, or leaking a secret from a URL into a
 * row every admin reads.
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

use OCA\Buildiq\Service\Connection\ConnectionObservations;
use OCA\Buildiq\Service\GitHubAppSyncService;
use OCA\Buildiq\Service\GitHubCatalogService;
use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConnectionObservations.
 *
 * @covers \OCA\Buildiq\Service\Connection\ConnectionObservations
 */
class ConnectionObservationsTest extends TestCase {

	/**
	 * The mapper under test.
	 *
	 * @var ConnectionObservations
	 */
	private ConnectionObservations $observations;

	/**
	 * Set up the mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->observations = new ConnectionObservations();
	}//end setUp()

	/**
	 * Each store outcome maps to the status the design names, with the host.
	 *
	 * @return void
	 */
	public function testEachStoreOutcomeMapsToTheDesignedStatus(): void {
		$url = 'https://store.gemeente.example/index.php';

		$this->assertSame(
			expected: ['configured', 'The template store at store.gemeente.example answered the last search.'],
			actual: $this->observations->storeSearch(outcome: GenericStoreService::OUTCOME_OK, registryUrl: $url)
		);
		$this->assertSame(expected: 'unconfigured', actual: $this->observations->storeSearch(outcome: GenericStoreService::OUTCOME_NOT_CONFIGURED, registryUrl: '')[0]);
		$this->assertSame(
			expected: ['error', 'The last search could not reach the template store at store.gemeente.example.'],
			actual: $this->observations->storeSearch(outcome: GenericStoreService::OUTCOME_UNREACHABLE, registryUrl: $url)
		);
		$this->assertSame(expected: 'error', actual: $this->observations->storeSearch(outcome: GenericStoreService::OUTCOME_INVALID, registryUrl: $url)[0]);
		$this->assertSame(expected: 'limited', actual: $this->observations->storeSearch(outcome: 'rate_limited', registryUrl: $url)[0]);
		$this->assertNull(actual: $this->observations->storeSearch(outcome: 'something_new', registryUrl: $url));
	}//end testEachStoreOutcomeMapsToTheDesignedStatus()

	/**
	 * A store with no parseable host is still named, without a dangling "at".
	 *
	 * @return void
	 */
	public function testAStoreWithoutAHostIsNamedPlainly(): void {
		$this->assertSame(
			expected: ['configured', 'The template store answered the last search.'],
			actual: $this->observations->storeSearch(outcome: 'ok', registryUrl: 'not a url')
		);
	}//end testAStoreWithoutAHostIsNamedPlainly()

	/**
	 * A GitHub search that works without the broker is only part of the connection.
	 *
	 * @return void
	 */
	public function testAGitHubSearchWithoutTheBrokerIsLimited(): void {
		$without = $this->observations->gitHubSearch(outcome: GitHubCatalogService::OUTCOME_OK, brokerAvailable: false);
		$with    = $this->observations->gitHubSearch(outcome: GitHubCatalogService::OUTCOME_OK, brokerAvailable: true);

		$this->assertSame(expected: 'limited', actual: $without[0]);
		$this->assertStringContainsString(needle: 'credential broker', haystack: $without[1]);
		$this->assertSame(expected: ['configured', 'The last catalogue search reached GitHub.'], actual: $with);
	}//end testAGitHubSearchWithoutTheBrokerIsLimited()

	/**
	 * A rate limit is limited, an unreachable GitHub is an error, whatever the broker.
	 *
	 * @return void
	 */
	public function testGitHubSearchFailuresMapIndependentOfTheBroker(): void {
		foreach ([true, false] as $broker) {
			$this->assertSame(expected: 'limited', actual: $this->observations->gitHubSearch(outcome: GitHubCatalogService::OUTCOME_RATE_LIMITED, brokerAvailable: $broker)[0]);
			$this->assertSame(expected: 'error', actual: $this->observations->gitHubSearch(outcome: GitHubCatalogService::OUTCOME_UNREACHABLE, brokerAvailable: $broker)[0]);
		}
	}//end testGitHubSearchFailuresMapIndependentOfTheBroker()

	/**
	 * Push and pull report only outcomes about GitHub, not about one credential or one app.
	 *
	 * @return void
	 */
	public function testPushAndPullReportOnlyConnectionOutcomes(): void {
		$this->assertSame(expected: 'configured', actual: $this->observations->gitHubSync(outcome: GitHubAppSyncService::OUTCOME_OK)[0]);
		$this->assertSame(expected: 'limited', actual: $this->observations->gitHubSync(outcome: GitHubAppSyncService::OUTCOME_BROKER_UNAVAILABLE)[0]);
		$this->assertSame(expected: 'error', actual: $this->observations->gitHubSync(outcome: GitHubAppSyncService::OUTCOME_UNREACHABLE)[0]);

		foreach ([
			GitHubAppSyncService::OUTCOME_BROKER_DENIED,
			GitHubAppSyncService::OUTCOME_FORBIDDEN,
			GitHubAppSyncService::OUTCOME_PUSH_CONFLICT,
			GitHubAppSyncService::OUTCOME_NOT_LINKED,
			'version_not_found',
		] as $outcome) {
			$this->assertNull(actual: $this->observations->gitHubSync(outcome: $outcome), message: $outcome);
		}
	}//end testPushAndPullReportOnlyConnectionOutcomes()

	/**
	 * An HTTP answer maps to error, configured or nothing, as the design names.
	 *
	 * A 400, 404 or 500 is about one request, so it is not reported.
	 *
	 * @return void
	 */
	public function testAnHttpAnswerMapsAsDesigned(): void {
		$expected = [
			200 => 'configured',
			201 => 'configured',
			302 => 'configured',
			401 => 'error',
			403 => 'error',
			502 => 'error',
			503 => 'error',
			504 => 'error',
			400 => null,
			404 => null,
			500 => null,
		];

		foreach ($expected as $httpStatus => $status) {
			$observed = $this->observations->httpCall(name: 'Filinq', httpStatus: $httpStatus);
			$this->assertSame(expected: $status, actual: $observed[0] ?? null, message: 'HTTP ' . $httpStatus);
		}

		$this->assertSame(expected: ['error', 'The last call to Filinq got no answer.'], actual: $this->observations->httpCall(name: 'Filinq', httpStatus: null));
		$this->assertSame(expected: ['error', 'Filinq refused the login (HTTP 401).'], actual: $this->observations->httpCall(name: 'Filinq', httpStatus: 401));
	}//end testAnHttpAnswerMapsAsDesigned()

	/**
	 * A webhook message carries the host and nothing else from the URL.
	 *
	 * @return void
	 */
	public function testAWebhookMessageCarriesOnlyTheHost(): void {
		$name     = $this->observations->webhookName(url: 'https://user:s3cret@hooks.example.nl/path/abc?token=xyz#frag');
		$observed = $this->observations->httpCall(name: $name, httpStatus: 503);

		$this->assertSame(expected: ['error', 'The rule webhook at hooks.example.nl answered HTTP 503 on the last call.'], actual: $observed);
		foreach (['s3cret', 'user', 'token', 'xyz', '/path', 'frag'] as $leak) {
			$this->assertStringNotContainsString(needle: $leak, haystack: $observed[1]);
		}
	}//end testAWebhookMessageCarriesOnlyTheHost()
}//end class
