<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests the call the broker client makes for a GitHub request.
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\GitHub;

use OCA\Buildiq\Kernel\Values\BrokerAccess;
use OCA\Buildiq\Service\GitHub\CredentialBrokerClient;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The request goes to the OpenRegister credential broker under this app's id,
 * with GitHub JSON headers, the body JSON-encoded and the acting user passed on.
 *
 * @covers \OCA\Buildiq\Service\GitHub\CredentialBrokerClient
 * @uses   \OCA\Buildiq\Kernel\Values\BrokerAccess
 */
class CredentialBrokerClientTest extends TestCase {
	/**
	 * A broker stand-in that records its calls and answers with a fixed response.
	 *
	 * @return object
	 */
	private function broker(): object {
		return new class {
			/**
			 * The recorded calls.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * Record a request.
			 *
			 * @param mixed ...$args The request arguments.
			 *
			 * @return array<string, int>
			 */
			public function request(mixed ...$args): array {
				$this->calls[] = $args;
				return ['status' => 201];
			}//end request()
		};
	}//end broker()

	/**
	 * The client wired to a container that serves the given broker.
	 *
	 * @param object $broker The broker.
	 *
	 * @return CredentialBrokerClient
	 */
	private function client(object $broker): CredentialBrokerClient {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->once())->method('get')
			->with('OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService')
			->willReturn($broker);

		return new CredentialBrokerClient(container: $container);
	}//end client()

	/**
	 * A request with a body sends it JSON-encoded with the GitHub headers.
	 *
	 * @return void
	 */
	public function testABodyIsSentAsJson(): void {
		$broker = $this->broker();

		$result = $this->client(broker: $broker)->sendJson(
			request: ['method' => 'PUT', 'path' => '/repos/o/r/contents/a', 'body' => ['message' => 'm', 'n' => 1]],
			access: new BrokerAccess(credentialId: 'cred-1', actingUserId: 'alice')
		);

		$this->assertSame(expected: ['status' => 201], actual: $result);
		$this->assertSame(
			expected: [[
				'cred-1',
				'buildiq',
				'PUT',
				'/repos/o/r/contents/a',
				['Accept' => 'application/vnd.github+json', 'Content-Type' => 'application/json'],
				'{"message":"m","n":1}',
				'alice',
			]],
			actual: $broker->calls
		);
	}//end testABodyIsSentAsJson()

	/**
	 * A request without a body sends null, not an encoded "null".
	 *
	 * @return void
	 */
	public function testNoBodySendsNull(): void {
		$broker = $this->broker();

		$this->client(broker: $broker)->sendJson(
			request: ['method' => 'GET', 'path' => '/repos/o/r', 'body' => null],
			access: new BrokerAccess(credentialId: 'cred-2')
		);

		$this->assertNull(actual: $broker->calls[0][5]);
		$this->assertNull(actual: $broker->calls[0][6]);
		$this->assertSame(expected: 'GET', actual: $broker->calls[0][2]);
	}//end testNoBodySendsNull()
}//end class
