<?php

/**
 * Unit tests for the builder-group grant in PermissionResolver
 * (REQ-OBRBAC-008).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/builder-groups/specs/openbuild-rbac/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\PermissionResolver;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A member of an admin-nominated builder group is an editor of every app.
 */
final class PermissionResolverBuilderGroupsTest extends TestCase {

	/**
	 * An app whose permission block names someone else.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const PERMISSIONS = [
		'owners' => ['user:alice'],
		'editors' => [],
		'viewers' => ['group:team-readers'],
	];

	/**
	 * Build a resolver whose app config stores the given builder groups.
	 *
	 * @param string|null $stored The stored JSON, or null for no app config at all.
	 *
	 * @return PermissionResolver
	 */
	private function resolver(?string $stored): PermissionResolver {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);

		$appConfig = null;
		if ($stored !== null) {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturnCallback(
				static fn (string $app, string $key, string $default = ''): string => ($key === 'builder_groups') ? $stored : $default
			);
		}

		return new PermissionResolver(
			groupManager: $groupManager,
			logger: $this->createMock(LoggerInterface::class),
			appConfig: $appConfig
		);
	}//end resolver()

	/**
	 * A caller with the given uid.
	 *
	 * @return IUser
	 */
	private function carol(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('carol');
		return $user;
	}//end carol()

	/**
	 * A builder passes every check that accepts editors.
	 *
	 * @return void
	 */
	public function testABuilderPassesAnEditorCheck(): void {
		$allowed = $this->resolver(stored: '["buildiq-builders"]')->matchesCaller(
			permissions: self::PERMISSIONS,
			caller: $this->carol(),
			userGroups: ['staff', 'buildiq-builders'],
			allowAdminBypass: true,
			roles: ['owners', 'editors']
		);

		self::assertTrue($allowed);
	}//end testABuilderPassesAnEditorCheck()

	/**
	 * A builder also passes a read check, which includes editors.
	 *
	 * @return void
	 */
	public function testABuilderPassesAReadCheck(): void {
		$allowed = $this->resolver(stored: '["buildiq-builders"]')->matchesCaller(
			permissions: self::PERMISSIONS,
			caller: $this->carol(),
			userGroups: ['buildiq-builders'],
			allowAdminBypass: false,
			roles: ['owners', 'editors', 'viewers']
		);

		self::assertTrue($allowed);
	}//end testABuilderPassesAReadCheck()

	/**
	 * A builder does not pass an owner-only check.
	 *
	 * @return void
	 */
	public function testABuilderDoesNotPassAnOwnerOnlyCheck(): void {
		$allowed = $this->resolver(stored: '["buildiq-builders"]')->matchesCaller(
			permissions: self::PERMISSIONS,
			caller: $this->carol(),
			userGroups: ['buildiq-builders'],
			allowAdminBypass: true,
			roles: ['owners']
		);

		self::assertFalse($allowed);
	}//end testABuilderDoesNotPassAnOwnerOnlyCheck()

	/**
	 * A user outside every builder group is refused, as before.
	 *
	 * @return void
	 */
	public function testAUserOutsideTheBuilderGroupsIsRefused(): void {
		$allowed = $this->resolver(stored: '["buildiq-builders"]')->matchesCaller(
			permissions: self::PERMISSIONS,
			caller: $this->carol(),
			userGroups: ['staff'],
			allowAdminBypass: true,
			roles: ['owners', 'editors']
		);

		self::assertFalse($allowed);
	}//end testAUserOutsideTheBuilderGroupsIsRefused()

	/**
	 * With no builder groups configured, or no app config at all, nobody
	 * gains access this way.
	 *
	 * @return void
	 */
	public function testNoBuilderGroupsGrantNothing(): void {
		foreach (['[]', 'not json', null] as $stored) {
			$allowed = $this->resolver(stored: $stored)->matchesCaller(
				permissions: self::PERMISSIONS,
				caller: $this->carol(),
				userGroups: ['buildiq-builders'],
				allowAdminBypass: true,
				roles: ['owners', 'editors']
			);

			self::assertFalse($allowed, 'stored value: ' . var_export($stored, true));
		}
	}//end testNoBuilderGroupsGrantNothing()

	/**
	 * An empty permission block stays closed, for builders as for admins.
	 *
	 * @return void
	 */
	public function testAnEmptyPermissionBlockStaysClosed(): void {
		$allowed = $this->resolver(stored: '["buildiq-builders"]')->matchesCaller(
			permissions: [],
			caller: $this->carol(),
			userGroups: ['buildiq-builders'],
			allowAdminBypass: true,
			roles: ['owners', 'editors']
		);

		self::assertFalse($allowed);
	}//end testAnEmptyPermissionBlockStaysClosed()

	/**
	 * Per-app roles keep working with builder groups configured.
	 *
	 * @return void
	 */
	public function testPerAppRolesStillWork(): void {
		$allowed = $this->resolver(stored: '["buildiq-builders"]')->matchesCaller(
			permissions: self::PERMISSIONS,
			caller: $this->carol(),
			userGroups: ['team-readers'],
			allowAdminBypass: false,
			roles: ['viewers']
		);

		self::assertTrue($allowed);
	}//end testPerAppRolesStillWork()
}//end class
