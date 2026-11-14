<?php

/**
 * Unit tests for the organisation scope on the MCP tools.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Mcp\Handler
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

namespace OCA\Buildiq\Tests\Unit\Mcp\Handler;

use OCA\Buildiq\Mcp\Handler\AbstractToolHandler;
use OCA\Buildiq\Mcp\Handler\ListAppsHandler;
use OCA\Buildiq\Service\AppOrganisationGuard;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The MCP tools answer for another organisation's scoped app as for an app that does not exist.
 *
 * @covers \OCA\Buildiq\Mcp\Handler\AbstractToolHandler
 * @covers \OCA\Buildiq\Mcp\Handler\ListAppsHandler
 * @uses   \OCA\Buildiq\Service\AppOrganisationGuard
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
 */
class McpOrganisationScopeTest extends TestCase {

	/**
	 * The list holds the shared app and the caller's own scoped app, never another organisation's.
	 *
	 * @return void
	 */
	public function testTheListLeavesOutAnotherOrganisationsScopedApp(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjectsBySlug')->willReturn(
			[
				$this->app(slug: 'shared', scope: null),
				$this->app(slug: 'ours', scope: 'holder'),
				$this->app(slug: 'theirs', scope: 'holder'),
			]
		);

		$result = $this->listHandler(objects: $objects, container: $this->containerWith(guard: $this->guardAdmitting(slugs: ['ours'])))->handle([]);

		$this->assertSame(['shared', 'ours'], array_column($result['apps'], 'slug'));
	}//end testTheListLeavesOutAnotherOrganisationsScopedApp()

	/**
	 * Without a resolvable guard, scoped apps drop out and shared ones stay (fail closed).
	 *
	 * @return void
	 */
	public function testAnUnresolvableGuardHidesScopedApps(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjectsBySlug')->willReturn([$this->app(slug: 'shared', scope: 'all'), $this->app(slug: 'ours', scope: 'holder')]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no guard'));

		$result = $this->listHandler(objects: $objects, container: $container)->handle([]);

		$this->assertSame(['shared'], array_column($result['apps'], 'slug'));
	}//end testAnUnresolvableGuardHidesScopedApps()

	/**
	 * A write to another organisation's scoped app finds nothing, even for an editor.
	 *
	 * @return void
	 */
	public function testAWriteToAnotherOrganisationsAppIsNotFound(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjectsBySlug')->willReturn([$this->app(slug: 'theirs', scope: 'holder')]);
		$handler = $this->bareHandler(objects: $objects, container: $this->containerWith(guard: $this->guardAdmitting(slugs: [])));

		$this->assertSame(
			['isError' => true, 'error' => 'not_found', 'message' => "No virtual app found for slug 'theirs'."],
			$handler->call('requireWriteRole', ['theirs'])
		);
	}//end testAWriteToAnotherOrganisationsAppIsNotFound()

	/**
	 * Reading another organisation's scoped app answers as an unknown slug does.
	 *
	 * @return void
	 */
	public function testAReadOfAnotherOrganisationsAppIsNotFound(): void {
		$handler = $this->bareHandler(
			objects: $this->createMock(ObjectServiceInterface::class),
			container: $this->containerWith(guard: $this->guardAdmitting(slugs: []))
		);

		$this->assertSame(
			['isError' => true, 'error' => 'not_found', 'message' => "No published virtual app found for slug 'theirs'."],
			$handler->call('requireAnyRoleOnApp', [$this->app(slug: 'theirs', scope: 'holder')])
		);
		$this->assertNull($handler->call('requireAnyRoleOnApp', [$this->app(slug: 'shared', scope: null)]));
	}//end testAReadOfAnotherOrganisationsAppIsNotFound()

	/**
	 * An app alice edits, with the given scope.
	 *
	 * @param string      $slug  The slug.
	 * @param string|null $scope The organisation scope, null for none.
	 *
	 * @return array<string, mixed> The Application.
	 */
	private function app(string $slug, ?string $scope): array {
		$app = ['uuid' => "uuid-{$slug}", 'slug' => $slug, 'permissions' => ['editors' => ['user:alice']], '@self' => ['organisation' => 'org-noord']];
		if ($scope !== null) {
			$app['organisationScope'] = $scope;
		}

		return $app;
	}//end app()

	/**
	 * A guard that admits only the given slugs.
	 *
	 * @param array<int, string> $slugs The slugs the caller's organisation holds.
	 *
	 * @return AppOrganisationGuard The guard.
	 */
	private function guardAdmitting(array $slugs): AppOrganisationGuard {
		$guard = $this->createMock(AppOrganisationGuard::class);
		$guard->method('canSee')->willReturnCallback(
			static fn (array $app): bool => in_array($app['slug'], $slugs, true)
		);
		return $guard;
	}//end guardAdmitting()

	/**
	 * A container that hands out the guard.
	 *
	 * @param AppOrganisationGuard $guard The guard.
	 *
	 * @return ContainerInterface The container.
	 */
	private function containerWith(AppOrganisationGuard $guard): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(AppOrganisationGuard::class)->willReturn($guard);
		return $container;
	}//end containerWith()

	/**
	 * The session of alice.
	 *
	 * @return IUserSession The session.
	 */
	private function session(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A group manager for a non-admin without groups.
	 *
	 * @return IGroupManager The group manager.
	 */
	private function nonAdminGroups(): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('getUserGroups')->willReturn([]);
		return $groups;
	}//end nonAdminGroups()

	/**
	 * The list_apps handler.
	 *
	 * @param ObjectServiceInterface $objects   The object service.
	 * @param ContainerInterface     $container The container.
	 *
	 * @return ListAppsHandler The handler.
	 */
	private function listHandler(ObjectServiceInterface $objects, ContainerInterface $container): ListAppsHandler {
		return new ListAppsHandler(
			userSession: $this->session(),
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->nonAdminGroups(),
			objectService: $objects
		);
	}//end listHandler()

	/**
	 * A bare handler exposing the protected checks.
	 *
	 * @param ObjectServiceInterface $objects   The object service.
	 * @param ContainerInterface     $container The container.
	 *
	 * @return AbstractToolHandler The handler, with a public call().
	 */
	private function bareHandler(ObjectServiceInterface $objects, ContainerInterface $container): AbstractToolHandler {
		return new class(
			userSession: $this->session(),
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->nonAdminGroups(),
			objectService: $objects
		) extends AbstractToolHandler {

			/**
			 * Not exercised.
			 *
			 * @param array<string, mixed> $args The arguments.
			 *
			 * @return array<string, mixed> Nothing.
			 */
			public function handle(array $args): array {
				return [];
			}//end handle()

			/**
			 * Expose a protected method.
			 *
			 * @param string       $name The method.
			 * @param array<mixed> $args The arguments.
			 *
			 * @return mixed What it returns.
			 */
			public function call(string $name, array $args): mixed {
				return $this->{$name}(...$args);
			}//end call()
		};
	}//end bareHandler()
}//end class
