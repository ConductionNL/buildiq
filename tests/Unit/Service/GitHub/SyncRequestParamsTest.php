<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests how the GitHub sync endpoints read their request parameters.
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\GitHub;

use OCA\Buildiq\Service\GitHub\SyncRequestParams;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * A blank credential is no credential, a version slug is optional, and a new
 * repository is only taken when every name in it is a valid owner/repo name.
 *
 * @covers \OCA\Buildiq\Service\GitHub\SyncRequestParams
 * @uses   \OCA\Buildiq\Service\GitHub\AppPushRequest
 * @uses   \OCA\Buildiq\Service\GitHub\BrokerAccess
 * @uses   \OCA\Buildiq\Service\GitHub\BrokeredRepo
 * @uses   \OCA\Buildiq\Service\GitHub\PushTarget
 */
class SyncRequestParamsTest extends TestCase {
	/**
	 * Parameters read from a request carrying the given values.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return SyncRequestParams
	 */
	private function params(array $params): SyncRequestParams {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		return new SyncRequestParams(request: $request);
	}//end params()

	/**
	 * A credential id is returned as given.
	 *
	 * @return void
	 */
	public function testACredentialIdIsReturned(): void {
		$this->assertSame(expected: 'cred-1', actual: $this->params(params: ['credentialId' => 'cred-1'])->credential());
	}//end testACredentialIdIsReturned()

	/**
	 * Values that carry no credential.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function noCredential(): array {
		return [
			'absent' => [null],
			'empty'  => [''],
			'number' => [42],
			'array'  => [['cred-1']],
		];
	}//end noCredential()

	/**
	 * An absent, empty or non-string credential id is no credential.
	 *
	 * @param mixed $value The credentialId parameter.
	 *
	 * @dataProvider noCredential
	 *
	 * @return void
	 */
	public function testAnythingButANonEmptyStringIsNoCredential(mixed $value): void {
		$this->assertNull(actual: $this->params(params: ['credentialId' => $value])->credential());
	}//end testAnythingButANonEmptyStringIsNoCredential()

	/**
	 * A push request carries the access, the version slug and no new repository by default.
	 *
	 * @return void
	 */
	public function testAPushRequestCarriesTheAccessAndVersion(): void {
		$push = $this->params(params: ['versionSlug' => 'v2'])->pushRequest(credentialId: 'cred-1', actingUserId: 'alice');

		$this->assertSame(expected: 'cred-1', actual: $push->access->credentialId);
		$this->assertSame(expected: 'alice', actual: $push->access->actingUserId);
		$this->assertSame(expected: 'v2', actual: $push->versionSlug);
		$this->assertNull(actual: $push->newRepo);
	}//end testAPushRequestCarriesTheAccessAndVersion()

	/**
	 * An empty or non-string version slug becomes null.
	 *
	 * @return void
	 */
	public function testABlankVersionSlugIsNull(): void {
		$this->assertNull(actual: $this->params(params: ['versionSlug' => ''])->pushRequest(credentialId: 'c', actingUserId: null)->versionSlug);
		$this->assertNull(actual: $this->params(params: ['versionSlug' => 3])->pushRequest(credentialId: 'c', actingUserId: null)->versionSlug);
	}//end testABlankVersionSlugIsNull()

	/**
	 * A valid new repository becomes the push target, private only when asked for.
	 *
	 * @return void
	 */
	public function testAValidNewRepositoryBecomesTheTarget(): void {
		$public = $this->params(
			params: ['repo' => ['owner' => 'me', 'name' => 'my.app_1', 'org' => 'Conduction-NL']]
		)->pushRequest(credentialId: 'c', actingUserId: null)->newRepo;

		$this->assertNotNull(actual: $public);
		$this->assertSame(expected: 'Conduction-NL', actual: $public->org);
		$this->assertSame(expected: 'my.app_1', actual: $public->repo);
		$this->assertSame(expected: 'public', actual: $public->visibility);

		$private = $this->params(
			params: ['repo' => ['name' => 'app'], 'visibility' => 'PRIVATE']
		)->pushRequest(credentialId: 'c', actingUserId: null)->newRepo;

		$this->assertNotNull(actual: $private);
		$this->assertSame(expected: '', actual: $private->org);
		$this->assertSame(expected: 'private', actual: $private->visibility);
	}//end testAValidNewRepositoryBecomesTheTarget()

	/**
	 * Visibility values other than "private" mean public.
	 *
	 * @return void
	 */
	public function testAnyOtherVisibilityIsPublic(): void {
		foreach (['internal', 1, null] as $visibility) {
			$target = $this->params(
				params: ['repo' => ['name' => 'app'], 'visibility' => $visibility]
			)->pushRequest(credentialId: 'c', actingUserId: null)->newRepo;
			$this->assertSame(expected: 'public', actual: $target?->visibility);
		}
	}//end testAnyOtherVisibilityIsPublic()

	/**
	 * Repository parameters that are refused.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalidRepos(): array {
		return [
			'not an array'      => ['app'],
			'no name'           => [['owner' => 'me']],
			'name with slash'   => [['name' => 'a/b']],
			'name too long'     => [['name' => str_repeat('a', 101)]],
			'owner with space'  => [['name' => 'app', 'owner' => 'm e']],
			'org with slash'    => [['name' => 'app', 'org' => '../x']],
		];
	}//end invalidRepos()

	/**
	 * A repository with an invalid name, owner or org is not taken.
	 *
	 * @param mixed $repo The repo parameter.
	 *
	 * @dataProvider invalidRepos
	 *
	 * @return void
	 */
	public function testAnInvalidNewRepositoryIsDropped(mixed $repo): void {
		$this->assertNull(
			actual: $this->params(params: ['repo' => $repo])->pushRequest(credentialId: 'c', actingUserId: null)->newRepo
		);
	}//end testAnInvalidNewRepositoryIsDropped()

	/**
	 * Linking reads owner, name and credential, with missing values as empty strings.
	 *
	 * @return void
	 */
	public function testLinkRepoReadsOwnerNameAndCredential(): void {
		$linked = $this->params(
			params: ['owner' => 'ConductionNL', 'name' => 'buildiq', 'credentialId' => 'cred-9']
		)->linkRepo(actingUserId: 'bob');

		$this->assertSame(expected: '/repos/ConductionNL/buildiq', actual: $linked->target->repoPath());
		$this->assertSame(expected: 'cred-9', actual: $linked->access->credentialId);
		$this->assertSame(expected: 'bob', actual: $linked->access->actingUserId);

		$empty = $this->params(params: [])->linkRepo(actingUserId: null);
		$this->assertSame(expected: '', actual: $empty->target->org);
		$this->assertSame(expected: '', actual: $empty->target->repo);
		$this->assertSame(expected: '', actual: $empty->access->credentialId);
	}//end testLinkRepoReadsOwnerNameAndCredential()
}//end class
