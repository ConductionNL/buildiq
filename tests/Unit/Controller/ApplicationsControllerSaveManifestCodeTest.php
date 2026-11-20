<?php

/**
 * ApplicationsController::saveManifest() and code on pages (pages-custom-code D6).
 *
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-only-owners-write-code-and-only-when-allowed-req-bqcc-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationsController;
use OCA\Buildiq\Service\AppChannelApplier;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\ManifestResolverService;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\RegisterStore;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Only an app owner adds, changes or removes code, and only with "Allow code on pages" on.
 */
class ApplicationsControllerSaveManifestCodeTest extends TestCase {

	/**
	 * A manifest without code.
	 *
	 * @var array<string, mixed>
	 */
	private const PLAIN = ['version' => '1.2.0', 'pages' => [['id' => 'home', 'type' => 'custom', 'config' => ['component' => 'Home']]]];

	/**
	 * The same manifest with a code component on the page.
	 *
	 * @var array<string, mixed>
	 */
	private const WITH_CODE = ['version' => '1.2.0', 'pages' => [['id' => 'home', 'type' => 'custom', 'config' => ['code' => ['html' => '<p>hi</p>', 'css' => '', 'js' => 'render()', 'inputs' => ['user']]]]]];

	/**
	 * Request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private $request;

	/**
	 * OpenRegister objects.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private $objectService;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private $userSession;

	/**
	 * Group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private $groupManager;

	/**
	 * The manifest resolver, answering the stored manifest.
	 *
	 * @var ManifestResolverService&MockObject
	 */
	private $manifestResolver;

	/**
	 * App config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private $appConfig;

	/**
	 * Set up an app permit-tracker owned by alice, edited by bob.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request          = $this->createMock(IRequest::class);
		$this->objectService    = $this->createMock(ObjectServiceInterface::class);
		$this->userSession      = $this->createMock(IUserSession::class);
		$this->manifestResolver = $this->createMock(ManifestResolverService::class);
		$this->appConfig        = $this->createMock(IAppConfig::class);
		$this->groupManager     = $this->createMock(IGroupManager::class);
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => $uid === 'root' && $group === 'admin');

		$this->objectService->method('searchObjects')->willReturn([['id' => 'route-1', 'slug' => 'permit-tracker', 'applicationUuid' => 'app-uuid']]);
		$this->objectService->method('find')->willReturn($this->entity([
			'id' => 'app-uuid',
			'slug' => 'permit-tracker',
			'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
		]));
	}//end setUp()

	/**
	 * The controller under test, with the real permission resolver.
	 *
	 * @return ApplicationsController
	 */
	private function controller(): ApplicationsController {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$register       = new Register();
		$register->setId(1);
		$registerMapper->method('find')->willReturn($register);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schema       = new Schema();
		$schema->setId(2);
		$schemaMapper->method('find')->willReturn($schema);

		return new ApplicationsController(
			request: $this->request,
			logger: $this->createMock(LoggerInterface::class),
			store: new RegisterStore(objectService: $this->objectService, registerMapper: $registerMapper, schemaMapper: $schemaMapper, auditTrailMapper: null),
			caller: new CallerContext(
				userSession: $this->userSession,
				groupManager: $this->groupManager,
				permissionResolver: new PermissionResolver($this->groupManager, $this->createMock(LoggerInterface::class))
			),
			manifestResolver: $this->manifestResolver,
			channelApplier: $this->createMock(AppChannelApplier::class),
			appConfig: $this->appConfig
		);
	}//end controller()

	/**
	 * An object entity serialising to this payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = new class() extends ObjectEntity {
			/**
			 * @var array<string, mixed>
			 */
			public array $payload = [];

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->payload;
			}
		};
		$entity->payload = $payload;
		return $entity;
	}//end entity()

	/**
	 * A user sends a manifest over a stored one, with the setting on or off.
	 *
	 * @param string              $uid     The caller.
	 * @param array<string,mixed> $stored  The stored manifest.
	 * @param array<string,mixed> $sent    The manifest sent.
	 * @param bool                $allowed The instance setting.
	 *
	 * @return void
	 */
	private function given(string $uid, array $stored, array $sent, bool $allowed): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->manifestResolver->method('resolve')->willReturn($stored);
		$this->appConfig->method('getValueBool')->willReturnCallback(static fn (string $app, string $key, bool $default = false): bool => ($app === 'buildiq' && $key === 'allow_page_code') ? $allowed : $default);
		$this->request->method('getParam')->willReturnMap([['manifest', null, $sent]]);
	}//end given()

	/**
	 * An editor cannot add code, even with the setting on; nothing is written.
	 *
	 * @return void
	 */
	public function testAnEditorCannotAddCode(): void {
		$this->given(uid: 'bob', stored: self::PLAIN, sent: self::WITH_CODE, allowed: true);
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['error' => 'forbidden', 'code' => 'buildiq.code.owners_only', 'message' => 'Only app owners can add code to a page.'], $response->getData());
	}//end testAnEditorCannotAddCode()

	/**
	 * With the setting off, even an owner cannot add code.
	 *
	 * @return void
	 */
	public function testTheSettingOffRefusesAnOwner(): void {
		$this->given(uid: 'alice', stored: self::PLAIN, sent: self::WITH_CODE, allowed: false);
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('buildiq.code.disabled', $response->getData()['code']);
	}//end testTheSettingOffRefusesAnOwner()

	/**
	 * A Nextcloud administrator who is not an owner gets no bypass for code.
	 *
	 * @return void
	 */
	public function testAnAdministratorWhoIsNoOwnerCannotAddCode(): void {
		$this->given(uid: 'root', stored: self::PLAIN, sent: self::WITH_CODE, allowed: true);
		$this->objectService->expects(self::never())->method('saveObject');

		self::assertSame('buildiq.code.owners_only', $this->controller()->saveManifest(slug: 'permit-tracker')->getData()['code']);
	}//end testAnAdministratorWhoIsNoOwnerCannotAddCode()

	/**
	 * An editor cannot remove code an owner wrote either.
	 *
	 * @return void
	 */
	public function testAnEditorCannotRemoveCode(): void {
		$this->given(uid: 'bob', stored: self::WITH_CODE, sent: self::PLAIN, allowed: true);
		$this->objectService->expects(self::never())->method('saveObject');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->saveManifest(slug: 'permit-tracker')->getStatus());
	}//end testAnEditorCannotRemoveCode()

	/**
	 * An owner with the setting on saves code.
	 *
	 * @return void
	 */
	public function testAnOwnerWithTheSettingOnSavesCode(): void {
		$this->given(uid: 'alice', stored: self::PLAIN, sent: self::WITH_CODE, allowed: true);
		$this->objectService->expects(self::once())->method('saveObject')->willReturn($this->entity([]));

		self::assertSame(Http::STATUS_OK, $this->controller()->saveManifest(slug: 'permit-tracker')->getStatus());
	}//end testAnOwnerWithTheSettingOnSavesCode()

	/**
	 * An editor saving other changes around unchanged code is not refused, with the setting off.
	 *
	 * @return void
	 */
	public function testAnEditorKeepsWorkingAroundUnchangedCode(): void {
		$sent             = self::WITH_CODE;
		$sent['version']  = '1.3.0';
		$sent['pages'][]  = ['id' => 'about', 'type' => 'custom', 'config' => ['component' => 'About']];
		$this->given(uid: 'bob', stored: self::WITH_CODE, sent: $sent, allowed: false);
		$this->objectService->expects(self::once())->method('saveObject')->willReturn($this->entity([]));

		self::assertSame(Http::STATUS_OK, $this->controller()->saveManifest(slug: 'permit-tracker')->getStatus());
	}//end testAnEditorKeepsWorkingAroundUnchangedCode()
}//end class
