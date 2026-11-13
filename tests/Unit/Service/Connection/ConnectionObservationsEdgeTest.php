<?php

/**
 * Unit tests for the edges of ConnectionObservations: the HTTP status read
 * from an exception, the 2xx/3xx boundary of a call, and the exact GitHub
 * and store messages a connection reports.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Connection
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Connection;

use OCA\Buildiq\Service\Connection\ConnectionObservations;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Edge cases of ConnectionObservations.
 *
 * @covers \OCA\Buildiq\Service\Connection\ConnectionObservations
 */
final class ConnectionObservationsEdgeTest extends TestCase {

	/**
	 * An exception carrying a response yields that response's status code as an integer.
	 *
	 * @return void
	 */
	public function testTheStatusIsReadFromTheExceptionsResponse(): void {
		$response = new class {
			public function getStatusCode(): string {
				return '503';
			}
		};
		$exception = new class ($response) extends RuntimeException {
			public function __construct(private readonly object $response) {
				parent::__construct('bad gateway');
			}

			public function getResponse(): object {
				return $this->response;
			}
		};

		self::assertSame(503, (new ConnectionObservations())->httpStatusOf(exception: $exception));
	}//end testTheStatusIsReadFromTheExceptionsResponse()

	/**
	 * An exception without a response, or whose response is not one, yields no status.
	 *
	 * @return void
	 */
	public function testNoUsableResponseYieldsNoStatus(): void {
		$observations = new ConnectionObservations();
		$nullResponse = new class extends RuntimeException {
			public function getResponse(): ?object {
				return null;
			}
		};
		$plainResponse = new class extends RuntimeException {
			public function getResponse(): object {
				return new \stdClass();
			}
		};

		self::assertNull($observations->httpStatusOf(exception: new RuntimeException('timeout')));
		self::assertNull($observations->httpStatusOf(exception: $nullResponse));
		self::assertNull($observations->httpStatusOf(exception: $plainResponse));
	}//end testNoUsableResponseYieldsNoStatus()

	/**
	 * 2xx and 3xx answers are configured; the statuses just outside that range report nothing.
	 *
	 * @return void
	 */
	public function testTheSuccessRangeIsTwoHundredToThreeNinetyNine(): void {
		$observations = new ConnectionObservations();

		self::assertSame(['configured', 'Filinq answered the last call.'], $observations->httpCall(name: 'filinq', httpStatus: 200));
		self::assertSame('configured', $observations->httpCall(name: 'Filinq', httpStatus: 399)[0]);
		self::assertNull($observations->httpCall(name: 'Filinq', httpStatus: 199));
		self::assertNull($observations->httpCall(name: 'Filinq', httpStatus: 400));
		self::assertSame(
			['error', 'Filinq answered HTTP 504 on the last call.'],
			$observations->httpCall(name: 'Filinq', httpStatus: 504)
		);
	}//end testTheSuccessRangeIsTwoHundredToThreeNinetyNine()

	/**
	 * Each push or pull outcome reports its own message; any other outcome reports nothing.
	 *
	 * @return void
	 */
	public function testTheGitHubSyncMessages(): void {
		$observations = new ConnectionObservations();

		self::assertSame(
			['configured', 'The last push or pull reached GitHub through the credential broker.'],
			$observations->gitHubSync(outcome: 'ok')
		);
		self::assertSame(
			['limited', 'Push and pull need the OpenRegister credential broker, which is not installed. Catalogue search still works.'],
			$observations->gitHubSync(outcome: 'broker_unavailable')
		);
		self::assertSame(
			['error', 'The last push or pull could not reach GitHub.'],
			$observations->gitHubSync(outcome: 'github_unreachable')
		);
		self::assertNull($observations->gitHubSync(outcome: 'repo_not_found'));
	}//end testTheGitHubSyncMessages()

	/**
	 * A catalogue search names the rate limit; a failed search reports nothing outside the known outcomes.
	 *
	 * @return void
	 */
	public function testTheGitHubSearchMessages(): void {
		$observations = new ConnectionObservations();

		self::assertSame(
			['configured', 'The last catalogue search reached GitHub.'],
			$observations->gitHubSearch(outcome: 'ok', brokerAvailable: true)
		);
		self::assertSame(
			['limited', 'GitHub limited the last catalogue search. Without a credential GitHub allows 60 requests an hour.'],
			$observations->gitHubSearch(outcome: 'github_rate_limited', brokerAvailable: false)
		);
		self::assertNull($observations->gitHubSearch(outcome: 'invalid_query', brokerAvailable: true));
	}//end testTheGitHubSearchMessages()

	/**
	 * The store's host is named in its messages, with surrounding whitespace ignored.
	 *
	 * @return void
	 */
	public function testTheStoreMessagesNameItsHost(): void {
		$observations = new ConnectionObservations();

		self::assertSame(
			['configured', 'The template store at store.example.nl answered the last search.'],
			$observations->storeSearch(outcome: 'ok', registryUrl: '  https://store.example.nl/api  ')
		);
		self::assertSame(
			['error', 'The template store at store.example.nl answered, but not with a list of templates.'],
			$observations->storeSearch(outcome: 'store_invalid_response', registryUrl: 'https://store.example.nl')
		);
		self::assertSame(
			['limited', 'The template store at store.example.nl limited the last search.'],
			$observations->storeSearch(outcome: 'rate_limited', registryUrl: 'https://store.example.nl')
		);
		self::assertNull($observations->storeSearch(outcome: 'not_found', registryUrl: 'https://store.example.nl'));
	}//end testTheStoreMessagesNameItsHost()
}//end class
