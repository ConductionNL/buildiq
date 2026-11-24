<?php

/**
 * Tests for AppPageSettingsController: users of a virtual app read the
 * values saved on its settings page; owners and editors save them
 * (REQ-BQCV-011).
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\AppPageSettingsController;
use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCA\Buildiq\Service\AppChangeHistory;
use OCA\Buildiq\Service\AppPageSettingsAccess;
use OCA\Buildiq\Service\AppPageSettingsStore;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the settings page values endpoint.
 *
 * @covers \OCA\Buildiq\Controller\AppPageSettingsController
 * @uses   \OCA\Buildiq\Service\AppChangeHistory
 * @uses   \OCA\Buildiq\Service\AppPageSettingsAccess
 * @uses   \OCA\Buildiq\Service\AppPageSettingsStore
 */
final class AppPageSettingsControllerTest extends TestCase {
	/**
	 * The app config entries the double holds.
	 *
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * The roles each user holds on the app.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const ROLES = [
		'olaf'  => ['owners'],
		'edith' => ['editors'],
		'vera'  => ['viewers'],
	];

	/**
	 * An editor saves a reply period and a viewer sees it on the next visit.
	 *
	 * @return void
	 */
	public function testAUserSeesTheSavedReplyPeriodOnTheNextVisit(): void {
		$saved = $this->controller(uid: 'edith', body: ['replyPeriod' => 21])->update(appSlug: 'permits', pageId: 'settings');
		self::assertSame(expected: [200, ['values' => ['replyPeriod' => 21]]], actual: [$saved->getStatus(), $saved->getData()]);

		$read = $this->controller(uid: 'vera')->show(appSlug: 'permits', pageId: 'settings');
		self::assertSame(
			expected: [200, ['values' => ['replyPeriod' => 21], 'canSave' => false]],
			actual: [$read->getStatus(), $read->getData()]
		);
	}//end testAUserSeesTheSavedReplyPeriodOnTheNextVisit()

	/**
	 * An owner may save, so the read says so.
	 *
	 * @return void
	 */
	public function testAnOwnerMaySave(): void {
		$read = $this->controller(uid: 'olaf')->show(appSlug: 'permits', pageId: 'settings');

		self::assertSame(expected: [200, ['values' => [], 'canSave' => true]], actual: [$read->getStatus(), $read->getData()]);
	}//end testAnOwnerMaySave()

	/**
	 * A viewer's save is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testAViewersSaveIsRefused(): void {
		$response = $this->controller(uid: 'vera', body: ['replyPeriod' => 1])->update(appSlug: 'permits', pageId: 'settings');

		self::assertSame(expected: [403, ['error' => 'buildiq.rbac.no_role']], actual: [$response->getStatus(), $response->getData()]);
		self::assertSame(expected: [], actual: $this->config);
	}//end testAViewersSaveIsRefused()

	/**
	 * A user without a role reads nothing.
	 *
	 * @return void
	 */
	public function testAUserWithoutARoleIsRefused(): void {
		$this->config[AppPageSettingsStore::KEY_PREFIX . 'app-uuid'] = '{"settings":{"replyPeriod":21}}';

		$response = $this->controller(uid: 'mallory')->show(appSlug: 'permits', pageId: 'settings');

		self::assertSame(expected: [403, ['error' => 'buildiq.rbac.no_role']], actual: [$response->getStatus(), $response->getData()]);
	}//end testAUserWithoutARoleIsRefused()

	/**
	 * Another organisation's scoped app answers like an unknown one.
	 *
	 * @return void
	 */
	public function testAnAppTheCallerCannotSeeIsNotFound(): void {
		$response = $this->controller(uid: 'olaf', sees: false, body: ['replyPeriod' => 1])->update(appSlug: 'permits', pageId: 'settings');

		self::assertSame(expected: 404, actual: $response->getStatus());
		self::assertSame(expected: [], actual: $this->config);
	}//end testAnAppTheCallerCannotSeeIsNotFound()

	/**
	 * A bad option key is a 400 and nothing is written.
	 *
	 * @return void
	 */
	public function testABadOptionKeyIsRefused(): void {
		$response = $this->controller(uid: 'olaf', body: ['reply period' => 1])->update(appSlug: 'permits', pageId: 'settings');

		self::assertSame(expected: [400, ['error' => 'invalid_option_key']], actual: [$response->getStatus(), $response->getData()]);
		self::assertSame(expected: [], actual: $this->config);
	}//end testABadOptionKeyIsRefused()

	/**
	 * A bad page id is a 400 before the app is read.
	 *
	 * @return void
	 */
	public function testABadPageIdIsRefused(): void {
		$response = $this->controller(uid: 'olaf')->show(appSlug: 'permits', pageId: '..');

		self::assertSame(expected: 400, actual: $response->getStatus());
	}//end testABadPageIdIsRefused()

	/**
	 * Nobody signed in is 401.
	 *
	 * @return void
	 */
	public function testNobodySignedInIsUnauthorised(): void {
		$response = $this->controller(uid: null)->show(appSlug: 'permits', pageId: 'settings');

		self::assertSame(expected: 401, actual: $response->getStatus());
	}//end testNobodySignedInIsUnauthorised()

	/**
	 * Build the controller over the app `permits` and an in-memory app config.
	 *
	 * @param string|null         $uid  The signed-in user, or null for nobody.
	 * @param bool                $sees Whether the caller's organisation sees the app.
	 * @param array<string,mixed> $body The request body.
	 *
	 * @return AppPageSettingsController
	 */
	private function controller(?string $uid, bool $sees=true, array $body=[]): AppPageSettingsController {
		$application = ['id' => 'app-uuid', 'slug' => 'permits', 'permissions' => ['owners' => ['user:olaf']]];
		$objects     = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturn([$application]);

		$register = new Register();
		$register->setId(1);
		$registers = $this->createMock(originalClassName: RegisterMapper::class);
		$registers->method('find')->willReturn($register);
		$schema = new Schema();
		$schema->setId(2);
		$schemas = $this->createMock(originalClassName: SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$resolver = $this->createMock(originalClassName: PermissionResolverInterface::class);
		$resolver->method('seesApplication')->willReturn($sees);
		$resolver->method('resolveUserGroups')->willReturn([]);
		$resolver->method('matchesCaller')->willReturnCallback(
			static function (array $permissions, IUser $caller, array $userGroups, ?bool $allowAdminBypass, array $roles): bool {
				if ($allowAdminBypass !== false) {
					return true;
				}

				return array_intersect(self::ROLES[$caller->getUID()] ?? [], $roles) !== [];
			}
		);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturn($body + ['appSlug' => 'permits', 'pageId' => 'settings', '_route' => 'buildiq.appPageSettings.update']);

		$access = new AppPageSettingsAccess(
			userSession: $session,
			permissionResolver: $resolver,
			applications: new AppChangeHistory(objectService: $objects, registerMapper: $registers, schemaMapper: $schemas),
		);

		return new AppPageSettingsController(
			request: $request,
			access: $access,
			store: new AppPageSettingsStore(appConfig: $appConfig),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end controller()
}//end class
