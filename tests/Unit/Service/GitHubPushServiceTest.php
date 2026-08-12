<?php

/**
 * OpenBuild GitHubPushService unit tests
 *
 * This file used to lock the OPPOSITE contract: that the PAT was a method-scoped
 * parameter, not stored on `$this`, and absent from log lines. That is good hygiene
 * around a secret the app should never have had. The app no longer has it — every
 * GitHub call goes through OpenRegister's credential broker, which injects the token
 * server-side — so the tests now pin the absence of the PAT surface itself, plus the
 * fail-closed guards.
 *
 * There is deliberately no happy-path test here. `push()` resolves the broker through
 * `Server::get()`, which needs the Nextcloud container; with OpenRegister absent from
 * the unit-test autoloader `isBrokerAvailable()` is false and the service fails closed —
 * which is exactly the behaviour asserted below. The wire surface is covered where it is
 * actually exercisable: against the live broker on the dev instance.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Service\GitHubPushService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for {@see GitHubPushService} — the no-token contract + fail-closed guards.
 */
final class GitHubPushServiceTest extends TestCase {
	/**
	 * A scratch tree with one file, cleaned up by the caller.
	 *
	 * @return string Absolute path to the tree.
	 */
	private function makeTree(): string {
		$treeDir = sys_get_temp_dir() . '/openbuild-test-tree-' . uniqid();
		mkdir($treeDir, 0o755, true);
		file_put_contents($treeDir . '/README.md', '# hello');

		return $treeDir;
	}//end makeTree()

	/**
	 * Remove a scratch tree.
	 *
	 * @param string $treeDir The tree to remove.
	 *
	 * @return void
	 */
	private function removeTree(string $treeDir): void {
		if (is_file($treeDir . '/README.md') === true) {
			unlink($treeDir . '/README.md');
		}

		if (is_dir($treeDir) === true) {
			rmdir($treeDir);
		}
	}//end removeTree()

	/**
	 * The core regression: NO method on this service may take a PAT.
	 *
	 * A `$pat` parameter reappearing anywhere means the app has taken custody of the
	 * user's token again, which is the whole thing this change removes. Asserted over
	 * every method — public and private — so it cannot creep back in via a helper.
	 *
	 * @return void
	 */
	public function testNoMethodAcceptsAToken(): void {
		$reflection = new \ReflectionClass(GitHubPushService::class);

		foreach ($reflection->getMethods() as $method) {
			foreach ($method->getParameters() as $parameter) {
				$name = strtolower($parameter->getName());
				self::assertNotSame(
					'pat',
					$name,
					$method->getName() . '() must NOT take a PAT — GitHub auth belongs to the broker'
				);
				self::assertStringNotContainsString(
					'token',
					$name,
					$method->getName() . '() must NOT take a token — GitHub auth belongs to the broker'
				);
			}
		}
	}//end testNoMethodAcceptsAToken()

	/**
	 * push() names a credential, not a secret.
	 *
	 * @return void
	 */
	public function testPushTakesACredentialIdAndAnActingUser(): void {
		$reflection = new \ReflectionMethod(GitHubPushService::class, 'push');
		$names = array_map(static fn ($p) => $p->getName(), $reflection->getParameters());

		self::assertContains('credentialId', $names, 'push() must take a broker credential UUID');
		self::assertContains(
			'actingUserId',
			$names,
			'push() must take the credential owner — the background job has no session for the broker to read'
		);
	}//end testPushTakesACredentialIdAndAnActingUser()

	/**
	 * The service holds no HTTP client: it makes no outbound call of its own, so
	 * there is no request it could attach an Authorization header to.
	 *
	 * @return void
	 */
	public function testServiceHoldsNoHttpClient(): void {
		$reflection = new \ReflectionClass(GitHubPushService::class);

		foreach ($reflection->getConstructor()->getParameters() as $parameter) {
			$type = (string)$parameter->getType();
			self::assertStringNotContainsString(
				'IClientService',
				$type,
				'GitHubPushService must not hold an HTTP client — every call goes through the broker'
			);
		}
	}//end testServiceHoldsNoHttpClient()

	/**
	 * Fail closed when the broker cannot serve the call: no fallback, no push.
	 *
	 * OpenRegister IS on the unit-test autoloader, so `isBrokerAvailable()` is true
	 * here and `push()` gets as far as the first broker call — which cannot resolve a
	 * real `Server::get()` container in a unit test. It must throw rather than degrade
	 * to any token-bearing path. Whether the broker is missing, denies the call, or is
	 * simply unreachable, the outcome has to be the same: no repository is created.
	 *
	 * @return void
	 */
	public function testPushFailsClosedWhenTheBrokerCannotServeTheCall(): void {
		$treeDir = $this->makeTree();
		$service = new GitHubPushService(new NullLogger());

		$this->expectException(RuntimeException::class);

		try {
			$service->push(
				jobUuid: 'job-123',
				treeDir: $treeDir,
				credentialId: 'cred-uuid',
				org: 'acme',
				repo: 'app',
				visibility: 'public'
			);
		} finally {
			$this->removeTree($treeDir);
		}
	}//end testPushFailsClosedWhenTheBrokerCannotServeTheCall()

	/**
	 * The broker-availability check is a real `class_exists` on OpenRegister's broker,
	 * so an instance without OpenRegister cannot reach the push path at all.
	 *
	 * @return void
	 */
	public function testBrokerAvailabilityIsCheckedAgainstOpenRegister(): void {
		$service = new GitHubPushService(new NullLogger());

		self::assertTrue(
			$service->isBrokerAvailable(),
			'OpenRegister is on the test autoloader, so the broker must resolve'
		);
	}//end testBrokerAvailabilityIsCheckedAgainstOpenRegister()

	/**
	 * An empty credential is refused too — a GitHub export cannot proceed
	 * unauthenticated.
	 *
	 * @return void
	 */
	public function testPushRefusesAnEmptyCredential(): void {
		$treeDir = $this->makeTree();
		$service = new GitHubPushService(new NullLogger());

		$this->expectException(RuntimeException::class);

		try {
			$service->push(
				jobUuid: 'job-123',
				treeDir: $treeDir,
				credentialId: '',
				org: 'acme',
				repo: 'app'
			);
		} finally {
			$this->removeTree($treeDir);
		}
	}//end testPushRefusesAnEmptyCredential()
}//end class
