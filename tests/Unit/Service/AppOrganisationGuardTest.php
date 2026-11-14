<?php

/**
 * Unit tests for AppOrganisationGuard.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppOrganisationGuard;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the organisation scope: `all`, a member, a non-member, a child organisation and a missing scope.
 *
 * @covers \OCA\Buildiq\Service\AppOrganisationGuard
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
 */
class AppOrganisationGuardTest extends TestCase {

	private const NOORD = 'org-omgevingsdienst-noord';

	private const ZUID = 'org-gemeente-zuid';

	private const TEAM = 'org-noord-team-toezicht';

	/**
	 * A shared app (scope `all`) is seen by anyone, without reading membership.
	 *
	 * @return void
	 */
	public function testAnAllScopedAppIsSeenByEveryone(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$guard = new AppOrganisationGuard($container, $this->createMock(LoggerInterface::class));

		$this->assertTrue($guard->canSee($this->app(scope: 'all'), $this->user('zuid-user')));
		$this->assertTrue($guard->canSee($this->app(scope: 'all'), null));
	}//end testAnAllScopedAppIsSeenByEveryone()

	/**
	 * An app from before the scope existed (no scope) stays shared.
	 *
	 * @return void
	 */
	public function testAMissingScopeMeansAll(): void {
		$this->assertTrue(AppOrganisationGuard::isShared(['slug' => 'vergunningen']));
		$this->assertTrue(AppOrganisationGuard::isShared(['organisationScope' => '']));
		$this->assertFalse(AppOrganisationGuard::isShared(['organisationScope' => 'holder']));
		$this->assertFalse(AppOrganisationGuard::isShared(['organisationScope' => 'nonsense']), 'an unreadable scope fails closed');
		$this->assertFalse(AppOrganisationGuard::isShared(['organisationScope' => ['all']]), 'an unreadable scope fails closed');
	}//end testAMissingScopeMeansAll()

	/**
	 * A member of the holder sees the scoped app.
	 *
	 * @return void
	 */
	public function testAMemberOfTheHolderSeesIt(): void {
		$guard = $this->guard(memberships: ['noord-user' => [self::NOORD]]);

		$this->assertTrue($guard->canSee($this->app(scope: 'holder'), $this->user('noord-user')));
	}//end testAMemberOfTheHolderSeesIt()

	/**
	 * A member of another organisation does not.
	 *
	 * @return void
	 */
	public function testANonMemberDoesNotSeeIt(): void {
		$guard = $this->guard(memberships: ['zuid-user' => [self::ZUID]]);

		$this->assertFalse($guard->canSee($this->app(scope: 'holder'), $this->user('zuid-user')));
	}//end testANonMemberDoesNotSeeIt()

	/**
	 * A member of a child organisation of the holder sees it.
	 *
	 * @return void
	 */
	public function testAMemberOfAChildOrganisationSeesIt(): void {
		$guard = $this->guard(memberships: ['team-user' => [self::ZUID, self::TEAM]], parents: [self::TEAM => [self::NOORD]]);

		$this->assertTrue($guard->canSee($this->app(scope: 'holder'), $this->user('team-user')));
	}//end testAMemberOfAChildOrganisationSeesIt()

	/**
	 * The holder is read from the rendered `@self` block or a flat `organisation` field.
	 *
	 * @return void
	 */
	public function testTheHolderIsReadFromAFlatOrganisationField(): void {
		$guard = $this->guard(memberships: ['noord-user' => [self::NOORD]]);

		$this->assertTrue($guard->canSee(['organisationScope' => 'holder', 'organisation' => self::NOORD], $this->user('noord-user')));
	}//end testTheHolderIsReadFromAFlatOrganisationField()

	/**
	 * Fail closed: no holder, no user, an unknown scope, or unreadable membership.
	 *
	 * @return void
	 */
	public function testItFailsClosed(): void {
		$guard = $this->guard(memberships: ['noord-user' => [self::NOORD]]);
		$noHolder = ['organisationScope' => 'holder'];

		$this->assertFalse($guard->canSee($noHolder, $this->user('noord-user')));
		$this->assertFalse($guard->canSee($this->app(scope: 'holder'), null));
		$this->assertFalse($guard->canSee($this->app(scope: 'holder'), $this->user('')));
		$this->assertTrue($guard->canSee($this->app(scope: 'garbled'), $this->user('noord-user')), 'an unknown scope is read as holder');
		$this->assertFalse($this->guard(memberships: ['zuid-user' => [self::ZUID]])->canSee($this->app(scope: 'garbled'), $this->user('zuid-user')));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OpenRegister is not installed'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$broken = new AppOrganisationGuard($container, $logger);
		$this->assertFalse($broken->canSee($this->app(scope: 'holder'), $this->user('noord-user')));
	}//end testItFailsClosed()

	/**
	 * The guard over a mapper with the given memberships and parent chains.
	 *
	 * @param array<string, array<int, string>> $memberships Organisations per user.
	 * @param array<string, array<int, string>> $parents     Parent chain per organisation.
	 *
	 * @return AppOrganisationGuard The guard.
	 */
	private function guard(array $memberships, array $parents = []): AppOrganisationGuard {
		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUserId')->willReturnCallback(
			static fn (string $uid): array => array_map(
				static fn (string $uuid): Organisation => new Organisation($uuid),
				($memberships[$uid] ?? [])
			)
		);
		$mapper->method('findParentChain')->willReturnCallback(
			static fn (string $uuid): array => ($parents[$uuid] ?? [])
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(OrganisationMapper::class)->willReturn($mapper);

		return new AppOrganisationGuard($container, $this->createMock(LoggerInterface::class));
	}//end guard()

	/**
	 * The `toezicht` app held by Omgevingsdienst Noord, as OpenRegister renders it.
	 *
	 * @param string $scope The organisation scope.
	 *
	 * @return array<string, mixed> The Application.
	 */
	private function app(string $scope): array {
		return [
			'slug' => 'toezicht',
			'organisationScope' => $scope,
			'@self' => ['organisation' => self::NOORD],
		];
	}//end app()

	/**
	 * A user with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser The user.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()
}//end class
