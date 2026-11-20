<?php

/**
 * The scenarios of REQ-BQCV-026: an administrator configures the builder on the admin settings page.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/settings-and-observability/spec.md#requirement-an-administrator-configures-the-builder-on-the-admin-settings-page-req-bqcv-026
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\SettingsController;
use OCA\Buildiq\Sections\SettingsSection;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\SettingsService;
use OCA\Buildiq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The real SettingsController over the real SettingsService, writing to an
 * app configuration held in memory, so a save and a later read meet in it.
 */
final class AdminSettingsScenariosTest extends TestCase {

	/**
	 * The app configuration, as `app/key => value`.
	 *
	 * @var array<string, mixed>
	 */
	private array $stored = [];

	/**
	 * Buildiq registers its admin section and panel in info.xml, and the panel sits in that section.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/settings-and-observability/spec.md#requirement-an-administrator-configures-the-builder-on-the-admin-settings-page-req-bqcv-026
	 */
	public function testBuildiqRegistersAnAdminSettingsSection(): void {
		$info = simplexml_load_file(filename: __DIR__ . '/../../../appinfo/info.xml');
		self::assertSame(expected: AdminSettings::class, actual: (string)$info->settings->admin);
		self::assertSame(expected: SettingsSection::class, actual: (string)$info->settings->{'admin-section'});

		$panel = new AdminSettings(
			appId: 'buildiq',
			sectionId: 'buildiq',
			priority: 10,
			appManager: $this->createMock(originalClassName: IAppManager::class),
			initialState: $this->createMock(originalClassName: IInitialState::class),
			appConfig: $this->createMock(originalClassName: IAppConfig::class)
		);
		self::assertSame(expected: 'buildiq', actual: $panel->getSection());
	}//end testBuildiqRegistersAnAdminSettingsSection()

	/**
	 * An administrator changes the builder's register; it is stored in the app
	 * configuration and a fresh read (the page after a reload) shows it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/settings-and-observability/spec.md#requirement-an-administrator-configures-the-builder-on-the-admin-settings-page-req-bqcv-026
	 */
	public function testAnAdministratorChangesABuilderSetting(): void {
		$response = $this->controller(uid: 'admin', isAdmin: true, params: ['register' => 'permits'])->update();

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: 'permits', actual: $this->stored['buildiq/register']);
		self::assertSame(expected: 'permits', actual: $response->getData()['config']['register']);

		$reloaded = $this->controller(uid: 'admin', isAdmin: true, params: [])->index();
		self::assertSame(expected: 'permits', actual: $reloaded->getData()['register']);
	}//end testAnAdministratorChangesABuilderSetting()

	/**
	 * A signed-in user who is not an administrator is refused, and no setting changes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/settings-and-observability/spec.md#requirement-an-administrator-configures-the-builder-on-the-admin-settings-page-req-bqcv-026
	 */
	public function testAUserWhoIsNotAnAdministratorCannotChangeThem(): void {
		$this->stored['buildiq/register'] = 'buildiq';

		$update = $this->controller(uid: 'bob', isAdmin: false, params: ['register' => 'elsewhere'])->update();
		$create = $this->controller(uid: 'bob', isAdmin: false, params: ['register' => 'elsewhere'])->create();

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $update->getStatus());
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $create->getStatus());
		self::assertSame(expected: ['buildiq/register' => 'buildiq'], actual: $this->stored);
	}//end testAUserWhoIsNotAnAdministratorCannotChangeThem()

	/**
	 * A SettingsController for this user and request, over a SettingsService writing to $this->stored.
	 *
	 * @param string               $uid     The signed-in user.
	 * @param boolean              $isAdmin Whether that user is in the admin group.
	 * @param array<string, mixed> $params  The request parameters.
	 *
	 * @return SettingsController
	 */
	private function controller(string $uid, bool $isAdmin, array $params): SettingsController {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isInGroup')->willReturn($isAdmin);
		$groups->method('isAdmin')->willReturn($isAdmin);
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn($params);

		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);

		$service = new SettingsService(
			appConfig: $this->appConfig(),
			appManager: $apps,
			container: $this->createMock(originalClassName: ContainerInterface::class),
			caller: new CallerContext(
				userSession: $session,
				groupManager: $groups,
				permissionResolver: $this->createMock(originalClassName: PermissionResolver::class)
			),
			logger: new NullLogger(),
		);

		return new SettingsController(request: $request, settingsService: $service, userSession: $session, groupManager: $groups);
	}//end controller()

	/**
	 * An IAppConfig that reads and writes $this->stored.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$read = fn (string $app, string $key, mixed $default = null): mixed => ($this->stored[$app . '/' . $key] ?? $default);
		$write = function (string $app, string $key, mixed $value): bool {
			$this->stored[$app . '/' . $key] = $value;
			return true;
		};
		$config->method('getValueString')->willReturnCallback($read);
		$config->method('getValueBool')->willReturnCallback($read);
		$config->method('setValueString')->willReturnCallback($write);
		$config->method('setValueBool')->willReturnCallback($write);

		return $config;
	}//end appConfig()
}//end class
